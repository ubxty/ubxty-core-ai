<?php

namespace Ubxty\CoreAi\Standards\Converse;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ubxty\CoreAi\Client\AbstractCredentialManager;
use Ubxty\CoreAi\Client\AbstractLLMClient;
use Ubxty\CoreAi\Contracts\LLMResult;
use Ubxty\CoreAi\Contracts\LLMUsage;
use Ubxty\CoreAi\Contracts\StructuredSchema;
use Ubxty\CoreAi\Contracts\ToolChoice;
use Ubxty\CoreAi\Exceptions\ConfigurationException;
use Ubxty\CoreAi\Exceptions\RateLimitException;

/**
 * Standard AWS Bedrock Converse wire-format client.
 *
 * Owns the SDK vs HTTP Bearer branching for the Converse API. Subclasses
 * (bedrock-ai's `BedrockClient`) override the auth/event hooks and the
 * model-listing paths but inherit the converse / converseStream / cachePoint
 * injection / error-mapping machinery.
 *
 * Conforms to `core-ai`'s `AbstractLLMClient` template-method contract —
 * the concrete converse() / converseStream() wrappers above call
 * `withRetry` (from `HasRetryLogic`) which in turn invokes the wire-format
 * hooks this class implements (buildRequest / sendRequest /
 * sendStreamingRequest / parseResponse / chatUrl / authHeaders).
 *
 * Does NOT depend on the AWS SDK at compile time — the SDK client is
 * passed in as an optional constructor arg. Without an SDK client, the
 * HTTP Bearer path is used; without a Bearer token either, runtime
 * ConfigurationException fires from `chatUrl()`.
 */
class ConverseClient extends AbstractLLMClient
{
    use HasConverseFormatting {
        // v2.3.1 compat: the trait provides applyCachePoints() but the
        // override below shadows it. Alias it so the override can call
        // back into the real implementation via $this->applyCachePointsFromTrait().
        // Without this, the override's parent::applyCachePoints() call resolves
        // to AbstractLLMClient (the parent class), which doesn't define
        // applyCachePoints() — only HasConverseFormatting does — and the call
        // site fires a class-load-time fatal when anchors are non-empty:
        //
        //     Call to undefined method Ubxty\CoreAi\Client\AbstractLLMClient::applyCachePoints()
        applyCachePoints as private applyCachePointsFromTrait;
    }

    /**
     * Optional pre-built AWS SDK client for IAM-mode callers.
     * If null, `sendRequest()` will lazily instantiate one from the
     * current key. Subclasses (bedrock-ai's BedrockClient) pre-wire this
     * so cross-connection key rotation works cleanly.
     */
    protected ?BedrockRuntimeClient $sdkClient = null;

    /** @var AbstractCredentialManager */
    protected $credentials;

    public function __construct(
        AbstractCredentialManager $credentials,
        int $maxRetries = 3,
        int $baseDelay = 2,
        int $modelsCacheTtl = 3600,
        ?BedrockRuntimeClient $sdkClient = null,
    ) {
        parent::__construct($credentials, $maxRetries, $baseDelay, $modelsCacheTtl);
        $this->sdkClient = $sdkClient;
    }

    // ─────────────────────────────────────────────────────────
    //  Feature-detection defaults for Converse
    // ─────────────────────────────────────────────────────────

    public function supportsPromptCaching(): bool
    {
        return true;
    }

    // ─────────────────────────────────────────────────────────
    //  AbstractLLMClient template-method hooks
    // ─────────────────────────────────────────────────────────

    protected function buildRequest(
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        array $tools,
        ?ToolChoice $toolChoice,
        ?StructuredSchema $schema,
        ?array $cacheAnchors,
        string $modelId,
        array $key,
    ): array {
        $systemBlocks = $systemPrompt !== '' ? [['text' => $systemPrompt]] : [];
        $formattedMessages = $this->formatMessages($messages);

        // Honour either the manager-supplied cache anchors (per call) or the
        // configured static anchors (from setPromptCachePoints).
        $anchors = $cacheAnchors ?? $this->promptCachePoints;
        if (! empty($anchors)) {
            [$formattedMessages, $systemBlocks] = $this->applyCachePoints(
                $formattedMessages, $systemBlocks, $modelId, $anchors
            );
        }

        $body = [
            'messages' => $formattedMessages,
            'inferenceConfig' => [
                'maxTokens' => $maxTokens,
                'temperature' => $temperature,
            ],
        ];

        if (! empty($systemBlocks)) {
            $body['system'] = $systemBlocks;
        }

        if (! empty($tools)) {
            $body['toolConfig'] = $this->translateTools($tools, $toolChoice);
        }

        return $body;
    }

    protected function sendRequest(
        string $url,
        array $body,
        array $headers,
        string $modelId,
        array $key,
        ?string $idempotencyKey,
    ): array {
        // Lazy: honour the optional SDK client only when no Authorization
        // header was already attached by authHeaders(). Bearer mode wins.
        $authHeader = $headers['Authorization'] ?? null;

        if ($authHeader === null || ! str_starts_with($authHeader, 'Bearer ')) {
            return $this->sendRequestViaSdk($body, $modelId, $idempotencyKey);
        }

        return $this->sendRequestViaHttp($url, $body, $headers, $idempotencyKey);
    }

    protected function sendStreamingRequest(
        string $url,
        array $body,
        array $headers,
        string $modelId,
        array $key,
        ?string $idempotencyKey,
        ?callable $onDelta,
    ): array {
        // Streaming is IAM-only. Bearer mode throws ConfigurationException
        // — consistent with v2.1.x's StreamingClient behaviour.
        if (! empty($headers['Authorization']) && str_starts_with($headers['Authorization'], 'Bearer ')) {
            throw new ConfigurationException(
                'Bedrock streaming is only supported in IAM mode. Bearer tokens cannot stream Converse responses.'
            );
        }

        $startTime = microtime(true);
        $client = $this->getOrCreateSdkClient($key);

        $result = $client->converseStream($body);

        $outputText = '';
        $streamEvents = [];

        if ($result && method_exists($result, 'getIterator')) {
            foreach ($result->getIterator() as $event) {
                $eventArray = $event->toArray();
                $streamEvents[] = $eventArray;

                // contentBlockDelta events carry the streaming text chunks.
                if (isset($eventArray['contentBlockDelta']['delta']['text'])) {
                    $chunk = (string) $eventArray['contentBlockDelta']['delta']['text'];
                    $outputText .= $chunk;
                    if ($onDelta !== null) {
                        $onDelta($chunk);
                    }
                }
            }
        }

        // Bedrock returns the final usage in metadata, not in the streaming
        // events themselves — pull it from the last event if present.
        $usage = ['inputTokens' => 0, 'outputTokens' => 0];
        $stopReason = 'end_turn';
        $modelIdOut = $modelId;

        foreach ($streamEvents as $event) {
            if (isset($event['metadata']['usage'])) {
                $usage = $event['metadata']['usage'];
            }
            if (isset($event['messageStop']['stopReason'])) {
                $stopReason = $event['messageStop']['stopReason'];
            }
        }

        return [
            'output' => ['message' => ['content' => [['text' => $outputText]]]],
            'usage' => $usage,
            'stopReason' => $stopReason,
            'model_id' => $modelIdOut,
            'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'stream_events' => $streamEvents,
        ];
    }

    protected function parseResponse(array $raw, string $modelId): LLMResult
    {
        $outputText = $raw['output']['message']['content'][0]['text'] ?? '';
        $inputTokens = (int) ($raw['usage']['inputTokens'] ?? 0);
        $outputTokens = (int) ($raw['usage']['outputTokens'] ?? 0);
        $cacheReadInputTokens = (int) ($raw['usage']['cacheReadInputTokens'] ?? 0);
        $cacheWriteInputTokens = (int) ($raw['usage']['cacheWriteInputTokens'] ?? 0);
        $stopReason = (string) ($raw['stopReason'] ?? 'end_turn');

        $effectiveInput = $this->effectiveInputTokens(
            $inputTokens, $cacheReadInputTokens, $cacheWriteInputTokens,
        );

        return new LLMResult(
            text: $outputText,
            toolCalls: [],
            finishReason: $stopReason,
            usage: new LLMUsage(
                inputTokens: $effectiveInput,
                outputTokens: $outputTokens,
                cachedReadTokens: $cacheReadInputTokens,
                cachedWriteTokens: $cacheWriteInputTokens,
            ),
            modelId: $modelId,
            keyLabel: '',
            latencyMs: (int) ($raw['latency_ms'] ?? 0),
            cached: false,
            raw: $raw,
        );
    }

    protected function chatUrl(string $endpoint, string $modelId, array $key): string
    {
        if (! empty($key['bearer_token'] ?? null)) {
            $region = $key['region'] ?? 'us-east-1';
            return "https://bedrock-runtime.{$region}.amazonaws.com/model/{$modelId}/converse";
        }

        // SDK path doesn't need a URL — return a sentinel.
        return 'aws-sdk://bedrock-runtime/converse';
    }

    protected function authHeaders(string $endpoint, array $key, ?string $idempotencyKey): array
    {
        $bearer = $key['bearer_token'] ?? null;
        if ($bearer !== null && $bearer !== '') {
            $headers = [
                'Authorization' => 'Bearer ' . $bearer,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
            return $headers;
        }

        // SDK path: no headers (signed by the SDK).
        return [];
    }

    protected function resolveModelId(string $modelId, array $key): string
    {
        $region = $key['region'] ?? 'us-east-1';
        return InferenceProfileResolver::resolve($modelId, $region);
    }

    // ─────────────────────────────────────────────────────────
    //  Internal helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Execute one converse call via the AWS PHP SDK (IAM mode).
     *
     * @return array<string, mixed>
     */
    protected function sendRequestViaSdk(array $body, string $modelId, ?string $idempotencyKey): array
    {
        $key = $this->credentials->current();
        $client = $this->getOrCreateSdkClient($key);
        $params = ['modelId' => $modelId] + $body;

        try {
            $result = $client->converse($params);
        } catch (\Aws\Exception\AwsException $e) {
            $this->translateAwsException($e);
        }

        return $result->toArray() + [
            'model_id' => $modelId,
            'key_used' => $key['label'] ?? 'Primary',
            'latency_ms' => 0,
        ];
    }

    /**
     * Execute one converse call via the HTTP Bearer endpoint.
     *
     * @return array<string, mixed>
     */
    protected function sendRequestViaHttp(string $url, array $body, array $headers, ?string $idempotencyKey): array
    {
        $startTime = microtime(true);

        $response = Http::withHeaders($headers)->post($url, $body);

        if (! $response->successful()) {
            $status = $response->status();
            if ($status === 429) {
                $retryAfter = $response->header('Retry-After');
                if ($retryAfter !== null) {
                    $this->setRetryAfterSeconds((int) $retryAfter);
                }
                throw new RateLimitException('429 Too many requests - rate limited', 429);
            }
            throw new \Ubxty\CoreAi\Exceptions\BedrockException(
                "Bedrock HTTP Error: {$status} - {$response->body()}",
                $status
            );
        }

        $data = $response->json();
        $data['latency_ms'] = (int) ((microtime(true) - $startTime) * 1000);
        $data['key_used'] = $this->extractKeyLabelFromHeaders($headers);

        return $data;
    }

    protected function getOrCreateSdkClient(array $key): BedrockRuntimeClient
    {
        if ($this->sdkClient !== null) {
            return $this->sdkClient;
        }

        $this->sdkClient = new BedrockRuntimeClient([
            'version' => 'latest',
            'region' => $key['region'] ?? 'us-east-1',
            'credentials' => [
                'key' => $key['aws_key'] ?? $key['access_key'] ?? '',
                'secret' => $key['aws_secret'] ?? $key['secret_key'] ?? '',
            ],
        ]);

        return $this->sdkClient;
    }

    protected function extractKeyLabelFromHeaders(array $headers): string
    {
        // Bearer mode doesn't include the key label in the request — best
        // we can do is "Bearer". Subclasses (bedrock-ai's BedrockClient)
        // override `parseResponse()` to inject the real label.
        return 'Bearer';
    }

    /**
     * Translate an AwsException into a core-ai exception so the rest of
     * the pipeline only has to deal with `RateLimitException` /
     * `ConfigurationException` / `BedrockException`.
     */
    protected function translateAwsException(\Aws\Exception\AwsException $e): never
    {
        $code = $e->getAwsErrorCode() ?? '';
        $status = $e->getStatusCode();

        if ($status === 429 || $code === 'ThrottlingException') {
            throw new RateLimitException(
                $e->getMessage(),
                $status,
                $e
            );
        }

        throw new \Ubxty\CoreAi\Exceptions\BedrockException(
            $e->getMessage(),
            $status,
            $e
        );
    }

    /**
     * Translate tool descriptors into the Converse `toolConfig` block.
     *
     * Per the Bedrock Converse spec, tools are declared as
     * `toolConfig.tools[].toolSpec.{name, description, inputSchema}`.
     */
    protected function translateTools(array $tools, ?ToolChoice $toolChoice): array
    {
        $toolConfig = ['tools' => []];

        foreach ($tools as $tool) {
            $toolConfig['tools'][] = [
                'toolSpec' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'inputSchema' => [
                        'json' => $tool->parameters ?? ['type' => 'object', 'properties' => []],
                    ],
                ],
            ];
        }

        if ($toolChoice !== null) {
            $toolConfig['toolChoice'] = match ($toolChoice->mode) {
                'auto'  => ['auto' => new \stdClass()],
                'any'   => ['any'  => new \stdClass()],
                'tool'  => ['tool' => ['name' => $toolChoice->toolName ?? '']],
                default => ['auto' => new \stdClass()],
            };
        }

        return $toolConfig;
    }

    /**
     * Override the parent `applyCachePoints` signature so per-call
     * `cacheAnchors` can be passed without touching the configured
     * `$promptCachePoints`. Falls back to the trait default if null.
     */
    protected function applyCachePoints(array $messages, array $system, string $modelId = '', ?array $anchors = null): array
    {
        if ($anchors === null || empty($anchors)) {
            return [$messages, $system];
        }

        // Temporarily swap, call trait default, swap back.
        $saved = $this->promptCachePoints;
        $this->promptCachePoints = $anchors;
        try {
            // v2.3.1 compat: parent::applyCachePoints() doesn't exist on
            // AbstractLLMClient — the real implementation lives in the
            // HasConverseFormatting trait, aliased above as
            // applyCachePointsFromTrait so the override can call back into it.
            return $this->applyCachePointsFromTrait($messages, $system, $modelId);
        } finally {
            $this->promptCachePoints = $saved;
        }
    }

    /**
     * Public wrapper for the trait's `supportsCaching()` so callers outside
     * the trait (e.g. {@see \Ubxty\BedrockAi\BedrockManager::modelSupportsCaching()})
     * can ask whether a model would receive cachePoint markers under the
     * current allowlist config without firing a request.
     */
    public function modelSupportsCaching(string $modelId): bool
    {
        return $this->supportsCaching($modelId);
    }
}
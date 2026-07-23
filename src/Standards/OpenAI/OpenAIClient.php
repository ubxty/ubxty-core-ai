<?php

namespace Ubxty\CoreAi\Standards\OpenAI;

use Illuminate\Support\Facades\Http;
use Ubxty\CoreAi\Client\AbstractCredentialManager;
use Ubxty\CoreAi\Client\AbstractLLMClient;
use Ubxty\CoreAi\Contracts\LLMResult;
use Ubxty\CoreAi\Contracts\LLMUsage;
use Ubxty\CoreAi\Contracts\StructuredSchema;
use Ubxty\CoreAi\Contracts\ToolCall;
use Ubxty\CoreAi\Contracts\ToolChoice;
use Ubxty\CoreAi\Exceptions\RateLimitException;

/**
 * Standard OpenAI Chat Completions wire-format client.
 *
 * Owns the JSON request body for the `/chat/completions` endpoint and
 * SSE parsing for `/chat/completions` streaming. Subclasses (azure-ai's
 * `AzureClient`) override the auth / URL / error-mapping hooks and add
 * any platform-specific endpoints (deployments listing, embeddings,
 * tests) but inherit the converse / converseStream / cache-marker
 * injection / error-translation machinery.
 *
 * Conforms to {@see \Ubxty\CoreAi\Client\AbstractLLMClient} — the
 * concrete converse() / converseStream() wrappers above call
 * `withRetry` (from {@see \Ubxty\CoreAi\Client\HasRetryLogic}) which
 * in turn invokes the wire-format hooks this class implements.
 *
 * Default endpoint flavour is the public OpenAI API
 * (`https://api.openai.com/v1`). Subclasses override `chatUrl()` for
 * vendor-specific URL shapes.
 */
class OpenAIClient extends AbstractLLMClient
{
    use HasOpenAIFormatting;

    public function platformName(): string
    {
        return 'OpenAI';
    }

    // ─────────────────────────────────────────────────────────
    //  Feature-detection defaults for OpenAI
    // ─────────────────────────────────────────────────────────

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    public function supportsStreaming(): bool
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
        $body = [
            'messages' => $this->formatMessages($messages, $systemPrompt),
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        if (! empty($tools)) {
            $body['tools'] = $this->translateTools($tools);
            if ($toolChoice !== null) {
                $body['tool_choice'] = $toolChoice->value;
            }
        }

        if ($schema !== null) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schema->name,
                    'schema' => $schema->schema,
                    'strict' => $schema->strict,
                ],
            ];
        }

        // Per-call cache anchors override the configured promptCachePoints
        // for this single request. Empty = use the static config (which
        // may itself be empty).
        $anchors = $cacheAnchors ?? $this->promptCachePoints;
        if (! empty($anchors)) {
            [$body['messages']] = $this->applyCacheControlWith($body['messages'], $anchors);
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
        $startTime = microtime(true);

        $response = Http::withHeaders($headers)->timeout(120)->post($url, $body);

        if (! $response->successful()) {
            $this->handleErrorResponse($response);
        }

        $data = $response->json() ?? [];

        return $data + [
            'model_id' => $modelId,
            'key_used' => $key['label'] ?? 'Primary',
            'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
        ];
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
        $body['stream'] = true;
        $body['stream_options'] = ['include_usage' => true];

        $startTime = microtime(true);
        $outputText = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $toolCalls = [];

        $response = Http::withHeaders($headers)
            ->timeout(300)
            ->withOptions(['stream' => true])
            ->post($url, $body);

        if (! $response->successful()) {
            $this->handleErrorResponse($response);
        }

        $response->throw();

        $buffer = '';
        foreach ($response->toPsrResponse()->getBody() as $chunk) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '' || $line === 'data: [DONE]') {
                    continue;
                }

                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = json_decode(substr($line, 6), true);
                if (! is_array($json)) {
                    continue;
                }

                // Stream usage tokens (when stream_options.include_usage is true).
                if (isset($json['usage'])) {
                    $inputTokens = (int) ($json['usage']['prompt_tokens'] ?? $inputTokens);
                    $outputTokens = (int) ($json['usage']['completion_tokens'] ?? $outputTokens);
                }

                $delta = $json['choices'][0]['delta'] ?? [];
                if (isset($delta['content'])) {
                    $text = (string) $delta['content'];
                    $outputText .= $text;
                    if ($onDelta !== null) {
                        $onDelta($text);
                    }
                }
                if (isset($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tcDelta) {
                        $index = (int) ($tcDelta['index'] ?? 0);
                        if (! isset($toolCalls[$index])) {
                            $toolCalls[$index] = ['id' => '', 'name' => '', 'arguments' => ''];
                        }
                        if (isset($tcDelta['id'])) {
                            $toolCalls[$index]['id'] .= (string) $tcDelta['id'];
                        }
                        if (isset($tcDelta['function']['name'])) {
                            $toolCalls[$index]['name'] .= (string) $tcDelta['function']['name'];
                        }
                        if (isset($tcDelta['function']['arguments'])) {
                            $toolCalls[$index]['arguments'] .= (string) $tcDelta['function']['arguments'];
                        }
                    }
                }
            }
        }

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => $outputText,
                    'tool_calls' => ! empty($toolCalls)
                        ? array_map(fn (array $tc) => [
                            'id' => $tc['id'],
                            'type' => 'function',
                            'function' => [
                                'name' => $tc['name'],
                                'arguments' => $tc['arguments'],
                            ],
                        ], array_values($toolCalls))
                        : null,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
            'model_id' => $modelId,
            'key_used' => $key['label'] ?? 'Primary',
            'latency_ms' => $latencyMs,
        ];
    }

    protected function parseResponse(array $raw, string $modelId): LLMResult
    {
        $choice = $raw['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $usage = $raw['usage'] ?? [];

        $outputText = (string) ($message['content'] ?? '');
        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);
        $cachedTokens = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
        $finishReason = (string) ($choice['finish_reason'] ?? 'stop');

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = new ToolCall(
                id: (string) ($tc['id'] ?? ''),
                name: (string) ($tc['function']['name'] ?? ''),
                arguments: is_string($tc['function']['arguments'] ?? null)
                    ? json_decode($tc['function']['arguments'], true) ?? []
                    : (array) ($tc['function']['arguments'] ?? []),
            );
        }

        return new LLMResult(
            text: $outputText,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: new LLMUsage(
                inputTokens: max(0, $inputTokens - $cachedTokens),
                outputTokens: $outputTokens,
                cachedReadTokens: $cachedTokens,
                cachedWriteTokens: 0,
            ),
            modelId: (string) ($raw['model_id'] ?? $modelId),
            keyLabel: (string) ($raw['key_used'] ?? ''),
            latencyMs: (int) ($raw['latency_ms'] ?? 0),
            cached: false,
            raw: $raw,
        );
    }

    protected function chatUrl(string $endpoint, string $modelId, array $key): string
    {
        $base = $endpoint !== '' ? rtrim($endpoint, '/') : 'https://api.openai.com/v1';

        return "{$base}/chat/completions";
    }

    protected function authHeaders(string $endpoint, array $key, ?string $idempotencyKey): array
    {
        $apiKey = $key['api_key'] ?? '';

        $headers = [
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }

    protected function resolveModelId(string $modelId, array $key): string
    {
        return $modelId;
    }

    protected function endpoint(array $key): string
    {
        return $key['endpoint'] ?? $key['base_url'] ?? 'https://api.openai.com/v1';
    }

    // ─────────────────────────────────────────────────────────
    //  Default listing / test stubs (subclasses override)
    // ─────────────────────────────────────────────────────────

    public function listModels(): array
    {
        $key = $this->credentials->current();
        $endpoint = $this->endpoint($key);
        $url = rtrim($endpoint, '/').'/models';

        $response = Http::withHeaders($this->authHeaders($endpoint, $key, null))
            ->get($url);

        if (! $response->successful()) {
            return [];
        }

        return $response->json('data') ?? [];
    }

    public function fetchModels(): array
    {
        $models = $this->listModels();

        return array_map(function (array $model) {
            $id = (string) ($model['id'] ?? '');

            return [
                'model_id' => $id,
                'name' => $id,
                'context_window' => 0,
                'max_tokens' => 0,
                'capabilities' => ['text'],
                'input_modalities' => ['text'],
                'is_active' => true,
                'provider' => $this->resolveProvider($id),
            ];
        }, $models);
    }

    public function testConnection(): array
    {
        $start = microtime(true);

        try {
            $key = $this->credentials->current();
            $endpoint = $this->endpoint($key);
            $url = rtrim($endpoint, '/').'/models';

            $response = Http::withHeaders($this->authHeaders($endpoint, $key, null))
                ->timeout(15)
                ->get($url);

            $elapsed = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $count = count($response->json('data') ?? []);

                return [
                    'success' => true,
                    'message' => "Connection successful! Found {$count} model(s).",
                    'response_time' => $elapsed,
                    'model_count' => $count,
                ];
            }

            return [
                'success' => false,
                'message' => 'HTTP '.$response->status().': '.$response->body(),
                'response_time' => $elapsed,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'response_time' => (int) ((microtime(true) - $start) * 1000),
            ];
        }
    }

    // ─────────────────────────────────────────────────────────
    //  Internal helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Map a ToolDescriptor to the OpenAI `tools[]` shape.
     *
     * @param  array<int, \Ubxty\CoreAi\Contracts\ToolDescriptor>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function translateTools(array $tools): array
    {
        $out = [];

        foreach ($tools as $tool) {
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->parameters ?: ['type' => 'object', 'properties' => []],
                ],
            ];
        }

        return $out;
    }

    /**
     * Handle a non-successful HTTP response. Subclasses override for
     * vendor-specific status mapping (Azure maps 401 to friendly auth
     * errors, etc.).
     *
     * @throws \Ubxty\CoreAi\Exceptions\AiException|\Ubxty\CoreAi\Exceptions\RateLimitException
     */
    protected function handleErrorResponse(\Illuminate\Http\Client\Response $response): void
    {
        $status = $response->status();
        $body = $response->json() ?? [];
        $message = (string) ($body['error']['message'] ?? $response->body());

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After');
            if ($retryAfter !== null) {
                $this->setRetryAfterSeconds((int) $retryAfter);
            }
            throw new RateLimitException("429 Too many requests: {$message}", 429);
        }

        throw new \Ubxty\CoreAi\Exceptions\AiException(
            $this->extractFriendlyError("HTTP {$status} - {$message}"),
            $status
        );
    }

    /**
     * Map a model name to a provider for grouping.
     */
    protected function resolveProvider(string $modelName): string
    {
        $modelLower = strtolower($modelName);

        if (str_starts_with($modelLower, 'gpt-')
            || str_starts_with($modelLower, 'o1')
            || str_starts_with($modelLower, 'o3')
            || str_starts_with($modelLower, 'o4')
            || str_starts_with($modelLower, 'dall-e')
            || str_starts_with($modelLower, 'whisper')
            || str_starts_with($modelLower, 'tts')
            || str_starts_with($modelLower, 'text-embedding')) {
            return 'OpenAI';
        }

        return 'Other';
    }

    /**
     * Apply cache_control markers with an explicit anchor list (instead
     * of reading from the trait's $this->promptCachePoints). Used by
     * buildRequest() to honour per-call $cacheAnchors overrides.
     *
     * @param  array<int, array{role: string, content: string|array<int, mixed>}>  $messages
     * @param  array<int, string>  $anchors
     * @return array{0: array<int, array{role: string, content: string|array<int, mixed>}>}
     */
    protected function applyCacheControlWith(array $messages, array $anchors): array
    {
        $saved = $this->promptCachePoints;
        $this->promptCachePoints = array_values(array_filter(
            array_map('strval', $anchors),
            fn (string $a) => in_array($a, ['system', 'last_user'], true),
        ));

        try {
            return $this->applyCacheControl($messages);
        } finally {
            $this->promptCachePoints = $saved;
        }
    }

    /** @var AbstractCredentialManager */
    protected $credentials;
}
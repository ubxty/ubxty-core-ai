<?php

namespace Ubxty\CoreAi\Client;

use Ubxty\CoreAi\Contracts\LLMClientContract;
use Ubxty\CoreAi\Contracts\LLMResult;
use Ubxty\CoreAi\Contracts\StructuredSchema;
use Ubxty\CoreAi\Contracts\ToolChoice;

/**
 * Template-method base for every standard LLM client (OpenAI, Claude,
 * AWS Bedrock Converse, Google Gemini, etc.).
 *
 * Owns credential rotation + retry (via {@see HasRetryLogic}), the
 * converse() / converseStream() orchestration, and the feature-detection
 * defaults. Subclasses only implement the wire-format hooks
 * (buildRequest / sendRequest / sendStreamingRequest / parseResponse /
 * chatUrl / authHeaders / resolveModelId).
 */
abstract class AbstractLLMClient implements LLMClientContract
{
    use HasRetryLogic;

    /**
     * TTL (seconds) for the locally-cached model list returned by listModels().
     */
    protected int $modelsCacheTtl = 3600;

    /**
     * Per-call modelId context. Set by BedrockClient::converse() (and
     * similar overrides) before invoking parent::converse() / converseStream(),
     * since the {@see LLMClientContract::converse()} signature intentionally
     * excludes `$modelId` — the model binding lives on the client / call site.
     *
     * Read by `converse()` and `converseStream()` when calling `withRetry()`
     * so the wire-format hooks receive the resolved model_id rather than ''.
     */
    protected ?string $currentModelId = null;

    public function __construct(
        AbstractCredentialManager $credentials,
        int $maxRetries = 3,
        int $baseDelay = 2,
        int $modelsCacheTtl = 3600,
    ) {
        $this->credentials = $credentials;
        $this->maxRetries = $maxRetries;
        $this->baseDelay = $baseDelay;
        $this->modelsCacheTtl = $modelsCacheTtl;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Template-method hooks subclasses MUST implement
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build the wire-format request body for one conversation turn.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  array<int, \Ubxty\CoreAi\Contracts\ToolDescriptor>  $tools
     * @param  ?array<int|string>  $cacheAnchors
     * @return array<string, mixed>
     */
    abstract protected function buildRequest(
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
    ): array;

    /**
     * Execute a non-streaming wire call and return the raw decoded response.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    abstract protected function sendRequest(
        string $url,
        array $body,
        array $headers,
        string $modelId,
        array $key,
        ?string $idempotencyKey,
    ): array;

    /**
     * Execute a streaming wire call, invoking $onDelta for each text chunk.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    abstract protected function sendStreamingRequest(
        string $url,
        array $body,
        array $headers,
        string $modelId,
        array $key,
        ?string $idempotencyKey,
        ?callable $onDelta,
    ): array;

    /**
     * Parse a raw wire response into a platform-neutral {@see LLMResult}.
     */
    abstract protected function parseResponse(array $raw, string $modelId): LLMResult;

    /**
     * Resolve the full chat URL for the given endpoint + model + credential key.
     */
    abstract protected function chatUrl(string $endpoint, string $modelId, array $key): string;

    /**
     * Build the auth headers (and any vendor-required fixed headers) for one call.
     *
     * @return array<string, string>
     */
    abstract protected function authHeaders(string $endpoint, array $key, ?string $idempotencyKey): array;

    /**
     * Resolve the wire-level model_id (e.g. inference-profile prefix) for the
     * current credential key. Default returns $modelId unchanged.
     */
    protected function resolveModelId(string $modelId, array $key): string
    {
        return $modelId;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Concrete feature-detection defaults (overridden per standard)
    // ─────────────────────────────────────────────────────────────────────

    public function supportsTools(): bool             { return true; }
    public function supportsStructuredOutput(): bool  { return false; }
    public function supportsPromptCaching(): bool     { return false; }
    public function supportsStreaming(): bool         { return true; }

    // ─────────────────────────────────────────────────────────────────────
    //  Concrete converse() / converseStream() — wrap hooks in retry
    // ─────────────────────────────────────────────────────────────────────

    public function converse(
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        array $tools = [],
        ?ToolChoice $toolChoice = null,
        ?StructuredSchema $schema = null,
        ?string $idempotencyKey = null,
        ?array $cacheAnchors = null,
    ): LLMResult {
        $start = microtime(true);
        $resolvedModelId = '';

        $raw = $this->withRetry($this->currentModelId ?? '', function (string $modelId, array $key) use (
            $messages, $systemPrompt, $maxTokens, $temperature,
            $tools, $toolChoice, $schema, $cacheAnchors, $idempotencyKey,
            &$resolvedModelId,
        ) {
            $resolvedModelId = $modelId;

            $endpoint = $this->endpoint($key);
            $body = $this->buildRequest(
                $messages, $systemPrompt, $maxTokens, $temperature,
                $tools, $toolChoice, $schema, $cacheAnchors, $modelId, $key,
            );
            $headers = $this->authHeaders($endpoint, $key, $idempotencyKey)
                + ['Content-Type' => 'application/json'];

            return $this->sendRequest(
                $this->chatUrl($endpoint, $modelId, $key), $body, $headers, $modelId, $key, $idempotencyKey,
            );
        });

        $result = $this->parseResponse($raw, $resolvedModelId);

        return new LLMResult(
            text:         $result->text,
            toolCalls:    $result->toolCalls,
            finishReason: $result->finishReason,
            usage:        $result->usage,
            modelId:      $result->modelId,
            keyLabel:     $result->keyLabel,
            latencyMs:    (int) ((microtime(true) - $start) * 1000),
            cached:       false,
            raw:          $result->raw,
        );
    }

    public function converseStream(
        array $messages,
        callable $onDelta,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        array $tools = [],
        ?ToolChoice $toolChoice = null,
        ?StructuredSchema $schema = null,
        ?string $idempotencyKey = null,
        ?array $cacheAnchors = null,
    ): LLMResult {
        $start = microtime(true);
        $resolvedModelId = '';

        $raw = $this->withRetry($this->currentModelId ?? '', function (string $modelId, array $key) use (
            $messages, $onDelta, $systemPrompt, $maxTokens, $temperature,
            $tools, $toolChoice, $schema, $cacheAnchors, $idempotencyKey,
            &$resolvedModelId,
        ) {
            $resolvedModelId = $modelId;

            $endpoint = $this->endpoint($key);
            $body = $this->buildRequest(
                $messages, $systemPrompt, $maxTokens, $temperature,
                $tools, $toolChoice, $schema, $cacheAnchors, $modelId, $key,
            );
            $headers = $this->authHeaders($endpoint, $key, $idempotencyKey)
                + ['Content-Type' => 'application/json'];

            return $this->sendStreamingRequest(
                $this->chatUrl($endpoint, $modelId, $key), $body, $headers, $modelId, $key, $idempotencyKey, $onDelta,
            );
        });

        $result = $this->parseResponse($raw, $resolvedModelId);

        return new LLMResult(
            text:         $result->text,
            toolCalls:    $result->toolCalls,
            finishReason: $result->finishReason,
            usage:        $result->usage,
            modelId:      $result->modelId,
            keyLabel:     $result->keyLabel,
            latencyMs:    (int) ((microtime(true) - $start) * 1000),
            cached:       false,
            raw:          $result->raw,
        );
    }

    /**
     * Resolve the base endpoint URL for the current credential key.
     * Override for multi-endpoint / multi-base-url keys.
     */
    protected function endpoint(array $key): string
    {
        return $key['endpoint'] ?? $key['base_url'] ?? '';
    }
}

<?php

namespace Ubxty\CoreAi\Standards\Claude;

use Illuminate\Support\Facades\Http;
use Ubxty\CoreAi\Client\AbstractLLMClient;
use Ubxty\CoreAi\Contracts\LLMResult;
use Ubxty\CoreAi\Contracts\LLMUsage;
use Ubxty\CoreAi\Contracts\StructuredSchema;
use Ubxty\CoreAi\Contracts\ToolCall;
use Ubxty\CoreAi\Contracts\ToolChoice;
use Ubxty\CoreAi\Exceptions\RateLimitException;

/**
 * Standard Anthropic Messages API wire-format client.
 *
 * Implements the direct Anthropic API (https://api.anthropic.com/v1/messages).
 * Subclasses (anthropic-ai's `AnthropicClient` adapter — or a direct
 * anthropic-ai package extending this class) override the auth / URL /
 * model-listing hooks but inherit the converse / converseStream /
 * cache-marker injection / error-translation machinery.
 *
 * Conforms to {@see \Ubxty\CoreAi\Client\AbstractLLMClient} — the
 * concrete converse() / converseStream() wrappers above call
 * `withRetry` (from {@see \Ubxty\CoreAi\Client\HasRetryLogic}) which
 * in turn invokes the wire-format hooks this class implements.
 */
class ClaudeClient extends AbstractLLMClient
{
    use HasClaudeFormatting;

    public function platformName(): string
    {
        return 'Anthropic';
    }

    // ─────────────────────────────────────────────────────────
    //  Feature-detection defaults for Anthropic Claude
    // ─────────────────────────────────────────────────────────

    public function supportsPromptCaching(): bool
    {
        return true;
    }

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
        [$formattedMessages, $systemPrompt] = $this->applyCacheControl(
            $this->formatMessages($messages),
            $systemPrompt
        );

        $body = [
            'model' => $modelId,
            'messages' => $formattedMessages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];

        if ($systemPrompt !== '') {
            $body['system'] = $systemPrompt;
        }

        if (! empty($tools)) {
            $body['tools'] = $this->translateTools($tools);
            if ($toolChoice !== null) {
                $body['tool_choice'] = $this->translateToolChoice($toolChoice);
            }
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

        $startTime = microtime(true);
        $outputText = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheReadTokens = 0;
        $cacheWriteTokens = 0;
        $stopReason = 'end_turn';

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

                if ($line === '' || ! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = json_decode(substr($line, 6), true);
                if (! is_array($json)) {
                    continue;
                }

                $eventType = $json['type'] ?? '';

                if ($eventType === 'content_block_delta') {
                    $delta = $json['delta'] ?? [];
                    if (isset($delta['type']) && $delta['type'] === 'text_delta' && isset($delta['text'])) {
                        $text = (string) $delta['text'];
                        $outputText .= $text;
                        if ($onDelta !== null) {
                            $onDelta($text);
                        }
                    }
                } elseif ($eventType === 'message_delta') {
                    $usage = $json['usage'] ?? [];
                    $outputTokens = (int) ($usage['output_tokens'] ?? $outputTokens);
                    if (isset($json['delta']['stop_reason'])) {
                        $stopReason = (string) $json['delta']['stop_reason'];
                    }
                } elseif ($eventType === 'message_start') {
                    $usage = $json['message']['usage'] ?? [];
                    $inputTokens = (int) ($usage['input_tokens'] ?? $inputTokens);
                    $cacheReadTokens = (int) ($usage['cache_read_input_tokens'] ?? $cacheReadTokens);
                    $cacheWriteTokens = (int) ($usage['cache_creation_input_tokens'] ?? $cacheWriteTokens);
                }
            }
        }

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'content' => [['type' => 'text', 'text' => $outputText]],
            'stop_reason' => $stopReason,
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cache_read_input_tokens' => $cacheReadTokens,
                'cache_creation_input_tokens' => $cacheWriteTokens,
            ],
            'model_id' => $modelId,
            'key_used' => $key['label'] ?? 'Primary',
            'latency_ms' => $latencyMs,
        ];
    }

    protected function parseResponse(array $raw, string $modelId): LLMResult
    {
        $content = (array) ($raw['content'] ?? []);
        $usage = (array) ($raw['usage'] ?? []);

        $outputText = '';
        $toolCalls = [];
        foreach ($content as $block) {
            $type = (string) ($block['type'] ?? '');
            if ($type === 'text') {
                $outputText .= (string) ($block['text'] ?? '');
            } elseif ($type === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: (string) ($block['id'] ?? ''),
                    name: (string) ($block['name'] ?? ''),
                    arguments: (array) ($block['input'] ?? []),
                );
            }
        }

        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $cacheReadTokens = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $cacheWriteTokens = (int) ($usage['cache_creation_input_tokens'] ?? 0);

        return new LLMResult(
            text: $outputText,
            toolCalls: $toolCalls,
            finishReason: (string) ($raw['stop_reason'] ?? 'end_turn'),
            usage: new LLMUsage(
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                cachedReadTokens: $cacheReadTokens,
                cachedWriteTokens: $cacheWriteTokens,
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
        $base = $endpoint !== '' ? rtrim($endpoint, '/') : 'https://api.anthropic.com';

        return "{$base}/v1/messages";
    }

    protected function authHeaders(string $endpoint, array $key, ?string $idempotencyKey): array
    {
        $apiKey = (string) ($key['api_key'] ?? '');
        $version = (string) ($key['anthropic_version'] ?? '2023-06-01');

        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => $version,
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
        return (string) ($key['base_url'] ?? 'https://api.anthropic.com');
    }

    // ─────────────────────────────────────────────────────────
    //  Default listing / test stubs (subclasses override)
    // ─────────────────────────────────────────────────────────

    public function listModels(): array
    {
        // Anthropic doesn't expose a public /models listing endpoint.
        // Subclasses (the anthropic-ai package) typically hardcode the
        // current model catalog in the package config and call
        // AbstractAiManager::fetchModels() with that.
        return [];
    }

    public function fetchModels(): array
    {
        $models = $this->listModels();

        return array_map(function (array $model): array {
            $id = (string) ($model['id'] ?? '');

            return [
                'model_id' => $id,
                'name' => (string) ($model['name'] ?? $id),
                'context_window' => (int) ($model['context_window'] ?? 0),
                'max_tokens' => (int) ($model['max_tokens'] ?? 0),
                'capabilities' => (array) ($model['capabilities'] ?? ['text']),
                'input_modalities' => (array) ($model['input_modalities'] ?? ['text']),
                'is_active' => (bool) ($model['is_active'] ?? true),
                'provider' => 'Anthropic',
            ];
        }, $models);
    }

    public function testConnection(): array
    {
        $start = microtime(true);

        try {
            $key = $this->credentials->current();
            $endpoint = $this->endpoint($key);
            $url = rtrim($endpoint, '/').'/v1/messages';

            $response = Http::withHeaders($this->authHeaders($endpoint, $key, null))
                ->timeout(15)
                ->post($url, [
                    'model' => (string) ($key['model'] ?? 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ]);

            $elapsed = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Connection successful!',
                    'response_time' => $elapsed,
                    'model_count' => 0,
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
     * Map a ToolDescriptor to the Anthropic `tools[]` shape.
     *
     * @param  array<int, \Ubxty\CoreAi\Contracts\ToolDescriptor>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function translateTools(array $tools): array
    {
        $out = [];

        foreach ($tools as $tool) {
            $out[] = [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->parameters ?: ['type' => 'object', 'properties' => []],
            ];
        }

        return $out;
    }

    /**
     * Translate a ToolChoice into the Anthropic `tool_choice` shape.
     */
    protected function translateToolChoice(ToolChoice $toolChoice): array|string
    {
        return match ($toolChoice->value) {
            'auto' => ['type' => 'auto'],
            'any' => ['type' => 'any'],
            'none' => ['type' => 'none'],
            default => str_starts_with($toolChoice->value, 'tool:')
                ? ['type' => 'tool', 'name' => substr($toolChoice->value, 5)]
                : ['type' => 'auto'],
        };
    }

    /**
     * Handle a non-successful HTTP response.
     */
    protected function handleErrorResponse(\Illuminate\Http\Client\Response $response): void
    {
        $status = $response->status();
        $body = $response->json() ?? [];
        $message = (string) (
            $body['error']['message']
            ?? $body['message']
            ?? $response->body()
        );

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

    /** @var \Ubxty\CoreAi\Client\AbstractCredentialManager */
    protected $credentials;
}
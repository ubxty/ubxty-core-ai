<?php

namespace Ubxty\CoreAi\Standards\Gemini;

use Illuminate\Support\Facades\Http;
use Ubxty\CoreAi\Client\AbstractLLMClient;
use Ubxty\CoreAi\Contracts\LLMResult;
use Ubxty\CoreAi\Contracts\LLMUsage;
use Ubxty\CoreAi\Contracts\StructuredSchema;
use Ubxty\CoreAi\Contracts\ToolCall;
use Ubxty\CoreAi\Contracts\ToolChoice;
use Ubxty\CoreAi\Exceptions\RateLimitException;

/**
 * Standard Google Gemini `generateContent` wire-format client.
 *
 * Implements the direct Gemini API (https://generativelanguage.googleapis.com).
 * Subclasses (gemini-ai's adapter) override the auth / URL / model-listing
 * hooks but inherit the converse / converseStream / message-formatting /
 * error-translation machinery.
 */
class GeminiClient extends AbstractLLMClient
{
    use HasGeminiFormatting;

    public function platformName(): string
    {
        return 'Google Gemini';
    }

    // ─────────────────────────────────────────────────────────
    //  Feature-detection defaults for Gemini
    // ─────────────────────────────────────────────────────────

    public function supportsTools(): bool
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
        $body = [
            'contents' => $this->formatMessages($messages),
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature' => $temperature,
            ],
        ];

        if ($systemPrompt !== '') {
            $body = array_merge($body, $this->formatSystemInstruction($systemPrompt));
        }

        if (! empty($tools)) {
            $body['tools'] = [
                ['functionDeclarations' => $this->translateTools($tools)],
            ];
        }

        if ($schema !== null) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema'] = $schema->schema;
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
        // Switch to streamGenerateContent endpoint with SSE.
        $url = str_replace(':generateContent', ':streamGenerateContent?alt=sse', $url);

        $startTime = microtime(true);
        $outputText = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = 'stop';
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

                if ($line === '' || ! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = json_decode(substr($line, 6), true);
                if (! is_array($json)) {
                    continue;
                }

                $candidate = $json['candidates'][0] ?? [];
                $parts = $candidate['content']['parts'] ?? [];

                foreach ($parts as $part) {
                    if (isset($part['text'])) {
                        $text = (string) $part['text'];
                        $outputText .= $text;
                        if ($onDelta !== null) {
                            $onDelta($text);
                        }
                    }
                    if (isset($part['functionCall'])) {
                        $fc = $part['functionCall'];
                        $toolCalls[] = [
                            'name' => (string) ($fc['name'] ?? ''),
                            'args' => (array) ($fc['args'] ?? []),
                        ];
                    }
                }

                if (isset($candidate['finishReason'])) {
                    $finishReason = (string) $candidate['finishReason'];
                }

                $usage = $json['usageMetadata'] ?? [];
                $inputTokens = (int) ($usage['promptTokenCount'] ?? $inputTokens);
                $outputTokens = (int) ($usage['candidatesTokenCount'] ?? $outputTokens);
            }
        }

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => array_merge(
                        $outputText !== '' ? [['text' => $outputText]] : [],
                        array_map(
                            fn (array $tc) => ['functionCall' => ['name' => $tc['name'], 'args' => $tc['args']]],
                            $toolCalls,
                        ),
                    ),
                ],
                'finishReason' => $finishReason,
            ]],
            'usageMetadata' => [
                'promptTokenCount' => $inputTokens,
                'candidatesTokenCount' => $outputTokens,
                'totalTokenCount' => $inputTokens + $outputTokens,
            ],
            'model_id' => $modelId,
            'key_used' => $key['label'] ?? 'Primary',
            'latency_ms' => $latencyMs,
        ];
    }

    protected function parseResponse(array $raw, string $modelId): LLMResult
    {
        $candidate = $raw['candidates'][0] ?? [];
        $parts = (array) ($candidate['content']['parts'] ?? []);
        $usage = (array) ($raw['usageMetadata'] ?? []);

        $outputText = '';
        $toolCalls = [];
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $outputText .= (string) $part['text'];
            }
            if (isset($part['functionCall'])) {
                $fc = $part['functionCall'];
                $toolCalls[] = new ToolCall(
                    id: (string) ($fc['name'] ?? '').':'.bin2hex(random_bytes(4)),
                    name: (string) ($fc['name'] ?? ''),
                    arguments: (array) ($fc['args'] ?? []),
                );
            }
        }

        $inputTokens = (int) ($usage['promptTokenCount'] ?? 0);
        $outputTokens = (int) ($usage['candidatesTokenCount'] ?? 0);
        $cachedTokens = (int) ($usage['cachedContentTokenCount'] ?? 0);

        return new LLMResult(
            text: $outputText,
            toolCalls: $toolCalls,
            finishReason: (string) ($candidate['finishReason'] ?? 'stop'),
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
        $base = $endpoint !== '' ? rtrim($endpoint, '/') : 'https://generativelanguage.googleapis.com';
        $apiKey = (string) ($key['api_key'] ?? '');

        return "{$base}/v1beta/models/{$modelId}:generateContent?key={$apiKey}";
    }

    protected function authHeaders(string $endpoint, array $key, ?string $idempotencyKey): array
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }

    protected function resolveModelId(string $modelId, array $key): string
    {
        return $modelId;
    }

    protected function endpoint(array $key): string
    {
        return (string) ($key['base_url'] ?? 'https://generativelanguage.googleapis.com');
    }

    // ─────────────────────────────────────────────────────────
    //  Default listing / test stubs
    // ─────────────────────────────────────────────────────────

    public function listModels(): array
    {
        $key = $this->credentials->current();
        $apiKey = (string) ($key['api_key'] ?? '');
        $endpoint = $this->endpoint($key);

        $response = Http::timeout(15)->get("{$endpoint}/v1beta/models?key={$apiKey}");

        if (! $response->successful()) {
            return [];
        }

        $models = $response->json('models') ?? [];

        return array_map(fn (array $m): array => ['id' => $m['name'] ?? '', 'name' => $m['displayName'] ?? ''], $models);
    }

    public function fetchModels(): array
    {
        $models = $this->listModels();

        return array_map(function (array $model): array {
            $id = (string) ($model['id'] ?? '');

            return [
                'model_id' => $id,
                'name' => (string) ($model['name'] ?? $id),
                'context_window' => 0,
                'max_tokens' => 0,
                'capabilities' => ['text'],
                'input_modalities' => ['text'],
                'is_active' => true,
                'provider' => 'Google',
            ];
        }, $models);
    }

    public function testConnection(): array
    {
        $start = microtime(true);

        try {
            $models = $this->listModels();
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            return [
                'success' => true,
                'message' => 'Connection successful! Found '.count($models).' model(s).',
                'response_time' => $elapsed,
                'model_count' => count($models),
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
                'parameters' => $tool->parameters ?: ['type' => 'object', 'properties' => []],
            ];
        }

        return $out;
    }

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
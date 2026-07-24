<?php

namespace Ubxty\CoreAi\Providers\Anthropic;

use Ubxty\CoreAi\Providers\Anthropic\Client\AnthropicCredentialManager;
use Ubxty\CoreAi\Providers\Anthropic\Events\AnthropicInvoked;
use Ubxty\CoreAi\Exceptions\ConfigurationException;
use Ubxty\CoreAi\Manager\AbstractAiManager;
use Ubxty\CoreAi\Standards\Claude\ClaudeClient;

class AnthropicManager extends AbstractAiManager
{
    /** @var array<string, ClaudeClient> */
    protected array $clients = [];

    public function client(?string $connection = null): ClaudeClient
    {
        $connection ??= $this->config['default'] ?? 'default';

        if (isset($this->clients[$connection])) {
            return $this->clients[$connection];
        }

        $connectionConfig = $this->config['connections'][$connection] ?? null;

        if (! $connectionConfig) {
            throw new ConfigurationException("Anthropic connection [{$connection}] is not configured.");
        }

        $keys = $connectionConfig['keys'] ?? [];

        if (empty($keys)) {
            throw new ConfigurationException("No API keys configured for Anthropic connection [{$connection}].");
        }

        $retryConfig = $this->config['retry'] ?? [];

        $client = new ClaudeClient(
            new AnthropicCredentialManager($keys),
            $retryConfig['max_retries'] ?? 3,
            $retryConfig['base_delay'] ?? 2,
        );

        $client->setPromptCachePoints($this->promptCachePoints());

        $this->clients[$connection] = $client;

        return $client;
    }

    // ─────────────────────────────────────────────────────────
    //  v2.2 platform hooks
    // ─────────────────────────────────────────────────────────

    protected function usePlatformHook(): bool
    {
        return true;
    }

    protected function platformInvoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?string $idempotencyKey,
    ): array {
        $start = microtime(true);

        $result = $this->client($connection)->converse(
            [['role' => 'user', 'content' => $userMessage]],
            $systemPrompt,
            $maxTokens,
            $temperature,
            [], null, null, $idempotencyKey,
        );

        return [
            'response' => $result->text,
            'input_tokens' => $result->usage?->inputTokens ?? 0,
            'output_tokens' => $result->usage?->outputTokens ?? 0,
            'total_tokens' => ($result->usage?->inputTokens ?? 0) + ($result->usage?->outputTokens ?? 0),
            'cache_read_input_tokens' => $result->usage?->cachedReadTokens ?? 0,
            'cache_write_input_tokens' => $result->usage?->cachedWriteTokens ?? 0,
            'cost' => $this->calculateCost($result->usage?->inputTokens ?? 0, $result->usage?->outputTokens ?? 0, null),
            'latency_ms' => $result->latencyMs ?: (int) ((microtime(true) - $start) * 1000),
            'status' => 'success',
            'key_used' => $result->keyLabel ?? 'unknown',
            'model_id' => $result->modelId ?? $modelId,
        ];
    }

    protected function platformConverse(
        string $modelId,
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride,
        ?string $idempotencyKey,
    ): array {
        $start = microtime(true);

        $result = $this->client($connection)->converse(
            $messages, $systemPrompt, $maxTokens, $temperature,
            [], null, null, $idempotencyKey,
        );

        return [
            'response' => $result->text,
            'input_tokens' => $result->usage?->inputTokens ?? 0,
            'output_tokens' => $result->usage?->outputTokens ?? 0,
            'total_tokens' => ($result->usage?->inputTokens ?? 0) + ($result->usage?->outputTokens ?? 0),
            'cache_read_input_tokens' => $result->usage?->cachedReadTokens ?? 0,
            'cache_write_input_tokens' => $result->usage?->cachedWriteTokens ?? 0,
            'stop_reason' => $result->finishReason ?? 'end_turn',
            'cost' => $this->calculateCost($result->usage?->inputTokens ?? 0, $result->usage?->outputTokens ?? 0, null),
            'latency_ms' => $result->latencyMs ?: (int) ((microtime(true) - $start) * 1000),
            'status' => 'success',
            'key_used' => $result->keyLabel ?? 'unknown',
            'model_id' => $result->modelId ?? $modelId,
        ];
    }

    protected function platformConverseStream(
        string $modelId,
        array $messages,
        ?callable $onChunk,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride,
        ?string $idempotencyKey,
    ): array {
        $start = microtime(true);

        $deltaDelegate = function (string $delta) use (&$onChunk): void {
            if (is_callable($onChunk)) {
                $onChunk($delta, false);
            }
        };

        $result = $this->client($connection)->converseStream(
            $messages, $deltaDelegate, $systemPrompt, $maxTokens, $temperature,
            [], null, null, $idempotencyKey,
        );

        if (is_callable($onChunk)) {
            $onChunk('', true);
        }

        return [
            'response' => $result->text,
            'input_tokens' => $result->usage?->inputTokens ?? 0,
            'output_tokens' => $result->usage?->outputTokens ?? 0,
            'total_tokens' => ($result->usage?->inputTokens ?? 0) + ($result->usage?->outputTokens ?? 0),
            'cache_read_input_tokens' => $result->usage?->cachedReadTokens ?? 0,
            'cache_write_input_tokens' => $result->usage?->cachedWriteTokens ?? 0,
            'cost' => $this->calculateCost($result->usage?->inputTokens ?? 0, $result->usage?->outputTokens ?? 0, null),
            'latency_ms' => $result->latencyMs ?: (int) ((microtime(true) - $start) * 1000),
            'status' => 'success',
            'key_used' => $result->keyLabel ?? 'unknown',
            'model_id' => $result->modelId ?? $modelId,
        ];
    }

    // ─────────────────────────────────────────────────────────
    //  Abstract platform methods
    // ─────────────────────────────────────────────────────────

    public function testConnection(?string $connection = null): array
    {
        return $this->client($connection)->testConnection();
    }

    public function listModels(?string $connection = null): array
    {
        return $this->client($connection)->listModels();
    }

    public function fetchModels(?string $connection = null): array
    {
        return $this->client($connection)->fetchModels();
    }

    public function platformName(): string
    {
        return 'Anthropic';
    }

    protected function providerDefault(): string
    {
        return 'Anthropic';
    }

    // ─────────────────────────────────────────────────────────
    //  Cache-config proxies (consumed by AbstractChatCommand)
    // ─────────────────────────────────────────────────────────

    public function modelSupportsCaching(string $modelId): bool
    {
        return in_array('prompt_caching', $this->capabilitiesFor($modelId), true);
    }

    public function packageCachePointsConfigured(): bool
    {
        return ! empty($this->promptCachePoints());
    }

    /** @return string[] */
    public function configuredCachePoints(): array
    {
        return $this->promptCachePoints();
    }

    // ─────────────────────────────────────────────────────────
    //  Event firing + cache-point config
    // ─────────────────────────────────────────────────────────

    protected function fireInvokedEvent(array $result): void
    {
        if (function_exists('event')) {
            event(new AnthropicInvoked(
                modelId: $result['model_id'] ?? 'unknown',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                cost: $result['cost'] ?? 0,
                latencyMs: $result['latency_ms'] ?? 0,
                keyUsed: $result['key_used'] ?? 'unknown',
            ));
        }
    }

    /** @return string[] */
    protected function promptCachePoints(): array
    {
        $configured = $this->config['prompt_caching']['points'] ?? [];

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $configured),
            fn (string $p) => in_array($p, ['system', 'last_user'], true),
        ));
    }
}
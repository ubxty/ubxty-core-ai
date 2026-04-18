<?php

namespace Ubxty\CoreAi\Manager;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Ubxty\CoreAi\Client\ModelAliasResolver;
use Ubxty\CoreAi\Contracts\AiManagerContract;
use Ubxty\CoreAi\Conversation\ConversationBuilder;
use Ubxty\CoreAi\Events\AiInvoked;
use Ubxty\CoreAi\Exceptions\ConfigurationException;
use Ubxty\CoreAi\Exceptions\CostLimitExceededException;
use Ubxty\CoreAi\Logging\InvocationLogger;

abstract class AbstractAiManager implements AiManagerContract
{
    protected array $config;

    protected ?ModelAliasResolver $aliasResolver = null;

    protected ?InvocationLogger $logger = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // ─────────────────────────────────────────────────────────
    //  High-level operations (invoke, converse, stream)
    //  Wraps platform calls with cost tracking, events, logging.
    // ─────────────────────────────────────────────────────────

    public function invoke(
        string $modelId = '',
        string $systemPrompt = '',
        string $userMessage = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?array $pricing = null,
        ?string $connection = null
    ): array {
        $modelId = $modelId ?: $this->defaultModel();

        if (! $modelId) {
            throw new ConfigurationException(
                'No model ID specified and no default model configured. '.
                'Set a default model in your .env or pass a model ID explicitly.'
            );
        }

        $this->checkCostLimits();

        $modelId = $this->resolveAlias($modelId);

        $result = $this->performInvoke($modelId, $systemPrompt, $userMessage, $maxTokens, $temperature, $pricing, $connection);

        $this->trackCost($result['cost'] ?? 0);
        $this->fireInvokedEvent($result);
        $this->getLogger()->log($result);

        return $result;
    }

    public function converse(
        string $modelId,
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null
    ): array {
        $this->checkCostLimits();

        $modelId = $this->resolveAlias($modelId);

        $result = $this->performConverse($modelId, $messages, $systemPrompt, $maxTokens, $temperature, $connection);

        $cost = $this->calculateCost($result['input_tokens'] ?? 0, $result['output_tokens'] ?? 0, $pricing);
        $result['cost'] = $cost;

        $this->trackCost($cost);
        $this->fireInvokedEvent($result);
        $this->getLogger()->log($result);

        return $result;
    }

    public function converseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null
    ): array {
        $this->checkCostLimits();

        $modelId = $this->resolveAlias($modelId);

        $result = $this->performConverseStream($modelId, $messages, $onChunk, $systemPrompt, $maxTokens, $temperature, $connection);

        $cost = $this->calculateCost($result['input_tokens'] ?? 0, $result['output_tokens'] ?? 0, $pricing);
        $result['cost'] = $cost;

        $this->trackCost($cost);
        $this->fireInvokedEvent($result);
        $this->getLogger()->log($result);

        return $result;
    }

    // ─────────────────────────────────────────────────────────
    //  Abstract platform methods
    // ─────────────────────────────────────────────────────────

    /**
     * Platform-specific invoke implementation.
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cost: float, latency_ms: int, status: string, key_used: string, model_id: string}
     */
    abstract protected function performInvoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        ?array $pricing,
        ?string $connection
    ): array;

    /**
     * Platform-specific converse implementation.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string}
     */
    abstract protected function performConverse(
        string $modelId,
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection
    ): array;

    /**
     * Platform-specific streaming converse implementation.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  callable(string $chunk): void  $onChunk
     */
    abstract protected function performConverseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection
    ): array;

    // ─────────────────────────────────────────────────────────
    //  Abstract platform methods (testing, models)
    // ─────────────────────────────────────────────────────────

    abstract public function testConnection(?string $connection = null): array;

    abstract public function listModels(?string $connection = null): array;

    abstract public function fetchModels(?string $connection = null): array;

    abstract public function syncModels(?string $connection = null): int;

    abstract public function isConfigured(?string $connection = null): bool;

    abstract public function supportsStreaming(?string $connection = null): bool;

    abstract public function getCredentialInfo(?string $connection = null): array;

    abstract public function platformName(): string;

    // ─────────────────────────────────────────────────────────
    //  Shared implementations
    // ─────────────────────────────────────────────────────────

    public function conversation(string $modelId): ConversationBuilder
    {
        $modelId = $this->resolveAlias($modelId);

        return new ConversationBuilder($this, $modelId);
    }

    /**
     * Get models grouped by provider with context-based filtering.
     *
     * Override `fetchModelsForGrouping()` to control the data source.
     */
    public function getModelsGrouped(?string $connection = null, ?string $context = null): array
    {
        $rows = $this->fetchModelsForGrouping($connection);

        if (empty($rows)) {
            $rows = $this->fetchModels($connection);
        }

        $grouped = [];
        foreach ($rows as $model) {
            $provider = $model['provider'] ?: (explode('.', $model['model_id'])[0] ?? 'Other');
            $grouped[$provider][] = $model;
        }

        // Build the effective disabled list: global + context-scoped.
        $globalDisabled = array_filter((array) ($this->config['providers']['disabled_providers'] ?? []));
        $contextDisabled = $context
            ? array_filter((array) ($this->config['providers'][$context]['disabled_providers'] ?? []))
            : [];

        $disabled = array_map('strtolower', array_merge($globalDisabled, $contextDisabled));

        if (! empty($disabled)) {
            $grouped = array_filter(
                $grouped,
                fn (string $provider) => ! in_array(strtolower($provider), $disabled, true),
                ARRAY_FILTER_USE_KEY
            );
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Fetch models for grouping from the database or other storage.
     * Returns an empty array to fall back to `fetchModels()`.
     */
    protected function fetchModelsForGrouping(?string $connection): array
    {
        return [];
    }

    public function defaultModel(): string
    {
        return $this->config['defaults']['model'] ?? '';
    }

    public function defaultImageModel(): string
    {
        return $this->config['defaults']['image_model'] ?? '';
    }

    public function aliases(): ModelAliasResolver
    {
        if (! $this->aliasResolver) {
            $this->aliasResolver = new ModelAliasResolver($this->config['aliases'] ?? []);
        }

        return $this->aliasResolver;
    }

    public function resolveAlias(string $modelIdOrAlias): string
    {
        return $this->aliases()->resolve($modelIdOrAlias);
    }

    public function getLogger(): InvocationLogger
    {
        if (! $this->logger) {
            $loggingConfig = $this->config['logging'] ?? [];
            $this->logger = new InvocationLogger(
                $loggingConfig['enabled'] ?? false,
                $loggingConfig['channel'] ?? 'stack'
            );
        }

        return $this->logger;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    // ─────────────────────────────────────────────────────────
    //  Cost tracking
    // ─────────────────────────────────────────────────────────

    /**
     * Get the cache key prefix for this platform.
     */
    protected function cachePrefix(): string
    {
        return strtolower(str_replace(' ', '_', $this->platformName())).'_ai';
    }

    protected function checkCostLimits(): void
    {
        $prefix = $this->cachePrefix();
        $dailyLimit = $this->config['limits']['daily'] ?? null;
        $monthlyLimit = $this->config['limits']['monthly'] ?? null;

        if ($dailyLimit !== null) {
            $dailyCost = (float) Cache::get("{$prefix}_daily_cost_".date('Y-m-d'), 0);

            if ($dailyCost >= (float) $dailyLimit) {
                throw new CostLimitExceededException('daily', (float) $dailyLimit, $dailyCost);
            }
        }

        if ($monthlyLimit !== null) {
            $monthlyCost = (float) Cache::get("{$prefix}_monthly_cost_".date('Y-m'), 0);

            if ($monthlyCost >= (float) $monthlyLimit) {
                throw new CostLimitExceededException('monthly', (float) $monthlyLimit, $monthlyCost);
            }
        }
    }

    protected function trackCost(float $cost): void
    {
        if ($cost <= 0) {
            return;
        }

        $prefix = $this->cachePrefix();
        $dailyKey = "{$prefix}_daily_cost_".date('Y-m-d');
        $monthlyKey = "{$prefix}_monthly_cost_".date('Y-m');

        try {
            Cache::lock("{$prefix}_cost_lock", 5)->block(2, function () use ($dailyKey, $monthlyKey, $cost) {
                Cache::put($dailyKey, (float) Cache::get($dailyKey, 0) + $cost, now()->endOfDay());
                Cache::put($monthlyKey, (float) Cache::get($monthlyKey, 0) + $cost, now()->endOfMonth());
            });
        } catch (LockTimeoutException $e) {
            Cache::put($dailyKey, (float) Cache::get($dailyKey, 0) + $cost, now()->endOfDay());
            Cache::put($monthlyKey, (float) Cache::get($monthlyKey, 0) + $cost, now()->endOfMonth());
        }
    }

    protected function fireInvokedEvent(array $result): void
    {
        if (function_exists('event')) {
            event(new AiInvoked(
                modelId: $result['model_id'] ?? 'unknown',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                cost: $result['cost'] ?? 0,
                latencyMs: $result['latency_ms'] ?? 0,
                keyUsed: $result['key_used'] ?? 'unknown',
                platform: $this->platformName(),
            ));
        }
    }

    protected function calculateCost(int $inputTokens, int $outputTokens, ?array $pricing = null): float
    {
        $inputPrice = $pricing['input_price_per_1k'] ?? 0.003;
        $outputPrice = $pricing['output_price_per_1k'] ?? 0.015;

        return round(
            ($inputTokens / 1000) * $inputPrice + ($outputTokens / 1000) * $outputPrice,
            6
        );
    }

    /**
     * Get the first key from the default connection (used as fallback).
     */
    protected function getDefaultKey(): array
    {
        $connection = $this->config['default'] ?? 'default';
        $keys = $this->config['connections'][$connection]['keys'] ?? [];

        return $keys[0] ?? [];
    }
}

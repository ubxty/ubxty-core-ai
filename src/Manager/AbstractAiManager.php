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
use Ubxty\CoreAi\Models\ModelSpecResolver;
use Ubxty\CoreAi\Support\CacheKeyContext;
use Ubxty\CoreAi\Support\TokenEstimator;

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
        return $this->invokeWithContext(
            $modelId, $systemPrompt, $userMessage, $maxTokens, $temperature,
            $pricing, $connection, null
        );
    }

    public function converse(
        string $modelId,
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null,
        ?array $cachePointsOverride = null
    ): array {
        return $this->converseWithContext(
            $modelId, $messages, $systemPrompt, $maxTokens, $temperature,
            $connection, $pricing, null, $cachePointsOverride
        );
    }

    public function converseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null,
        ?array $cachePointsOverride = null
    ): array {
        return $this->converseStreamWithContext(
            $modelId, $messages, $onChunk, $systemPrompt, $maxTokens, $temperature,
            $connection, $pricing, null, $cachePointsOverride
        );
    }

    /**
     * A5 — Cache-namespace-aware invoke overload. This is the real
     * implementation; the no-context `invoke()` is a thin wrapper that
     * calls this with `$ctx = null`. New code should prefer this method
     * when it has a tenant + conversation handle.
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cost: float, latency_ms: int, status: string, key_used: string, model_id: string}
     */
    public function invokeWithContext(
        string $modelId = '',
        string $systemPrompt = '',
        string $userMessage = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?array $pricing = null,
        ?string $connection = null,
        ?CacheKeyContext $ctx = null
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

        // Pre-flight: clamp max_tokens to model max, ensure input+output fits context.
        [$maxTokens] = $this->clampMaxTokens(
            $modelId, $maxTokens,
            $systemPrompt . $userMessage
        );

        // Response-cache: same content + same key params returns a cached result
        // when cache.response_ttl > 0. Skip on streaming / explicit bypass.
        $responseTtl = (int) ($this->config['cache']['response_ttl'] ?? 0);
        if ($responseTtl > 0) {
            $cacheKey = $this->responseCacheKey($modelId, $systemPrompt, $userMessage, $maxTokens, $temperature, $ctx);
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                $cached['cached'] = true;
                $cached['latency_ms'] = 0;
                $this->fireInvokedEvent($cached);

                return $cached;
            }
        }

        $result = $this->performInvoke($modelId, $systemPrompt, $userMessage, $maxTokens, $temperature, $pricing, $connection);

        $this->trackCost($result['cost'] ?? 0);
        $this->fireInvokedEvent($result);
        $this->getLogger()->log($result);

        if ($responseTtl > 0) {
            $cacheKey ??= $this->responseCacheKey($modelId, $systemPrompt, $userMessage, $maxTokens, $temperature, $ctx);
            Cache::put($cacheKey, $result, $responseTtl);
        }

        return $result;
    }

    /**
     * A5 — Cache-namespace-aware converse overload.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string, cost: float}
     */
    public function converseWithContext(
        string $modelId,
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null,
        ?CacheKeyContext $ctx = null,
        ?array $cachePointsOverride = null
    ): array {
        $this->checkCostLimits();

        $modelId = $this->resolveAlias($modelId);

        // Estimate total prompt tokens for fits-check using TokenEstimator.
        $promptTokens = TokenEstimator::estimateMultimodal($messages, $systemPrompt);
        [$maxTokens] = $this->clampMaxTokens($modelId, $maxTokens, '', $promptTokens);

        // Response-cache: hash on canonical message array + parameters.
        $responseTtl = (int) ($this->config['cache']['response_ttl'] ?? 0);
        if ($responseTtl > 0) {
            $cacheKey = $this->responseCacheKeyConverse($modelId, $systemPrompt, $messages, $maxTokens, $temperature, $ctx);
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                $cached['cached'] = true;
                $cached['latency_ms'] = 0;
                $this->fireInvokedEvent($cached);

                return $cached;
            }
        }

        $result = $this->performConverse($modelId, $messages, $systemPrompt, $maxTokens, $temperature, $connection, $cachePointsOverride);

        $cost = $this->calculateCost($result['input_tokens'] ?? 0, $result['output_tokens'] ?? 0, $pricing);
        $result['cost'] = $cost;

        $this->trackCost($cost);
        $this->fireInvokedEvent($result);
        $this->getLogger()->log($result);

        if ($responseTtl > 0) {
            $cacheKey ??= $this->responseCacheKeyConverse($modelId, $systemPrompt, $messages, $maxTokens, $temperature, $ctx);
            Cache::put($cacheKey, $result, $responseTtl);
        }

        return $result;
    }

    /**
     * A5 — Cache-namespace-aware streaming converse overload.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  callable(string $chunk): void  $onChunk
     */
    public function converseStreamWithContext(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null,
        ?CacheKeyContext $ctx = null,
        ?array $cachePointsOverride = null
    ): array {
        $this->checkCostLimits();

        $modelId = $this->resolveAlias($modelId);

        // Response-cache: same content + same key params returns a cached result
        // when cache.response_ttl > 0. We still emit synthetic delta chunks to the
        // caller so any downstream UI that streams works identically.
        $responseTtl = (int) ($this->config['cache']['response_ttl'] ?? 0);
        $cacheKey = null;
        if ($responseTtl > 0) {
            $cacheKey = $this->responseCacheKeyConverse($modelId, $systemPrompt, $messages, $maxTokens, $temperature, $ctx);
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && isset($cached['response'])) {
                $text = (string) $cached['response'];
                for ($i = 0, $n = strlen($text); $i < $n; $i += 80) {
                    $onChunk(substr($text, $i, 80), false);
                }
                $onChunk('', true);

                $cached['cached'] = true;
                $cached['latency_ms'] = 0;
                $this->fireInvokedEvent($cached);

                return $cached;
            }
        }

        // Pre-flight token ceiling: catch runaway multimodal payloads before
        // they reach the SDK. The 10x multiplier on maxTokens is the safe headroom
        // for input+output combined; we only trip when callers stack huge
        // attachments on a tiny max_tokens budget.
        $est = TokenEstimator::estimateMultimodal($messages, $systemPrompt);
        if ($est > $maxTokens * 10) {
            throw new \Ubxty\CoreAi\Exceptions\TokenLimitExceededException(
                'converseStream: estimated '.$est.' tokens exceeds safe ceiling'
            );
        }

        $result = $this->performConverseStream($modelId, $messages, $onChunk, $systemPrompt, $maxTokens, $temperature, $connection, $cachePointsOverride);

        $cost = $this->calculateCost($result['input_tokens'] ?? 0, $result['output_tokens'] ?? 0, $pricing);
        $result['cost'] = $cost;

        $this->trackCost($cost);
        $this->fireInvokedEvent($result);
        $this->getLogger()->log($result);

        if ($responseTtl > 0) {
            $cacheKey ??= $this->responseCacheKeyConverse($modelId, $systemPrompt, $messages, $maxTokens, $temperature, $ctx);
            Cache::put($cacheKey, $result, $responseTtl);
        }

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
        ?string $connection,
        ?array $cachePointsOverride = null
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
        ?string $connection,
        ?array $cachePointsOverride = null
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
        $dailyLimit = $this->normalizeLimit($this->config['limits']['daily'] ?? null);
        $monthlyLimit = $this->normalizeLimit($this->config['limits']['monthly'] ?? null);

        if ($dailyLimit !== null) {
            $dailyCost = (float) Cache::get("{$prefix}_daily_cost_".date('Y-m-d'), 0);

            if ($dailyCost >= $dailyLimit) {
                throw new CostLimitExceededException('daily', $dailyLimit, $dailyCost);
            }
        }

        if ($monthlyLimit !== null) {
            $monthlyCost = (float) Cache::get("{$prefix}_monthly_cost_".date('Y-m'), 0);

            if ($monthlyCost >= $monthlyLimit) {
                throw new CostLimitExceededException('monthly', $monthlyLimit, $monthlyCost);
            }
        }

        // Tenant cost cap seam (T2-PR3): if the host application binds a
        // TenantCostCapResolver, surface the tenant's daily / monthly caps
        // here. The core package does NOT enforce them — T3-PR6 owns the
        // throw path in app/Services/UbxtyAiManagerService. This block just
        // makes the contract visible so a future per-tenant enforcement
        // point can plug in without touching AbstractAiManager again.
        $container = function_exists('app') ? app() : null;
        if ($container !== null && $container->bound(\Ubxty\CoreAi\Contracts\TenantCostCapResolver::class)) {
            $tenantId = function_exists('tenant') ? tenant('id') : null;
            if (is_int($tenantId) || is_numeric($tenantId)) {
                $cap = $container->make(\Ubxty\CoreAi\Contracts\TenantCostCapResolver::class)
                    ->resolve((int) $tenantId);
                if ($cap->daily > 0.0 || $cap->monthly > 0.0) {
                    /* platform-side already tracks; this PR only adds the
                     * contract seam — actual enforcement happens in
                     * app/Services/UbxtyAiManagerService per T3-PR6 */
                }
            }
        }
    }

    /**
     * Coerce a configured spend cap to a float, or null when no cap is set.
     *
     * env('LIMIT', null) returns '' (not null) when .env has LIMIT= with no
     * value, so we have to treat empty strings as "unset" ourselves. Also
     * accepts null and false; everything else is cast to float.
     */
    private function normalizeLimit(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return (float) $value;
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

    // ─────────────────────────────────────────────────────────
    //  v2.1.0 — Cost optimisation helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Clamp a requested maxTokens value against the model's max_tokens and
     * the available output budget after the input prompt.
     *
     * @return array{0: int, 1: array{context_window: int, max_tokens: int}, 2: int} [clamped, specs, estimatedInput]
     */
    protected function clampMaxTokens(
        string $modelId,
        int $requested,
        string $concatContentForEstimate = '',
        int $precomputedInputTokens = 0
    ): array {
        $specs = ModelSpecResolver::resolve($modelId);
        $maxAllowed = (int) $specs['max_tokens'];
        $contextWindow = (int) $specs['context_window'];

        $estimatedInput = $precomputedInputTokens > 0
            ? $precomputedInputTokens
            : TokenEstimator::estimate($concatContentForEstimate);

        $clamped = max(0, min($requested, $maxAllowed));

        if (($estimatedInput + $clamped) > $contextWindow) {
            $clamped = max(0, $contextWindow - $estimatedInput);
        }

        return [$clamped, $specs, $estimatedInput];
    }

    /**
     * Build a tenant/conversation/prompt-version-scoped cache namespace.
     *
     * Shape: "<prefix>:t<tenantId>:c<conversationId>:v<promptVersion>:<scope>".
     * A null context (or null fields) degrades to the shared "t0:c0:v0" bucket
     * so callers that don't opt into scoping keep deterministic keys.
     */
    protected function cacheNamespace(?CacheKeyContext $ctx, string $scope): string
    {
        $tenant = $ctx?->tenantId ?? 0;
        $conversation = $ctx?->conversationId ?? 0;
        $version = $ctx?->promptVersion ?? 0;

        return $this->cachePrefix().":t{$tenant}:c{$conversation}:v{$version}:{$scope}";
    }

    /**
     * Build a deterministic response-cache key for `invoke()`.
     */
    protected function responseCacheKey(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        ?CacheKeyContext $ctx = null
    ): string {
        $raw = implode('|', [
            $modelId,
            $systemPrompt,
            $userMessage,
            (string) $maxTokens,
            (string) $temperature,
        ]);

        return $this->cacheNamespace($ctx, 'response').'_'.hash('sha256', $raw);
    }

    /**
     * Build a deterministic response-cache key for `converse()` (multi-turn).
     */
    protected function responseCacheKeyConverse(
        string $modelId,
        string $systemPrompt,
        array $messages,
        int $maxTokens,
        float $temperature,
        ?CacheKeyContext $ctx = null
    ): string {
        $raw = implode('|', [
            $modelId,
            $systemPrompt,
            json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string) $maxTokens,
            (string) $temperature,
        ]);

        return $this->cacheNamespace($ctx, 'response').'_'.hash('sha256', $raw);
    }

    /**
     * Build a deterministic idempotency key suitable for upstream `Idempotency-Key`
     * headers. Reuses the same content-hash strategy as the response cache so the
     * two align for repeat requests.
     */
    public function idempotencyKey(string $modelId, string $content): string
    {
        return $this->cachePrefix().'-'.hash('sha256', $modelId.'|'.$content);
    }
}

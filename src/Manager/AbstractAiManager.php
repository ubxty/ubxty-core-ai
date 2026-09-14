<?php

namespace Ubxty\CoreAi\Manager;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Ubxty\CoreAi\Client\ModelAliasResolver;
use Ubxty\CoreAi\Contracts\AiManagerContract;
use Ubxty\CoreAi\Contracts\ImageGenerationOptions;
use Ubxty\CoreAi\Contracts\ImageResult;
use Ubxty\CoreAi\Conversation\ConversationBuilder;
use Ubxty\CoreAi\Events\AiInvoked;
use Ubxty\CoreAi\Exceptions\ConfigurationException;
use Ubxty\CoreAi\Exceptions\CostLimitExceededException;
use Ubxty\CoreAi\Logging\InvocationLogger;
use Ubxty\CoreAi\Models\ModelSpecResolver;
use Ubxty\CoreAi\Support\CacheKeyContext;
use Ubxty\CoreAi\Support\ImagePersistence;
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
    //  Image generation (generate / edit / variation)
    //  Wraps platform calls with cost tracking, persistence, events,
    //  logging, and an aggressive response cache (image-gen is expensive).
    // ─────────────────────────────────────────────────────────

    public function generateImage(
        string $modelId,
        string $prompt,
        ?ImageGenerationOptions $options = null,
        ?string $connection = null,
    ): array {
        $modelId = $modelId ?: $this->defaultImageModel();

        if (! $modelId) {
            throw new ConfigurationException(
                'No image model ID specified and no default image model configured. '
                .'Set defaults.image_model in config/core-ai.php or pass a model ID explicitly.'
            );
        }

        $this->checkCostLimits();
        $modelId = $this->resolveAlias($modelId);
        $options ??= new ImageGenerationOptions();
        $this->assertImageCapability($modelId, 'generate');

        $responseTtl = $this->imageCacheTtl();
        $cacheKey = $responseTtl > 0
            ? $this->imageCacheKey('generate', $modelId, $prompt, null, null, $options)
            : null;

        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cached['cached'] = true;
                $cached['latency_ms'] = 0;
                $this->fireImageInvokedEvent($cached, 'generate');

                return $cached;
            }
        }

        $result = $this->performGenerateImage($modelId, $prompt, null, null, $options, $connection);

        $count = $result->usage?->imageCount ?? 1;
        $cost = $this->calculateImageCost($count, $this->pricingFor($modelId), $this->sizeKey($options));

        $envelope = $this->projectImageEnvelope($result, $modelId, $cost, 'generate', $options);

        $this->trackCost($cost);
        $this->fireImageInvokedEvent($envelope, 'generate');
        $this->getLogger()->log($envelope);

        if ($cacheKey !== null) {
            Cache::put($cacheKey, $envelope, $responseTtl);
        }

        return $envelope;
    }

    public function editImage(
        string $modelId,
        string $prompt,
        string $sourceImagePath,
        ?string $maskPath = null,
        ?ImageGenerationOptions $options = null,
        ?string $connection = null,
    ): array {
        if (trim($prompt) === '') {
            throw new \InvalidArgumentException(
                'editImage requires a non-empty prompt. A description of the edit is required.'
            );
        }

        $this->assertSourceReadable($sourceImagePath);
        if ($maskPath !== null) {
            $this->assertSourceReadable($maskPath);
        }

        $modelId = $modelId ?: $this->defaultImageModel();

        if (! $modelId) {
            throw new ConfigurationException(
                'No image model ID specified and no default image model configured. '
                .'Set defaults.image_model in config/core-ai.php or pass a model ID explicitly.'
            );
        }

        $this->checkCostLimits();
        $modelId = $this->resolveAlias($modelId);
        $options ??= new ImageGenerationOptions();
        $this->assertImageCapability($modelId, 'edit');

        $responseTtl = $this->imageCacheTtl();
        $cacheKey = $responseTtl > 0
            ? $this->imageCacheKey('edit', $modelId, $prompt, $sourceImagePath, $maskPath, $options)
            : null;

        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cached['cached'] = true;
                $cached['latency_ms'] = 0;
                $this->fireImageInvokedEvent($cached, 'edit');

                return $cached;
            }
        }

        $result = $this->performEditImage($modelId, $prompt, $sourceImagePath, $maskPath, $options, $connection);

        $count = $result->usage?->imageCount ?? 1;
        $cost = $this->calculateImageCost($count, $this->pricingFor($modelId), $this->sizeKey($options));

        $envelope = $this->projectImageEnvelope($result, $modelId, $cost, 'edit', $options);

        $this->trackCost($cost);
        $this->fireImageInvokedEvent($envelope, 'edit');
        $this->getLogger()->log($envelope);

        if ($cacheKey !== null) {
            Cache::put($cacheKey, $envelope, $responseTtl);
        }

        return $envelope;
    }

    public function variationImage(
        string $modelId,
        string $sourceImagePath,
        ?ImageGenerationOptions $options = null,
        ?string $connection = null,
    ): array {
        $this->assertSourceReadable($sourceImagePath);

        $modelId = $modelId ?: $this->defaultImageModel();

        if (! $modelId) {
            throw new ConfigurationException(
                'No image model ID specified and no default image model configured. '
                .'Set defaults.image_model in config/core-ai.php or pass a model ID explicitly.'
            );
        }

        $this->checkCostLimits();
        $modelId = $this->resolveAlias($modelId);
        $options ??= new ImageGenerationOptions();
        $this->assertImageCapability($modelId, 'variation');

        $responseTtl = $this->imageCacheTtl();
        $cacheKey = $responseTtl > 0
            ? $this->imageCacheKey('variation', $modelId, '', $sourceImagePath, null, $options)
            : null;

        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cached['cached'] = true;
                $cached['latency_ms'] = 0;
                $this->fireImageInvokedEvent($cached, 'variation');

                return $cached;
            }
        }

        $result = $this->performVariationImage($modelId, $sourceImagePath, $options, $connection);

        $count = $result->usage?->imageCount ?? 1;
        $cost = $this->calculateImageCost($count, $this->pricingFor($modelId), $this->sizeKey($options));

        $envelope = $this->projectImageEnvelope($result, $modelId, $cost, 'variation', $options);

        $this->trackCost($cost);
        $this->fireImageInvokedEvent($envelope, 'variation');
        $this->getLogger()->log($envelope);

        if ($cacheKey !== null) {
            Cache::put($cacheKey, $envelope, $responseTtl);
        }

        return $envelope;
    }

    // ─────────────────────────────────────────────────────────
    //  Abstract platform methods
    // ─────────────────────────────────────────────────────────

    /**
     * Platform-specific invoke implementation.
     */
    protected function performInvoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        ?array $pricing,
        ?string $connection
    ): array {
        return $this->performPlatformCall(
            'invoke', $modelId, $systemPrompt,
            [['role' => 'user', 'content' => $userMessage]],
            $maxTokens, $temperature, $connection,
        );
    }

    /**
     * Platform-specific converse implementation.
     */
    protected function performConverse(
        string $modelId,
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride = null
    ): array {
        return $this->performPlatformCall(
            'converse', $modelId, $systemPrompt, $messages,
            $maxTokens, $temperature, $connection, $cachePointsOverride,
        );
    }

    /**
     * Platform-specific streaming converse implementation.
     */
    protected function performConverseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride = null
    ): array {
        return $this->performPlatformCall(
            'converseStream', $modelId, $systemPrompt, $messages,
            $maxTokens, $temperature, $connection, $cachePointsOverride,
            null, $onChunk,
        );
    }

    /**
     * Platform-specific text-to-image implementation.
     *
     * Subclasses translate `$options` into the provider's wire format,
     * call the upstream API, and return an {@see ImageResult} carrying
     * raw image bytes. The manager wraps this call with cost tracking,
     * auto-persistence, and event firing.
     */
    abstract protected function performGenerateImage(
        string $modelId,
        string $prompt,
        ?string $sourceImagePath,
        ?string $maskPath,
        ImageGenerationOptions $options,
        ?string $connection,
    ): ImageResult;

    /**
     * Platform-specific image-edit (inpaint / mask-driven) implementation.
     */
    abstract protected function performEditImage(
        string $modelId,
        string $prompt,
        string $sourceImagePath,
        ?string $maskPath,
        ImageGenerationOptions $options,
        ?string $connection,
    ): ImageResult;

    /**
     * Platform-specific image-variation implementation.
     */
    abstract protected function performVariationImage(
        string $modelId,
        string $sourceImagePath,
        ImageGenerationOptions $options,
        ?string $connection,
    ): ImageResult;

    // ─────────────────────────────────────────────────────────
    //  performPlatformCall — opt-in template-method dispatch
    // ─────────────────────────────────────────────────────────

    /**
     * Template-method dispatch for the v2.2 perform* hooks.
     *
     * When a subclass implements the three `platform*` methods below
     * (platformInvoke / platformConverse / platformConverseStream),
     * overriding `performInvoke` etc. to call `performPlatformCall('invoke', ...)`
     * switches the manager over to the new wire-format path. Subclasses
     * that don't implement the platform* hooks stay on the legacy
     * `performInvoke` body.
     *
     * The legacy `performInvoke` / `performConverse` / `performConverseStream`
     * remain abstract above — the platform* hooks are an OPT-IN alternative
     * for v2.2+ clients. bedrock-ai's BedrockManager implements them so its
     *   `BedrockClient extends core-ai/Standards/Converse/ConverseClient`
     * refactor lands cleanly. azure-ai's AzureManager can adopt them in v2.3.
     *
     * BC guarantee: any v2.1.x subclass that doesn't override the
     * `platform*` methods keeps working — `performPlatformCall()` only
     * activates the new path when the subclass declares intent via
     * `usePlatformHook(): true`.
     */
    protected function performPlatformCall(
        string $verb,
        string $modelId,
        string $systemPrompt,
        array $messages,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride = null,
        ?\Ubxty\CoreAi\Support\CacheKeyContext $ctx = null,
        ?callable $onChunk = null,
    ): array {
        if (! $this->usePlatformHook()) {
            throw new \LogicException(
                static::class . ' does not opt into performPlatformCall. '
                . 'Override usePlatformHook(): true after implementing platformInvoke / platformConverse / platformConverseStream.'
            );
        }

        $idempotencyKey = $ctx !== null ? hash('sha256', $ctx->conversationId . '|' . $ctx->promptVersion . '|' . $modelId) : null;

        return match ($verb) {
            'invoke' => $this->platformInvoke(
                $modelId, $systemPrompt, $messages[0]['content'] ?? '',
                $maxTokens, $temperature, $connection, $idempotencyKey,
            ),
            'converse' => $this->platformConverse(
                $modelId, $messages, $systemPrompt, $maxTokens, $temperature,
                $connection, $cachePointsOverride, $idempotencyKey,
            ),
            'converseStream' => $this->platformConverseStream(
                $modelId, $messages, $onChunk, $systemPrompt, $maxTokens, $temperature,
                $connection, $cachePointsOverride, $idempotencyKey,
            ),
            default => throw new \InvalidArgumentException(
                "performPlatformCall: unknown verb [{$verb}]"
            ),
        };
    }

    /**
     * Opt-in flag: return true in a subclass after implementing the
     * three `platform*` methods below.
     */
    protected function usePlatformHook(): bool
    {
        return false;
    }

    /**
     * Platform-specific invoke implementation for the v2.2 platform hook.
     * Override alongside usePlatformHook(): true to switch performInvoke
     * onto the new path.
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cost: float, latency_ms: int, status: string, key_used: string, model_id: string}
     */
    protected function platformInvoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?string $idempotencyKey,
    ): array {
        throw new \LogicException(static::class . '::platformInvoke() not implemented');
    }

    /**
     * Platform-specific converse implementation for the v2.2 platform hook.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     */
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
        throw new \LogicException(static::class . '::platformConverse() not implemented');
    }

    /**
     * Platform-specific streaming converse implementation for the v2.2 platform hook.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  ?callable(string $chunk, bool $isFinal): void  $onChunk
     */
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
        throw new \LogicException(static::class . '::platformConverseStream() not implemented');
    }

    // ─────────────────────────────────────────────────────────
    //  Abstract platform methods (testing, models)
    // ─────────────────────────────────────────────────────────

    abstract public function testConnection(?string $connection = null): array;

    abstract public function listModels(?string $connection = null): array;

    abstract public function fetchModels(?string $connection = null): array;

    /**
     * Count of configured models on the given connection.
     *
     * Default uses {@see getConfiguredModels()} so the 4 satellite
     * managers don't duplicate it. A subclass may override if it has
     * a richer model source (e.g. Bedrock fetches them from AWS live).
     */
    public function syncModels(?string $connection = null): int
    {
        $connection ??= $this->config['default'] ?? 'default';

        return count($this->getConfiguredModels($connection));
    }

    /**
     * Whether the platform has at least one configured credential.
     *
     * Default delegates the per-key emptiness check to
     * {@see keyConfigured()} so subclasses can override that one
     * method instead of repeating the connection-resolution boilerplate
     * (Bedrock keeps its full override because IAM-vs-bearer branching
     * needs the credential-manager in a try/catch).
     */
    public function isConfigured(?string $connection = null): bool
    {
        $connection ??= $this->config['default'] ?? 'default';
        $connectionConfig = $this->config['connections'][$connection] ?? null;

        if (! $connectionConfig) {
            return false;
        }

        $keys = $connectionConfig['keys'] ?? [];

        if (empty($keys)) {
            return false;
        }

        return $this->keyConfigured($keys[0]);
    }

    /**
     * Per-key emptiness check. Default is "api_key present and non-empty",
     * which fits all 3 non-Bedrock satellites. Bedrock overrides
     * isConfigured() wholesale and ignores this template.
     */
    protected function keyConfigured(array $key): bool
    {
        return ! empty($key['api_key']);
    }

    /**
     * Whether streamed conversations are supported on this platform.
     *
     * Default is `true`. Bedrock overrides it to gate on
     * `!isBearerMode()` (Bearer tokens don't support streaming), and
     * any future provider that lacks streaming can do the same.
     */
    public function supportsStreaming(?string $connection = null): bool
    {
        return true;
    }

    /**
     * Safe wrapper around `client()->getCredentialManager()->list()`.
     *
     * Returns `[]` on any Throwable (missing connection, empty keys,
     * unconfigured credential manager) so callers never need to
     * try/catch their own. Bedrock previously propagated the throw —
     * this is a strict improvement.
     */
    public function getCredentialInfo(?string $connection = null): array
    {
        try {
            return $this->client($connection)->getCredentialManager()->list();
        } catch (\Throwable) {
            return [];
        }
    }

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
     * Default implementation normalizes the per-platform configured
     * model spec map into the shape consumed by getModelsGrouped().
     * Subclasses only need to declare `providerDefault()`; the rest is
     * lifted here so the 4 satellite managers don't duplicate it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchModelsForGrouping(?string $connection): array
    {
        $defaultProvider = $this->providerDefault();
        $models = $this->getConfiguredModels($connection ?? $this->config['default'] ?? 'default');

        return array_values(array_map(
            fn (string $modelId, array $spec): array => [
                'model_id'         => $modelId,
                'name'             => $spec['name'] ?? $modelId,
                'provider'         => $spec['provider'] ?? $defaultProvider,
                'context_window'   => (int) ($spec['context_window'] ?? 0),
                'max_tokens'       => (int) ($spec['max_tokens'] ?? 0),
                'capabilities'     => (array) ($spec['capabilities'] ?? []),
                'input_modalities' => (array) ($spec['input_modalities'] ?? ['text']),
                'is_active'        => (bool) ($spec['is_active'] ?? true),
            ],
            array_keys($models),
            array_values($models),
        ));
    }

    /**
     * Provider label used as the fallback 'provider' field when a
     * configured model spec doesn't declare its own.
     *
     * Subclasses return their platform name (e.g. 'Anthropic',
     * 'OpenAI', 'Google Gemini', or 'Other' for multi-provider Bedrock).
     */
    abstract protected function providerDefault(): string;

    /**
     * Returns the configured model specs for $connection, keyed by
     * model_id. If the top-level config has a per-connection bucket
     * (`models[$connection]`), that wins; otherwise the flat config is
     * filtered by the optional `connection` field on each spec.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getConfiguredModels(string $connection): array
    {
        $all = $this->config['models'] ?? [];

        if (! is_array($all)) {
            return [];
        }

        if (isset($all[$connection]) && is_array($all[$connection])) {
            return $all[$connection];
        }

        return array_filter(
            $all,
            fn ($spec) => is_array($spec)
                && (! isset($spec['connection']) || $spec['connection'] === $connection),
        );
    }

    /**
     * Capabilities lookup for a model_id. Tries direct id match first;
     * falls back to scanning by `name` and `alias` so that
     * modelSupportsCaching() works whether callers pass the bare id
     * (`gpt-4o`) or a display name / alias.
     *
     * @return string[]
     */
    protected function capabilitiesFor(string $modelId): array
    {
        $models = $this->getConfiguredModels($this->config['default'] ?? 'default');

        if (isset($models[$modelId]) && is_array($models[$modelId])) {
            return (array) ($models[$modelId]['capabilities'] ?? []);
        }

        foreach ($models as $spec) {
            if (! is_array($spec)) {
                continue;
            }

            if (($spec['name'] ?? null) === $modelId || ($spec['alias'] ?? null) === $modelId) {
                return (array) ($spec['capabilities'] ?? []);
            }
        }

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
     *
     * Strips a leading 'AWS ' from the platform name so Bedrock's
     * native name 'AWS Bedrock' produces the legacy prefix 'bedrock_ai'
     * rather than the noisy 'aws_bedrock_ai' (and lets the override in
     * BedrockManager be deleted — the cache-key namespace stays
     * byte-identical for in-flight daily/monthly cost counters).
     */
    protected function cachePrefix(): string
    {
        $name = $this->platformName();

        if (str_starts_with($name, 'AWS ')) {
            $name = substr($name, 4);
        }

        return strtolower(str_replace(' ', '_', $name)).'_ai';
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
     * Per-image cost calculator. Image-gen billing is per-image, not per-token.
     *
     * Pricing keys (first match wins):
     *   - `price_per_image_<sizeKey>` (e.g. `price_per_image_1024x1024`)
     *   - `price_per_image` flat rate
     *
     * If neither key is present the call returns 0.0 — the manager still
     * tracks invocations, just without spend.
     */
    protected function calculateImageCost(int $count, ?array $pricing = null, ?string $sizeKey = null): float
    {
        if ($count <= 0) {
            return 0.0;
        }

        $pricing ??= [];

        if ($sizeKey !== null && $sizeKey !== '') {
            $cleaned = strtolower(str_replace(['x', '×', ' ', '-', '_'], '', $sizeKey));
            if ($cleaned !== '') {
                $perImage = $pricing['price_per_image_'.$cleaned] ?? null;
                if (is_numeric($perImage)) {
                    return round((float) $perImage * $count, 6);
                }
            }
        }

        $perImage = $pricing['price_per_image'] ?? null;
        if (is_numeric($perImage)) {
            return round((float) $perImage * $count, 6);
        }

        return 0.0;
    }

    /**
     * Pricing-table lookup for a configured model. Mirrors
     * {@see capabilitiesFor()} — direct id match first, then scans by
     * name / alias so callers can pass either form.
     *
     * @return array<string, mixed>
     */
    protected function pricingFor(string $modelId): array
    {
        $models = $this->getConfiguredModels($this->config['default'] ?? 'default');

        if (isset($models[$modelId]) && is_array($models[$modelId])) {
            return (array) ($models[$modelId]['pricing'] ?? []);
        }

        foreach ($models as $spec) {
            if (! is_array($spec)) {
                continue;
            }
            if (($spec['name'] ?? null) === $modelId || ($spec['alias'] ?? null) === $modelId) {
                return (array) ($spec['pricing'] ?? []);
            }
        }

        return [];
    }

    /**
     * Throw if the model isn't flagged for image generation in its config spec,
     * or if it doesn't expose the requested `operation` in its `operations`
     * list (which would otherwise let `editImage`/`variationImage` succeed
     * for `generate-only` models — surprising for callers).
     */
    protected function assertImageCapability(string $modelId, string $operation = 'generate'): void
    {
        $caps = $this->capabilitiesFor($modelId);

        if (! in_array('image_generation', $caps, true)) {
            throw new ConfigurationException(
                "Model [{$modelId}] is not configured for image generation. "
                ."Add `capabilities: ['image_generation']` to its entry in core-ai.<provider>.models."
            );
        }

        $supported = $this->operationsFor($modelId);
        if ($supported !== [] && ! in_array($operation, $supported, true)) {
            throw new ConfigurationException(
                "Model [{$modelId}] does not support the [{$operation}] image operation. "
                ."Its `operations` allowlist is [".implode(', ', $supported).'].'
            );
        }
    }

    /**
     * Read the `operations` allowlist for a model from its config spec.
     * Empty list means "all operations allowed" (legacy configs without
     * the key keep the old behaviour).
     *
     * @return string[]
     */
    protected function operationsFor(string $modelId): array
    {
        $models = $this->getConfiguredModels($this->config['default'] ?? 'default');

        if (isset($models[$modelId]) && is_array($models[$modelId])) {
            return (array) ($models[$modelId]['operations'] ?? []);
        }

        foreach ($models as $spec) {
            if (! is_array($spec)) {
                continue;
            }
            if (($spec['name'] ?? null) === $modelId || ($spec['alias'] ?? null) === $modelId) {
                return (array) ($spec['operations'] ?? []);
            }
        }

        return [];
    }

    /**
     * Guard against missing / unreadable source files for edit / variation.
     */
    protected function assertSourceReadable(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException(
                "Source image is not readable: {$path}"
            );
        }

        $size = @filesize($path);
        if ($size !== false && $size > 15 * 1024 * 1024) {
            throw new \InvalidArgumentException(
                "Source image exceeds the 15 MB hard cap: {$path}"
            );
        }
    }

    /**
     * Cache TTL for image-gen results, in seconds. Falls back to
     * `cache.image_ttl` (default 86400) and treats 0 / negative as disabled.
     */
    protected function imageCacheTtl(): int
    {
        $ttl = (int) ($this->config['cache']['image_ttl'] ?? 86400);

        return $ttl > 0 ? $ttl : 0;
    }

    /**
     * Deterministic cache key for an image-gen operation.
     *
     * Includes the operation kind, model, prompt, source/mask byte hashes
     * (when present), and the resolved size/seed/etc. so callers can hit
     * the cache on identical inputs without going to the wire.
     */
    protected function imageCacheKey(
        string $operation,
        string $modelId,
        string $prompt,
        ?string $sourceImagePath,
        ?string $maskPath,
        ImageGenerationOptions $options,
    ): string {
        $sourceHash = $sourceImagePath && is_file($sourceImagePath)
            ? hash_file('sha256', $sourceImagePath)
            : '';
        $maskHash = $maskPath && is_file($maskPath)
            ? hash_file('sha256', $maskPath)
            : '';

        $raw = implode('|', [
            $operation,
            $modelId,
            $prompt,
            $sourceHash,
            $maskHash,
            (string) ($options->size ?? ''),
            (string) ($options->width ?? ''),
            (string) ($options->height ?? ''),
            (string) ($options->quality ?? ''),
            (string) ($options->negativePrompt ?? ''),
            (string) ($options->seed ?? ''),
            (string) ($options->steps ?? ''),
            (string) ($options->cfgScale ?? ''),
            (string) ($options->inputFidelity ?? ''),
            (string) ($options->background ?? ''),
            (string) ($options->outputFormat ?? ''),
            (string) ($options->n ?? ''),
            (string) ($options->filenamePrefix ?? ''),
            // Persistence flag — `persist: true` and `persist: false` callers
            // get distinct cache entries so the cached envelope's `path`
            // matches the caller's intent.
            ($options->persist === false ? 'no-persist' : 'persist'),
        ]);

        return $this->cachePrefix().':images:'.$operation.':'.hash('sha256', $raw);
    }

    /**
     * Resolve the size key used for cost lookup.
     */
    protected function sizeKey(ImageGenerationOptions $options): ?string
    {
        if ($options->size) {
            return $options->size;
        }
        if ($options->width !== null && $options->height !== null) {
            return $options->width.'x'.$options->height;
        }

        return null;
    }

    /**
     * Project an {@see ImageResult} into the legacy array envelope and
     * persist the bytes to Laravel Storage (unless explicitly disabled).
     *
     * @return array{
     *     bytes: string, mime: string, model_id: string, revised_prompt: ?string,
     *     latency_ms: int, cost: float, key_used: string, cached: bool, status: string,
     *     operation: string, usage: ?array{image_count: int, input_tokens: int, output_tokens: int},
     *     path: ?string, url: ?string, disk: ?string, filename: ?string, storage_error: ?string
     * }
     */
    protected function projectImageEnvelope(
        ImageResult $result,
        string $modelId,
        float $cost,
        string $operation,
        ImageGenerationOptions $options,
    ): array {
        $envelope = [
            'bytes' => $result->bytes,
            'mime' => $result->mimeType,
            'model_id' => $modelId,
            'revised_prompt' => $result->revisedPrompt,
            'latency_ms' => $result->latencyMs,
            'cost' => $cost,
            'key_used' => $result->keyLabel ?? 'unknown',
            'cached' => $result->cached,
            'status' => 'success',
            'operation' => $operation,
            'usage' => $result->usage ? [
                'image_count' => $result->usage->imageCount,
                'input_tokens' => $result->usage->inputTokens,
                'output_tokens' => $result->usage->outputTokens,
            ] : null,
            'path' => null,
            'url' => null,
            'disk' => null,
            'filename' => null,
            'storage_error' => null,
        ];

        if ($options->persist === false) {
            return $envelope;
        }

        try {
            $stored = ImagePersistence::persist(
                $result->bytes,
                $result->mimeType,
                $options->disk,
                $options->dir,
                $options->filenamePrefix,
            );
            $envelope['path'] = $stored['path'];
            $envelope['url'] = $stored['url'];
            $envelope['disk'] = $stored['disk'];
            $envelope['filename'] = $stored['filename'];
        } catch (\Throwable $e) {
            $envelope['storage_error'] = $e->getMessage();
        }

        return $envelope;
    }

    /**
     * Fire the {@see AiInvoked} event for an image-gen call.
     */
    protected function fireImageInvokedEvent(array $envelope, string $operation): void
    {
        if (! function_exists('event')) {
            return;
        }

        event(new AiInvoked(
            modelId: $envelope['model_id'] ?? 'unknown',
            inputTokens: $envelope['usage']['input_tokens'] ?? 0,
            outputTokens: $envelope['usage']['output_tokens'] ?? 0,
            cost: $envelope['cost'] ?? 0.0,
            latencyMs: $envelope['latency_ms'] ?? 0,
            keyUsed: $envelope['key_used'] ?? 'unknown',
            platform: $this->platformName(),
            operation: $operation,
            imageCount: $envelope['usage']['image_count'] ?? null,
            savedPath: $envelope['path'] ?? null,
            mimeType: $envelope['mime'] ?? null,
        ));
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

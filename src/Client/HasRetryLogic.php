<?php

namespace Ubxty\CoreAi\Client;

use Ubxty\CoreAi\Exceptions\AiException;
use Ubxty\CoreAi\Exceptions\RateLimitException;

/**
 * Shared retry and key-rotation logic for AI client classes.
 *
 * Platform packages override the hook methods to add SDK client creation,
 * event dispatching, and platform-specific error handling.
 */
trait HasRetryLogic
{
    protected AbstractCredentialManager $credentials;

    protected int $maxRetries;

    protected int $baseDelay;

    /**
     * Named anchors where the manager wants a `cachePoint` / `cache_control` block
     * injected. Currently: 'system', 'last_user'.
     *
     * @var string[]
     */
    protected array $promptCachePoints = [];

    /**
     * Optional explicit Retry-After (seconds) set by the HTTP path after parsing
     * the rate-limit response. Cleared on each retry iteration.
     */
    protected ?int $retryAfterSeconds = null;

    /**
     * Set the prompt-cache checkpoint anchors. Pass-through from
     * `core-ai.{bedrock,azure_ai}.prompt_caching.points`.
     */
    public function setPromptCachePoints(array $points): static
    {
        $this->promptCachePoints = array_values(array_filter(
            array_map('strval', $points),
            fn (string $p) => in_array($p, ['system', 'last_user'], true),
        ));

        return $this;
    }

    /**
     * Set the Retry-After hint parsed from an upstream HTTP 429/503 response.
     * When set, withRetry() uses it in preference to the exponential backoff.
     */
    public function setRetryAfterSeconds(?int $seconds): static
    {
        $this->retryAfterSeconds = $seconds;

        return $this;
    }

    /**
     * Execute a callable with key rotation and retry logic.
     *
     * @param  callable(string $resolvedModelId, array $key): array  $callback
     */
    protected function withRetry(string $modelId, callable $callback): array
    {
        $this->credentials->reset();
        $maxKeyAttempts = $this->credentials->count();
        $keyAttempt = 0;

        while ($keyAttempt < $maxKeyAttempts) {
            $retryAttempt = 0;

            while ($retryAttempt <= $this->maxRetries) {
                try {
                    $key = $this->credentials->current();
                    $resolvedModelId = $this->resolveModelId($modelId, $key);

                    return $callback($resolvedModelId, $key);
                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();
                    $isRateLimited = $this->isRateLimitError($errorMessage);

                    if ($isRateLimited && $retryAttempt < $this->maxRetries) {
                        // Honour Retry-After when set (HTTP bearer path). Otherwise
                        // fall back to exponential backoff: baseDelay^attempt.
                        $waitTime = $this->retryAfterSeconds !== null
                            ? max(1, $this->retryAfterSeconds)
                            : (int) pow($this->baseDelay, $retryAttempt + 1);
                        $this->retryAfterSeconds = null; // consume the hint
                        sleep($waitTime);
                        $retryAttempt++;

                        continue;
                    }

                    $this->resetPlatformClient();

                    if ($this->credentials->next()) {
                        $this->onKeyRotated($key, $this->credentials->current(), $errorMessage, $modelId);
                        break;
                    }

                    if ($isRateLimited) {
                        $this->onRateLimitExhausted($modelId, $key, $retryAttempt);

                        throw new RateLimitException(
                            'AI service is temporarily busy. Please wait a moment and try again.',
                            429, $e, $modelId, $key['label'] ?? null
                        );
                    }

                    throw new AiException(
                        $this->extractFriendlyError($errorMessage),
                        0, $e, $modelId, $key['label'] ?? null
                    );
                }
            }

            $keyAttempt++;
        }

        throw new AiException('AI service unavailable. All credential keys exhausted.', 0, null, $modelId);
    }

    /**
     * Resolve a model ID for the current key/region.
     * Override in platform packages for model ID transformations (e.g. inference profiles).
     */
    protected function resolveModelId(string $modelId, array $key): string
    {
        return $modelId;
    }

    /**
     * Check if an error message indicates rate limiting.
     */
    protected function isRateLimitError(string $message): bool
    {
        return str_contains($message, '429')
            || str_contains($message, 'Too many requests')
            || str_contains($message, 'ThrottlingException')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'Rate limit');
    }

    /**
     * Extract a user-friendly error message from a raw provider error.
     * Override in platform packages for provider-specific error mapping.
     */
    protected function extractFriendlyError(string $errorMessage): string
    {
        return 'AI service error. Please try again.';
    }

    /**
     * Reset the platform SDK client (e.g. when rotating keys).
     * Override in platform packages that cache SDK client instances.
     */
    protected function resetPlatformClient(): void
    {
        // Override in platform-specific clients
    }

    /**
     * Hook called when a key is rotated due to error.
     */
    protected function onKeyRotated(array $fromKey, array $toKey, string $reason, string $modelId): void
    {
        // Override in platform packages to dispatch events
    }

    /**
     * Hook called when all keys are exhausted due to rate limiting.
     */
    protected function onRateLimitExhausted(string $modelId, array $key, int $retryAttempt): void
    {
        // Override in platform packages to dispatch events
    }

    /**
     * Calculate the cost of an invocation from token counts.
     */
    protected function calculateCost(int $inputTokens, int $outputTokens, ?array $pricing = null): float
    {
        $inputPrice = $pricing['input_price_per_1k'] ?? 0.003;
        $outputPrice = $pricing['output_price_per_1k'] ?? 0.015;

        return round(
            ($inputTokens / 1000) * $inputPrice + ($outputTokens / 1000) * $outputPrice,
            6
        );
    }
}

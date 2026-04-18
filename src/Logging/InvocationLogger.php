<?php

namespace Ubxty\CoreAi\Logging;

use Illuminate\Support\Facades\Log;

class InvocationLogger
{
    protected string $channel;

    protected bool $enabled;

    public function __construct(bool $enabled = true, string $channel = 'stack')
    {
        $this->enabled = $enabled;
        $this->channel = $channel;
    }

    /**
     * Log an invocation result.
     *
     * @param  array{response?: string, input_tokens: int, output_tokens: int, total_tokens: int, cost: float, latency_ms: int, status: string, key_used: string, model_id: string}  $result
     */
    public function log(array $result): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->channel)->info('AI invocation', [
            'model_id' => $result['model_id'] ?? 'unknown',
            'input_tokens' => $result['input_tokens'] ?? 0,
            'output_tokens' => $result['output_tokens'] ?? 0,
            'total_tokens' => $result['total_tokens'] ?? 0,
            'cost' => $result['cost'] ?? 0,
            'latency_ms' => $result['latency_ms'] ?? 0,
            'status' => $result['status'] ?? 'unknown',
            'key_used' => $result['key_used'] ?? 'unknown',
        ]);
    }

    /**
     * Log a failed invocation.
     */
    public function logError(string $modelId, string $error, ?string $keyLabel = null): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->channel)->error('AI invocation failed', [
            'model_id' => $modelId,
            'error' => $error,
            'key_used' => $keyLabel ?? 'unknown',
        ]);
    }

    /**
     * Log a rate limit event.
     */
    public function logRateLimit(string $modelId, string $keyLabel, int $attempt, int $waitSeconds): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->channel)->warning('AI rate limited', [
            'model_id' => $modelId,
            'key_used' => $keyLabel,
            'attempt' => $attempt,
            'wait_seconds' => $waitSeconds,
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }
}

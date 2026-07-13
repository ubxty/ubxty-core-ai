# Events and Listeners

> Companion to the [README](../README.md). Reference for the three lifecycle events dispatched by core-ai (and the BC aliases dispatched by the provider packages), plus ready-to-use listener templates.

---

## Why events over return-value inspection

Every successful invocation returns an array with `cost`, `latency_ms`, `key_used`, etc. Good enough for the caller's hot path. But secondary effects — audit log, cost roll-up, alerting, downstream notifications — should not depend on a synchronous `try/catch` flow. Events give you:

- Decoupled observability (write to a separate DB / SIEM without touching the caller).
- Fan-out (one invocation can drive N listeners — usage log + cost cap + cache warmer + audit).
- Test isolation (assert on the event, not on a final state).

This document lists the three core events, the deprecated provider-specific BC aliases, and three ready-to-use listener templates.

---

## `AiInvoked`

Fires after **every** successful invocation — including cache hits (`latency_ms: 0`, `cached: true`).

```php
namespace Ubxty\CoreAi\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AiInvoked
{
    use Dispatchable;

    public function __construct(
        public readonly string $modelId,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $cost,
        public readonly int $latencyMs,
        public readonly string $keyUsed,
        public readonly ?string $connection = null,
        public readonly ?string $platform = null,
    ) {}
}
```

### When it fires

`AbstractAiManager::invoke()`, `converse()`, and `converseStream()` each call `$this->fireInvokedEvent($result)` after a non-throwing return path. Cached responses are dispatched too — listeners can detect them via `latencyMs === 0`.

### Listener template — usage ledger

```php
namespace App\Listeners;

use Ubxty\CoreAi\Events\AiInvoked;

class WriteUsageRow
{
    public function handle(AiInvoked $event): void
    {
        DB::table('ai_invocations')->insert([
            'tenant_id'     => tenant_id() ?? 'central',
            'model_id'      => $event->modelId,
            'input_tokens'  => $event->inputTokens,
            'output_tokens' => $event->outputTokens,
            'cost'          => $event->cost,
            'latency_ms'    => $event->latencyMs,
            'key_used'      => $event->keyUsed,
            'connection'    => $event->connection,
            'platform'      => $event->platform,
            'cached'        => $event->latencyMs === 0,
            'occurred_at'   => now(),
        ]);
    }
}
```

```php
// AppServiceProvider::boot()
Event::listen(AiInvoked::class, WriteUsageRow::class);
```

---

## `AiKeyRotated`

Fires when the credential manager rotates to the next key because of a rate-limit or auth error.

```php
namespace Ubxty\CoreAi\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AiKeyRotated
{
    use Dispatchable;

    public function __construct(
        public readonly string $fromKeyLabel,
        public readonly string $toKeyLabel,
        public readonly string $reason,
        public readonly string $modelId,
        public readonly ?string $platform = null,
    ) {}
}
```

### When it fires

Inside `HasRetryLogic::withRetry()`, after `$this->credentials->next()` succeeds. The default hook in the abstract trait calls `event(new AiKeyRotated(...))`.

### Listener template — pager alert

```php
namespace App\Listeners;

use Ubxty\CoreAi\Events\AiKeyRotated;
use Illuminate\Support\Facades\Log;

class PageOnKeyRotation
{
    public function handle(AiKeyRotated $event): void
    {
        Log::warning('ai.key.rotated', [
            'from'      => $event->fromKeyLabel,
            'to'        => $event->toKeyLabel,
            'reason'    => $event->reason,
            'model'     => $event->modelId,
            'platform'  => $event->platform,
        ]);

        // Counter-based paging: rotation count > 5 in 5 min on the same model.
        $count = Cache::increment("ai.rotation.{$event->modelId}");
        Cache::put("ai.rotation.{$event->modelId}", $count, now()->addMinutes(5));

        if ($count > 5) {
            // Page on-call.
        }
    }
}
```

---

## `AiRateLimited`

Fires on every retry-sleep decision inside `withRetry()`. Useful for SLO dashboards and exhaustion alerts.

```php
namespace Ubxty\CoreAi\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AiRateLimited
{
    use Dispatchable;

    public function __construct(
        public readonly string $modelId,
        public readonly string $keyLabel,
        public readonly int $retryAttempt,
        public readonly int $waitSeconds,
        public readonly ?string $platform = null,
    ) {}
}
```

### When it fires

Two places:

- Inside the inner retry loop — `withRetry()` calls `event(new AiRateLimited(...))` before sleeping, with the `Retry-After`-honoured wait time.
- When all keys are exhausted on rate-limit — the `onRateLimitExhausted` hook also dispatches this event with `waitSeconds: 0`.

### Listener template — SLO dashboard counter

```php
namespace App\Listeners;

use Ubxty\CoreAi\Events\AiRateLimited;
use Illuminate\Support\Facades\Redis;

class IncrementRateLimitCounter
{
    public function handle(AiRateLimited $event): void
    {
        $bucket = sprintf(
            'rl:%s:%s:%s',
            $event->platform ?? 'unknown',
            now()->format('YmdH'),
            $event->modelId,
        );

        Redis::hincrby($bucket, "k:{$event->keyLabel}", 1);
        Redis::expire($bucket, 86400); // 24h TTL
    }
}
```

---

## Deprecated BC aliases

The provider packages (bedrock-ai, azure-ai) fire provider-specific events that existed before `core-ai` standardised on the canonical event names. They are still dispatched for backward compatibility, but new code should prefer the canonical events above.

```php
// Deprecated (still fires):
Ubxty\BedrockAi\Events\BedrockInvoked   extends Ubxty\CoreAi\Events\AiInvoked
Ubxty\BedrockAi\Events\BedrockKeyRotated extends Ubxty\CoreAi\Events\AiKeyRotated
Ubxty\BedrockAi\Events\BedrockRateLimited extends Ubxty\CoreAi\Events\AiRateLimited
Ubxty\AzureAi\Events\AzureInvoked       extends Ubxty\CoreAi\Events\AiInvoked
Ubxty\AzureAi\Events\AzureKeyRotated    extends Ubxty\CoreAi\Events\AiKeyRotated
Ubxty\AzureAi\Events\AzureRateLimited   extends Ubxty\CoreAi\Events\AiRateLimited
```

Both the deprecated and canonical events fire on every successful invocation. Listeners attached to the canonical name see all providers. Listeners attached to the deprecated name only see that one provider.

Schedule of canonical names: core-ai v2.0.0 (consolidated the events into the canonical namespace). Schedule of deprecation: TBD in the v2.3 line — once stats scripts migrate.

---

## Test recipe

Listen to events in feature tests without running a model:

```php
use Illuminate\Support\Facades\Event;
use Ubxty\CoreAi\Events\AiInvoked;
use Ubxty\BedrockAi\BedrockManager;

it('fires AiInvoked with the cost of a successful invocation', function () {
    Event::fake([AiInvoked::class]);

    // Bind a stub manager that returns a known cost.
    $stub = new class([]) extends BedrockManager {
        public function __construct($c) { parent::__construct(['default' => 'x', 'connections' => ['x' => ['keys' => [['label'=>'l','api_key'=>'']]]]); }
        protected function performInvoke(string $m, string $s, string $u, int $mt, float $t, ?array $p, ?string $c): array
        {
            return [
                'response' => 'ok',
                'input_tokens' => 10, 'output_tokens' => 5,
                'total_tokens' => 15, 'cost' => 0.0001,
                'latency_ms' => 1, 'status' => 'ok',
                'key_used' => 'Primary', 'model_id' => 'stub',
            ];
        }
    };

    $stub->invoke('stub', 'sys', 'user', 256, 0.2);

    Event::assertDispatched(AiInvoked::class, function (AiInvoked $e) {
        return $e->modelId === 'stub' && $e->cost === 0.0001;
    });
});
```

---

## Ordering guarantees

Events fire in the following order within a single invocation:

1. Inner retry loop decides to sleep → `AiRateLimited` (per attempt).
2. Inner retry loop exhausts retries on rate-limit → `onRateLimitExhausted` hook → `AiRateLimited` (waitSeconds: 0).
3. Inner retry loop gives up → `AiException` thrown (or `RateLimitException`).
4. Outer key rotation succeeds → `onKeyRotated` → `AiKeyRotated`.
5. Successful invocation completes → `fireInvokedEvent` → `AiInvoked`.

If you write to the same store in multiple listeners, be aware that listener execution order is the order they were registered. Use `Event::listen(…, $priority)` to control ordering explicitly.

---

## Cost-cap listener (combined)

```php
namespace App\Listeners;

use Ubxty\CoreAi\Events\AiInvoked;

class HardDailyCap
{
    public function handle(AiInvoked $event): void
    {
        $key = "ai.spend.".date('Y-m-d');
        $spent = (float) Cache::get($key, 0) + $event->cost;

        Cache::put($key, $spent, now()->endOfDay());

        if ($spent > config('ai.daily_cap')) {
            // Push to a "blocked" list checked by the call site.
            Cache::put('ai.blocked', true, now()->endOfDay());
        }
    }
}
```

Combine with a config-level `limits.daily` for the manager's pre-flight check. This listener is a backstop in case the config-level cap drifts out of sync with the audit ledger.

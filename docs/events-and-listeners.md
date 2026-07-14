# Events and Listeners

> Companion to the [README](../README.md). Reference for the three lifecycle event payloads defined by core-ai, the canonical `AiInvoked` dispatch, and the retry hooks that provider packages can override to dispatch rotation and rate-limit events.

---

## Why events over return-value inspection

Every successful invocation returns an array with `cost`, `latency_ms`, `key_used`, etc. Good enough for the caller's hot path. But secondary effects — audit log, cost roll-up, alerting, downstream notifications — should not depend on a synchronous `try/catch` flow. Events give you:

- Decoupled observability (write to a separate DB / SIEM without touching the caller).
- Fan-out (one invocation can drive N listeners — usage log + cost cap + cache warmer + audit).
- Test isolation (assert on the event, not on a final state).

This document lists the three core event payloads, the provider override points, version-dependent provider aliases, and ready-to-use listener templates.

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

Inside `HasRetryLogic::withRetry()`, after `$this->credentials->next()` succeeds, the trait calls `onKeyRotated()`. Core-ai's default hook is empty. Provider packages must override `onKeyRotated()` if they want to dispatch `AiKeyRotated` or a provider-named subclass.

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

Represents rate-limit exhaustion when a provider package chooses to dispatch it from `onRateLimitExhausted()`. Useful for SLO dashboards and exhaustion alerts.

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

Core-ai's `HasRetryLogic` trait does not dispatch `AiRateLimited` in the inner retry loop. When all keys are exhausted on a rate-limit, it calls `onRateLimitExhausted()`; the default hook is empty. Provider packages must override that hook to dispatch `AiRateLimited` or a provider-named subclass, typically with `waitSeconds: 0`.

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

## Provider-package BC aliases

Provider packages may retain provider-named subclasses of the canonical core-ai event payloads for backward compatibility. In versions that implement these aliases, the relationship is:

```php
Ubxty\BedrockAi\Events\BedrockInvoked extends Ubxty\CoreAi\Events\AiInvoked
Ubxty\BedrockAi\Events\BedrockKeyRotated extends Ubxty\CoreAi\Events\AiKeyRotated
Ubxty\BedrockAi\Events\BedrockRateLimited extends Ubxty\CoreAi\Events\AiRateLimited
Ubxty\AzureAi\Events\AzureInvoked extends Ubxty\CoreAi\Events\AiInvoked
Ubxty\AzureAi\Events\AzureKeyRotated extends Ubxty\CoreAi\Events\AiKeyRotated
Ubxty\AzureAi\Events\AzureRateLimited extends Ubxty\CoreAi\Events\AiRateLimited
```

These classes and their dispatch policy belong to the installed provider version, not to core-ai. Confirm that version's source or changelog before relying on a provider-named alias or assuming that both alias and canonical class names are dispatched separately. New code should prefer the canonical core-ai payloads where the provider supports them.

---

## Provider-package test recipe

This recipe uses `BedrockManager`, so it registers only when the Bedrock provider package is installed:

```php
use Illuminate\Support\Facades\Event;
use Ubxty\BedrockAi\BedrockManager;
use Ubxty\BedrockAi\Events\BedrockInvoked;

if (class_exists(BedrockManager::class)) {
    it('fires the provider invocation event with the cost of a successful invocation', function () {
        Event::fake([BedrockInvoked::class]);

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

        Event::assertDispatched(BedrockInvoked::class, function (BedrockInvoked $e) {
            return $e->modelId === 'stub' && $e->cost === 0.0001;
        });
    });
}
```

---

## Ordering guarantees

The core lifecycle and provider override points run in this order within a single invocation:

1. Inner retry loop decides to sleep → core-ai sleeps without dispatching `AiRateLimited`.
2. Inner retry loop exhausts retries on a rate-limit → `onRateLimitExhausted` hook; an event fires only if the provider package overrides the empty core hook.
3. Inner retry loop gives up → `AiException` thrown (or `RateLimitException`).
4. Outer key rotation succeeds → `onKeyRotated`; an event fires only if the provider package overrides the empty core hook.
5. Successful invocation completes → `fireInvokedEvent` → canonical `AiInvoked` from `AbstractAiManager`, unless a provider manager overrides that dispatch method.

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

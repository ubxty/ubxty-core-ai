# Real-World Patterns

> Companion to the [README](../README.md). Recipes distilled from running `ubxty/core-ai`-based providers in multi-tenant Laravel host apps. Each section is a standalone pattern — pick the ones you need.

---

## 1. Multi-tenant provider dispatch

When the same host app serves many tenants, and each tenant has its own provider credentials (some Bedrock, some Azure), build a tenant-aware dispatcher.

```php
namespace App\Services\Ai;

use Ubxty\CoreAi\Contracts\AiManagerContract;
use Ubxty\BedrockAi\BedrockManager;
use Ubxty\AzureAi\AzureManager;

class TenantAiDispatcher
{
    public function resolve(string $tenantId): AiManagerContract
    {
        $cfg = AiProviderConfigService::for($tenantId);

        return match ($cfg['provider']) {
            'bedrock' => new BedrockManager([
                'default' => 'tenant',
                'connections' => [
                    'tenant' => ['keys' => [$cfg['keys']]],
                ],
                'retry' => ['max_retries' => 3, 'base_delay' => 2],
                'cache' => [
                    'models_ttl' => 600,
                    'response_ttl' => 0, // per-request cache key (Bedrock-scoped)
                ],
                'logging' => ['enabled' => false],
                'providers' => ['disabled_providers' => $cfg['disabled_providers'] ?? []],
                'defaults' => ['model' => $cfg['model_id']],
            ]),
            'azure' => new AzureManager([
                'default' => 'tenant',
                'connections' => ['tenant' => ['keys' => [$cfg['keys']]]],
                'retry' => ['max_retries' => 3, 'base_delay' => 2],
                'cache' => ['models_ttl' => 600],
                'defaults' => ['model' => $cfg['model_id']],
            ]),
        };
    }
}
```

Cache invalidation when a tenant rotates keys: the manager has no internal state across calls (it stores keys on the credential manager object and resets at the top of `withRetry()`), so you don't need to invalidate. Re-instantiate if the config changes.

---

## 2. Single-prompt task with retry safety

```php
use Ubxty\BedrockAi\BedrockManager;

$startMs = (int) (microtime(true) * 1000);
$manager = app(BedrockManager::class);

try {
    $result = $manager->invoke(
        modelId: 'claude-sonnet-4',
        systemPrompt: 'You are a careful reader.',
        userMessage: $ticketText,
        maxTokens: 1024,
        temperature: 0.1,
    );

    Log::info('ai.ticket.classified', [
        'tokens_in' => $result['input_tokens'],
        'tokens_out' => $result['output_tokens'],
        'cost' => $result['cost'],
        'cached' => $result['cached'] ?? false,
        'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
    ]);
} catch (\Ubxty\CoreAi\Exceptions\RateLimitException) {
    // idempotent retry — same idempotency-key returns same response
    $result = $manager->invoke(…);
} catch (\Ubxty\CoreAi\Exceptions\CostLimitExceededException $e) {
    // surface to the user, queue the request, or escalate
}
```

The retry safety is automatic: `idempotencyKey()` is computed from `(modelId, content)` upstream and injected as a header on the Bearer-mode HTTP path. A network blip retry returns the same cached response — no double billing.

---

## 3. Multi-turn, multimodal conversation

```php
$result = app(BedrockManager::class)
    ->conversation('claude-sonnet-4')
    ->system('You are a contract reviewer. Identify non-standard clauses.')
    ->user('Review the attached contract.')
    ->userWithDocument('What did I miss?', $contractPath)
    ->userWithImage('Diagram — anything suspicious?', $diagramPath)
    ->maxTokens(4096)
    ->temperature(0.05)
    ->withPricing([
        'input_price_per_1k' => 0.003,
        'output_price_per_1k' => 0.015,
    ])
    ->send();
```

For larger than 15 MB inputs, the builder throws `AiException` before any HTTP. Re-encode / compress first.

For a streaming variant (long responses, agent-like UX):

```php
$chunks = [];
$result = $manager->conversation('claude-sonnet-4')
    ->system('You write in long form.')
    ->user('Tell me about the Roman Empire in detail.')
    ->maxTokens(8192)
    ->sendStream(function (string $chunk) use (&$chunks) {
        $chunks[] = $chunk;
        // Stream chunk to client, push to SSE, append to terminal — your choice.
    });
```

---

## 4. Structured JSON output

Use a strict system prompt and parse defensively.

```php
$systemPrompt = <<<EOT
Output ONLY valid JSON matching this schema. No preamble. No markdown fences.

{"items": [{"name": string, "amount": number}]}

Rules:
- `name` is the line item description verbatim from the source.
- `amount` is the numeric total in the source currency.
EOT;

$result = $manager->invoke(
    modelId: 'claude-sonnet-4',
    systemPrompt: $systemPrompt,
    userMessage: $receiptText,
    maxTokens: 4096,
    temperature: 0.0, // deterministic
);

$decoded = json_decode($result['response'], true, flags: JSON_THROW_ON_ERROR);
```

For stricter guarantees, the v2.2 roadmap adds an `InvokeResult` DTO + typed JSON parsing. Today, the array return shape + `JSON_THROW_ON_ERROR` parse is the convention.

---

## 5. Cost-cap listener

```php
namespace App\Listeners;

use Ubxty\CoreAi\Events\AiInvoked;

class TrackDailySpend
{
    public function handle(AiInvoked $event): void
    {
        DB::table('ai_usage')->insert([
            'tenant_id'      => tenant_id(),
            'model_id'       => $event->modelId,
            'input_tokens'   => $event->inputTokens,
            'output_tokens'  => $event->outputTokens,
            'cost'           => $event->cost,
            'latency_ms'     => $event->latencyMs,
            'key_used'       => $event->keyUsed,
            'platform'       => $event->platform,
            'occurred_at'    => now(),
        ]);
    }
}
```

```php
// AppServiceProvider::boot()
Event::listen(AiInvoked::class, TrackDailySpend::class);
```

Roll up daily / monthly:

```sql
SELECT DATE(occurred_at) AS day,
       platform,
       SUM(cost) AS daily_cost
FROM ai_usage
WHERE tenant_id = ?
  AND occurred_at >= NOW() - INTERVAL 30 DAY
GROUP BY 1, 2
ORDER BY 1 DESC;
```

---

## 6. Key-rotation alerting

```php
namespace App\Listeners;

use Ubxty\CoreAi\Events\AiKeyRotated;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class AlertOnKeyRotation
{
    public function handle(AiKeyRotated $event): void
    {
        Log::warning('ai.key.rotated', [
            'from'   => $event->fromKeyLabel,
            'to'     => $event->toKeyLabel,
            'reason' => $event->reason,
            'model'  => $event->modelId,
            'platform' => $event->platform,
        ]);

        // Page on-call if > 5 rotations in 5 minutes on the same model.
        // Or just write to Sentry with tags — your call.
    }
}
```

---

## 7. Idempotent queue workers

When a queue worker may retry a job after a network blip, you want the second run to skip the model call when the first run already produced a result.

```php
class SummarizeDocument implements ShouldQueue
{
    public function handle(): void
    {
        $document = Document::find($this->documentId);

        // Memo-keyed by the document + the model — same content + same model
        // returns the cached result without re-running the model.
        $result = Cache::remember(
            'doc_summary:'.sha1($document->text.':claude-sonnet-4'),
            now()->addDay(),
            fn () => app(BedrockManager::class)->invoke(
                modelId: 'claude-sonnet-4',
                systemPrompt: 'You are a careful summariser.',
                userMessage: $document->text,
                maxTokens: 1024,
                temperature: 0.1,
            )
        );

        $document->summary = $result['response'];
        $document->summarized_at = now();
        $document->save();
    }
}
```

Or, more idiomatically, set `<provider>.cache.response_ttl > 0` (`bedrock.cache.response_ttl` or `azure_ai.cache.response_ttl`) and let the manager handle the memoisation itself.

---

## 8. Multi-key round-robin for one connection

For predictable throughput across regions.

```php
// config/core-ai.php
'bedrock' => [
    'connections' => [
        'default' => [
            'keys' => [
                ['label' => 'us-east-1a', 'bearer_token' => env('BEDROCK_TOKEN_A'), 'region' => 'us-east-1'],
                ['label' => 'us-east-1b', 'bearer_token' => env('BEDROCK_TOKEN_B'), 'region' => 'us-east-1'],
                ['label' => 'us-west-2',  'bearer_token' => env('BEDROCK_TOKEN_C'), 'region' => 'us-west-2'],
            ],
        ],
    ],
],
```

The credential manager rotates through `keys[]` on rate-limit / auth errors. Round-robin comes from `next()` + `reset()` semantics — the manager doesn't pre-balance, it just tries the next on failure.

---

## 9. Cost-aware image extraction

PDFs and lab reports often have small embedded images that cost more to OCR than the value they return.

```php
$estimate = $manager->conversation('claude-sonnet-4')
    ->system('You extract lab values.')
    ->userWithDocument('Extract all values.', $pdfPath)
    ->estimate();

if (! $estimate['fits']) {
    Log::warning('ai.oversize.skip', $estimate);
    return ['error' => 'pdf_too_large'];
}

if ($estimate['estimated_cost'] > 0.20) {
    Log::warning('ai.expensive.skip', $estimate);
    return ['error' => 'cost_cap'];
}

return $manager->conversation('claude-sonnet-4')
    ->system('You extract lab values.')
    ->userWithDocument('Extract all values.', $pdfPath)
    ->maxTokens(2048)
    ->send();
```

`->estimate()` returns `input_tokens`, `available_output`, `fits`, `context_window`, `estimated_cost` — enough to decide whether to call.

---

## 10. Bypassing the cache without changing the prompt

If you've enabled `<provider>.cache.response_ttl` but want a forced fresh run, change the temperature or the max-tokens by a fraction:

```php
use Illuminate\Support\Facades\Cache;

// Easiest — change temperature by 0.001 so the cache key changes:
$dynamic = $manager->invoke(
    systemPrompt: 'You are a careful summariser.',
    userMessage: $text,
    maxTokens: 1024,
    temperature: 0.199 + (mt_rand(0, 1000) / 1_000_000), // 0.199000–0.199999
);
```

For explicit invalidation, use the Laravel cache facade directly:

```php
Cache::forget('aws_bedrock_ai_response_'.hash('sha256', "model|$system|$user|1024|0.2"));
```

(Note: build the key identically to how `responseCacheKey()` builds it. Don't invalidate by guessing.)

---

## 11. DTO-free cost claim

```php
// In a job:
$result = $manager->invoke(…);

// Persist just what the audit ledger needs.
$cost = $result['cost'];
$latency = $result['latency_ms'];
$key = $result['key_used'];
$model = $result['model_id'];

DB::table('ai_invocations')->insert([
    'cost' => $cost, 'latency_ms' => $latency,
    'key_used' => $key, 'model_id' => $model,
    'created_at' => now(),
]);
```

The v2.2 roadmap adds an `InvokeResult` readonly DTO so you can type-hint `$result->cost` instead of `$result['cost']`. Today, the array shape is the contract.

---

## Case study — cost impact of v2.1.0

Imagine a hot-path that:

- Uses a 600-token system prompt.
- Receives 1,000 calls per hour from front-line users.
- Each call's user content varies (so the response cache alone doesn't help).

| Layer | Without v2.1.0 | With v2.1.0 |
|---|---|---|
| Bedrock Claude 3.5 Sonnet, cachePoint `system` | $0.003/1k × 700 input × 1,000 = **$2.10/hr** | $0.0003/1k × 100 fresh + $0.00018/1k × 600 cached × 999 = **$0.14/hr** |
| Idempotency-Key on retry | $2.10/hr × 1 % retry rate = +$0.02/hr | $0 (reuses cached upstream response) |
| `Retry-After` honouring | 8 s exponential × 1 % of calls = +5 ms p50 latency | Often <2 s × 1 % = +1 ms p50 latency |

Net: **~$1.98/hr saved per 1k-call hot path**, plus faster recovery on rate-limits. Multiplied by 24 hr and the path volume: **~$47/day saved per hot path**. For a host app with 10 hot paths, that's **~$170k/year** before factoring in embedding savings.

These are illustrative numbers using Anthropic's published 0.003/1k input rate and the 10% cached-prefix rate. Plug your own rates and call patterns in.

---

## License

This document is part of the package and inherits the MIT license.

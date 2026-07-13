# FAQ

> Companion to the [README](../README.md). Distilled answers to questions that come up during package upgrades, integration work, or production debugging.

---

## Installation

**Q: I get "Class 'Ubxty\\CoreAi\\…' not found" after composer install.**

A: Run `composer dump-autoload`. Path repositories (`composer.json` `"repositories": [...]`) sometimes miss regeneration after a fresh clone.

**Q: `php artisan list` doesn't show any `core-ai:*` commands.**

A: That's correct — `core-ai` ships only abstract command bases (for provider packages to extend). Provider packages register their own commands (`bedrock:chat`, `azure:test`, …).

**Q: How do I keep `.env` out of source control but still readable in Laravel?**

A: Standard Laravel answer — `.env.example` is committed with placeholders, real `.env` is gitignored. The package has no opinion on this; env vars are read at boot. For prod, use AWS Secrets Manager + Laravel's `SecretsManager` cache driver, or HashiCorp Vault.

---

## Cost & tokens

**Q: Why is `cost` 0 in the response?**

A: Two reasons:
1. The call was a cache hit (cached responses still fire `AiInvoked` with the original cost, but `cached` is true and `latency_ms` is 0).
2. You didn't pass a `pricing` array. Without it, `calculateCost()` uses the default input/output rates ($0.003 / $0.015 per 1k) — which may round to `0.0` for a 50-token call.

Pass `pricing: ['input_price_per_1k' => 0.003, 'output_price_per_1k' => 0.015]` for accurate cost. Or fetch pricing via `Ubxty\BedrockAi\Pricing\PricingService::getPricing()` and pass it at call time.

**Q: How do I get an exact cost without calling the provider?**

A: `ConversationBuilder::estimate()` returns `{input_tokens, available_output, fits, context_window, estimated_cost}` — a dry-run cost forecast without hitting the wire. Use it in queue gates / pre-flight checks.

**Q: My token counts look wrong for short prompts in non-Latin scripts.**

A: The estimator uses `mb_strlen / 4` characters per token, which is a rough average for English. Languages with low chars-per-token (Hindi/Sanskrit-derived terminology, logographic scripts) will under-estimate; languages with long compound words will over-estimate. The v2.2 roadmap adds a BPE tokenizer option.

**Q: Why did my call get silently clamped to a lower `maxTokens`?**

A: `clampMaxTokens()` resolves the model's `max_tokens` ceiling via `ModelSpecResolver`. If you passed `maxTokens: 16384` to Claude 3 Haiku (capped at 4096), the manager silently downscales to 4096. The actual `maxTokens` value used is logged in `InvocationLogger`. To avoid surprises, call `estimate()` first or pass the correct value.

---

## Caching

**Q: When should I enable `cache.response_ttl`?**

A: When your prompts are deterministic for given inputs and you re-issue the same call frequently. NOT for live chat or any prompt that varies by user input or conversation history. The cache key is `sha256(model|system|user|max|temp)` for `invoke()` and `sha256(model|system|json_messages|max|temp)` for `converse()`.

**Q: How do I know if my call was a cache hit?**

A: `result['cached']` is `true` on a cache hit. `result['latency_ms']` is `0`. The `AiInvoked` event still fires (so dashboards roll up cached + fresh counts).

**Q: My cached response looks stale — how do I invalidate it?**

A: The cache key is content-derived, so changing any of `model`, `system`, `user`, `maxTokens`, `temperature` invalidates naturally. To force a fresh run on the same content, vary `temperature` by `0.001` (or `maxTokens` by `1`). For explicit invalidation:

```php
use Illuminate\Support\Facades\Cache;

$prefix = 'bedrock_ai_response';
$suffix = hash('sha256', "{model}|{system}|{user}|{maxTokens}|{temperature}");
Cache::forget("$prefix_$suffix");
```

**Q: Will the response cache survive a queue worker restart?**

A: Yes — caches use the default Laravel cache store. Set `CACHE_STORE=redis` for production. The file store works for local dev.

**Q: Why does my embedding call re-hits the wire for the same text?**

A: Check `cache.embedding_ttl` — `0` disables. The default is 7 days. The cache key is `sha256(modelId|dimensions|text)`, so any change in those resets the cache.

---

## Authentication & credentials

**Q: I'm rotating Bedrock bearer tokens. Will the package pick up the new token automatically?**

A: The credential manager reads env at request time (via Laravel's config caching layer). After updating `.env`, run `php artisan config:clear` and restart any long-lived queue workers / daemons. The `keys[]` array supports multiple entries for in-process rotation without restart.

**Q: How do I support multi-region Bedrock?**

A: Define multiple keys with different `region` fields. The credential manager rotates through them on rate-limit / auth errors. There's no automatic region preference; the manager picks the first key that succeeds.

**Q: Can I use STS / WebIdentity tokens instead of long-lived credentials?**

A: Yes — set `auth_mode: 'iam'` and use `aws_key` / `aws_secret` (or an STS-derived session token if your tooling writes them). The package uses the AWS SDK's standard credential chain.

---

## Streaming

**Q: I called `converseStream()` and got back an empty array.**

A: `converseStream()` requires `supportsStreaming()` to return `true` for the connection. For Bedrock bearer-mode, this is `false` (Bearer tokens don't support streaming in the same way that IAM credentials do); use IAM mode or the regular `converse()` for chunked UX via the `conversation()` builder's `sendStream()`.

**Q: How is the response of `sendStream` different from `converseStream()`?**

A: Functionally identical. The convenience builder wraps the same call. Use `sendStream()` for fluent composition, `converseStream()` for callback-driven code where you don't need the message history.

---

## Events & observability

**Q: I don't see `AiInvoked` events in my listener.**

A: Make sure the listener is registered (`Event::listen(...)` in `AppServiceProvider::boot()` or via `app/Listeners/` with auto-discovery). If you're using auto-discovery, the class needs a `handle()` method that's typed against the event class:

```php
public function handle(AiInvoked $event): void { … }
```

For `Event::listen` style, the event class is the first argument:

```php
Event::listen(AiInvoked::class, fn (AiInvoked $e) => …);
```

**Q: Why do I get two events per invocation?**

A: The provider package (bedrock-ai, azure-ai) fires both its deprecated BC alias (`BedrockInvoked`, `AzureInvoked`) AND the canonical `AiInvoked`. They both extend `AiInvoked`, so a listener attached to `AiInvoked` will fire once per canonical event — but if you listen on the concrete subclass and the canonical class, you get two events.

To consolidate, listen only on `AiInvoked`.

---

## Errors

**Q: My call throws `RateLimitException` immediately, not after retries.**

A: `RateLimitException` fires after all `keys[]` × `max_retries` combinations have been exhausted. If you see it on the first call, all your keys are likely throttled by the upstream. Check `BEDROCK_DAILY_LIMIT` style env vars too — `CostLimitExceededException` is the one for that.

**Q: `ConfigurationException: "No credential keys configured."` — but my .env has them.**

A: You probably forgot to run `php artisan config:clear` after a `.env` change. Or you placed keys in `config/core-ai.php` directly but `config/core-ai.bedrock.connections.default.keys` is empty. Run `php artisan tinker` → `config('core-ai.bedrock')` to inspect.

**Q: `AiException("Image file exceeds 15 MB limit")` from `userWithImage`.**

A: `ConversationBuilder::userWithImage()` and `userWithDocument()` cap inputs at 15 MB. Resize / compress before attaching. The cap is before the SDK call, so no cost is incurred when it triggers.

---

## Extending

**Q: Can I write a custom provider without extending `AbstractAiManager`?**

A: Yes — implement `AiManagerContract` directly and skip the cost-optimisation pipeline. You'll lose response-cache, max-tokens clamp, idempotency-key, cost tracking, and event dispatching unless you re-implement them. Extending `AbstractAiManager` is the recommended path.

**Q: I extended `AbstractAiManager` but my `perform*` hook throws `TypeError`.**

A: Check the return shape. Every hook must return the documented `array` shape — the manager pipes it through `calculateCost`, `fireInvokedEvent`, and `InvocationLogger::log`. A missing `cost` field defaults to 0; a missing `key_used` produces an `'unknown'` in events.

**Q: My custom exception isn't being caught.**

A: All package exceptions extend `Ubxty\CoreAi\Exceptions\AiException`. Catch `AiException` (the base) or the specific subclass. Don't catch `\Throwable` — the manager uses other runtime exceptions internally.

---

## Misc

**Q: How do I cite this package in a paper?**

A: Not yet — there is no white paper or peer-reviewed citation. Use the GitHub repo URL + version.

**Q: Is this package supported commercially?**

A: Yes. Contact `info.ubxty@gmail.com` for paid support plans.

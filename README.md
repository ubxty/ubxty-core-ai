# ubxty/core-ai

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ubxty/core-ai.svg?style=flat-square)](https://packagist.org/packages/ubxty/core-ai)
[![License](https://img.shields.io/packagist/l/ubxty/core-ai.svg?style=flat-square)](LICENSE)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square)
![Laravel 11|12](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20?style=flat-square)

**Core AI abstraction layer for Laravel.** Owns the contract, the retry trait, the response cache, the token estimator, and the conversation builder that every provider package in the ubxty family (AWS Bedrock, Azure OpenAI, …) extends. Provider-specific logic — SDK wiring, bearer/IAM auth, wire-format caching markers — lives in the provider packages.

This package ships:

- The `AiManagerContract` interface every provider manager implements.
- The `AbstractAiManager` base class with **response-cache**, **max-tokens clamp + fits gate**, **idempotency-key**, **cost-limit enforcement**, **event dispatching**, and **invocation logging** baked into `invoke()` / `converse()` / `converseStream()`.
- The `HasRetryLogic` trait with **key rotation**, **exponential backoff**, and **`Retry-After` honouring**.
- The `ConversationBuilder` fluent API for multi-turn, multimodal conversations.
- The `TokenEstimator`, `ModelSpecResolver`, `ModelAliasResolver` helpers.
- The `InvocationLogger` for structured invocation telemetry.
- Three events (`AiInvoked`, `AiKeyRotated`, `AiRateLimited`) and four exceptions (`AiException`, `ConfigurationException`, `RateLimitException`, `CostLimitExceededException`).
- Abstract command bases so every provider package's CLI has the same UX.

Provider packages built on top:

- [`ubxty/bedrock-ai`](https://packagist.org/packages/ubxty/bedrock-ai) — AWS Bedrock (IAM + bearer/ABSK auth, multi-key failover, prompt-cache checkpoints).
- [`ubxty/azure-ai`](https://packagist.org/packages/ubxty/azure-ai) — Azure OpenAI (Microsoft Foundry v1 + traditional endpoints).

---

## Contents

1. [Installation](#installation)
2. [Why a core package?](#why-a-core-package)
3. [Quickstart](#quickstart)
4. [The contract at a glance](#the-contract-at-a-glance)
5. [AbstractAiManager reference](#abstractaimanager-reference)
6. [Cost Optimisations (v2.1.0+)](#cost-optimisations-v210)
7. [HasRetryLogic trait](#hasretrylogic-trait)
8. [ConversationBuilder](#conversationbuilder)
9. [TokenEstimator](#tokenestimator)
10. [ModelSpecResolver](#modelspecresolver)
11. [ModelAliasResolver](#modelaliasresolver)
12. [InvocationLogger](#invocationlogger)
13. [Events](#events)
14. [Exceptions](#exceptions)
15. [Configuration](#configuration)
16. [Testing](#testing)
17. [Extending core-ai](#extending-core-ai)
18. [Contributing](#contributing)
19. [Security](#security)
20. [Changelog](#changelog)
21. [License](#license)

---

## Installation

```bash
composer require ubxty/core-ai
```

The service provider is auto-discovered through Laravel's package discovery. PHP 8.2+ and Laravel 11 or 12 are required.

To publish the config (optional — the defaults are usable out of the box):

```bash
php artisan vendor:publish --tag=core-ai-config
```

This copies `config/core-ai.php` into your app, where you can customise defaults and add provider blocks (`bedrock`, `azure_ai`, …).

---

## Why a core package?

Writing an AI provider package sounds simple — call the SDK, get a string back. The hard parts show up a week later.

| Pain point | What core-ai gives you |
|---|---|
| Two API keys for two regions. One throttles, the other works. | `AbstractCredentialManager` + `HasRetryLogic` rotate keys automatically, retry on the same key on `429`, and `AiKeyRotated` lets you watch what happened. |
| "We lost $200 in one retry loop because the network blipped between send and ack." | `idempotencyKey()` produces a deterministic content hash. Provider packages inject it as an `Idempotency-Key` HTTP header so retries return the same cached result instead of double-billing. |
| "Why is every request slow even when the prompt is identical?" | Response-cache layer on `invoke()` + `converse()`. Set `<provider>.cache.response_ttl` (under `bedrock` or `azure_ai`) and the SHA256-hashed pair returns instantly. |
| "A caller asked for 16k tokens from a 4k-cap model. We got a 400." | `clampMaxTokens()` silently downscales to the model's ceiling and fits-check against remaining context. No more upstream 400s on mis-sized requests. |
| "The Claude 3.5 Sonnet v2 spec changed again." | `ModelSpecResolver` is a static catalogue keyed on model ID. Bump it once. |
| "Why are our token estimates off by 3×?" | `TokenEstimator` uses 4 chars/token, image flat-budget (1,600 tokens), and base64-bytes-per-token for documents. Multimodal messages are supported. |
| "Every provider command has different flags." | Abstract command bases (`AbstractChatCommand`, `AbstractConfigureCommand`, `AbstractModelsCommand`, `AbstractTestCommand`, `AbstractDefaultModelCommand`) standardise the CLI UX. |
| "We're spending $300/day and nobody noticed." | `CostLimitExceededException` throws when `limits.daily` or `limits.monthly` is hit. |

If you're building your own provider package, extending `AbstractAiManager` and `HasRetryLogic` gets all of the above with ~200 lines of glue code (see the `/docs/extending-core-ai.md` cookbook).

---

## Quickstart

```php
use Ubxty\BedrockAi\BedrockManager;
// — or —
// use Ubxty\AzureAi\AzureManager;

$result = app(BedrockManager::class)->invoke(
    modelId: 'claude-sonnet-4-20250514',  // or your 'default' model
    systemPrompt: 'You are a helpful assistant.',
    userMessage: 'Summarise this in one sentence: …',
    maxTokens: 1024,
    temperature: 0.3,
);

echo $result['response'];
// [
//     'response'      => 'The package adds 6 cost-saving hooks across …',
//     'input_tokens'  => 128,
//     'output_tokens' => 47,
//     'total_tokens'  => 175,
//     'cost'          => 0.0017,
//     'latency_ms'    => 812,
//     'status'        => 'ok',
//     'key_used'      => 'Primary',
//     'model_id'      => 'anthropic.claude-sonnet-4-20250514-v1:0',
// ]
```

Both `BedrockManager` and `AzureManager` extend this package's `AbstractAiManager`. The `invoke()`, `converse()`, and `conversation()` calls are defined here; providers implement the abstract `perform*` hooks.

For a longer walkthrough see [`docs/getting-started.md`](docs/getting-started.md).

---

## The contract at a glance

Every provider manager implements [`Ubxty\CoreAi\Contracts\AiManagerContract`](src/Contracts/AiManagerContract.php).

| Method | Returns | One-line purpose |
|---|---|---|
| `invoke($modelId, $system, $user, $maxTokens, $temp, $pricing, $connection)` | `array` | Single-turn call. Returns response + token counts + cost. |
| `converse($modelId, $messages, $system, $maxTokens, $temp, $connection, $pricing)` | `array` | Multi-turn call. `$messages` is `[['role' => 'user', 'content' => '…'], …]`. |
| `converseStream($modelId, $messages, $onChunk, $system, $maxTokens, $temp, $connection, $pricing)` | `array` | Multi-turn streaming. `$onChunk(string $chunk): void` invoked per token chunk. |
| `conversation($modelId): ConversationBuilder` | `ConversationBuilder` | Fluent API for multi-turn + multimodal. |
| `testConnection($connection?)` | `array{success, message, response_time, model_count?}` | Health probe. |
| `listModels($connection?)` | `array` | Catalog (config or live API). |
| `fetchModels($connection?)` | `array` | Fresh fetch — bypasses cache. |
| `syncModels($connection?): int` | `int` | Returns config-row count (config-only since v2.0; no DB write). |
| `getModelsGrouped($connection?, $context?)` | `array<provider, models[]>` | Grouped by provider with disabled-list filtering. `$context` ∈ `null`, `chat`, `image`. |
| `defaultModel(): string` | `string` | Resolved from `core-ai.{bedrock,azure_ai}.defaults.model`. |
| `defaultImageModel(): string` | `string` | Resolved from `defaults.image_model`. |
| `resolveAlias($aliasOrId): string` | `string` | Alias → full model ID; passes through if unknown. |
| `aliases(): ModelAliasResolver` | `ModelAliasResolver` | Alias registry. |
| `isConfigured($connection?): bool` | `bool` | True when at least one key is set. |
| `getConfig(): array` | `array` | Full resolved config. |
| `getLogger(): InvocationLogger` | `InvocationLogger` | Per-invocation logger. |
| `platformName(): string` | `string` | `"AWS Bedrock"`, `"Azure OpenAI"`, … |
| `supportsStreaming($connection?): bool` | `bool` | False for bearer-only providers. |
| `getCredentialInfo($connection?)` | `array<int, array{index, label}>` | Safe-to-display key list (no secrets). |
| `idempotencyKey($modelId, $content): string` | `string` | v2.1.0 — deterministic hash for `Idempotency-Key` headers. |

All 19 methods are documented in [`/docs/real-world-patterns.md`](docs/real-world-patterns.md) and `/docs/extending-core-ai.md`.

---

## AbstractAiManager reference

`AbstractAiManager` implements the contract and adds the cost-optimisation pipeline. Concrete provider classes (Bedrock, Azure) extend this.

### Public methods added by the base class

| Method | Purpose |
|---|---|
| `idempotencyKey(string $modelId, string $content): string` | Returns `{platform}-sha256(modelId|content)`. Used by provider packages for upstream `Idempotency-Key` headers. |

### Abstract `perform*` hooks (must be implemented by providers)

| Hook | Responsibility |
|---|---|
| `abstract protected performInvoke($modelId, $system, $user, $maxTokens, $temp, $pricing, $connection): array` | Hit the provider SDK and return the standard array. |
| `abstract protected performConverse($modelId, $messages, $system, $maxTokens, $temp, $connection): array` | Multi-turn request. Token counts required. |
| `abstract protected performConverseStream($modelId, $messages, $onChunk, $system, $maxTokens, $temp, $connection): array` | Streaming variant — invoke `$onChunk` per chunk. |
| `abstract public testConnection($connection?): array` | Health-check shape. |
| `abstract public listModels($connection?): array` | Cheap listing (config-cache fallback OK). |
| `abstract public fetchModels($connection?): array` | Fresh fetch — call provider API. |
| `abstract public syncModels($connection?): int` | Config-row count. |
| `abstract public isConfigured($connection?): bool` | At least one key set? |
| `abstract public supportsStreaming($connection?): bool` | Provider supports streaming? |
| `abstract public getCredentialInfo($connection?): array` | Safe-to-display key list. |
| `abstract public platformName(): string` | `"AWS Bedrock"`, `"Azure OpenAI"`, `"OpenAI Compatible"`, … |

### Protected helpers available to subclasses

| Helper | Purpose |
|---|---|
| `cachePrefix(): string` | Lowercased platform name used as cache-key prefix (e.g. `"aws_bedrock_ai"` for `platformName() = "AWS Bedrock"`, `"azure_openai_ai"` for `platformName() = "Azure OpenAI"`). |
| `checkCostLimits(): void` | Called at the top of `invoke()` / `converse()`. Throws `CostLimitExceededException` on hit. |
| `trackCost(float $cost): void` | Called after a successful invocation. Cache-locked daily + monthly. |
| `fireInvokedEvent(array $result): void` | Dispatches `AiInvoked`. |
| `calculateCost(int $inputTokens, int $outputTokens, ?array $pricing): float` | Returns rounded cost. |
| `clampMaxTokens(string $modelId, int $requested, string $concatContentForEstimate = '', int $precomputedInputTokens = 0): array{0:int,1:array,2:int}` | v2.1.0 — silent maxTokens downscale + fits gate. |
| `responseCacheKey($modelId, $system, $user, $maxTokens, $temp): string` | v2.1.0 — SHA256 cache key for `invoke()`. |
| `responseCacheKeyConverse($modelId, $system, $messages, $maxTokens, $temp): string` | v2.1.0 — SHA256 cache key for `converse()`. |
| `getDefaultKey(): array` | First key in the default connection. |
| `getLogger(): InvocationLogger` | Lazy-initialised logger. |

---

## Cost Optimisations (v2.1.0+)

The base manager runs the following pre-flight + cache hooks before any provider call. Each is independently opt-in via config.

### 1. Max-tokens clamp + fits gate

`invoke()` and `converse()` call [`ModelSpecResolver::resolve($modelId)`](src/Models/ModelSpecResolver.php) to fetch the model's `context_window` and `max_tokens` ceiling, then clamp the requested `max_tokens` to the lowest of:

- the model's `max_tokens` cap,
- `(context_window − estimated_input_tokens)`,

so callers can pass generous `maxTokens` values without provoking upstream 400s. Multi-turn calls use `TokenEstimator::estimateMultimodal($messages, $systemPrompt)` for the input estimate.

```php
$result = $manager->invoke(
    'claude-3-haiku-20240307',
    $system,
    $longUserMessage,
    maxTokens: 16384,           // ← Haiku caps at 4096 → silently clamped to 4096
);
```

### 2. Response cache (`<provider>.cache.response_ttl`)

When `core-ai.bedrock.cache.response_ttl > 0` (or the equivalent `core-ai.azure_ai.cache.response_ttl`), identical `(modelId, systemPrompt, userMessage, maxTokens, temperature)` calls return the previous result without hitting the provider. Multi-turn `converse()` hashes the canonical `messages` JSON array. Each provider has its own key — there is no shared root-level fallback.

```php
// config/core-ai.php
'bedrock' => [
    'cache' => [
        'response_ttl' => 3600, // memoise Bedrock responses for 1 hour
    ],
],
'azure_ai' => [
    'cache' => [
        'response_ttl' => 3600, // memoise Azure responses for 1 hour
    ],
],
```

Cache key: `sha256("{platform}_response_{model}|{system}|{user}|{maxTokens}|{temp}")` (single-turn) and the SHA256 of the canonical `messages` JSON (multi-turn).

Cached responses carry:

```php
[
    'response'    => '…',
    'cached'      => true,
    'latency_ms'  => 0,
    // …everything the original result had
]
```

Important: enable only when the prompt is deterministic for a given set of inputs. Chat UIs with rapidly-changing conversation history should leave `response_ttl = 0`.

### 3. Idempotency-Key

`AbstractAiManager::idempotencyKey($modelId, $content)` returns a deterministic hash suitable for upstream `Idempotency-Key` HTTP headers. Provider packages inject this on the Bearer-mode HTTP path so a network-blip retry returns the same cached upstream response instead of double-billing.

```php
$key = $manager->idempotencyKey($modelId, $systemPrompt.$userMessage);
// "aws_bedrock_ai-<sha256 hash>"
```

### 4. Embedding cache (`<provider>.cache.embedding_ttl`)

`core-ai.bedrock.cache.embedding_ttl` and `core-ai.azure_ai.cache.embedding_ttl` (each default **604800** = 7 days) are consumed by `BedrockManager::embed()` and `AzureManager::embed()` to memoise per-text embeddings. Embeddings are deterministic for a fixed model ID + dimensions, so caching eliminates redundant ingestion. Each provider has its own key — there is no shared root-level fallback.

### 5. Cost-limit enforcement

Set `core-ai.{bedrock,azure_ai}.limits.daily` and/or `.monthly` (in USD). When the cumulative spend for the day or month exceeds the limit, `CostLimitExceededException` fires before any provider call.

```php
try {
    $manager->invoke(…);
} catch (CostLimitExceededException $e) {
    // $e->getLimitType()  → 'daily' / 'monthly'
    // $e->getLimit()      → 10.0
    // $e->getCurrentSpend() → 10.42
}
```

### 6. Prompt caching (provider-specific)

The Bedrock provider package implements wire-format caching on top of core-ai:

- **Bedrock** — `cachePoint: { type: 'default' }` markers injected on `Converse` content arrays. Configured via `core-ai.bedrock.prompt_caching.points`.

See [`/docs/caching-strategy.md`](docs/caching-strategy.md) for the full deep-dive including a worked "before/after v2.1.0" cost comparison.

---

## HasRetryLogic trait

[`Ubxty\CoreAi\Client\HasRetryLogic`](src/Client/HasRetryLogic.php) is the shared retry + key-rotation trait. Provider-specific `Client` classes (`use HasRetryLogic;`) get the same behaviour for free.

### Public API

```php
trait HasRetryLogic {
    public function setPromptCachePoints(array $points): static;   // v2.1.1
    public function setRetryAfterSeconds(?int $seconds): static;  // v2.1.1
}
```

`setPromptCachePoints()` accepts `['system', 'last_user']` (other values are silently filtered out).

`setRetryAfterSeconds()` is for the HTTP path to record the upstream `Retry-After` hint before throwing on 429. The trait's `withRetry()` loop prefers the hint over the exponential backoff when set, then consumes one hint per iteration.

### Override hooks (in subclasses)

`HasRetryLogic` defines the retry control flow, but its `onKeyRotated()` and `onRateLimitExhausted()` hooks are empty in core-ai. Provider packages override these hooks when they need to dispatch provider-specific lifecycle events.

| Hook | Override to |
|---|---|---|---|
| `withRetry(string $modelId, callable $callback): array` | The main retry+rotation loop. Most providers don't override this directly — base behaviour is correct. |
| `resolveModelId(string $modelId, array $key): string` | Apply per-key/per-region model transformations (e.g. Bedrock inference profiles). |
| `isRateLimitError(string $message): bool` | Add platform-specific rate-limit fingerprints. |
| `extractFriendlyError(string $errorMessage): string` | Map raw provider errors to user-friendly text. |
| `resetPlatformClient(): void` | Drop a cached SDK client (e.g. when rotating IAM creds). |
| `onKeyRotated(array $fromKey, array $toKey, string $reason, string $modelId): void` | Dispatch an `AiKeyRotated` event (core-ai's default hook is empty). |
| `onRateLimitExhausted(string $modelId, array $key, int $retryAttempt): void` | Dispatch an `AiRateLimited` event (core-ai's default hook is empty). |
| `calculateCost(int $in, int $out, ?array $pricing): float` | Provider-specific cost math. |

### Default behaviour

`withRetry()`:

```php
for each key in credentials (N keys):
    for attempt = 0..maxRetries:
        try:
            return callback(currentKey)
        except exception e:
            if isRateLimitError(e) and attempt < maxRetries:
                if retryAfterSeconds is set:
                    sleep(retryAfterSeconds)   # honour upstream hint
                else:
                    sleep(baseDelay ** attempt) # exponential
                continue
            resetPlatformClient()
            if credentials.next():
                onKeyRotated(from, to, reason, modelId)
                break  # try next key
            if isRateLimitError(e):
                onRateLimitExhausted(modelId, key, attempt)
                throw RateLimitException
            throw AiException
throw AiException('All credential keys exhausted.')
```

---

## ConversationBuilder

[`Ubxty\CoreAi\Conversation\ConversationBuilder`](src/Conversation/ConversationBuilder.php) is a fluent wrapper around `converse()` for multi-turn, multimodal flows.

```php
use App\Ai\MyManager;

$result = app(MyManager::class)
    ->conversation('claude-sonnet-4')
    ->system('You are a careful reader.')
    ->user('Here is a contract. List the unusual clauses.')
    ->userWithDocument('Anything you missed?', '/tmp/contract.pdf')
    ->assistant('…prior assistant turn…')
    ->maxTokens(2048)
    ->temperature(0.1)
    ->withPricing(['input_price_per_1k' => 0.003, 'output_price_per_1k' => 0.015])
    ->connection('us')
    ->send();

echo $result['response'];
```

### Fluent methods

| Method | Purpose |
|---|---|
| `system(string $prompt): static` | Sets the system prompt. |
| `user(string $message): static` | Appends a user turn. |
| `userWithImage(string $prompt, string $source, string $format = 'auto'): static` | `$source` is a filesystem path **or** already-base64 data. `format` ∈ `jpeg\|png\|gif\|webp\|auto`. 15 MB hard cap. |
| `userWithDocument(string $prompt, string $source, string $format = 'auto', string $name = ''): static` | Same shape. `format` ∈ `pdf\|csv\|doc\|docx\|xls\|xlsx\|html\|txt\|md\|auto`. |
| `assistant(string $message): static` | Appends a prior assistant turn for few-shot context. |
| `maxTokens(int): static` | Output budget. Subject to clamp + fits gate. |
| `temperature(float): static` | Sampling temperature. |
| `withPricing(array $pricing): static` | Provide `input_price_per_1k` + `output_price_per_1k` so cost math is exact. |
| `connection(string $connection): static` | Switch to a named connection. |
| `send(): array` | Blocking call. Appends the assistant reply to the message history. |
| `sendStream(callable $onChunk): array` | Streaming call. `$onChunk(string $chunk): void`. |
| `estimate(): array{input_tokens, available_output, fits, context_window, estimated_cost}` | Dry-run; returns the same shape `TokenEstimator::estimateInvocation()` plus `estimated_cost`. |
| `getMessages(): array` | Current message history. |
| `getSystemPrompt(): string` | Current system prompt. |
| `getModelId(): string` | Resolved model ID (alias already resolved). |
| `reset(): static` | Wipes messages; keeps system + settings. |
| `setMessages(array $messages): static` | Replace the whole history (use for error recovery). |

`send()` returns the same `array{response, input_tokens, output_tokens, total_tokens, stop_reason, latency_ms, model_id, key_used, cost}` as `converse()`. The builder appends the assistant reply to its in-memory history; if you `->send()` twice, the second call sees the first reply as a prior assistant turn.

For image and document files larger than 15 MB, the builder throws `AiException` before any HTTP call. Resize / compress first.

---

## TokenEstimator

[`Ubxty\CoreAi\Support\TokenEstimator`](src/Support/TokenEstimator.php) is a static helper with rough-but-cheap heuristics.

| Static method | Returns | Notes |
|---|---|---|
| `estimate(string $text): int` | int | `ceil(mb_strlen / 4)`. Empty → 0. |
| `estimateMultimodal(array $messages, string $systemPrompt = ''): int` | int | `text → estimate()`, `image → 1600 tokens` flat-budget, `document → ceil(base64_bytes / 750)`. |
| `estimateInvocation(string $system, string $user, string $modelId, int $maxOutputTokens = 4096): array{input_tokens, available_output, fits, context_window}` | array | `fits` is `input_tokens + maxOutputTokens <= context_window`. |
| `estimateCost(string $system, string $user, int $expectedOutputTokens = 1000, ?array $pricing = null): float` | float | Default input price $0.003/1k, output $0.015/1k. Overrides via `$pricing`. |

```php
use Ubxty\CoreAi\Support\TokenEstimator;

$tokens = TokenEstimator::estimateMultimodal(
    [
        ['role' => 'user', 'content' => [
            ['type' => 'image', 'format' => 'jpeg', 'data' => $b64],
            ['type' => 'text', 'text' => 'What is in this image?'],
        ]],
    ],
    'You are a vision model.'
);

// int(1700) — 1600 for image + ~100 for system.
```

> **Note** — the heuristic is intentionally character-based for cross-lingual robustness. Replacing it with a real BPE tokenizer is on the v2.2 roadmap.

---

## ModelSpecResolver

[`Ubxty\CoreAi\Models\ModelSpecResolver`](src/Models/ModelSpecResolver.php) is a static catalog of `context_window` + `max_tokens` for every supported model. The manager calls it during max-tokens clamp.

| Static method | Returns | Notes |
|---|---|---|
| `resolve(string $modelId): array{context_window: int, max_tokens: int}` | array | Falls back to `['context_window' => 128000, 'max_tokens' => 4096]` for unknown IDs. |
| `inputModalities(string $modelId): array<int, string>` | string[] | `text`/`image`/`document` flags. |
| `supportsModality(string $modelId, string $modality): bool` | bool | Convenience predicate. |
| `families(): array<string, array{name, context_window, max_tokens}>` | array | Canonical family list. |

Supported families: `claude-3.5`, `claude-4`, `gpt-4o`, `gpt-4`, `gpt-3.5`, `nova`, `titan`, `llama-3`, `llama-4`, `mistral`, `cohere`, `jamba`. See source for the per-model specs.

Override per-model defaults by adding entries to `core-ai.bedrock.models` or `core-ai.azure_ai.models` in your published config — the resolver still wins when the catalog has no match.

---

## ModelAliasResolver

[`Ubxty\CoreAi\Client\ModelAliasResolver`](src/Client/ModelAliasResolver.php) is a tiny alias registry.

```php
$resolver = new ModelAliasResolver([
    'sonnet' => 'anthropic.claude-sonnet-4-20250514-v1:0',
    'mini'   => 'amazon.nova-micro-v1:0',
]);

$resolver->resolve('sonnet');         // 'anthropic.claude-sonnet-4-20250514-v1:0'
$resolver->resolve('mystery-model');  // 'mystery-model' (pass-through)
$resolver->isAlias('sonnet');         // true
$resolver->register('haiku', 'anthropic.claude-3-5-haiku-20241022-v1:0');
$resolver->all();                     // ['sonnet' => '…', 'mini' => '…', 'haiku' => '…']
```

Aliases are seeded from `core-ai.{bedrock,azure_ai}.aliases` config. The manager exposes the resolver via `aliases()` and auto-resolves through `resolveAlias()` on every invocation.

---

## InvocationLogger

[`Ubxty\CoreAi\Logging\InvocationLogger`](src/Logging/InvocationLogger.php) writes structured records to a Laravel log channel.

| Method | Channel | Records |
|---|---|---|
| `log(array $result)` | info `AI invocation` | `model_id, input_tokens, output_tokens, total_tokens, cost, latency_ms, status, key_used` |
| `logError(string $modelId, string $error, ?string $keyLabel)` | error `AI invocation failed` | model + error + key |
| `logRateLimit(string $modelId, string $keyLabel, int $attempt, int $waitSeconds)` | warning `AI rate limited` | model + key + attempt + wait |
| `isEnabled(): bool` / `getChannel(): string` | — | accessors |

Configure via the per-provider block — `core-ai.bedrock.logging` or `core-ai.azure_ai.logging`. The top-level `core-ai.logging` is **not** read by the manager code:

```php
'bedrock' => [
    'logging' => [
        'enabled' => true,
        'channel' => 'stack', // or 'ai', 'daily', …
    ],
],
'azure_ai' => [
    'logging' => [
        'enabled' => true,
        'channel' => 'stack',
    ],
],
```

The logger never logs the `response` body — only counters and metadata. Pair with a custom channel that writes to your audit DB or SIEM for full observability.

---

## Events

Core-ai defines three `Dispatchable` event payloads. `AbstractAiManager` dispatches `AiInvoked` after successful invocations. The default `HasRetryLogic` hooks for key rotation and rate-limit exhaustion are empty; provider packages decide whether to dispatch `AiKeyRotated` and `AiRateLimited` from their overrides.

### `AiInvoked`

```php
new AiInvoked(
    modelId: 'anthropic.claude-sonnet-4-20250514-v1:0',
    inputTokens: 128,
    outputTokens: 47,
    cost: 0.0017,
    latencyMs: 812,
    keyUsed: 'Primary',
    connection: 'default',   // optional
    platform: 'AWS Bedrock', // optional
);
```

Fires after every successful invocation — including cached hits. Pair with a listener that writes to a `usage` table for tenant-level dashboards.

### `AiKeyRotated`

```php
new AiKeyRotated(
    fromKeyLabel: 'Primary',
    toKeyLabel:   'Secondary',
    reason:       '429: Too many requests',
    modelId:      'anthropic.claude-sonnet-4-20250514-v1:0',
    platform:     'AWS Bedrock',
);
```

Provider packages may dispatch this from an `onKeyRotated()` override. Core-ai's default hook is empty.

### `AiRateLimited`

```php
new AiRateLimited(
    modelId:      'anthropic.claude-sonnet-4-20250514-v1:0',
    keyLabel:     'Primary',
    retryAttempt: 3,
    waitSeconds:  17,
    platform:     'AWS Bedrock',
);
```

Provider packages may dispatch this from an `onRateLimitExhausted()` override. Core-ai's retry loop does not dispatch it before retry sleeps, and the default exhaustion hook is empty.

---

## Exceptions

| Exception | When it's thrown |
|---|---|
| `AiException` | Base — every package exception extends this. Carries `modelId` + `keyLabel`. |
| `ConfigurationException extends AiException` | No keys configured, invalid key, no default model. |
| `RateLimitException extends AiException` | All keys exhausted on rate-limit. |
| `CostLimitExceededException extends AiException` | Daily or monthly spend cap breached. Carries `limitType`, `limit`, `currentSpend`. |
| `AiException("Image file exceeds 15 MB limit…")` | `ConversationBuilder::userWithImage()` / `userWithDocument()` cap. |

```php
use Ubxty\CoreAi\Exceptions\RateLimitException;
use Ubxty\CoreAi\Exceptions\CostLimitExceededException;

try {
    return $manager->invoke(…);
} catch (CostLimitExceededException $e) {
    return response()->json([
        'error'  => 'daily_limit',
        'limit'  => $e->getLimit(),
        'spent'  => $e->getCurrentSpend(),
    ], 402);
} catch (RateLimitException $e) {
    return response()->json(['error' => 'temporarily_busy'], 503);
}
```

---

## Configuration

The full `config/core-ai.php` ships with these top-level keys. **Provider packages add their own top-level blocks** (`bedrock`, `azure_ai`); see the provider docs for those.

| Key | Default | Purpose |
|---|---|---|
| `retry.max_retries` | `3` | Per-key retry count inside `withRetry()`. |
| `retry.base_delay` | `2` | Backoff base — `baseDelay ** attempt` seconds. |
| `cache.models_ttl` | `3600` | How long `listModels()` results stay cached. |
| `cache.usage_ttl` | `900` | `UsageTracker::calculateCosts()` cache window. |
| `cache.pricing_ttl` | `86400` | `PricingService::getPricing()` cache window. |
| `bedrock.cache.response_ttl` / `azure_ai.cache.response_ttl` | `0` | v2.1.0 — memoise `invoke`/`converse` responses. `0` disables. Per-provider. |
| `bedrock.cache.embedding_ttl` / `azure_ai.cache.embedding_ttl` | `604800` | v2.1.0 — 7 days. Embeddings are deterministic and expensive. Per-provider. |
| `bedrock.cache.billing_ttl` | `3600` | Bedrock only — Cost Explorer billing cache window. |
| `bedrock.logging.enabled` / `azure_ai.logging.enabled` | `false` | Enable the invocation logger. Per-provider. |
| `bedrock.logging.channel` / `azure_ai.logging.channel` | `stack` | Any configured Laravel log channel. Per-provider. |

Both provider blocks (`core-ai.bedrock.*`, `core-ai.azure_ai.*`) include `default`, `connections` (with nested `keys`), `retry`, `limits`, `cache`, `providers.disabled_providers`, `providers.chat.disabled_providers`, `providers.image.disabled_providers`, `defaults`, `aliases`, `models`, `logging`, and `health_check`. Bedrock additionally includes `pricing`, `usage`, and `prompt_caching`; those keys are not part of the Azure block. See each provider's README for the full reference.

> **Don't dump the config file in your README.** Read [`ubxty/bedrock-ai` README § Configuration](https://github.com/ubxty/ubxty-bedrock-ai) for the canonical shape; copying it here would diverge on the next bump.

---

## Testing

The abstract manager exists for testability. Bind a fake manager in the container and the rest of your test suite does not need to know which provider is wired:

```php
use Ubxty\CoreAi\Contracts\AiManagerContract;
use Ubxty\CoreAi\Manager\AbstractAiManager;

class FakeManager extends AbstractAiManager
{
    public array $calls = [];
    public array $stub = [
        'response' => 'stub',
        'input_tokens' => 0, 'output_tokens' => 0,
        'cost' => 0, 'latency_ms' => 0,
        'status' => 'ok', 'key_used' => 'fake',
        'model_id' => 'fake-model',
    ];

    public function invoke(…): array
    {
        $this->calls[] = func_get_args();
        return $this->stub;
    }

    // … implement the other abstract hooks …
    protected function performInvoke(…) { return $this->stub; }
    protected function performConverse(…) { return $this->stub; }
    protected function performConverseStream(…) { return $this->stub; }

    public function testConnection(?string $c = null): array { return ['success' => true, 'message' => 'fake', 'response_time' => 0]; }
    public function listModels(?string $c = null): array { return []; }
    public function fetchModels(?string $c = null): array { return []; }
    public function syncModels(?string $c = null): int { return 0; }
    public function isConfigured(?string $c = null): bool { return true; }
    public function supportsStreaming(?string $c = null): bool { return false; }
    public function getCredentialInfo(?string $c = null): array { return []; }
    public function platformName(): string { return 'Fake'; }
}

beforeEach(function () {
    app()->instance(AiManagerContract::class, new FakeManager(['defaults' => ['model' => 'fake']]));
});
```

---

## Extending core-ai

For a complete walkthrough — building your own provider from scratch — see [`docs/extending-core-ai.md`](docs/extending-core-ai.md). The cookbook covers:

1. Why `AbstractAiManager` is the right base.
2. The 11 abstract hooks, with stubs.
3. Implementing `AbstractCredentialManager` for your auth model.
4. Wiring `HasRetryLogic` into your client classes.
5. Mapping your SDK's response into the standard `array`.
6. Registering the service provider, merging config, optional facade.
7. An 80-line "hello provider" template (no SDK).

For real-world patterns — embedding pipelines, multi-tenant providers, RAG pipelines, streaming responses, cost-cap listeners — see [`docs/real-world-patterns.md`](docs/real-world-patterns.md).

---

## Contributing

Open a PR on the `main` branch. Match existing code style:

- PHP 8.2+ syntax (`readonly`, `match`, named args).
- Strict-typing on all new public methods — no `mixed` returns unless documented.
- Tests are out of scope for this project (see the host-app convention). The maintainer verifies regressions by hand against the rubric report.

---

## Security

- Provider keys are never logged, never serialised through configuration dumps. `getCredentialInfo()` returns `{index, label}` only.
- Bearer tokens are loaded from env at request time, not committed to `config/core-ai.php`.
- The `ConversationBuilder` 15 MB cap on image/document payloads prevents accidental OOM at the SDK layer — bigger inputs go to the SDK but never blow past that cap.

Report vulnerabilities via `info.ubxty@gmail.com`. PGP key on request.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md). Past minor versions:

- **2.1.3** — ConversationBuilder helpers plus corrections that bring the v2.1.2 docs in line with the shipped config and event hooks.
- **2.1.2** — Documentation overhaul. No API changes.
- **2.1.1** — `setPromptCachePoints()` + `setRetryAfterSeconds()` on the canonical `HasRetryLogic` trait; `Retry-After` honouring.
- **2.1.0** — `clampMaxTokens()`, response-cache layer, `idempotencyKey()`. New cache TTL knobs (`response_ttl`, `embedding_ttl`). Bedrock `prompt_caching` config block.
- **2.0.0** — Unified `core-ai` namespace. `bedrock`/`azure_ai` config blocks now live under one file.
- **1.0.0** — Initial release extracted from `ubxty/bedrock-ai`.

---

## License

MIT — see [LICENSE](LICENSE).

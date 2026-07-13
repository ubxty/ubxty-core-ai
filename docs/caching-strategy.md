# Caching Strategy

> Companion to the [README](../README.md). Covers every cache layer in core-ai + the provider-specific prompt-caching layers. Read once; refer back whenever you tune cost.

---

## Why caching matters here

Large-language-model APIs charge per token. Every repeated read, every retry, and every byte that crosses the wire burns dollars. The package bakes four classes of cache into one pipeline:

1. **Provider-discovery caches** (`models`, `usage`, `pricing`) — meta caches. Mostly time-of-day deterministic.
2. **Response cache** (v2.1.0) — application-level memoisation of `invoke`/`converse` results.
3. **Embedding cache** (v2.1.0) — memoisation of `embed()` per text.
4. **Provider prompt-cache** (v2.1.0) — wire-format markers that the upstream provider honours for cheaper input tokens.

Plus one HTTP-layer safety net:

5. **`Retry-After` honouring** — capture upstream rate-limit hints in the HTTP path, prefer over exponential backoff in `withRetry()`.

This guide is dense by design. Use the table of contents.

---

## 1. The cache key contract

Every cache layer uses SHA256 content hashes so cache keys are deterministic across processes, queues, and machines. Hit the cache with the same `(modelId, systemPrompt, userMessage, maxTokens, temperature)` tuple; you get a hit. Change any field; cache miss.

| Layer | Key shape | Value shape |
|---|---|---|
| `models_ttl` | `{platform}_models_*` | Provider model catalog. |
| `usage_ttl` | `{platform}_usage_*` | Aggregated usage metrics. |
| `pricing_ttl` | `{platform}_pricing_*` | Pricing tier table. |
| `response_ttl` (v2.1.0) | `{platform}_response_{sha256(model\|system\|user\|max\|temp)}` | Full invoke result. |
| `response_ttl` (multi-turn) | `{platform}_response_{sha256(model\|system\|json_messages\|max\|temp)}` | Full converse result. |
| `embedding_ttl` | `{platform}_embeddings_{sha256(modelId\|dimensions\|text)}` | `{text: float[]}` row. |
| `idempotency-key` (v2.1.0) | `{platform}-{sha256(modelId\|content)}` | Not stored — passed upstream. |

`{platform}` is the lowercased `platformName()` output (`"bedrock_ai"`, `"azure_ai"`), which is why cache keys for one provider don't collide with another.

---

## 2. Response cache (`cache.response_ttl`, v2.1.0)

The single most impactful cost lever for deterministic prompts.

### When to enable

- Re-ingestion of structured data (CSV → JSON, JSON → JSON).
- Templated replies (summarise this report, classify this support ticket).
- Anywhere the same `(model, system, user, max, temp)` is sent more than a few times per TTL window.

### When NOT to enable

- Live chat with rolling history.
- Streaming responses (the package deliberately does not memoise streaming — `converseStream()` bypasses the cache).
- Any prompt whose target output mutates per call (random seed, current date).

### Configuration

```php
// config/core-ai.php
'cache' => [
    'response_ttl' => 3600, // memoise for 1 hour
],
```

Set `0` to disable (the default).

### Bypassing the cache

Two options:

1. Set `cache.response_ttl` to `0` for that environment.
2. Send a unique request — change `userMessage`, `temperature`, or `maxTokens` by even one unit. The SHA256 hash will not match and the cache miss path runs.

### Cost math

For a static system prompt + 1k users each making 10 calls in an hour, all hitting the same prompt:

- Without cache: 10,000 model calls × full input rate.
- With cache: 1 model call × full rate + 9,999 hits × **0** cost (zero SDK round-trip, zero tokens billed).

---

## 3. Embedding cache (`cache.embedding_ttl`, v2.1.0)

Embeddings are deterministic — same model + same text + same dimensions = same vector. The package memoise them per `(modelId, dimensions, sha256(text))` for 7 days by default (`604800` seconds).

```php
// BedrockManager / AzureManager
$vectors = $manager->embed('amazon.titan-embed-text-v2:0', $corpus, dimensions: 1024);

// First call: hits AWS Bedrock InvokeModel for every text. Caches rows.
// Second call (within TTL) for the same text: cache hit, zero provider spend.
```

Per-row keys mean you can mix and match: append one text to a 1M-row corpus, only the new row touches the wire.

### When to invalidate

You almost never need to. If you change models or dimensions, the `modelId` + `dimensions` segment of the key changes and the old rows simply go cold. A 7-day TTL on stale rows is harmless.

If a particular row's source text has changed (you have an updated version), call `embed()` again with the new text — its hash differs and a new row is written.

### Bumping the TTL

Set `cache.embedding_ttl` in your published config:

```php
'cache' => [
    'embedding_ttl' => 30 * 24 * 3600, // 30 days, for slow-moving corpora
],
```

---

## 4. Provider prompt caches (v2.1.0)

Prompt caching is a wire-format feature in the upstream provider SDK. The package injects markers at the configured anchor points; the provider charges ~10% of the normal input-token rate for any prefix that matches a recent marker.

### Bedrock (`cachePoint`)

```php
// config/core-ai.php
'bedrock' => [
    'prompt_caching' => [
        'points' => ['system', 'last_user'],
        'ttl_seconds' => 300, // 5 min, max 3600 (1 hour)
    ],
],
```

Anchors:

- `system` — inject a `cachePoint: { type: 'default' }` block after the system-prompt content.
- `last_user` — inject a block after the last user-message content.

Up to 4 cache-points per Converse request (Bedrock limit). The package only adds anchors you configure, so 2 points is well within budget.

> **400 on unsupported models.** Bedrock returns a 400 if a `cachePoint` is placed on a model that doesn't support prompt caching. If you switch models and start seeing `400 cachePoint is not supported for this model`, drop the cache-points from config.

### Azure (`cache_control: { type: 'ephemeral' }`)

```php
'azure_ai' => [
    'prompt_caching' => [
        'points' => ['system', 'last_user'],
    ],
],
```

Same anchors. The Azure OpenAI `cache_control` marker is set as `ephemeral` — Azure revalidates on every stream (Azure-side default, not configurable by client).

Up to 4 breakpoints per chat-completion call.

### Cost math (worked)

Suppose a Claude 3.5 Sonnet call has:

- System prompt: 600 tokens.
- User message: 100 tokens.
- Output: 200 tokens.

Without prompt cache, the input bill is 700 × $0.003/1k = $0.0021.

With `cachePoint` at the `system` anchor and the system prompt being identical across many calls:

- First call: 700 input tokens × full rate.
- Subsequent calls within the cache window: 100 × full rate + 600 × **10% of** full rate = $0.0003 + $0.00018 = $0.00048.

> ~77% off the input-token bill on cached calls. The output-token bill is unaffected.

---

## 5. `Retry-After` honouring (v2.1.1)

When the upstream returns `429: Too Many Requests`, most AWS / Azure deployments include a `Retry-After` header in seconds. The package captures it in the HTTP path:

```
HTTP 429 + Retry-After: 17
  → HTTP path captures 17 → setRetryAfterSeconds(17)
  → withRetry() sees hint, sleeps 17s, clears hint, retries
  → next attempt: if it also 429s, it asks again
```

If no `Retry-After` is present (or the previous hint was consumed), `withRetry()` falls back to `baseDelay ** attempt`:

```
attempt 0 → 2 s
attempt 1 → 4 s
attempt 2 → 8 s
```

Effective wait times after a real 429:

- With hint: often `5–30 s` — the provider's actual cooldown.
- Without hint: `2 s → 4 s → 8 s` — risk of re-429.

The `Retry-After` path typically recovers in seconds where the exponential path would need 14+ s and still fail.

### Tuning

```php
'retry' => [
    'max_retries' => 3,
    'base_delay'  => 2, // seconds
],
```

Provider packages expose their own env-bridged overrides:

```dotenv
BEDROCK_MAX_RETRIES=3
BEDROCK_RETRY_DELAY=2
AZURE_OPENAI_MAX_RETRIES=3
AZURE_OPENAI_RETRY_DELAY=2
```

---

## 6. Putting it all together

For a high-volume pipeline (1M calls/day, 600-token system prompt, 100-token user message):

| Lever | Expected impact | Effort |
|---|---|---|
| `cachePoint` on `system` | ~77% off input rate on cached calls | 1 line in config |
| `cache.response_ttl` for deterministic prompts | 100% off on cache hits | 1 line in config |
| `cache.embedding_ttl` for ingestion | One-time cost per text | 1 line in config |
| Idempotency-Key for retry safety | Eliminates double-billing | Automatic |
| `Retry-After` honouring | Faster recovery on real rate-limit | Automatic |

For the canonical "before vs after v2.1.0" comparison, see [`/docs/real-world-patterns.md`](real-world-patterns.md#case-study-cost-impact-of-v210).

---

## 7. Cache store

All caches use the default Laravel cache store. Switch to Redis or Memcached the same way you would for any other Laravel cache:

```dotenv
CACHE_STORE=redis
```

The response-cache and embedding-cache layers store row-sized entries (a few KB at most per row). Redis is recommended for production; the file driver works for local development.

# Changelog

All notable changes to `ubxty/core-ai` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.1.3] - 2026-07-14

### Documentation
- README.md L280: removed the `core-ai.azure_ai.prompt_caching.points` claim (no such key in config).
- README.md L586: removed `billing` from the "provider-specific keys" list (no such key).
- README.md L148/L165/L187/L254: corrected `cachePrefix()` examples from `"bedrock_ai"` to `"aws_bedrock_ai"` to match `platformName() = "AWS Bedrock"`.
- README.md L192: corrected parameter name to `concatContentForEstimate`.
- README.md L679: added cross-link noting v2.1.2 doc-only corrections (this release).
- docs/events-and-listeners.md: distinguished core-ai's empty `HasRetryLogic` hooks from provider-package overrides that actually dispatch `AiKeyRotated` / `AiRateLimited`.
- docs/getting-started.md: added the `composer require ubxty/bedrock-ai` line before the `BedrockManager` `use`.
- docs/caching-strategy.md: clarified `$manager->embed(...)` is a provider-package method, not part of the `AiManagerContract`.
- docs/caching-strategy.md: clarified provider-package events vs canonical `AiInvoked`.

### Added (ConversationBuilder)
- `userWithDocuments(string $prompt, array $documents)` — multi-document single-message helper. Each $documents entry is a string path or assoc `{path, format?, name?}`.
- `userWithAttachments(string $prompt, array $attachments)` — mixed image/document attachments in a single user message. Each entry is assoc `{type:'image'|'document', path, format?, name?}`.
- `image(string $source, string $prompt = '', string $format = 'auto')` — shorthand for `userWithImage()`.
- `model(string $modelId)` — overrides the model ID mid-build.
- `history(array $messages)` — appends every entry to the running conversation (use `setMessages()` to replace).
- `schema(array $jsonSchema)` — request a JSON response conforming to a JSON Schema; appended to the system prompt as an instruction (advisory, not a wire-format constraint).
- `stream(callable $onChunk): array` — alias for `sendStream()`; same assembled result array.
- `getSchema(): ?array` — accessor.

### Changed
- `userWithImage()` and `userWithDocument()` now route through private `readAsBase64()` / `resolveImageFormat()` / `resolveDocumentFormat()` helpers — no behaviour change for callers; empty-file guard added to both.
- `send()` and `sendStream()` now use the schema-augmented system prompt when `schema()` was set (no change when schema is null).

### Notes
- New methods are additive; nothing changed in the existing public API.
- The ConversationBuilder changes ship as a feature-level add-on; the doc fixes are independent corrections that the docs were ahead of the code on.

---

## [2.1.2] - 2026-07-13

### Documentation
- **`README.md`** — full rewrite. Replaces the 103-line quickstart with a 600-line reference covering the contract, the abstract manager, the retry trait, the conversation builder, token estimation, alias + spec resolvers, invocation logging, events, exceptions, configuration, testing, and a guidance "Why a core package?" section.
- **`docs/getting-started.md`** — end-to-end quickstart from `composer require` to first successful invocation, with example env-var sets for both Bedrock auth modes and Azure OpenAI.
- **`docs/caching-strategy.md`** — full deep-dive on the v2.1.x cache layers (response, embedding, prompt-cache via the provider packages) plus `Retry-After` honouring. Includes a worked cost-saving math example.
- **`docs/extending-core-ai.md`** — recipe for writing a provider package that extends `AbstractAiManager` and `AbstractCredentialManager`. ~200 lines of glue code; full 80-line "hello provider" walkthrough.
- **`docs/real-world-patterns.md`** — 11 patterns distilled from multi-tenant host apps: tenant-aware dispatch, retry safety, multi-turn + multimodal workflows, structured JSON extraction, cost-cap listeners, key-rotation alerting, idempotent queue workers, multi-key round-robin, cost-aware image extraction, cache-bypass, and a v2.1.0 cost-impact case study.
- **`docs/events-and-listeners.md`** — full payload reference for `AiInvoked`, `AiKeyRotated`, `AiRateLimited`, plus ready-to-use listener templates (usage ledger, pager, SLO counter), BC-alias guidance, ordering guarantees.
- **`docs/faq.md`** — 30+ short entries spanning installation, cost/tokens, caching, authentication, streaming, events, errors, and extension recipes.

### Notes
- No source-code changes. No BC implications for downstream consumers.
- All code examples are derived from how host apps actually use the package; tenant-specific identifiers are abstracted to placeholders.

---

## [2.1.1] - 2026-07-13

### Added
- **`HasRetryLogic::setPromptCachePoints()` / `setRetryAfterSeconds()`** — public setters on the canonical (core-ai) trait, so platform clients (azure-ai) get the same hook surface as bedrock-ai. Bedrock-ai had its own local copy in 2.1.0; this patch unifies it in core-ai.

### Changed
- **`HasRetryLogic::withRetry()`** now honours the captured `Retry-After` hint (set by the HTTP path before throwing on a 429) in preference to the exponential backoff. Each retry iteration consumes one hint. Bedrock-ai's local copy was already updated in 2.1.0; this aligns core-ai's canonical trait.

### Notes
- Patch release — v2.1.0 ships the AbstractAiManager hooks (response cache, max-tokens clamp, idempotency key) and the `bedrock.prompt_caching` config block; v2.1.1 finishes the platform-client hooks in the shared retry trait. Both versions are fully compatible.

---

## [2.1.0] - 2026-07-13

### Added
- **`AbstractAiManager::clampMaxTokens()`** — pre-flight guard that resolves the model's spec via `ModelSpecResolver` and clamps the requested `max_tokens` to (a) the model's `max_tokens` ceiling, then (b) whatever the remaining context window can hold after the input prompt. Called inside `invoke()` and `converse()` before any provider call, so callers can safely pass `maxTokens: 16384` and trust the manager to downscale.
- **Response-cache layer** on `invoke()` and `converse()`. When `core-ai.cache.response_ttl > 0`, identical `(modelId, systemPrompt, userMessage, maxTokens, temperature)` calls return the previous result without hitting the provider. Hash is `sha256(model|system|user|max|temp)` keyed under `{platform}_ai_response_*`. Multi-turn `converse()` hashes the canonical `messages` JSON array. Cached responses set `cached: true` and `latency_ms: 0`.
- **`AbstractAiManager::idempotencyKey()`** — public helper that returns a deterministic hash suitable for upstream `Idempotency-Key` headers. Same content-hash strategy as the response cache, so a retried request and its original map to the same key.
- **Two new cache TTL config knobs** under `core-ai.cache`:
  - `response_ttl` (int, default `0`) — seconds to memoise `invoke` / `converse` responses. `0` disables.
  - `embedding_ttl` (int, default `604800` = 7 days) — TTL for embedding responses; embeddings are deterministic and expensive.
- **`bedrock.prompt_caching` config block** under the bedrock provider section. `points` is a list of named anchors (`system`, `last_user`) where `ubxty/bedrock-ai` injects `cachePoint: { type: 'default' }` markers on the Converse content array. `ttl_seconds` controls the upstream cache window (default 300s, max 3600s). Empty `points` disables. See [`ubxty/bedrock-ai` v2.1.0 changelog](https://github.com/ubxty/bedrock-ai/blob/main/CHANGELOG.md) for the runtime behaviour.

### Changed
- `invoke()` and `converse()` now resolve the model's alias, run the max-tokens clamp + fits check, and consult the response cache *before* any provider call. The contract return shape is unchanged.
- `converse()` estimates total prompt tokens using `TokenEstimator::estimateMultimodal($messages, $systemPrompt)` for the fits check, instead of only counting characters in a single string.
- `cache.models_ttl`, `cache.usage_ttl`, `cache.pricing_ttl` remain as defaults shared across providers; the two new TTLs are additive.

### Notes
- All additions are backward-compatible. The response cache is opt-in (set `cache.response_ttl` in your published config or override at the manager level). Prompt-cache checkpoints are opt-in via `bedrock.prompt_caching.points`. Existing v2.0.0 consumers keep working unchanged.

---

## [2.0.0] - 2026-07-13

### BREAKING CHANGES

- **Unified config namespace.** Provider-specific config blocks (`bedrock`, `azure_ai`) are now consolidated into `config/core-ai.php`. The single publishable config file is `core-ai.php` — installed providers (bedrock-ai, azure-ai) read their settings from `config('core-ai.bedrock.*')` and `config('core-ai.azure_ai.*')`.
- **Host apps that published the old per-provider configs** (`vendor:publish --tag=bedrock-config` or `--tag=azure-ai-config`) must republish via `vendor:publish --tag=core-ai-config` and merge their customisations into the new top-level structure.
- **Host apps referencing `config('bedrock.*')` or `config('azure-ai.*')` in their own code** must update to `config('core-ai.bedrock.*')` / `config('core-ai.azure_ai.*')`.
- **Publish tags** `bedrock-config` and `azure-ai-config` are removed. Only `core-ai-config` is published.
- **bedrock-ai and azure-ai packages** require `ubxty/core-ai ^2.0` and are themselves bumped to `2.0.0`.

### Changed
- `config/core-ai.php` now contains the shared defaults (`retry`, `cache`, `logging`) **plus** the `bedrock` and `azure_ai` provider sections (formerly in `ubxty/bedrock-ai/config/bedrock.php` and `ubxty/azure-ai/config/azure-ai.php`).
- Environment variables (`BEDROCK_*`, `AZURE_OPENAI_*`) are unchanged — they remain the source of truth for secrets.
- BedrockManager and AzureManager constructors still take a single `array $config`; their service providers now bind from the nested `core-ai.*` keys instead of the old top-level names.

### Migration from 1.x
1. `composer remove ubxty/bedrock-ai ubxty/azure-ai` (if installed)
2. `composer require ubxty/core-ai:^2.0 ubxty/bedrock-ai:^2.0 ubxty/azure-ai:^2.0` (or whichever subset you use)
3. `php artisan vendor:publish --tag=core-ai-config --force` to publish the new consolidated config
4. Move any customisations from `config/bedrock.php` / `config/azure-ai.php` into `config/core-ai.php` under the `bedrock` / `azure_ai` keys
5. In your own code, replace `config('bedrock.*')` with `config('core-ai.bedrock.*')` and `config('azure-ai.*')` with `config('core-ai.azure_ai.*')`

---

## [1.0.0] - 2026-04-18

### Added

- **Initial public release** — Core AI abstraction layer extracted from `ubxty/bedrock-ai` to enable shared infrastructure across multiple AI provider packages.
- **`AiManagerContract`** — Common interface all AI manager implementations must satisfy: `invoke()`, `converse()`, `stream()`, `syncModels()`, `getModelsGrouped()`, `defaultModel()`, `defaultImageModel()`, `platformName()`, `getCredentialInfo()`.
- **`AbstractAiManager`** — Base manager class providing cost limit enforcement, cost tracking, event dispatching (`AiInvoked`, `AiKeyRotated`, `AiRateLimited`), invocation logging, and retry orchestration. Subclasses implement `performInvoke()`, `performConverse()`, and `performConverseStream()`.
- **`AbstractCredentialManager`** — Base class for multi-key credential rotation with round-robin and failover strategies.
- **`HasRetryLogic` trait** — Shared retry loop, exponential backoff, and rate-limit detection for client classes.
- **`ModelAliasResolver`** — Resolves friendly model aliases (e.g. `claude-3.5`, `gpt-4o`) to full provider model IDs.
- **`ConversationBuilder`** — Fluent multi-turn conversation builder with multimodal support (text, images, documents), pricing, streaming, and token estimation.
- **`ModelSpecResolver`** — Resolves `context_window` and `max_tokens` for known model IDs across Anthropic, OpenAI, Amazon, Meta, Mistral, Cohere, AI21, and DeepSeek.
- **`TokenEstimator`** — Estimates token counts for text, images (~1,600 tokens/image), and documents (~750 base64 bytes/token). Includes cost estimation helpers.
- **`InvocationLogger`** — Structured channel-based logging for AI invocations.
- **Abstract CLI commands** — `AbstractChatCommand`, `AbstractConfigureCommand`, `AbstractModelsCommand`, `AbstractTestCommand`, `AbstractDefaultModelCommand`, and `WritesEnvFile` trait for consistent CLI UX across provider packages.
- **Events** — `AiInvoked`, `AiKeyRotated`, `AiRateLimited`.
- **Exceptions** — `AiException`, `ConfigurationException`, `CostLimitExceededException`, `RateLimitException`.
- **`HealthCheckController`** — Abstract base HTTP controller for provider connectivity health checks.
- **`CoreAiServiceProvider`** — Laravel service provider with auto-discovery support.

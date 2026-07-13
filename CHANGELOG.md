# Changelog

All notable changes to `ubxty/core-ai` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

# Changelog

All notable changes to `ubxty/core-ai` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

# ubxty/core-ai

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ubxty/core-ai.svg?style=flat-square)](https://packagist.org/packages/ubxty/core-ai)
[![License](https://img.shields.io/packagist/l/ubxty/core-ai.svg?style=flat-square)](LICENSE)

**Core AI abstraction layer for Laravel.** Provides shared contracts, abstract managers, retry logic, cost tracking, conversation builders, token estimation, and CLI scaffolding for building AI provider packages on top of.

This package is the foundation shared by:
- [`ubxty/bedrock-ai`](https://packagist.org/packages/ubxty/bedrock-ai) — AWS Bedrock
- [`ubxty/azure-ai`](https://packagist.org/packages/ubxty/azure-ai) — Azure OpenAI

---

## Requirements

- PHP 8.2+
- Laravel 11 or 12

---

## Installation

```bash
composer require ubxty/core-ai
```

The service provider is auto-discovered via Laravel's package discovery.

---

## What's Included

### Contracts
- `AiManagerContract` — Interface all AI manager implementations must satisfy (invoke, converse, stream, syncModels, etc.)

### Abstract Manager
- `AbstractAiManager` — Base class handling cost limit checks, cost tracking, event dispatching, invocation logging, and retry orchestration. Extend this to build a provider-specific manager.

### Client Helpers
- `AbstractCredentialManager` — Base class for multi-key credential rotation.
- `HasRetryLogic` — Trait providing retry loops, backoff, and error classification.
- `ModelAliasResolver` — Resolves friendly model aliases (e.g. `claude-3.5`) to full model IDs.

### Conversation
- `ConversationBuilder` — Fluent builder for multi-turn conversations with multimodal support (text, images, documents).

### Models
- `ModelSpecResolver` — Resolves context window and max token limits for known model IDs across all major providers.

### Support
- `TokenEstimator` — Estimates token counts and cost for text, image, and document inputs.
- `InvocationLogger` — Structured invocation logging.

### Commands (Abstract)
- `AbstractChatCommand` — Base for interactive `artisan chat` commands.
- `AbstractConfigureCommand` — Base for provider configuration wizards.
- `AbstractModelsCommand` — Base for model listing commands.
- `AbstractTestCommand` — Base for provider test commands.
- `AbstractDefaultModelCommand` — Base for default model selection commands.
- `WritesEnvFile` — Trait for writing key=value pairs to `.env`.

### Events
- `AiInvoked`, `AiKeyRotated`, `AiRateLimited`

### Exceptions
- `AiException`, `ConfigurationException`, `CostLimitExceededException`, `RateLimitException`

### HTTP
- `HealthCheckController` — Base health check endpoint for AI connectivity probes.

---

## Extending core-ai

To build a new AI provider package:

1. Require `ubxty/core-ai` as a dependency.
2. Extend `AbstractAiManager` and implement the abstract `performInvoke()`, `performConverse()`, `performConverseStream()` methods.
3. Extend `AbstractCredentialManager` for your credential rotation logic.
4. Use `HasRetryLogic` in your client classes.
5. Extend the abstract command classes for consistent CLI UX.

See `ubxty/bedrock-ai` and `ubxty/azure-ai` for reference implementations.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).

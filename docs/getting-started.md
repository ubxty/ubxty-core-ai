# Getting Started with `ubxty/core-ai`

> Companion to the [README](../README.md). This guide walks through installing the package, publishing config, wiring credentials, and making your first call. Provider-specific setup (Bedrock, Azure) lives in the provider packages' READMEs.

---

## 1. Install

```bash
composer require ubxty/core-ai
```

## 2. Verify installation

```bash
php artisan about
```

You should see `ubxty/core-ai` listed under "Service Providers" or "Packages". If you don't, double-check `composer.json`'s minimum-stability.

## 3. Publish the config (optional)

```bash
php artisan vendor:publish --tag=core-ai-config
```

This writes `config/core-ai.php` into your app. The defaults are usable out of the box, so publishing is only needed if you want to customise `cache.response_ttl`, `logging.enabled`, or add provider blocks.

> **Don't auto-publish in CI.** The tag is only available when `$this->app->runningInConsole()` is true, which is fine for a development deployment — but if you run `vendor:publish --all` in CI, you'll overwrite your team's config. Pin only what you need via `config([...])` in a service provider.

## 4. Install a provider

`ubxty/core-ai` is the abstraction. To do anything useful, install at least one provider:

```bash
composer require ubxty/bedrock-ai
# — or —
composer require ubxty/azure-ai
```

Both providers register their service providers automatically. They expect the `core-ai` config to be present (it is — by default or via your published copy).

## 5. Set credentials

Credentials are read from env, with provider-specific prefixes.

For Bedrock (IAM keys):

```dotenv
BEDROCK_CONNECTION=default
BEDROCK_AUTH_MODE=iam
BEDROCK_AWS_KEY=AKIAXXXXXXXXXXXXXXXX
BEDROCK_AWS_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
BEDROCK_REGION=us-east-1
BEDROCK_DEFAULT_MODEL=anthropic.claude-sonnet-4-20250514-v1:0
```

For Bedrock (bearer / ABSK):

```dotenv
BEDROCK_AUTH_MODE=bearer
BEDROCK_BEARER_TOKEN=ABSKYm1k…RGs9
BEDROCK_REGION=us-east-1
BEDROCK_DEFAULT_MODEL=amazon.nova-pro-v1:0
```

For Azure OpenAI:

```dotenv
AZURE_OPENAI_CONNECTION=default
AZURE_OPENAI_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com
AZURE_OPENAI_API_VERSION=2024-10-21
AZURE_OPENAI_DEFAULT_MODEL=my-gpt-4o-deployment
```

> Never commit `.env`. If you can't keep secrets out of source control, use AWS Secrets Manager + Laravel's `SecretsManager` driver, or HashiCorp Vault.

## 6. First call

The `BedrockManager` is the canonical entry point in the Bedrock package:

```php
use Ubxty\BedrockAi\BedrockManager;

$result = app(BedrockManager::class)->invoke(
    modelId: '',
    systemPrompt: 'You are a careful summariser.',
    userMessage: 'Q3 revenue was $4.2M, up 18% YoY.',
    maxTokens: 256,
    temperature: 0.2,
);

echo $result['response'];
```

The empty `modelId` falls back to `core-ai.bedrock.defaults.model`. The contract return shape is the standard array:

```php
[
    'response'      => '…',
    'input_tokens'  => 42,
    'output_tokens' => 18,
    'total_tokens'  => 60,
    'cost'          => 0.0004,
    'latency_ms'    => 612,
    'status'        => 'ok',
    'key_used'      => 'Primary',
    'model_id'      => 'anthropic.claude-sonnet-4-20250514-v1:0',
]
```

For Azure, swap to `app(Ubxty\AzureAi\AzureManager::class)->invoke(…)`.

## 7. Multi-turn with the builder

```php
$result = app(BedrockManager::class)
    ->conversation('claude-sonnet-4')
    ->system('You are a contract lawyer.')
    ->user('Review this clause.')
    ->userWithDocument('Anything non-standard?', '/tmp/clause.pdf')
    ->maxTokens(2048)
    ->temperature(0.1)
    ->send();
```

`->send()` returns the same array shape as `invoke()` and appends the assistant reply to the builder's message history.

## 8. Verify what you wrote

```bash
php artisan config:cache
php artisan tinker
>>> app(BedrockManager::class)->isConfigured();
=> true
>>> app(BedrockManager::class)->defaultModel();
=> "anthropic.claude-sonnet-4-20250514-v1:0"
>>> app(BedrockManager::class)->platformName();
=> "AWS Bedrock"
```

The whole loop, from `composer require` to a working call, takes under five minutes. The remaining optimisation — prompt caching, response caching, multi-key failover, cost caps — is a configuration change, not a refactor. See [`caching-strategy.md`](caching-strategy.md) for the cost-saving levers.

# Extending core-ai — Build Your Own Provider

> Companion to the [README](../README.md). A step-by-step recipe for writing a provider package that drops into `ubxty/core-ai`'s contract surface.

---

## When to build your own

Reach for extending core-ai when:

- You have an SDK or HTTP service that isn't covered by `ubxty/bedrock-ai` or `ubxty/azure-ai`.
- You're building a thin package that wraps OpenRouter, Together, Anyscale, vLLM, a private LLM gateway, or your own inference stack.
- You want consistent retry + key-rotation + cost-tracking across multiple backends in a single host app.

Don't extend it when:

- A single endpoint is hard-coded into your app. Use the SDK directly; the abstraction is overhead.
- You don't need provider failover — one model, one key. Plain HTTP is fine.

---

## The architecture in one paragraph

You write a `YourManager extends AbstractAiManager` that implements the 11 abstract `perform*` + `test*` + `listModels` + `isConfigured` + `supportsStreaming` + `getCredentialInfo` + `platformName` hooks. You write a `YourCredentials extends AbstractCredentialManager` that knows how to read your auth shape from config. You use `HasRetryLogic` on your `YourClient` classes to get key rotation, retries, and `Retry-After` honouring for free. You register a service provider that merges config and binds a facade. Total package size: ~200 lines of glue.

---

## Step 1 — Set up the package

```bash
mkdir -p packages/acme/my-ai/src/{Manager,Client,Events,Exceptions}
cd packages/acme/my-ai
composer init --name="acme/my-ai" --type=library --require="ubxty/core-ai:^2.1"
```

In `composer.json`:

```json
{
  "extra": {
    "laravel": {
      "providers": ["Acme\\MyAi\\MyAiServiceProvider"],
      "aliases": {"MyAi": "Acme\\MyAi\\Facades\\MyAi"}
    }
  }
}
```

---

## Step 2 — Implement the manager

`Acme\MyAi\MyManager` extends `AbstractAiManager` and implements the abstract hooks.

```php
namespace Acme\MyAi\Manager;

use Ubxty\CoreAi\Manager\AbstractAiManager;
use Ubxty\CoreAi\Exceptions\ConfigurationException;
use Acme\MyAi\Client\MyClient;
use Acme\MyAi\Client\MyCredentials;

class MyManager extends AbstractAiManager
{
    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    // AbstractAiManager hook implementations

    protected function performInvoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        ?array $pricing,
        ?string $connection
    ): array {
        $client = $this->client($connection);
        return $client->invoke($modelId, $systemPrompt, $userMessage, $maxTokens, $temperature, $pricing);
    }

    protected function performConverse(
        string $modelId,
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection
    ): array {
        $client = $this->client($connection);
        return $client->converse($modelId, $messages, $systemPrompt, $maxTokens, $temperature);
    }

    protected function performConverseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection
    ): array {
        $client = $this->client($connection);
        return $client->converseStream($modelId, $messages, $onChunk, $systemPrompt, $maxTokens, $temperature);
    }

    public function testConnection(?string $connection = null): array
    {
        $client = $this->client($connection);
        $start = microtime(true);
        try {
            $client->ping();
            return ['success' => true, 'message' => 'Connection OK', 'response_time' => (int) ((microtime(true) - $start) * 1000)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'response_time' => 0];
        }
    }

    public function listModels(?string $connection = null): array
    {
        $key = $this->cachePrefix().'_models_'.($connection ?? 'default');
        return \Illuminate\Support\Facades\Cache::remember(
            $key,
            (int) ($this->config['cache']['models_ttl'] ?? 3600),
            fn () => $this->client($connection)->listModels()
        );
    }

    public function fetchModels(?string $connection = null): array
    {
        return $this->client($connection)->listModels(); // bypass cache
    }

    public function syncModels(?string $connection = null): int
    {
        // If you're config-driven (no DB), this returns the count.
        $connection ??= $this->config['default'] ?? 'default';
        return count($this->configuredModels($connection));
    }

    public function isConfigured(?string $connection = null): bool
    {
        try {
            $credentials = $this->credentials($connection);
            return $credentials->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function supportsStreaming(?string $connection = null): bool
    {
        return true;
    }

    public function getCredentialInfo(?string $connection = null): array
    {
        $credentials = $this->credentials($connection);
        $info = [];
        for ($i = 0; $i < $credentials->count(); $i++) {
            $info[] = ['index' => $i, 'label' => "Key #{$i}"];
        }
        return $info;
    }

    public function platformName(): string
    {
        return 'Acme AI';
    }

    // Public helpers

    public function client(?string $connection = null): MyClient
    {
        $connection ??= $this->config['default'] ?? 'default';
        $credentials = $this->credentials($connection);

        return new MyClient($credentials, $this->config['retry'] ?? []);
    }

    protected function credentials(string $connection): MyCredentials
    {
        $keys = $this->config['connections'][$connection]['keys'] ?? [];
        return new MyCredentials($keys);
    }

    protected function configuredModels(string $connection): array
    {
        return $this->config['models'] ?? [];
    }
}
```

That's it for the manager. Every cost-optimisation hook (`clampMaxTokens`, response cache, idempotency key, cost limit, event dispatching) is inherited from `AbstractAiManager` and triggers for free.

---

## Step 3 — Credentials

```php
namespace Acme\MyAi\Client;

use Ubxty\CoreAi\Client\AbstractCredentialManager;
use Ubxty\CoreAi\Exceptions\ConfigurationException;

class MyCredentials extends AbstractCredentialManager
{
    protected function normalizeKey(array $key): array
    {
        if (empty($key['api_key'] ?? '')) {
            throw new ConfigurationException('Acme AI key missing api_key.');
        }
        return [
            'label'   => $key['label'] ?? 'Unnamed',
            'api_key' => $key['api_key'],
            'endpoint' => $key['endpoint'] ?? null,
        ];
    }

    public function getApiKey(): string
    {
        return $this->current()['api_key'];
    }

    public function getEndpoint(): ?string
    {
        return $this->current()['endpoint'] ?? null;
    }

    public function list(): array
    {
        $out = [];
        foreach ($this->keys as $i => $key) {
            $out[] = ['index' => $i, 'label' => $key['label'], 'configured' => true];
        }
        return $out;
    }
}
```

---

## Step 4 — Client with `HasRetryLogic`

```php
namespace Acme\MyAi\Client;

use Ubxty\CoreAi\Client\HasRetryLogic;
use Acme\MyAi\Exceptions\MyException;

class MyClient
{
    use HasRetryLogic;

    public function __construct(MyCredentials $credentials, array $retryConfig)
    {
        $this->credentials = $credentials;
        $this->maxRetries = (int) ($retryConfig['max_retries'] ?? 3);
        $this->baseDelay  = (int) ($retryConfig['base_delay'] ?? 2);
    }

    public function invoke(string $modelId, string $system, string $user, int $maxTokens, float $temperature, ?array $pricing): array
    {
        return $this->withRetry($modelId, function ($resolvedId, $key) use ($system, $user, $maxTokens, $temperature) {
            $start = microtime(true);
            $response = $this->http($key)->post('/chat', [
                'model' => $resolvedId,
                'system' => $system,
                'message' => $user,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
            ]);

            return [
                'response'      => $response['text'],
                'input_tokens'  => $response['usage']['input'],
                'output_tokens' => $response['usage']['output'],
                'total_tokens'  => $response['usage']['input'] + $response['usage']['output'],
                'cost'          => $this->calculateCost($response['usage']['input'], $response['usage']['output'], $pricing),
                'latency_ms'    => (int) ((microtime(true) - $start) * 1000),
                'status'        => 'ok',
                'key_used'      => $key['label'],
                'model_id'      => $resolvedId,
            ];
        });
    }

    public function listModels(): array
    {
        return $this->http($this->credentials->current())->get('/models');
    }

    public function ping(): void
    {
        $this->http($this->credentials->current())->get('/health');
    }

    // … converse + converseStream not shown for brevity …

    protected function http(array $key): \Illuminate\Support\Facades\Http
    {
        return \Illuminate\Support\Facades\Http::withToken($key['api_key'])
            ->baseUrl($key['endpoint'] ?? 'https://api.acme.ai')
            ->acceptJson()
            ->throw();
    }

    // Optional HasRetryLogic overrides

    protected function extractFriendlyError(string $errorMessage): string
    {
        return str_contains($errorMessage, 'INVALID_API_KEY')
            ? 'Acme API key is invalid. Please check your .env.'
            : parent::extractFriendlyError($errorMessage);
    }

    protected function onRateLimitExhausted(string $modelId, array $key, int $retryAttempt): void
    {
        event(new \Ubxty\CoreAi\Events\AiRateLimited(
            modelId: $modelId,
            keyLabel: $key['label'],
            retryAttempt: $retryAttempt,
            waitSeconds: 0,
            platform: 'Acme AI',
        ));
    }

    protected function onKeyRotated(array $fromKey, array $toKey, string $reason, string $modelId): void
    {
        event(new \Ubxty\CoreAi\Events\AiKeyRotated(
            fromKeyLabel: $fromKey['label'],
            toKeyLabel:   $toKey['label'],
            reason:       $reason,
            modelId:      $modelId,
            platform:     'Acme AI',
        ));
    }
}
```

That's it. You get:

- Key rotation across `connections.{name}.keys[]`.
- Exponential-backoff retry, with `Retry-After` honouring.
- `AiKeyRotated` and `AiRateLimited` events for free.
- Standardised user-friendly error mapping (override `extractFriendlyError`).
- The full cost-optimisation pipeline from the abstract manager (response cache, idempotency key, cost limit).

---

## Step 5 — Config file

```php
// config/my-ai.php
return [
    'default' => env('ACME_AI_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'keys' => [
                [
                    'label'   => env('ACME_AI_KEY_LABEL', 'Primary'),
                    'api_key' => env('ACME_AI_API_KEY', ''),
                    'endpoint' => env('ACME_AI_ENDPOINT', 'https://api.acme.ai'),
                ],
            ],
        ],
    ],

    'retry' => [
        'max_retries' => env('ACME_AI_MAX_RETRIES', 3),
        'base_delay'  => env('ACME_AI_RETRY_DELAY', 2),
    ],

    'cache' => [
        'models_ttl' => env('ACME_AI_MODELS_TTL', 3600),
    ],

    'limits' => [
        'daily'   => env('ACME_AI_DAILY_LIMIT', null),
        'monthly' => env('ACME_AI_MONTHLY_LIMIT', null),
    ],

    'defaults' => [
        'model'       => env('ACME_AI_DEFAULT_MODEL', ''),
        'image_model' => env('ACME_AI_DEFAULT_IMAGE_MODEL', ''),
    ],

    'aliases' => [
        // 'flagship' => 'acme-flagship-2026',
    ],

    'models' => array_filter([
        'default' => (function () {
            $ids = array_filter(array_map('trim', explode(',', (string) env('ACME_AI_MODELS', ''))));

            return array_filter(array_combine(
                $ids,
                array_map(fn (string $id) => ['name' => $id], $ids),
            ));
        })(),
    ]),

    'logging' => [
        'enabled' => env('ACME_AI_LOGGING_ENABLED', false),
        'channel' => env('ACME_AI_LOG_CHANNEL', 'stack'),
    ],

    'health_check' => [
        'enabled'    => env('ACME_AI_HEALTH_CHECK_ENABLED', false),
        'path'       => '/health/acme-ai',
        'middleware' => [],
    ],
];
```

> **Top-level inheritance.** Your config is merged with `core-ai`'s by Laravel, so `cache.response_ttl` and `cache.embedding_ttl` from the parent config are inherited. Override per-provider via `cache.response_ttl` in this file.

---

## Step 6 — Service provider

```php
namespace Acme\MyAi;

use Illuminate\Support\ServiceProvider;
use Acme\MyAi\Manager\MyManager;

class MyAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/my-ai.php', 'core-ai.my_ai');

        $this->app->singleton(MyManager::class, function ($app) {
            return new MyManager(
                config('core-ai.my_ai', []),
            );
        });

        $this->app->alias(MyManager::class, 'my-ai');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/my-ai.php' => config_path('my-ai.php'),
            ], 'my-ai-config');
        }
    }
}
```

> **Why the `core-ai.my_ai` namespace?** Since core-ai v2.0, provider config blocks live under `core-ai.{provider}`. The host app publishes `config/core-ai.php` once and adds `my_ai` alongside `bedrock` / `azure_ai`.

---

## Step 7 — Facade (optional)

```php
namespace Acme\MyAi\Facades;

use Illuminate\Support\Facades\Facade;

class MyAi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MyManager::class;
    }
}
```

Then host apps can call `MyAi::invoke(...)`, `MyAi::converse(...)`, `MyAi::conversation(...)->send()`.

---

## Step 8 — Use it

In the host app:

```php
use Acme\MyAi\Facades\MyAi;
use Acme\MyAi\Manager\MyManager;

$result = MyAi::invoke('flagship', 'You are careful.', 'Summarise this.', 256, 0.2);

// Or, by injection:
class FooService
{
    public function __construct(private MyManager $myAi) {}

    public function handle(): array
    {
        return $this->myAi->invoke('flagship', …);
    }
}
```

---

## What you got

Roughly 200 lines of glue. For that you got:

- 5 cache layers (response, embedding, models, usage, pricing)
- 3 events (Invoked, KeyRotated, RateLimited)
- 4 exceptions (Ai, Configuration, RateLimit, CostLimit)
- Multi-key failover with `Retry-After` honouring
- Idempotency-Key derivation for upstream deduplication
- Cost-limit enforcement (daily + monthly)
- Token estimation + max-tokens clamp + fits check
- A fluent conversation builder
- Model aliases
- Auto-discovered commands if you publish any (optional)

Everything that the existing `ubxty/bedrock-ai` and `ubxty/azure-ai` packages have.

For the real-world patterns that come out of this — RAG pipelines, embedding ingestion at scale, multimodal document analysis, multi-tenant provider dispatch — see [`real-world-patterns.md`](real-world-patterns.md).

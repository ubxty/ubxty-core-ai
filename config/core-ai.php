<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shared Defaults
    |--------------------------------------------------------------------------
    |
    | Default values shared across all ubxty/core-ai based provider packages
    | (e.g. ubxty/azure-ai, ubxty/bedrock-ai). Provider-specific config lives
    | under the `bedrock` and `azure_ai` keys below.
    |
    */

    'retry' => [
        'max_retries' => 3,
        'base_delay'  => 2,
    ],

    'cache' => [
        'models_ttl'  => 3600,
        'usage_ttl'   => 900,
        'pricing_ttl' => 86400,
        'response_ttl' => (int) env('CORE_AI_RESPONSE_TTL', 300), // v2.1.0 — cache provider responses (invoke/converse) when > 0. 0 disables.
        'embedding_ttl' => 604800, // v2.1.0 — 7 days; embeddings are deterministic and expensive.
    ],

    'logging' => [
        'enabled' => false,
        'channel' => 'stack',
    ],

    /*
    |--------------------------------------------------------------------------
    | AWS Bedrock Provider (ubxty/bedrock-ai)
    |--------------------------------------------------------------------------
    |
    | All Bedrock-specific settings. Consumed by BedrockAiServiceProvider
    | which binds BedrockManager with config('core-ai.bedrock').
    |
    */

    'bedrock' => [

        /*
        |----------------------------------------------------------------------
        | Default Connection
        |----------------------------------------------------------------------
        |
        | The default Bedrock connection to use. This corresponds to a key in
        | the "connections" array below. You may define as many connections
        | as needed.
        |
        */
        'default' => env('BEDROCK_CONNECTION', 'default'),

        /*
        |----------------------------------------------------------------------
        | Bedrock Connections
        |----------------------------------------------------------------------
        |
        | Each connection defines AWS credentials, region, and optional
        | settings for invoking Bedrock models. You can define multiple
        | connections and switch between them at runtime.
        |
        | "keys" supports multiple AWS credential sets for automatic failover.
        | Each key set can have a label, access key, secret, and region.
        |
        */
        'connections' => [
            'default' => [
                'keys' => [
                    [
                        'label'        => env('BEDROCK_KEY_LABEL', 'Primary'),
                        'auth_mode'    => env('BEDROCK_AUTH_MODE', 'iam'), // 'iam' or 'bearer'
                        'aws_key'      => env('BEDROCK_AWS_KEY', env('AWS_ACCESS_KEY_ID', '')),
                        'aws_secret'   => env('BEDROCK_AWS_SECRET', env('AWS_SECRET_ACCESS_KEY', '')),
                        'bearer_token' => env('BEDROCK_BEARER_TOKEN', ''),
                        'region'       => env('BEDROCK_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
                    ],
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Retry Configuration
        |----------------------------------------------------------------------
        */
        'retry' => [
            'max_retries' => env('BEDROCK_MAX_RETRIES', 3),
            'base_delay'  => env('BEDROCK_RETRY_DELAY', 2), // seconds, doubles each retry
        ],

        /*
        |----------------------------------------------------------------------
        | Cost Limits
        |----------------------------------------------------------------------
        |
        | Set daily and monthly spending limits. When exceeded, API calls will
        | throw a CostLimitExceededException. Set to null to disable.
        |
        */
        'limits' => [
            'daily'   => env('BEDROCK_DAILY_LIMIT', null),   // e.g., 10.00
            'monthly' => env('BEDROCK_MONTHLY_LIMIT', null), // e.g., 300.00
        ],

        /*
        |----------------------------------------------------------------------
        | Pricing API Credentials
        |----------------------------------------------------------------------
        |
        | Separate credentials for the AWS Pricing API. If not set, falls back
        | to the default connection's first key credentials. The Pricing API is
        | only available in us-east-1 and ap-south-1.
        |
        */
        'pricing' => [
            'aws_key'    => env('BEDROCK_PRICING_KEY', ''),
            'aws_secret' => env('BEDROCK_PRICING_SECRET', ''),
        ],

        /*
        |----------------------------------------------------------------------
        | Usage Tracking (CloudWatch)
        |----------------------------------------------------------------------
        |
        | Credentials for reading Bedrock usage metrics from CloudWatch.
        | Falls back to the default connection's first key if not set.
        |
        */
        'usage' => [
            'aws_key'    => env('BEDROCK_USAGE_KEY', ''),
            'aws_secret' => env('BEDROCK_USAGE_SECRET', ''),
            'region'     => env('BEDROCK_USAGE_REGION', env('BEDROCK_REGION', 'us-east-1')),
        ],

        /*
        |----------------------------------------------------------------------
        | Cache Configuration
        |----------------------------------------------------------------------
        |
        | How long to cache various API responses.
        |
        */
        'cache' => [
            'pricing_ttl' => 86400, // 24 hours
            'usage_ttl'   => 900,   // 15 minutes
            'models_ttl'  => 3600,  // 1 hour
        ],

        /*
        |----------------------------------------------------------------------
        | Provider Filtering
        |----------------------------------------------------------------------
        |
        | Control which model providers are visible across the entire
        | package — in terminal commands (bedrock:test, bedrock:default-model,
        | etc.) and in all PHP calls (getModelsGrouped, syncModels, etc.).
        |
        | 'disabled_providers' — list of provider names to hide globally.
        |
        | Or via .env as a comma-separated string:
        |   BEDROCK_DISABLED_PROVIDERS="AI21 Labs,Cohere,Writer"
        |
        */
        'providers' => [
            // Disabled for ALL contexts (chat and image). Applied first.
            'disabled_providers' => explode(',', env('BEDROCK_DISABLED_PROVIDERS', '')),

            // Disabled only when browsing/selecting chat models.
            'chat' => [
                'disabled_providers' => explode(',', env('BEDROCK_CHAT_DISABLED_PROVIDERS', '')),
            ],

            // Disabled only when browsing/selecting image models.
            'image' => [
                'disabled_providers' => explode(',', env('BEDROCK_IMAGE_DISABLED_PROVIDERS', '')),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Default Invocation Settings
        |----------------------------------------------------------------------
        */
        'defaults' => [
            'max_tokens'        => 4096,
            'temperature'       => 0.7,
            'anthropic_version' => 'bedrock-2023-05-31',
            'model'             => env('BEDROCK_DEFAULT_MODEL', ''),
            'image_model'       => env('BEDROCK_DEFAULT_IMAGE_MODEL', ''),
        ],

        /*
        |----------------------------------------------------------------------
        | Model Aliases
        |----------------------------------------------------------------------
        |
        | Define short aliases for frequently used model IDs. Use the alias
        | anywhere a model ID is accepted and it will be resolved automatically.
        |
        | Example: Bedrock::invoke('claude', 'system', 'hello')
        |
        */
        'aliases' => [
            // 'claude' => 'anthropic.claude-sonnet-4-20250514-v1:0',
            // 'haiku'  => 'anthropic.claude-3-5-haiku-20241022-v1:0',
            // 'nova'   => 'amazon.nova-pro-v1:0',
        ],

        /*
        |----------------------------------------------------------------------
        | Model Catalogue
        |----------------------------------------------------------------------
        |
        | Define the models you want surfaced by
        | BedrockManager::getModelsGrouped(). Two shapes are supported (flat
        | is recommended — Bedrock model IDs are globally unique across
        | regions):
        |
        |   Flat (recommended):
        |     'models' => [
        |       'anthropic.claude-3-5-sonnet-20241022-v2:0' => [
        |         'name' => 'Claude 3.5 Sonnet v2', 'provider' => 'Anthropic',
        |         'context_window' => 200000, 'max_tokens' => 8192,
        |         'capabilities' => ['text'], 'input_modalities' => ['text'],
        |         'is_active' => true,
        |       ],
        |     ],
        |
        |   Per-connection:
        |     'models' => [
        |       'default' => [ 'anthropic.…' => [ … ] ],
        |       'secondary' => [ 'anthropic.…' => [ … ] ],
        |     ],
        |
        | In the flat shape, an entry with an explicit 'connection' => '…' key
        | is filtered out when querying other connections.
        |
        | Leave empty to fall back to a live call against the AWS Bedrock
        | ListFoundationModels API (cached via Laravel cache for
        | cache.models_ttl).
        |
        | Override via BEDROCK_MODELS env (comma-separated model IDs):
        |   BEDROCK_MODELS=anthropic.claude-3-5-sonnet-20241022-v2:0,amazon.nova-lite-v1:0
        |
        | Each ID is wrapped into ['name' => id] so getModelsGrouped() can
        | surface it without hitting the AWS API. Add provider / capabilities
        | etc. via config/core-ai.php overrides or by populating the catalogue
        | in code; display labels stay customisable from the consuming app.
        |
        */
        'models' => array_filter([
            'default' => (function () {
                $ids = array_filter(array_map('trim', explode(',', (string) env('BEDROCK_MODELS', ''))));

                return array_filter(array_combine(
                    $ids,
                    array_map(fn (string $id) => ['name' => $id], $ids),
                ));
            })(),
        ]),

        /*
        |----------------------------------------------------------------------
        | Invocation Logging
        |----------------------------------------------------------------------
        |
        | Log every Bedrock invocation for auditing and cost tracking.
        | Set the channel to any configured Laravel log channel.
        |
        */
        'logging' => [
            'enabled' => env('BEDROCK_LOGGING_ENABLED', false),
            'channel' => env('BEDROCK_LOG_CHANNEL', 'stack'),
        ],

        /*
        |----------------------------------------------------------------------
        | Health Check
        |----------------------------------------------------------------------
        |
        | Register a /health/bedrock route for monitoring dashboards.
        | Protected by the specified middleware.
        |
        */
        'health_check' => [
            'enabled'    => env('BEDROCK_HEALTH_CHECK_ENABLED', false),
            'path'       => '/health/bedrock',
            'middleware' => [],
        ],

        /*
        |----------------------------------------------------------------------
        | Prompt Caching (v2.1.0)
        |----------------------------------------------------------------------
        |
        | AWS Bedrock supports checkpoint blocks (`cachePoint: { type: 'default' }`)
        | on Converse content arrays. Subsequent calls with the same prefix
        | within the cache TTL get charged ~10% of normal input-token rate.
        |
        | 'points' lists the named anchors where the package injects a cache
        | checkpoint. Supported anchors:
        |   - 'system'           after the system prompt blocks
        |   - 'last_user'        after the last user message blocks
        |
        | Leave empty to disable. Not all models support caching; the Bedrock
        | runtime returns a 400 if a checkpoint is placed on an unsupported one.
        |
        */
        'prompt_caching' => [
            'points' => explode(',', env('BEDROCK_PROMPT_CACHE_POINTS', '')),
            'ttl_seconds' => (int) env('BEDROCK_PROMPT_CACHE_TTL', 300), // 5 min, max 3600
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Azure OpenAI Provider (ubxty/azure-ai)
    |--------------------------------------------------------------------------
    |
    | All Azure-OpenAI-specific settings. Consumed by AzureAiServiceProvider
    | which binds AzureManager with config('core-ai.azure_ai').
    |
    */

    'azure_ai' => [

        /*
        |----------------------------------------------------------------------
        | Default Connection
        |----------------------------------------------------------------------
        */
        'default' => env('AZURE_OPENAI_CONNECTION', 'default'),

        /*
        |----------------------------------------------------------------------
        | Azure OpenAI Connections
        |----------------------------------------------------------------------
        |
        | Each connection defines an Azure OpenAI resource endpoint and API
        | key(s). "keys" supports multiple credential sets for automatic
        | failover.
        |
        */
        'connections' => [
            'default' => [
                'keys' => [
                    [
                        'label'       => env('AZURE_OPENAI_KEY_LABEL', 'Primary'),
                        'api_key'     => env('AZURE_OPENAI_API_KEY', ''),
                        'endpoint'    => env('AZURE_OPENAI_ENDPOINT', ''),
                        'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
                    ],
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Retry Configuration
        |----------------------------------------------------------------------
        */
        'retry' => [
            'max_retries' => env('AZURE_OPENAI_MAX_RETRIES', 3),
            'base_delay'  => env('AZURE_OPENAI_RETRY_DELAY', 2),
        ],

        /*
        |----------------------------------------------------------------------
        | Cost Limits
        |----------------------------------------------------------------------
        */
        'limits' => [
            'daily'   => env('AZURE_OPENAI_DAILY_LIMIT', null),
            'monthly' => env('AZURE_OPENAI_MONTHLY_LIMIT', null),
        ],

        /*
        |----------------------------------------------------------------------
        | Cache Configuration
        |----------------------------------------------------------------------
        */
        'cache' => [
            'models_ttl' => 3600,
        ],

        /*
        |----------------------------------------------------------------------
        | Provider Filtering
        |----------------------------------------------------------------------
        */
        'providers' => [
            'disabled_providers' => explode(',', env('AZURE_OPENAI_DISABLED_PROVIDERS', '')),

            'chat' => [
                'disabled_providers' => explode(',', env('AZURE_OPENAI_CHAT_DISABLED_PROVIDERS', '')),
            ],

            'image' => [
                'disabled_providers' => explode(',', env('AZURE_OPENAI_IMAGE_DISABLED_PROVIDERS', '')),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Default Invocation Settings
        |----------------------------------------------------------------------
        */
        'defaults' => [
            'max_tokens'  => 4096,
            'temperature' => 0.7,
            'model'       => env('AZURE_OPENAI_DEFAULT_MODEL', ''),
            'image_model' => env('AZURE_OPENAI_DEFAULT_IMAGE_MODEL', ''),
        ],

        /*
        |----------------------------------------------------------------------
        | Model Aliases
        |----------------------------------------------------------------------
        |
        | Short aliases for deployment names. Use the alias anywhere a
        | deployment ID is accepted and it will be resolved automatically.
        |
        */
        'aliases' => [
            // 'gpt4' => 'my-gpt-4o-deployment',
            // 'mini' => 'my-gpt-4o-mini-deployment',
        ],

        /*
        |----------------------------------------------------------------------
        | Model Catalogue
        |----------------------------------------------------------------------
        |
        | Define the deployments you want surfaced by
        | AzureManager::getModelsGrouped(). Two shapes are supported (flat
        | is recommended — deployment names are unique per Azure resource):
        |
        |   Flat (recommended):
        |     'models' => [
        |       'my-gpt-4o-deployment' => [
        |         'name' => 'GPT-4o', 'provider' => 'OpenAI',
        |         'context_window' => 128000, 'max_tokens' => 16384,
        |         'capabilities' => ['text', 'vision'], 'input_modalities' => ['text', 'image'],
        |         'is_active' => true,
        |       ],
        |     ],
        |
        |   Per-connection:
        |     'models' => [
        |       'default' => [ 'my-gpt-4o-deployment' => [ … ] ],
        |       'secondary' => [ 'my-gpt-4o-mini-deployment' => [ … ] ],
        |     ],
        |
        | In the flat shape, an entry with an explicit 'connection' => '…' key
        | is filtered out when querying other connections.
        |
        | Leave empty to fall back to a live call against the Azure OpenAI
        | /openai/models data-plane endpoint (cached via Laravel cache for
        | cache.models_ttl). Microsoft Foundry endpoints that don't expose
        | /models return [] gracefully.
        |
        | Override via AZURE_OPENAI_MODELS env (comma-separated deployment IDs):
        |   AZURE_OPENAI_MODELS=my-gpt-4o-deployment,my-gpt-4o-mini-deployment
        |
        | Each ID is wrapped into ['name' => id] so getModelsGrouped() can
        | surface it without hitting the Azure API. Add provider / capabilities
        | etc. via config/core-ai.php overrides or by populating the catalogue
        | in code; display labels stay customisable from the consuming app.
        |
        */
        'models' => array_filter([
            'default' => (function () {
                $ids = array_filter(array_map('trim', explode(',', (string) env('AZURE_OPENAI_MODELS', ''))));

                return array_filter(array_combine(
                    $ids,
                    array_map(fn (string $id) => ['name' => $id], $ids),
                ));
            })(),
        ]),

        /*
        |----------------------------------------------------------------------
        | Invocation Logging
        |----------------------------------------------------------------------
        */
        'logging' => [
            'enabled' => env('AZURE_OPENAI_LOGGING_ENABLED', false),
            'channel' => env('AZURE_OPENAI_LOG_CHANNEL', 'stack'),
        ],

        /*
        |----------------------------------------------------------------------
        | Health Check
        |----------------------------------------------------------------------
        */
        'health_check' => [
            'enabled'    => env('AZURE_OPENAI_HEALTH_CHECK_ENABLED', false),
            'path'       => '/health/azure-openai',
            'middleware' => [],
        ],

    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shared Defaults
    |--------------------------------------------------------------------------
    |
    | Default values shared across all ubxty/core-ai based provider packages
    | (e.g. ubxty/azure-ai, ubxty/bedrock-ai). Each provider may override
    | these via its own config file (e.g. azure-ai.retry.*).
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
    ],

    'logging' => [
        'enabled' => false,
        'channel' => 'stack',
    ],

];

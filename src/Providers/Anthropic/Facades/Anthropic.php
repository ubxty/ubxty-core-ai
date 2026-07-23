<?php

namespace Ubxty\CoreAi\Providers\Anthropic\Facades;

use Illuminate\Support\Facades\Facade;
use Ubxty\CoreAi\Providers\Anthropic\AnthropicManager;
use Ubxty\CoreAi\Standards\Claude\ClaudeClient;

/**
 * @method static \Ubxty\CoreAi\Standards\Claude\ClaudeClient client(?string $connection = null)
 * @method static array invoke(string $modelId = '', string $systemPrompt = '', string $userMessage = '', int $maxTokens = 4096, float $temperature = 0.7, ?array $pricing = null, ?string $connection = null)
 * @method static array converse(string $modelId, array $messages, string $systemPrompt = '', int $maxTokens = 4096, float $temperature = 0.7, ?string $connection = null, ?array $pricing = null)
 * @method static array stream(string $modelId, string $systemPrompt, string $userMessage, callable $onChunk, int $maxTokens = 4096, float $temperature = 0.7, ?string $connection = null, ?array $pricing = null)
 * @method static array converseStream(string $modelId, array $messages, callable $onChunk, string $systemPrompt = '', int $maxTokens = 4096, float $temperature = 0.7, ?string $connection = null, ?array $pricing = null)
 * @method static array testConnection(?string $connection = null)
 * @method static array listModels(?string $connection = null)
 * @method static array fetchModels(?string $connection = null)
 * @method static int syncModels(?string $connection = null)
 * @method static bool isConfigured(?string $connection = null)
 * @method static string platformName()
 * @method static array<string, mixed> getCredentialInfo(?string $connection = null)
 * @method static bool supportsStreaming(?string $connection = null)
 *
 * @see \Ubxty\CoreAi\Providers\Anthropic\AnthropicManager
 */
class Anthropic extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AnthropicManager::class;
    }
}

<?php

namespace Ubxty\CoreAi\Providers\Gemini\Facades;

use Illuminate\Support\Facades\Facade;
use Ubxty\CoreAi\Standards\Gemini\GeminiClient;
use Ubxty\CoreAi\Providers\Gemini\GeminiManager;

/**
 * @method static \Ubxty\CoreAi\Standards\Gemini\GeminiClient client(?string $connection = null)
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
 * @see \Ubxty\CoreAi\Providers\Gemini\GeminiManager
 */
class GeminiAi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GeminiManager::class;
    }
}

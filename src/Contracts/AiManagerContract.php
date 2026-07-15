<?php

namespace Ubxty\CoreAi\Contracts;

use Ubxty\CoreAi\Client\ModelAliasResolver;
use Ubxty\CoreAi\Conversation\ConversationBuilder;
use Ubxty\CoreAi\Logging\InvocationLogger;
use Ubxty\CoreAi\Support\CacheKeyContext;

interface AiManagerContract
{
    /**
     * Invoke a model with a single prompt.
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cost: float, latency_ms: int, status: string, key_used: string, model_id: string}
     */
    public function invoke(
        string $modelId = '',
        string $systemPrompt = '',
        string $userMessage = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?array $pricing = null,
        ?string $connection = null
    ): array;

    /**
     * Send a multi-turn conversation.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string, cost: float}
     */
    public function converse(
        string $modelId,
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null
    ): array;

    /**
     * Stream a multi-turn conversation with a callback for each chunk.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  callable(string $chunk): void  $onChunk
     */
    public function converseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null
    ): array;

    /**
     * Invoke a model with a single prompt, scoped by a cache-key context.
     *
     * The `$ctx` controls how the response cache is namespaced: the cache
     * key is split by `tenant` / `conversation` / `promptVersion` segments
     * so distinct conversations on the same tenant get distinct entries.
     * Use this overload from app code that already has a tenant + conversation
     * handle (e.g. the chat flow in RubriSense).
     *
     * Purely additive to the contract — implementations must also keep
     * `invoke()` working. `invoke($a, $b, …)` is equivalent to
     * `invokeWithContext($a, $b, …, null)`.
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cost: float, latency_ms: int, status: string, key_used: string, model_id: string}
     */
    public function invokeWithContext(
        string $modelId = '',
        string $systemPrompt = '',
        string $userMessage = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?array $pricing = null,
        ?string $connection = null,
        ?CacheKeyContext $ctx = null
    ): array;

    /**
     * Send a multi-turn conversation, scoped by a cache-key context.
     *
     * Purely additive. `converse($a, …, $pricing)` is equivalent to
     * `converseWithContext($a, …, $pricing, null)`.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string, cost: float}
     */
    public function converseWithContext(
        string $modelId,
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null,
        ?CacheKeyContext $ctx = null
    ): array;

    /**
     * Stream a multi-turn conversation, scoped by a cache-key context.
     *
     * Purely additive. `converseStream($a, …, $pricing)` is equivalent to
     * `converseStreamWithContext($a, …, $pricing, null)`.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  callable(string $chunk): void  $onChunk
     */
    public function converseStreamWithContext(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null,
        ?CacheKeyContext $ctx = null
    ): array;

    /**
     * Start a fluent conversation builder.
     */
    public function conversation(string $modelId): ConversationBuilder;

    /**
     * Test the connection to the AI service.
     *
     * @return array{success: bool, message: string, response_time: int, model_count?: int}
     */
    public function testConnection(?string $connection = null): array;

    /**
     * List available models.
     */
    public function listModels(?string $connection = null): array;

    /**
     * Fetch models with normalized structure.
     */
    public function fetchModels(?string $connection = null): array;

    /**
     * Sync models to the database.
     */
    public function syncModels(?string $connection = null): int;

    /**
     * Get models grouped by provider with optional filtering.
     *
     * @return array<string, array<int, array>>
     */
    public function getModelsGrouped(?string $connection = null, ?string $context = null): array;

    /**
     * Get the default chat model.
     */
    public function defaultModel(): string;

    /**
     * Get the default image model.
     */
    public function defaultImageModel(): string;

    /**
     * Resolve a model alias to its full ID.
     */
    public function resolveAlias(string $modelIdOrAlias): string;

    /**
     * Get the model alias resolver.
     */
    public function aliases(): ModelAliasResolver;

    /**
     * Check if the service is configured.
     */
    public function isConfigured(?string $connection = null): bool;

    /**
     * Get the full configuration array.
     */
    public function getConfig(): array;

    /**
     * Get the invocation logger.
     */
    public function getLogger(): InvocationLogger;

    /**
     * Get the platform display name (e.g. "AWS Bedrock", "Azure OpenAI").
     */
    public function platformName(): string;

    /**
     * Whether the current connection supports streaming.
     */
    public function supportsStreaming(?string $connection = null): bool;

    /**
     * Get safe credential info for display (no secrets).
     *
     * @return array<int, array{index: int, label: string, }>
     */
    public function getCredentialInfo(?string $connection = null): array;
}

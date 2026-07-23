<?php

namespace Ubxty\CoreAi\Contracts;

/**
 * Contract every standard LLM client implements.
 *
 * A standard client (OpenAI, Claude / Anthropic, AWS Bedrock Converse, Google
 * Gemini) wraps the wire format for one provider family and returns a
 * platform-neutral {@see LLMResult}. Identity, retry, key rotation, and cost
 * accounting are handled by {@see \Ubxty\CoreAi\Client\AbstractLLMClient};
 * this contract is just the public surface those classes and their callers
 * depend on.
 */
interface LLMClientContract
{
    /**
     * Send a non-streaming conversation turn.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  array<int, \Ubxty\CoreAi\Contracts\ToolDescriptor>  $tools
     * @param  ?array<int|string>  $cacheAnchors  named anchor points for prompt caching
     */
    public function converse(
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        array $tools = [],
        ?ToolChoice $toolChoice = null,
        ?StructuredSchema $schema = null,
        ?string $idempotencyKey = null,
        ?array $cacheAnchors = null,
    ): LLMResult;

    /**
     * Stream a conversation turn, invoking $onDelta for each text chunk.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  callable(string $delta): void  $onDelta
     * @param  array<int, \Ubxty\CoreAi\Contracts\ToolDescriptor>  $tools
     * @param  ?array<int|string>  $cacheAnchors
     */
    public function converseStream(
        array $messages,
        callable $onDelta,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        array $tools = [],
        ?ToolChoice $toolChoice = null,
        ?StructuredSchema $schema = null,
        ?string $idempotencyKey = null,
        ?array $cacheAnchors = null,
    ): LLMResult;

    /**
     * Return the locally-cached model list (no remote call).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listModels(): array;

    /**
     * Fetch the live model list from the provider and refresh the cache.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchModels(): array;

    /**
     * Probe the provider to confirm credentials + reachability.
     *
     * @return array{success: bool, message: string, response_time: int, model_count?: int}
     */
    public function testConnection(): array;

    /**
     * Whether this standard can route tool calls (`tools` / `tool_choice`).
     */
    public function supportsTools(): bool;

    /**
     * Whether this standard can enforce a structured (JSON-schema) response.
     */
    public function supportsStructuredOutput(): bool;

    /**
     * Whether this standard supports provider-side prompt caching
     * (e.g. Anthropic cache_control, OpenAI prompt_cache_key,
     * Gemini cachedContent, Bedrock cache points).
     */
    public function supportsPromptCaching(): bool;

    /**
     * Whether this standard can stream response tokens via $onDelta.
     */
    public function supportsStreaming(): bool;

    /**
     * Human-readable platform label for logs and UI (e.g. "OpenAI",
     * "Anthropic", "AWS Bedrock", "Google Gemini").
     */
    public function platformName(): string;
}

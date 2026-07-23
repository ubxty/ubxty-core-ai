<?php

namespace Ubxty\CoreAi\Contracts;

/**
 * Normalised token-usage breakdown returned by every LLM standard.
 *
 * Splits input vs output tokens and exposes the two prompt-cache buckets
 * reported by providers that implement Anthropic-style cache control
 * (Anthropic, OpenAI with auto-prompt-caching, AWS Bedrock Converse,
 * Google Gemini cachedContent). Standards that do not break out cache
 * counts leave the cache fields at zero.
 */
final readonly class LLMUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedReadTokens = 0,
        public int $cachedWriteTokens = 0,
    ) {}
}
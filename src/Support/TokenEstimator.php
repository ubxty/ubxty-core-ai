<?php

namespace Ubxty\CoreAi\Support;

use Ubxty\CoreAi\Models\ModelSpecResolver;

class TokenEstimator
{
    /**
     * Average characters per token for English text.
     * Claude/GPT models average ~4 chars per token.
     */
    protected const CHARS_PER_TOKEN = 4;

    /**
     * Approximate bytes of base64 document data per token.
     */
    protected const DOCUMENT_BYTES_PER_TOKEN = 750;

    /**
     * Approximate tokens per image (most vision models use a fixed image budget).
     */
    protected const IMAGE_TOKENS_DEFAULT = 1600;

    /**
     * Estimate the number of tokens in a string.
     */
    public static function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Estimate tokens for a full invocation (system + user prompt).
     *
     * @return array{input_tokens: int, available_output: int, fits: bool, context_window: int}
     */
    public static function estimateInvocation(
        string $systemPrompt,
        string $userMessage,
        string $modelId,
        int $maxOutputTokens = 4096
    ): array {
        $inputTokens = self::estimate($systemPrompt) + self::estimate($userMessage);
        $specs = ModelSpecResolver::resolve($modelId);
        $contextWindow = $specs['context_window'];
        $availableOutput = $contextWindow - $inputTokens;

        return [
            'input_tokens' => $inputTokens,
            'available_output' => max(0, $availableOutput),
            'fits' => ($inputTokens + $maxOutputTokens) <= $contextWindow,
            'context_window' => $contextWindow,
        ];
    }

    /**
     * Estimate the cost of an invocation before making the call.
     *
     * @param  array{input_price_per_1k: float, output_price_per_1k: float}|null  $pricing
     */
    public static function estimateCost(
        string $systemPrompt,
        string $userMessage,
        int $expectedOutputTokens = 1000,
        ?array $pricing = null
    ): float {
        $inputTokens = self::estimate($systemPrompt) + self::estimate($userMessage);
        $inputPrice = $pricing['input_price_per_1k'] ?? 0.003;
        $outputPrice = $pricing['output_price_per_1k'] ?? 0.015;

        return round(
            ($inputTokens / 1000) * $inputPrice + ($expectedOutputTokens / 1000) * $outputPrice,
            6
        );
    }

    /**
     * Estimate tokens for multimodal message blocks (text + images + documents).
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     */
    public static function estimateMultimodal(array $messages, string $systemPrompt = ''): int
    {
        $tokens = self::estimate($systemPrompt);

        foreach ($messages as $msg) {
            $content = $msg['content'];

            if (is_string($content)) {
                $tokens += self::estimate($content);

                continue;
            }

            foreach ($content as $block) {
                $type = $block['type'] ?? 'text';

                if ($type === 'text') {
                    $tokens += self::estimate($block['text'] ?? '');
                } elseif ($type === 'image') {
                    $tokens += self::IMAGE_TOKENS_DEFAULT;
                } elseif ($type === 'document') {
                    $dataLen = strlen($block['data'] ?? '');
                    $tokens += max(100, (int) ceil($dataLen / self::DOCUMENT_BYTES_PER_TOKEN));
                }
            }
        }

        return $tokens;
    }
}

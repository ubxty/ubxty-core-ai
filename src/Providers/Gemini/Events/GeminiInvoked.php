<?php

namespace Ubxty\CoreAi\Providers\Gemini\Events;

use Ubxty\CoreAi\Events\AiInvoked;

class GeminiInvoked extends AiInvoked
{
    public function __construct(
        string $modelId,
        int $inputTokens,
        int $outputTokens,
        float $cost,
        int $latencyMs,
        string $keyUsed,
        ?string $connection = null,
    ) {
        parent::__construct(
            modelId: $modelId,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cost: $cost,
            latencyMs: $latencyMs,
            keyUsed: $keyUsed,
            connection: $connection,
            platform: 'Google Gemini',
        );
    }
}

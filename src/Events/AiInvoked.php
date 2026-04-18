<?php

namespace Ubxty\CoreAi\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AiInvoked
{
    use Dispatchable;

    public function __construct(
        public readonly string $modelId,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $cost,
        public readonly int $latencyMs,
        public readonly string $keyUsed,
        public readonly ?string $connection = null,
        public readonly ?string $platform = null,
    ) {}
}

<?php

namespace Ubxty\CoreAi\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AiRateLimited
{
    use Dispatchable;

    public function __construct(
        public readonly string $modelId,
        public readonly string $keyLabel,
        public readonly int $retryAttempt,
        public readonly int $waitSeconds,
        public readonly ?string $platform = null,
    ) {}
}

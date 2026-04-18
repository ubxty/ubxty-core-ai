<?php

namespace Ubxty\CoreAi\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AiKeyRotated
{
    use Dispatchable;

    public function __construct(
        public readonly string $fromKeyLabel,
        public readonly string $toKeyLabel,
        public readonly string $reason,
        public readonly string $modelId,
        public readonly ?string $platform = null,
    ) {}
}

<?php

namespace Ubxty\CoreAi\Contracts;

final readonly class LLMResult
{
    public function __construct(
        public string $text,
        public array $toolCalls = [],
        public ?string $finishReason = null,
        public ?LLMUsage $usage = null,
        public ?string $modelId = null,
        public ?string $keyLabel = null,
        public int $latencyMs = 0,
        public bool $cached = false,
        public array $raw = [],
    ) {}
}

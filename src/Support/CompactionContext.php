<?php

namespace Ubxty\CoreAi\Support;

/**
 * Inputs to a {@see \Ubxty\CoreAi\Contracts\ConversationCompactor} run.
 *
 * `recentTurns` is the number of trailing messages to preserve verbatim.
 * `systemPromptSlug` lets implementations key caches (e.g. an LLM-summarising
 * compactor wants a deterministic hash of the system prompt it was given).
 */
final readonly class CompactionContext
{
    public function __construct(
        public ?int $recentTurns = 10,
        public ?string $systemPromptSlug = null,
    ) {}
}

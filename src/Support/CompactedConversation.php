<?php

namespace Ubxty\CoreAi\Support;

/**
 * Output of a {@see \Ubxty\CoreAi\Contracts\ConversationCompactor} run.
 *
 * `anchor` is a textual or structured summary of the older messages the
 * compactor dropped (empty when no summarisation is performed, e.g. a pure
 * sliding window). `recent` is the verbatim tail of the conversation that
 * the model will see. `strategy` identifies the compactor implementation
 * (e.g. "sliding_window", "llm_summary") — useful for telemetry and cache
 * invalidation keys.
 */
final readonly class CompactedConversation
{
    /**
     * @param  array<int, array{role: string, content: string|array}>  $recent
     */
    public function __construct(
        public string $anchor,
        public array $recent,
        public string $strategy,
    ) {}
}

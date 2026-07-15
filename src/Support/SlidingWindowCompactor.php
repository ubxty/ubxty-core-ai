<?php

namespace Ubxty\CoreAi\Support;

use Ubxty\CoreAi\Contracts\ConversationCompactor;

/**
 * Default {@see ConversationCompactor} that keeps the trailing
 * `CompactionContext::$recentTurns` messages verbatim and drops the rest.
 *
 * `anchor` is always empty — no summarisation is attempted. The strategy
 * tag is "sliding_window" so callers / telemetry can distinguish it from
 * an LLM-summarising compactor in the host application.
 *
 * When the conversation is already short enough to fit, the message array
 * is passed through unchanged so the SDK sees the same shape it would have
 * seen without compaction.
 */
class SlidingWindowCompactor implements ConversationCompactor
{
    public function compact(array $messages, CompactionContext $ctx): CompactedConversation
    {
        $recentTurns = $ctx->recentTurns ?? 10;

        if (count($messages) <= $recentTurns) {
            return new CompactedConversation('', $messages, 'sliding_window');
        }

        return new CompactedConversation(
            '',
            array_slice($messages, -$recentTurns),
            'sliding_window'
        );
    }
}

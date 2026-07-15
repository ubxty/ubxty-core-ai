<?php

namespace Ubxty\CoreAi\Contracts;

use Ubxty\CoreAi\Support\CompactedConversation;
use Ubxty\CoreAi\Support\CompactionContext;

interface ConversationCompactor
{
    /**
     * Reduce a multi-turn message history into a compact form for the model.
     *
     * Implementations may:
     *   - return a sliced recent window (sliding),
     *   - summarise older turns and return `anchor` + recent,
     *   - or any mix, exposed via the `strategy` field of the result.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     */
    public function compact(array $messages, CompactionContext $ctx): CompactedConversation;
}

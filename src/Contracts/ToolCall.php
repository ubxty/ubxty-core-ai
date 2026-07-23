<?php

namespace Ubxty\CoreAi\Contracts;

/**
 * Immutable representation of a single tool/function call requested by the
 * model. Constructed by the per-standard `parseResponse()` implementations
 * (OpenAI / Claude / Converse / Gemini) and consumed by the higher-level
 * orchestration that decides whether to dispatch the tool and re-issue a
 * follow-up turn.
 *
 * - `$id`       — the wire-format tool-call id (`call_…` from OpenAI,
 *                 `toolu_…` from Claude, etc.). Used to correlate the
 *                 follow-up `tool_result` / `functionResponse` block.
 * - `$name`     — the tool's declared name.
 * - `$arguments` — decoded JSON object as a PHP associative array.
 * - `$raw`      — the original wire-format payload for standards that need
 *                 to round-trip the call (e.g. passing it back as a
 *                 `tool_use` block on the next turn).
 */
final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
        public ?array $raw = null,
    ) {}
}

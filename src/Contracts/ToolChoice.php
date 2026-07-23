<?php

namespace Ubxty\CoreAi\Contracts;

/**
 * Immutable tool-choice directive sent to an LLM provider.
 *
 * The wire format is provider-specific (OpenAI uses an enum/object, Anthropic
 * and Bedrock Converse use a string, Gemini uses a different enum), so callers
 * pick one of these factory methods and the per-standard `RequestBuilder`
 * translates the carried `$value` into whatever shape the API expects. The
 * four standard encodings are:
 *
 *  - `auto`  — model decides whether to call a tool (`any`-equivalent for some).
 *  - `any`   — model must call at least one of the registered tools.
 *  - `none`  — suppress tool calling entirely.
 *  - `tool:<name>` — model must call the named tool.
 *
 * Constructed via the static factories because the allowed values are
 * enumerated; the constructor is private so callers cannot smuggle in an
 * arbitrary string.
 */
final readonly class ToolChoice
{
    private function __construct(public string $value) {}

    public static function auto(): self { return new self('auto'); }

    public static function any(): self { return new self('any'); }

    public static function none(): self { return new self('none'); }

    public static function tool(string $name): self { return new self('tool:' . $name); }
}

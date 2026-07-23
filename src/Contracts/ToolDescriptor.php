<?php

namespace Ubxty\CoreAi\Contracts;

/**
 * Immutable description of a callable tool that may be surfaced to a model
 * during converse / converseStream calls.
 *
 * `$parameters` is a JSON Schema object describing the tool's input shape —
 * the standard clients map it through their per-vendor tool-calling wire
 * formats (OpenAI `tools[].parameters`, Claude `input_schema`, Bedrock
 * Converse `toolSpec.inputSchema.json`, Gemini `functionDeclarations[].parameters`).
 */
final readonly class ToolDescriptor
{
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = [],
    ) {}
}

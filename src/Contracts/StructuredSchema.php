<?php

namespace Ubxty\CoreAi\Contracts;

/**
 * Immutable description of a JSON Schema the model must conform to.
 *
 * `$name` is a stable identifier (e.g. 'QueryPlan') so the structured-output
 * payload can be referenced across turns without re-sending the schema body.
 * `$schema` is the JSON Schema definition. When `$strict` is true, providers
 * are expected to enforce strict adherence to the schema (no additional
 * properties, no missing required fields).
 */
final readonly class StructuredSchema
{
    public function __construct(
        public string $name,                   // e.g. 'QueryPlan'
        public array $schema = [],             // JSON Schema
        public bool $strict = true,
    ) {}
}
<?php

namespace Ubxty\CoreAi\Client;

class ModelAliasResolver
{
    /** @var array<string, string> */
    protected array $aliases = [];

    /**
     * @param  array<string, string>  $aliases  Map of alias => model ID
     */
    public function __construct(array $aliases = [])
    {
        $this->aliases = $aliases;
    }

    /**
     * Resolve a model alias to its full model ID.
     * Returns the original string if no alias is found.
     */
    public function resolve(string $modelIdOrAlias): string
    {
        return $this->aliases[$modelIdOrAlias] ?? $modelIdOrAlias;
    }

    /**
     * Register a new alias.
     */
    public function register(string $alias, string $modelId): void
    {
        $this->aliases[$alias] = $modelId;
    }

    /**
     * Check if a string is a registered alias.
     */
    public function isAlias(string $value): bool
    {
        return isset($this->aliases[$value]);
    }

    /**
     * Get all registered aliases.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->aliases;
    }
}

<?php

namespace Ubxty\CoreAi\Client;

use Ubxty\CoreAi\Exceptions\ConfigurationException;

abstract class AbstractCredentialManager
{
    /** @var array<int, array> */
    protected array $keys = [];

    protected int $currentIndex = 0;

    public function __construct(array $keys)
    {
        if (empty($keys)) {
            throw new ConfigurationException('No credential keys configured.');
        }

        $this->keys = array_values(array_map([$this, 'normalizeKey'], $keys));
    }

    /**
     * Normalize a key config entry. Platform-specific.
     */
    abstract protected function normalizeKey(array $key): array;

    /**
     * Get the current credential set.
     */
    public function current(): array
    {
        return $this->keys[$this->currentIndex];
    }

    /**
     * Advance to the next credential set. Returns false if no more keys.
     */
    public function next(): bool
    {
        if ($this->currentIndex + 1 >= count($this->keys)) {
            return false;
        }

        $this->currentIndex++;

        return true;
    }

    /**
     * Reset to the first credential set.
     */
    public function reset(): void
    {
        $this->currentIndex = 0;
    }

    /**
     * Select a specific key by index.
     */
    public function select(int $index): void
    {
        if (! isset($this->keys[$index])) {
            throw new ConfigurationException("Key index {$index} does not exist.");
        }

        $this->currentIndex = $index;
    }

    /**
     * Get the number of available credential sets.
     */
    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * Get the current key index.
     */
    public function currentIndex(): int
    {
        return $this->currentIndex;
    }

    /**
     * Get all keys (safe info only, no secrets).
     *
     * @return array<int, array{index: int, label: string, configured: bool}>
     */
    abstract public function list(): array;
}

<?php

namespace Ubxty\CoreAi\Providers\Anthropic\Client;

use Ubxty\CoreAi\Client\AbstractCredentialManager;
use Ubxty\CoreAi\Exceptions\ConfigurationException;

class AnthropicCredentialManager extends AbstractCredentialManager
{
    protected function normalizeKey(array $key): array
    {
        return [
            'label' => $key['label'] ?? 'Primary',
            'api_key' => $key['api_key'] ?? '',
            'anthropic_version' => $key['anthropic_version'] ?? '2023-06-01',
            'base_url' => $key['base_url'] ?? 'https://api.anthropic.com',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        return array_map(function (array $key): array {
            return [
                'index' => 0,
                'label' => (string) $key['label'],
                'base_url' => (string) $key['base_url'],
                'anthropic_version' => (string) $key['anthropic_version'],
                'configured' => ! empty($key['api_key']),
            ];
        }, $this->keys, array_keys($this->keys));
    }

    public function current(): array
    {
        $key = parent::current();

        if (empty($key['api_key'])) {
            throw new ConfigurationException(
                'No Anthropic API key configured. Set ANTHROPIC_API_KEY in .env or run `php artisan anthropic:configure`.'
            );
        }

        return $key;
    }

    public function getApiKey(): string
    {
        return (string) $this->current()['api_key'];
    }

    public function getBaseUrl(): string
    {
        return (string) $this->current()['base_url'];
    }
}
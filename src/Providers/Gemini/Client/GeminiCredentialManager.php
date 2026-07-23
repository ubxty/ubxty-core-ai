<?php

namespace Ubxty\CoreAi\Providers\Gemini\Client;

use Ubxty\CoreAi\Client\AbstractCredentialManager;
use Ubxty\CoreAi\Exceptions\ConfigurationException;

class GeminiCredentialManager extends AbstractCredentialManager
{
    protected function normalizeKey(array $key): array
    {
        return [
            'label' => $key['label'] ?? 'Primary',
            'api_key' => $key['api_key'] ?? '',
            'base_url' => $key['base_url'] ?? 'https://generativelanguage.googleapis.com',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        return array_values(array_map(function (array $key, int $index): array {
            return [
                'index' => $index,
                'label' => (string) $key['label'],
                'base_url' => (string) $key['base_url'],
                'configured' => ! empty($key['api_key']),
            ];
        }, $this->keys, array_keys($this->keys)));
    }

    public function current(): array
    {
        $key = parent::current();

        if (empty($key['api_key'])) {
            throw new ConfigurationException(
                'No Gemini API key configured. Set GOOGLE_GEMINI_API_KEY in .env or run `php artisan gemini-ai:configure`.'
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

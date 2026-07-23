<?php

namespace Ubxty\CoreAi\Providers\OpenAI\Client;

use Ubxty\CoreAi\Client\AbstractCredentialManager;
use Ubxty\CoreAi\Exceptions\ConfigurationException;

class OpenAiCredentialManager extends AbstractCredentialManager
{
    protected function normalizeKey(array $key): array
    {
        return [
            'label' => $key['label'] ?? 'Primary',
            'api_key' => $key['api_key'] ?? '',
            'organization' => $key['organization'] ?? null,
            'project' => $key['project'] ?? null,
            'base_url' => $key['base_url'] ?? 'https://api.openai.com',
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
                'organization' => $key['organization'] ?? null,
                'project' => $key['project'] ?? null,
                'configured' => ! empty($key['api_key']),
            ];
        }, $this->keys, array_keys($this->keys)));
    }

    public function current(): array
    {
        $key = parent::current();

        if (empty($key['api_key'])) {
            throw new ConfigurationException(
                'No OpenAI API key configured. Set OPENAI_API_KEY in .env or run `php artisan openai-ai:configure`.'
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

    public function getOrganization(): ?string
    {
        $org = $this->current()['organization'] ?? null;

        return $org !== null && $org !== '' ? (string) $org : null;
    }

    public function getProject(): ?string
    {
        $project = $this->current()['project'] ?? null;

        return $project !== null && $project !== '' ? (string) $project : null;
    }
}

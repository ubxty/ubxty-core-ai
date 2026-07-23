<?php

namespace Ubxty\CoreAi\Providers\Gemini\Commands;

use Ubxty\CoreAi\Commands\AbstractConfigureCommand;
use Ubxty\CoreAi\Providers\Gemini\GeminiManager;

class ConfigureCommand extends AbstractConfigureCommand
{
    protected $signature = 'gemini-ai:configure {--connection=default : Connection name}';

    protected $description = 'Configure Google Gemini API credentials';

    public function handle(GeminiManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeConfigure();
    }

    protected function platformName(): string
    {
        return 'Google Gemini';
    }

    protected function envPrefix(): string
    {
        return 'GOOGLE_GEMINI';
    }

    /** @return array<string, string> */
    protected function requiredEnvKeys(): array
    {
        return [
            'GOOGLE_GEMINI_API_KEY' => 'Google Gemini API key (query-string API key)',
        ];
    }
}

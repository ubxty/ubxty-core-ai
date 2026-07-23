<?php

namespace Ubxty\CoreAi\Providers\OpenAI\Commands;

use Ubxty\CoreAi\Commands\AbstractConfigureCommand;
use Ubxty\CoreAi\Providers\OpenAI\OpenAiManager;

/**
 * OpenAI API-key configuration wizard.
 *
 * Extends core-ai's {@see AbstractConfigureCommand} to inherit the
 * configure-command flow (env-prefix discovery, key validation, .env
 * write, config:clear invocation).
 */
class ConfigureCommand extends AbstractConfigureCommand
{
    protected $signature = 'openai-ai:configure {--connection=default : Connection name}';

    protected $description = 'Configure OpenAI API credentials';

    public function handle(OpenAiManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeConfigure();
    }

    protected function platformName(): string
    {
        return 'OpenAI';
    }

    protected function envPrefix(): string
    {
        return 'OPENAI';
    }

    /** @return array<string, string> */
    protected function requiredEnvKeys(): array
    {
        return [
            'OPENAI_API_KEY' => 'OpenAI API key (sk-...)',
        ];
    }
}

<?php

namespace Ubxty\CoreAi\Providers\Anthropic\Commands;

use Ubxty\CoreAi\Providers\Anthropic\AnthropicManager;
use Ubxty\CoreAi\Commands\AbstractConfigureCommand;

/**
 * Anthropic API-key configuration wizard.
 *
 * Extends core-ai's {@see AbstractConfigureCommand} to inherit the
 * configure-command flow (env-prefix discovery, key validation, .env
 * write, config:clear invocation).
 */
class ConfigureCommand extends AbstractConfigureCommand
{
    protected $signature = 'anthropic:configure {--connection=default : Connection name}';

    protected $description = 'Configure Anthropic API credentials';

    public function handle(AnthropicManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeConfigure();
    }

    protected function platformName(): string
    {
        return 'Anthropic';
    }

    protected function envPrefix(): string
    {
        return 'ANTHROPIC';
    }

    /** @return array<string, string> */
    protected function requiredEnvKeys(): array
    {
        return [
            'ANTHROPIC_API_KEY' => 'Anthropic API key (x-api-key header value)',
        ];
    }
}

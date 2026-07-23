<?php

namespace Ubxty\CoreAi\Providers\Anthropic\Commands;

use Ubxty\CoreAi\Providers\Anthropic\AnthropicManager;
use Ubxty\CoreAi\Commands\AbstractDefaultModelCommand;

/**
 * Default chat + image model picker for Anthropic.
 *
 * Extends core-ai's {@see AbstractDefaultModelCommand} to inherit the
 * provider→model picker flow.
 */
class DefaultModelCommand extends AbstractDefaultModelCommand
{
    protected $signature = 'anthropic:default-model {--connection= : Connection name}';

    protected $description = 'Set the default chat and image Anthropic models';

    public function handle(AnthropicManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeDefaultModel();
    }

    protected function platformName(): string
    {
        return 'Anthropic';
    }

    /** @return array<string, string> */
    protected function envKeyMap(): array
    {
        return [
            'default' => 'ANTHROPIC_DEFAULT_MODEL',
            'image' => 'ANTHROPIC_DEFAULT_IMAGE_MODEL',
        ];
    }
}

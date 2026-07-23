<?php

namespace Ubxty\CoreAi\Providers\Anthropic\Commands;

use Ubxty\CoreAi\Providers\Anthropic\AnthropicManager;
use Ubxty\CoreAi\Commands\AbstractModelsCommand;

class ModelsCommand extends AbstractModelsCommand
{
    protected $signature = 'anthropic:models {--connection= : Connection name}';

    protected $description = 'List available Anthropic Claude models';

    public function handle(AnthropicManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeModels();
    }

    protected function platformName(): string
    {
        return 'Anthropic';
    }
}

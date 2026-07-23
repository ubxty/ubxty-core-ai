<?php

namespace Ubxty\CoreAi\Providers\OpenAI\Commands;

use Ubxty\CoreAi\Commands\AbstractModelsCommand;
use Ubxty\CoreAi\Providers\OpenAI\OpenAiManager;

class ModelsCommand extends AbstractModelsCommand
{
    protected $signature = 'openai-ai:models {--connection= : Connection name}';

    protected $description = 'List available OpenAI models';

    public function handle(OpenAiManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeModels();
    }

    protected function platformName(): string
    {
        return 'OpenAI';
    }
}

<?php

namespace Ubxty\CoreAi\Providers\Gemini\Commands;

use Ubxty\CoreAi\Commands\AbstractModelsCommand;
use Ubxty\CoreAi\Providers\Gemini\GeminiManager;

class ModelsCommand extends AbstractModelsCommand
{
    protected $signature = 'gemini-ai:models {--connection= : Connection name}';

    protected $description = 'List available Google Gemini models';

    public function handle(GeminiManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeModels();
    }

    protected function platformName(): string
    {
        return 'Google Gemini';
    }
}

<?php

namespace Ubxty\CoreAi\Providers\Gemini\Commands;

use Ubxty\CoreAi\Commands\AbstractTestCommand;
use Ubxty\CoreAi\Providers\Gemini\GeminiManager;

class TestCommand extends AbstractTestCommand
{
    protected $signature = 'gemini-ai:test
                            {model? : Model ID to test}
                            {--connection= : Connection name}
                            {--all-keys : Test all configured keys}';

    protected $description = 'Test connection and invoke a Google Gemini model';

    public function handle(GeminiManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeTest();
    }

    protected function platformName(): string
    {
        return 'Google Gemini';
    }
}

<?php

namespace Ubxty\CoreAi\Providers\OpenAI\Commands;

use Ubxty\CoreAi\Commands\AbstractTestCommand;
use Ubxty\CoreAi\Providers\OpenAI\OpenAiManager;

class TestCommand extends AbstractTestCommand
{
    protected $signature = 'openai-ai:test
                            {model? : Model ID to test}
                            {--connection= : Connection name}
                            {--all-keys : Test all configured keys}';

    protected $description = 'Test connection and invoke an OpenAI model';

    public function handle(OpenAiManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeTest();
    }

    protected function platformName(): string
    {
        return 'OpenAI';
    }
}

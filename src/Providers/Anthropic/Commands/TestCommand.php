<?php

namespace Ubxty\CoreAi\Providers\Anthropic\Commands;

use Ubxty\CoreAi\Providers\Anthropic\AnthropicManager;
use Ubxty\CoreAi\Commands\AbstractTestCommand;

class TestCommand extends AbstractTestCommand
{
    protected $signature = 'anthropic:test
                            {model? : Model ID to test}
                            {--connection= : Connection name}
                            {--all-keys : Test all configured keys}';

    protected $description = 'Test connection and invoke an Anthropic Claude model';

    public function handle(AnthropicManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeTest();
    }

    protected function platformName(): string
    {
        return 'Anthropic';
    }
}

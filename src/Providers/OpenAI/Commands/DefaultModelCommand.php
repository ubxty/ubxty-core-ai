<?php

namespace Ubxty\CoreAi\Providers\OpenAI\Commands;

use Ubxty\CoreAi\Commands\AbstractDefaultModelCommand;
use Ubxty\CoreAi\Providers\OpenAI\OpenAiManager;

/**
 * Default chat + image model picker for OpenAI.
 */
class DefaultModelCommand extends AbstractDefaultModelCommand
{
    protected $signature = 'openai-ai:default-model {--connection= : Connection name}';

    protected $description = 'Set the default chat and image OpenAI models';

    public function handle(OpenAiManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeDefaultModel();
    }

    protected function platformName(): string
    {
        return 'OpenAI';
    }

    /** @return array<string, string> */
    protected function envKeyMap(): array
    {
        return [
            'default' => 'OPENAI_DEFAULT_MODEL',
            'image' => 'OPENAI_DEFAULT_IMAGE_MODEL',
        ];
    }
}

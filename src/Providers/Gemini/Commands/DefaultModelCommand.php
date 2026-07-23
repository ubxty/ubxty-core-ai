<?php

namespace Ubxty\CoreAi\Providers\Gemini\Commands;

use Ubxty\CoreAi\Commands\AbstractDefaultModelCommand;
use Ubxty\CoreAi\Providers\Gemini\GeminiManager;

class DefaultModelCommand extends AbstractDefaultModelCommand
{
    protected $signature = 'gemini-ai:default-model {--connection= : Connection name}';

    protected $description = 'Set the default chat and image Google Gemini models';

    public function handle(GeminiManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeDefaultModel();
    }

    protected function platformName(): string
    {
        return 'Google Gemini';
    }

    /** @return array<string, string> */
    protected function envKeyMap(): array
    {
        return [
            'default' => 'GOOGLE_GEMINI_DEFAULT_MODEL',
            'image' => 'GOOGLE_GEMINI_DEFAULT_IMAGE_MODEL',
        ];
    }
}

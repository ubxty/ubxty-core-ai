<?php

namespace Ubxty\CoreAi\Providers\Gemini\Commands;

use Ubxty\CoreAi\Commands\AbstractChatCommand;
use Ubxty\CoreAi\Providers\Gemini\GeminiManager;

class ChatCommand extends AbstractChatCommand
{
    protected $signature = 'gemini-ai:chat
                            {model? : Model ID or alias to chat with (e.g. gemini-2.5-pro)}
                            {--connection= : Connection name}
                            {--system= : System prompt for the conversation}
                            {--max-tokens=8192 : Max tokens per response}
                            {--temperature=0.7 : Temperature for responses}
                            {--no-stream : Disable streaming (wait for full response)}';

    protected $description = 'Start an interactive chat session with a Google Gemini model';

    public function handle(GeminiManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeChat();
    }

    protected function platformName(): string
    {
        return 'Google Gemini';
    }

    protected function modelSupportsCaching(string $modelId): bool
    {
        return $this->manager->modelSupportsCaching($modelId);
    }

    protected function cachingBadge(string $modelId): string
    {
        return $this->modelSupportsCaching($modelId) ? ' <fg=magenta>[cached]</>' : '';
    }

    protected function shouldPromptForCacheMode(string $modelId): bool
    {
        return $this->modelSupportsCaching($modelId)
            && $this->manager->packageCachePointsConfigured();
    }

    protected function packageCachingEnabled(): bool
    {
        return $this->manager->packageCachePointsConfigured();
    }

    protected function cachePointsFor(bool $cachingEnabled): ?array
    {
        return $cachingEnabled ? $this->manager->configuredCachePoints() : [];
    }
}

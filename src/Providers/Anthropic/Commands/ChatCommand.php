<?php

namespace Ubxty\CoreAi\Providers\Anthropic\Commands;

use Ubxty\CoreAi\Providers\Anthropic\AnthropicManager;
use Ubxty\CoreAi\Commands\AbstractChatCommand;

/**
 * Interactive chat session against an Anthropic Claude model.
 *
 * Extends core-ai's {@see AbstractChatCommand} to inherit the multi-turn
 * loop, model picker, file commands, streaming toggle, paste spooling,
 * session token/cost tally, and `/help`/`/quit`/`/stats`/`/reset`/
 * `/system`/`/model`/`/temp`/`/cache` command surface.
 */
class ChatCommand extends AbstractChatCommand
{
    protected $signature = 'anthropic:chat
                            {model? : Model ID or alias to chat with (e.g. claude-sonnet-4-5-20250929)}
                            {--connection= : Connection name}
                            {--system= : System prompt for the conversation}
                            {--max-tokens=8192 : Max tokens per response}
                            {--temperature=0.7 : Temperature for responses}
                            {--no-stream : Disable streaming (wait for full response)}';

    protected $description = 'Start an interactive chat session with an Anthropic Claude model';

    public function handle(AnthropicManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeChat();
    }

    protected function platformName(): string
    {
        return 'Anthropic';
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

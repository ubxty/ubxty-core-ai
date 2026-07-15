<?php

namespace Ubxty\CoreAi;

use Illuminate\Support\ServiceProvider;
use Ubxty\CoreAi\Contracts\ConversationCompactor;
use Ubxty\CoreAi\Support\SlidingWindowCompactor;

class CoreAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/core-ai.php', 'core-ai');

        // Default ConversationCompactor: a sliding window. Host apps may
        // override this binding (e.g. with an LLM-summarising compactor) in
        // their own service provider after CoreAiServiceProvider registers.
        $this->app->bindIf(ConversationCompactor::class, fn () => new SlidingWindowCompactor);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/core-ai.php' => config_path('core-ai.php'),
            ], 'core-ai-config');
        }
    }
}

<?php

namespace Ubxty\CoreAi;

use Illuminate\Support\ServiceProvider;

class CoreAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/core-ai.php', 'core-ai');
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

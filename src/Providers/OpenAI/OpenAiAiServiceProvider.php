<?php

namespace Ubxty\CoreAi\Providers\OpenAI;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OpenAiAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OpenAiManager::class, function ($app) {
            return new OpenAiManager($app['config']->get('core-ai.openai_ai', []));
        });

        $this->app->alias(OpenAiManager::class, 'openai-ai');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ChatCommand::class,
                Commands\ConfigureCommand::class,
                Commands\DefaultModelCommand::class,
                Commands\ModelsCommand::class,
                Commands\TestCommand::class,
            ]);
        }

        $config = $this->app['config']->get('core-ai.openai_ai.health_check', []);

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        Route::get(
            $config['path'] ?? '/health/openai',
            \Ubxty\CoreAi\Http\HealthCheckController::class,
        )->middleware($config['middleware'] ?? [])
            ->name('openai-ai.health');
    }
}

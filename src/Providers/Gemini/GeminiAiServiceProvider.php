<?php

namespace Ubxty\CoreAi\Providers\Gemini;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class GeminiAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeminiManager::class, function ($app) {
            return new GeminiManager($app['config']->get('core-ai.gemini_ai', []));
        });

        $this->app->alias(GeminiManager::class, 'gemini-ai');
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

        $config = $this->app['config']->get('core-ai.gemini_ai.health_check', []);

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        Route::get(
            $config['path'] ?? '/health/gemini',
            \Ubxty\CoreAi\Http\HealthCheckController::class,
        )->middleware($config['middleware'] ?? [])
            ->name('gemini-ai.health');
    }
}

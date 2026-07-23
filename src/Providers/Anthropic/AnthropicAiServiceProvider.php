<?php

namespace Ubxty\CoreAi\Providers\Anthropic;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AnthropicAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnthropicManager::class, function ($app) {
            return new AnthropicManager($app['config']->get('core-ai.anthropic_ai', []));
        });

        $this->app->alias(AnthropicManager::class, 'anthropic');
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

        $config = $this->app['config']->get('core-ai.anthropic_ai.health_check', []);

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        Route::get(
            $config['path'] ?? '/health/anthropic',
            \Ubxty\CoreAi\Http\HealthCheckController::class,
        )->middleware($config['middleware'] ?? [])
            ->name('anthropic-ai.health');
    }
}

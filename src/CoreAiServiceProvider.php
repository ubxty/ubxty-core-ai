<?php

namespace Ubxty\CoreAi;

use Illuminate\Support\ServiceProvider;
use Ubxty\CoreAi\Contracts\ConversationCompactor;
use Ubxty\CoreAi\Support\SlidingWindowCompactor;
use Ubxty\CoreAi\Providers\Anthropic\AnthropicAiServiceProvider;
use Ubxty\CoreAi\Providers\Gemini\GeminiAiServiceProvider;
use Ubxty\CoreAi\Providers\OpenAI\OpenAiAiServiceProvider;

class CoreAiServiceProvider extends ServiceProvider
{
    /** @var array<class-string<ServiceProvider>> */
    private const PROVIDER_SERVICES = [
        AnthropicAiServiceProvider::class,
        OpenAiAiServiceProvider::class,
        GeminiAiServiceProvider::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/core-ai.php', 'core-ai');

        // Default ConversationCompactor: a sliding window. Host apps may
        // override this binding in their own service provider.
        $this->app->bindIf(ConversationCompactor::class, fn () => new SlidingWindowCompactor);

        foreach ($this->providerServices() as $provider) {
            $provider->register();
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/core-ai.php' => config_path('core-ai.php'),
            ], 'core-ai-config');
        }

        foreach ($this->providerServices() as $provider) {
            $provider->boot();
        }
    }

    /** @return list<ServiceProvider> */
    private function providerServices(): array
    {
        return array_map(
            fn (string $provider) => new $provider($this->app),
            self::PROVIDER_SERVICES,
        );
    }
}

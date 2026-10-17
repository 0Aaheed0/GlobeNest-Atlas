<?php

namespace App\Providers;

use App\Services\ChatCompletionClient;
use App\Services\GroqClient;
use App\Services\OpenRouterClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ChatCompletionClient::class, function (Application $app): ChatCompletionClient {
            return match (config('services.ai.provider') ?? 'openrouter') {
                'openrouter' => $app->make(OpenRouterClient::class),
                'groq' => $app->make(GroqClient::class),
                default => throw new InvalidArgumentException('AI_PROVIDER must be set to openrouter or groq.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

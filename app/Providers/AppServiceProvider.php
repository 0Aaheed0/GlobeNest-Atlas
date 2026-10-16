<?php

namespace App\Providers;

use App\Services\ChatCompletionClient;
use App\Services\OpenRouterClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ChatCompletionClient::class, OpenRouterClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

<?php

namespace App\Providers;

use App\Services\ChatCompletionClient;
use App\Services\GroqClient;
use App\Services\OpenRouterClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('login', function (Request $request): array {
            return [
                Limit::perMinute(5)
                    ->by($this->accountRateLimitKey('login', $request->input('email')))
                    ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                        $request,
                        $headers,
                        'Too many sign-in attempts. Please try again in %d seconds.',
                    )),
                Limit::perMinute(20)
                    ->by($this->ipRateLimitKey('login', $request->ip()))
                    ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                        $request,
                        $headers,
                        'Too many sign-in attempts. Please try again in %d seconds.',
                    )),
            ];
        });

        RateLimiter::for('registration', function (Request $request): array {
            return [
                Limit::perMinutes(15, 5)
                    ->by($this->accountRateLimitKey('registration', $request->input('email')))
                    ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                        $request,
                        $headers,
                        'Too many registration attempts. Please try again in %d seconds.',
                    )),
                Limit::perMinutes(15, 10)
                    ->by($this->ipRateLimitKey('registration', $request->ip()))
                    ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                        $request,
                        $headers,
                        'Too many registration attempts. Please try again in %d seconds.',
                    )),
            ];
        });

        RateLimiter::for('registration.verify', function (Request $request): Limit {
            return Limit::perMinutes(15, 30)
                ->by($this->ipRateLimitKey('registration.verify', $request->ip()))
                ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                    $request,
                    $headers,
                    'Too many verification attempts. Please try again in %d seconds.',
                    'verification_code',
                ));
        });

        RateLimiter::for('registration.resend', function (Request $request): array {
            $email = $request->session()->get('pending_registration.email');

            return [
                Limit::perMinutes(15, 3)
                    ->by($this->accountRateLimitKey('registration.resend', $email))
                    ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                        $request,
                        $headers,
                        'Too many code requests. Please try again in %d seconds.',
                        'verification_code',
                    )),
                Limit::perMinutes(15, 10)
                    ->by($this->ipRateLimitKey('registration.resend', $request->ip()))
                    ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                        $request,
                        $headers,
                        'Too many code requests. Please try again in %d seconds.',
                        'verification_code',
                    )),
            ];
        });

        RateLimiter::for('password.recovery.request', function (Request $request): array {
            return [
                Limit::perMinutes(15, 3)
                    ->by($this->accountRateLimitKey('password.recovery.request', $request->input('email')))
                    ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                        $request,
                        $headers,
                        'Too many recovery requests. Please try again in %d seconds.',
                    )),
                Limit::perMinutes(15, 10)
                    ->by($this->ipRateLimitKey('password.recovery.request', $request->ip()))
                    ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                        $request,
                        $headers,
                        'Too many recovery requests. Please try again in %d seconds.',
                    )),
            ];
        });

        RateLimiter::for('password.recovery.verify', function (Request $request): array {
            return [
                Limit::perMinutes(15, 5)
                    ->by($this->accountRateLimitKey('password.recovery.verify', $request->input('email')))
                    ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                        $request,
                        $headers,
                        'Too many recovery attempts. Please try again in %d seconds.',
                        'recovery_code',
                    )),
                Limit::perMinutes(15, 20)
                    ->by($this->ipRateLimitKey('password.recovery.verify', $request->ip()))
                    ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                        $request,
                        $headers,
                        'Too many recovery attempts. Please try again in %d seconds.',
                        'recovery_code',
                    )),
            ];
        });
    }

    private function accountRateLimitKey(string $purpose, mixed $identifier): string
    {
        $normalizedIdentifier = mb_strtolower(trim(is_string($identifier) ? $identifier : ''));

        return $purpose.':account:'.hash_hmac('sha256', $normalizedIdentifier, (string) config('app.key'));
    }

    private function ipRateLimitKey(string $purpose, ?string $ipAddress): string
    {
        return $purpose.':ip:'.hash_hmac('sha256', (string) $ipAddress, (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function throttledResponse(Request $request, array $headers, string $message, string $field = 'email'): JsonResponse|RedirectResponse
    {
        $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));
        $message = sprintf($message, $retryAfter);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 429, $headers);
        }

        return back()->withErrors([$field => $message]);
    }
}

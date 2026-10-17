<?php

namespace Tests\Feature;

use App\Services\ChatCompletionClient;
use App\Services\ChatCompletionProviderException;
use App\Services\GroqClient;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GroqClientTest extends TestCase
{
    private const GROQ_ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.groq.key', 'private-groq-test-key');
        Config::set('services.groq.model', 'openai/gpt-oss-120b');
    }

    public function test_openrouter_remains_the_default_provider_and_groq_is_opt_in(): void
    {
        Config::set('services.ai.provider', null);
        $this->assertInstanceOf(OpenRouterClient::class, app(ChatCompletionClient::class));

        Config::set('services.ai.provider', 'groq');
        $this->assertInstanceOf(GroqClient::class, app(ChatCompletionClient::class));
    }

    public function test_groq_smoke_test_uses_a_synthetic_tool_with_auto_choice_and_openai_compatible_payload(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::GROQ_ENDPOINT => Http::response($this->toolResponse('atlas_tool_check', [
                'token' => 'atlas-tool-check',
            ])),
        ]);

        $this->artisan('groq:test')
            ->expectsOutput('HTTP status: 200.')
            ->expectsOutput('Finish reason: tool_calls.')
            ->expectsOutput('tool_calls present: yes.')
            ->expectsOutput('Tool call count: 1.')
            ->expectsOutput('Groq connection and synthetic tool-call smoke test succeeded for model openai/gpt-oss-120b.')
            ->doesntExpectOutput('private-groq-test-key')
            ->assertSuccessful();

        $requests = Http::recorded();
        $this->assertCount(1, $requests);
        $request = $requests->first()[0];
        $this->assertSame(self::GROQ_ENDPOINT, $request->url());
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertSame('openai/gpt-oss-120b', $request['model']);
        $this->assertSame('Call the tool with token atlas-tool-check.', $request['messages'][0]['content']);
        $this->assertSame('auto', $request['tool_choice']);
        $this->assertSame('atlas_tool_check', $request['tools'][0]['function']['name']);
        $this->assertSame(128, $request['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $request->data());
        $this->assertStringNotContainsString('private-groq-test-key', $request->body());
    }

    public function test_groq_rejected_key_is_reported_without_provider_body_or_credentials(): void
    {
        $apiKey = 'private-groq-test-key';
        Http::preventStrayRequests();
        Http::fake([
            self::GROQ_ENDPOINT => Http::response([
                'error' => ['message' => 'Rejected credential '.$apiKey],
            ], 401),
        ]);
        Log::spy();

        try {
            app(GroqClient::class)->complete(['messages' => [['role' => 'user', 'content' => 'synthetic']]]);
            $this->fail('A rejected Groq API key must fail.');
        } catch (ChatCompletionProviderException $exception) {
            $this->assertSame(503, $exception->httpStatus);
            $this->assertSame('Groq rejected the configured API key. Verify GROQ_API_KEY on the server.', $exception->getMessage());
            $this->assertSame('invalid_api_key', $exception->diagnostics['error_category']);
            $this->assertStringNotContainsString($apiKey, $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($apiKey): bool {
            $this->assertSame('Groq API request failed.', $message);
            $this->assertStringNotContainsString($apiKey, json_encode($context));
            $this->assertArrayNotHasKey('provider_error_message', $context);

            return true;
        });
    }

    public function test_groq_rate_limit_is_explained_as_account_quota_without_retrying_or_leaking_body(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::GROQ_ENDPOINT => Http::response([
                'error' => ['message' => 'private quota response'],
            ], 429, ['Retry-After' => '12']),
        ]);

        try {
            app(GroqClient::class)->complete(['messages' => [['role' => 'user', 'content' => 'synthetic']]]);
            $this->fail('A Groq rate limit must be surfaced.');
        } catch (ChatCompletionProviderException $exception) {
            $this->assertSame(429, $exception->httpStatus);
            $this->assertSame('rate_limit_or_quota', $exception->diagnostics['error_category']);
            $this->assertSame('12 seconds', $exception->diagnostics['retry_after']);
            $this->assertStringNotContainsString('private quota response', $exception->getMessage());
        }

        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_groq_rejected_request_reports_safe_tool_compatibility_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::GROQ_ENDPOINT => Http::response([
                'error' => ['message' => 'private request payload detail'],
            ], 400),
        ]);

        try {
            app(GroqClient::class)->complete([
                'messages' => [['role' => 'user', 'content' => 'synthetic']],
                'tools' => [],
                'tool_choice' => 'auto',
            ]);
            $this->fail('A rejected provider request must not be treated as successful.');
        } catch (ChatCompletionProviderException $exception) {
            $this->assertSame(422, $exception->httpStatus);
            $this->assertSame('request_rejected', $exception->diagnostics['error_category']);
            $this->assertSame('Groq rejected the request. Check the configured model and tool-calling compatibility.', $exception->getMessage());
            $this->assertStringNotContainsString('private request payload detail', $exception->getMessage());
        }
    }

    public function test_groq_timeouts_are_safe_provider_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::GROQ_ENDPOINT => Http::failedConnection('cURL error 28: Operation timed out'),
        ]);

        try {
            app(GroqClient::class)->complete(['messages' => [['role' => 'user', 'content' => 'synthetic']]]);
            $this->fail('A timed-out request must fail safely.');
        } catch (ChatCompletionProviderException $exception) {
            $this->assertSame(504, $exception->httpStatus);
            $this->assertSame('Groq did not respond before the request timed out. Try again later.', $exception->getMessage());
        }

    }

    public function test_groq_malformed_responses_are_safe_provider_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GROQ_ENDPOINT => Http::response('not-json', 200)]);

        try {
            app(GroqClient::class)->complete(['messages' => [['role' => 'user', 'content' => 'synthetic']]]);
            $this->fail('A malformed response must fail safely.');
        } catch (ChatCompletionProviderException $exception) {
            $this->assertSame(502, $exception->httpStatus);
            $this->assertSame('invalid_response', $exception->diagnostics['error_category']);
            $this->assertStringNotContainsString('not-json', $exception->getMessage());
        }
    }

    public function test_groq_missing_key_fails_before_network_access(): void
    {
        Config::set('services.groq.key', null);
        Http::preventStrayRequests();

        try {
            app(GroqClient::class)->complete(['messages' => [['role' => 'user', 'content' => 'synthetic']]]);
            $this->fail('A missing key must fail before a request is sent.');
        } catch (ChatCompletionProviderException $exception) {
            $this->assertSame(503, $exception->httpStatus);
            $this->assertSame('Atlas AI is not configured. Set GROQ_API_KEY on the server.', $exception->getMessage());
        }

        $this->assertSame(0, Http::recorded()->count());
    }

    /**
     * @return array{choices: list<array<string, mixed>>}
     */
    private function toolResponse(string $name, array $arguments): array
    {
        return [
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_synthetic',
                        'type' => 'function',
                        'function' => [
                            'name' => $name,
                            'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
                        ],
                    ]],
                ],
            ]],
        ];
    }
}

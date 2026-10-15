<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AgentToolRegistry;
use App\Services\GeminiAgent;
use App\Services\GeminiProviderException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class AgentToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_tools_only_read_records_belonging_to_the_signed_in_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreignPlan = $other->tuitionPlans()->create([
            'title' => 'Private tuition plan',
            'amount' => '9000.00',
            'saved_amount' => '100.00',
            'currency' => 'USD',
            'due_date' => today()->addMonths(5),
            'installments' => 2,
        ]);
        $conversation = $owner->agentConversations()->create(['title' => 'My plan']);
        $registry = app(AgentToolRegistry::class);

        try {
            $registry->execute($owner, 'plan_tuition', ['tuition_plan_id' => $foreignPlan->id], $conversation);
            $this->fail('A user must not read another user’s tuition plan.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('agent_audit_logs', [
                'user_id' => $owner->id,
                'tool_name' => 'plan_tuition',
                'status' => 'rejected',
            ]);
        }
    }

    public function test_tool_rejects_unexpected_arguments_and_invalid_schemas(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Validation']);
        $registry = app(AgentToolRegistry::class);

        try {
            $registry->execute($user, 'get_financial_summary', ['user_id' => 123], $conversation);
            $this->fail('Unknown model arguments must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('agent_audit_logs', ['user_id' => $user->id, 'status' => 'rejected']);
        }

        $this->expectException(ValidationException::class);
        $registry->execute($user, 'analyze_spending', ['period_days' => 0], $conversation);
    }

    public function test_mutating_tool_waits_for_explicit_confirmation_and_writes_an_audit_trail(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Savings']);
        $registry = app(AgentToolRegistry::class);
        $arguments = [
            'title' => 'Emergency fund',
            'target_amount' => '1200.00',
            'currency' => 'USD',
            'current_amount' => '100.00',
        ];

        $prepared = $registry->execute($user, 'create_savings_goal', $arguments, $conversation);

        $this->assertTrue($prepared['confirmation_required']);
        $this->assertDatabaseCount('savings_goals', 0);
        $this->assertDatabaseHas('agent_audit_logs', ['status' => 'awaiting_confirmation']);

        $saved = $registry->confirm($user, $conversation, (int) $prepared['pending_action']['id']);

        $this->assertTrue($saved['saved']);
        $this->assertDatabaseHas('savings_goals', ['user_id' => $user->id, 'title' => 'Emergency fund']);
        $this->assertDatabaseHas('agent_audit_logs', ['status' => 'confirmed']);
    }

    public function test_tuition_tool_returns_deterministic_savings_plan_for_an_owned_record(): void
    {
        $user = User::factory()->create();
        $plan = $user->tuitionPlans()->create([
            'title' => 'Autumn tuition',
            'amount' => '6000.00',
            'saved_amount' => '1000.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(90),
            'installments' => 2,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Tuition']);

        $result = app(AgentToolRegistry::class)->execute($user, 'plan_tuition', ['tuition_plan_id' => $plan->id], $conversation);

        $this->assertSame('5000.00', $result['schedule']['remaining']);
        $this->assertSame('on_track', $result['schedule']['status']);
    }

    public function test_spending_tool_keeps_currencies_separate_and_excludes_other_users(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Spending']);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Food',
            'description' => 'Groceries',
            'amount' => '30.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Food',
            'description' => 'Market',
            'amount' => '20.00',
            'currency' => 'EUR',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $other->transactions()->create([
            'kind' => 'expense',
            'category' => 'Food',
            'description' => 'Private groceries',
            'amount' => '999.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);

        $result = app(AgentToolRegistry::class)->execute($user, 'analyze_spending', ['period_days' => 30], $conversation);

        $this->assertCount(2, $result['totals_by_category_and_currency']);
        $this->assertSame(['EUR', 'USD'], collect($result['totals_by_category_and_currency'])->pluck('currency')->sort()->values()->all());
        $this->assertSame(['20.00', '30.00'], collect($result['totals_by_category_and_currency'])->pluck('amount')->sort()->values()->all());
    }

    public function test_funding_tool_returns_only_owned_user_entered_opportunities(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Funding']);
        $user->fundingOpportunities()->create([
            'title' => 'Student emergency grant',
            'provider' => 'University office',
            'potential_amount' => '500.00',
            'currency' => 'USD',
            'deadline' => today()->addMonth(),
            'status' => 'researching',
            'source_url' => 'https://example.edu/funding',
        ]);
        $other->fundingOpportunities()->create([
            'title' => 'Private account award',
            'provider' => 'Another university',
            'potential_amount' => '9000.00',
            'currency' => 'USD',
            'deadline' => today()->addMonth(),
            'status' => 'awarded',
        ]);

        $result = app(AgentToolRegistry::class)->execute($user, 'get_funding_opportunities', [], $conversation);

        $this->assertCount(1, $result['opportunities']);
        $this->assertSame('Student emergency grant', $result['opportunities'][0]['title']);
        $this->assertTrue($result['opportunities'][0]['user_entered']);
        $this->assertStringContainsString('did not search', $result['note']);
    }

    public function test_gemini_function_call_is_executed_and_returned_for_explanation(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [[
                    'functionCall' => ['name' => 'get_financial_summary', 'args' => []],
                ]]]]]])
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'Your overview is ready.']]]]]]),
        ]);
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1800.00',
            'monthly_income' => '0.00',
            'memory_consent' => false,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Overview']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Show my overview']);

        $result = app(GeminiAgent::class)->respond($user, $conversation, 'Show my overview');

        $this->assertSame('Your overview is ready.', $result['text']);
        $this->assertSame('USD', $result['tool_results'][0]['result']['profile']['home_currency']);
        $this->assertSame(2, Http::recorded()->count());
        $this->assertDatabaseHas('agent_audit_logs', ['user_id' => $user->id, 'status' => 'completed']);
    }

    public function test_gemini_uses_the_configured_model_on_the_supported_generate_content_endpoint(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Config::set('services.gemini.model', 'gemini-3.8-flash');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'OK']]]]],
            ]),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Endpoint']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Reply with OK']);

        $result = app(GeminiAgent::class)->respond($user, $conversation, 'Reply with OK');

        $this->assertSame('OK', $result['text']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent'
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && $request['contents'][0]['parts'][0]['text'] === 'Reply with OK'
            && isset($request['tools'][0]['functionDeclarations'])
            && $this->hasObjectSchemasForNoArgumentFunctions($request)
            && ! str_contains(json_encode($request->data(), JSON_THROW_ON_ERROR), 'test-key'));
    }

    private function hasObjectSchemasForNoArgumentFunctions(Request $request): bool
    {
        $payload = json_decode($request->body());
        $declarations = $payload->tools[0]->functionDeclarations ?? [];

        return isset($declarations[0]->parameters->properties, $declarations[1]->parameters->properties)
            && is_object($declarations[0]->parameters->properties)
            && is_object($declarations[1]->parameters->properties)
            && (array) $declarations[0]->parameters->properties === []
            && (array) $declarations[1]->parameters->properties === [];
    }

    public function test_gemini_can_run_multiple_bounded_tools_before_a_final_answer(): void
    {
        Config::set('services.gemini.key', 'test-key');
        $user = User::factory()->create();
        $plan = $user->tuitionPlans()->create([
            'title' => 'Spring tuition',
            'amount' => '3000.00',
            'saved_amount' => '500.00',
            'currency' => 'USD',
            'due_date' => today()->addMonths(4),
            'installments' => 1,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Multi-step']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Summarize and plan tuition']);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [[
                    'functionCall' => ['name' => 'get_financial_summary', 'args' => []],
                ]]]]]])
                ->push(['candidates' => [['content' => ['parts' => [[
                    'functionCall' => ['name' => 'plan_tuition', 'args' => ['tuition_plan_id' => $plan->id]],
                ]]]]]])
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'Your tuition pace is ready.']]]]]]),
        ]);

        $result = app(GeminiAgent::class)->respond($user, $conversation, 'Summarize and plan tuition');

        $this->assertSame('Your tuition pace is ready.', $result['text']);
        $this->assertSame(['get_financial_summary', 'plan_tuition'], collect($result['tool_results'])->pluck('name')->all());
        $this->assertCount(3, Http::recorded());
    }

    public function test_gemini_tool_loop_stops_after_the_configured_limit(): void
    {
        Config::set('services.gemini.key', 'test-key');
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Bounded tools']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Keep checking my summary']);
        $toolCall = ['candidates' => [['content' => ['parts' => [[
            'functionCall' => ['name' => 'get_financial_summary', 'args' => []],
        ]]]]]];
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($toolCall)
                ->push($toolCall)
                ->push($toolCall)
                ->push($toolCall)
                ->push($toolCall),
        ]);

        $result = app(GeminiAgent::class)->respond($user, $conversation, 'Keep checking my summary');

        $this->assertCount(4, $result['tool_results']);
        $this->assertStringContainsString('safe limit', $result['text']);
        $this->assertCount(5, Http::recorded());
    }

    public function test_gemini_skips_duplicate_mutating_tool_calls_in_the_same_turn(): void
    {
        Config::set('services.gemini.key', 'test-key');
        $call = ['name' => 'create_savings_goal', 'args' => [
            'title' => 'Emergency fund',
            'target_amount' => '1000.00',
            'currency' => 'USD',
        ]];
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [
                    ['functionCall' => $call],
                    ['functionCall' => $call],
                ]]]]])
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'The draft is ready for review.']]]]]]),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Duplicate tool calls']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Create an emergency goal']);

        $result = app(GeminiAgent::class)->respond($user, $conversation, 'Create an emergency goal');

        $this->assertCount(2, $result['tool_results']);
        $this->assertArrayHasKey('pending_action', $result['tool_results'][0]['result']);
        $this->assertStringContainsString('already handled', $result['tool_results'][1]['result']['error']);
        $this->assertDatabaseCount('agent_pending_actions', 1);
        $this->assertDatabaseCount('savings_goals', 0);
    }

    public function test_gemini_rejects_an_oversized_tool_batch_before_any_tool_executes(): void
    {
        Config::set('services.gemini.key', 'test-key');
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Oversized batch']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Summarize my finances']);
        $parts = array_fill(0, 5, ['functionCall' => ['name' => 'get_financial_summary', 'args' => []]]);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => $parts]]],
            ]),
        ]);

        $result = app(GeminiAgent::class)->respond($user, $conversation, 'Summarize my finances');

        $this->assertStringContainsString('safe tool-call limit', $result['text']);
        $this->assertSame([], $result['tool_results']);
        $this->assertDatabaseCount('agent_audit_logs', 0);
        $this->assertCount(1, Http::recorded());
    }

    public function test_gemini_response_cannot_claim_a_prepared_write_was_already_saved(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [[
                    'functionCall' => ['name' => 'create_savings_goal', 'args' => [
                        'title' => 'Emergency fund',
                        'target_amount' => '1000.00',
                        'currency' => 'USD',
                    ]],
                ]]]]]])
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'I saved your goal.']]]]]]),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Prepare a goal']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Save a goal']);

        $result = app(GeminiAgent::class)->respond($user, $conversation, 'Save a goal');

        $this->assertStringContainsString('No planning changes were saved.', $result['text']);
        $this->assertTrue($result['tool_results'][0]['result']['confirmation_required']);
        $this->assertDatabaseCount('savings_goals', 0);
    }

    public function test_invalid_model_tool_arguments_are_not_executed(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [[
                    'functionCall' => ['name' => 'analyze_spending', 'args' => ['period_days' => 9999]],
                ]]]]]])
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'I could not analyze that period.']]]]]]),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Invalid request']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Analyze everything']);

        $result = app(GeminiAgent::class)->respond($user, $conversation, 'Analyze everything');

        $this->assertStringContainsString('I could not analyze that period.', $result['text']);
        $this->assertStringContainsString('At least one requested tool did not complete', $result['text']);
        $this->assertSame('The requested tool arguments were invalid or the record is unavailable to this account.', $result['tool_results'][0]['result']['error']);
        $this->assertDatabaseHas('agent_audit_logs', ['status' => 'rejected']);
    }

    public function test_rate_limited_gemini_requests_preserve_sanitized_provider_diagnostics_after_bounded_retries(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'Per-minute request limit exceeded.'],
        ], 429)]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Provider unavailable']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Try again']);

        try {
            app(GeminiAgent::class)->respond($user, $conversation, 'Try again');
            $this->fail('A failed provider response must be surfaced.');
        } catch (GeminiProviderException $exception) {
            $this->assertSame(429, $exception->httpStatus);
            $this->assertStringContainsString('HTTP 429 (RESOURCE_EXHAUSTED): Per-minute request limit exceeded.', $exception->getMessage());
        }

        $this->assertSame(3, Http::recorded()->count());
        Sleep::assertSleptTimes(2);
    }

    public function test_malformed_gemini_request_preserves_sanitized_error_without_retrying(): void
    {
        Config::set('services.gemini.key', 'private-test-key');
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'status' => 'INVALID_ARGUMENT',
                'message' => 'Invalid value in tool declaration; echoed key private-test-key.',
            ],
        ], 400)]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Help me plan.',
        ]);

        $expectedMessage = 'Gemini API returned HTTP 400 (INVALID_ARGUMENT): Invalid value in tool declaration; echoed key [REDACTED].';
        $response->assertStatus(502)->assertJsonPath('message', $expectedMessage);
        $this->assertSame(1, Http::recorded()->count());
        Sleep::assertSleptTimes(0);
        $this->assertDatabaseHas('agent_messages', [
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => $expectedMessage,
        ]);
    }

    public function test_upstream_service_unavailability_keeps_its_diagnostic_and_status_after_retries(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['status' => 'UNAVAILABLE', 'message' => 'The model is temporarily at capacity.']], 503)
                ->push(['error' => ['status' => 'UNAVAILABLE', 'message' => 'The model is temporarily at capacity.']], 503)
                ->push(['error' => ['status' => 'UNAVAILABLE', 'message' => 'The model is temporarily at capacity.']], 503),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Help me plan.',
        ]);

        $response->assertStatus(503)->assertJsonPath(
            'message',
            'Gemini API returned HTTP 503 (UNAVAILABLE): The model is temporarily at capacity.',
        );
        $this->assertSame(3, Http::recorded()->count());
        Sleep::assertSleptTimes(2);
    }

    public function test_gemini_408_is_retried_with_bounded_backoff_and_reported_as_gateway_timeout(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['status' => 'REQUEST_TIMEOUT', 'message' => 'The request timed out.'],
        ], 408)]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Provider timeout']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Try again']);

        try {
            app(GeminiAgent::class)->respond($user, $conversation, 'Try again');
            $this->fail('A provider timeout must be surfaced.');
        } catch (GeminiProviderException $exception) {
            $this->assertSame(504, $exception->httpStatus);
            $this->assertStringContainsString('HTTP 408 (REQUEST_TIMEOUT): The request timed out.', $exception->getMessage());
        }

        $this->assertSame(3, Http::recorded()->count());
        Sleep::assertSleptTimes(2);
    }

    public function test_transport_timeout_is_retried_boundedly_and_reported_as_gateway_timeout(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::failedConnection('cURL error 28: Operation timed out'),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Transport timeout']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Try again']);

        try {
            app(GeminiAgent::class)->respond($user, $conversation, 'Try again');
            $this->fail('A transport timeout must be surfaced.');
        } catch (GeminiProviderException $exception) {
            $this->assertSame(504, $exception->httpStatus);
            $this->assertStringContainsString('Gemini request timed out after bounded retries.', $exception->getMessage());
        }

        $this->assertSame(3, Http::recorded()->count());
        Sleep::assertSleptTimes(2);
    }

    public function test_gemini_retries_service_unavailability_with_bounded_backoff(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'Temporarily unavailable.']], 503)
                ->push(['error' => ['message' => 'Temporarily unavailable.']], 503)
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'Recovered.']]]]]]),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Retry transient failure']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Reply briefly']);

        $result = app(GeminiAgent::class)->respond($user, $conversation, 'Reply briefly');

        $this->assertSame('Recovered.', $result['text']);
        $this->assertSame(3, Http::recorded()->count());
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds === 500.0, 1);
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds === 1000.0, 1);
        Sleep::assertSleptTimes(2);
    }

    public function test_gemini_authentication_and_missing_endpoint_errors_are_not_retried(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['status' => 'UNAUTHENTICATED', 'message' => 'API key is invalid.']], 401)
                ->push(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'API key lacks permission.']], 403)
                ->push(['error' => ['status' => 'NOT_FOUND', 'message' => 'Configured model does not exist.']], 404),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Provider configuration']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Help me plan']);

        foreach ([
            'HTTP 401' => 'API key',
            'HTTP 403' => 'API key',
            'HTTP 404' => 'model or endpoint',
        ] as $status => $message) {
            try {
                app(GeminiAgent::class)->respond($user, $conversation, 'Help me plan');
                $this->fail('A failed provider response must be surfaced.');
            } catch (GeminiProviderException $exception) {
                $this->assertSame(502, $exception->httpStatus);
                $this->assertStringContainsString($status, $exception->getMessage());
                $expectedDiagnostic = $status === 'HTTP 404' ? 'Configured model does not exist.' : $message;
                $this->assertStringContainsString($expectedDiagnostic, $exception->getMessage());
            }
        }

        $this->assertSame(3, Http::recorded()->count());
    }

    public function test_gemini_tls_certificate_errors_are_not_retried_or_misreported_as_503(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::failedConnection('cURL error 60: SSL certificate problem'),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'TLS failure']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Try again']);

        try {
            app(GeminiAgent::class)->respond($user, $conversation, 'Try again');
            $this->fail('A TLS failure must be surfaced.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('TLS certificate verification failed', $exception->getMessage());
            $this->assertStringNotContainsString('HTTP 503', $exception->getMessage());
        }

        $this->assertSame(1, Http::recorded()->count());
        Sleep::assertSleptTimes(0);
    }

    public function test_unavailable_gemini_model_returns_a_clear_configuration_error(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['status' => 'NOT_FOUND', 'message' => 'The requested model is unavailable.'],
        ], 404)]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Unavailable model']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Help me plan']);

        try {
            app(GeminiAgent::class)->respond($user, $conversation, 'Help me plan');
            $this->fail('An unavailable model must not be presented as a successful answer.');
        } catch (GeminiProviderException $exception) {
            $this->assertSame(502, $exception->httpStatus);
            $this->assertSame('Gemini API returned HTTP 404 (NOT_FOUND): The requested model is unavailable.', $exception->getMessage());
        }
    }

    public function test_chat_route_reports_missing_provider_configuration_without_faking_an_ai_reply(): void
    {
        Config::set('services.gemini.key', null);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Help me plan tuition.',
        ])->assertStatus(503)
            ->assertJsonPath('message', 'Gemini is not configured. Set GEMINI_API_KEY on the server to enable the assistant.');

        $this->assertDatabaseHas('agent_messages', ['user_id' => $user->id, 'role' => 'user']);
    }

    public function test_chat_endpoint_prepares_pending_actions_and_requires_confirm_or_cancel(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [[
                    'functionCall' => ['name' => 'create_savings_goal', 'args' => [
                        'title' => 'Emergency fund',
                        'target_amount' => '1200.00',
                        'currency' => 'USD',
                    ]],
                ]]]]]])
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'Review this goal before saving.']]]]]])
                ->push(['candidates' => [['content' => ['parts' => [[
                    'functionCall' => ['name' => 'create_budget', 'args' => [
                        'category' => 'Books',
                        'amount' => '250.00',
                        'currency' => 'USD',
                    ]],
                ]]]]]])
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'Review this budget before saving.']]]]]]),
        ]);
        $user = User::factory()->create();

        $goalResponse = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Prepare an emergency savings goal.',
        ])->assertOk()
            ->assertJsonPath('text', "Review this goal before saving.\n\nNo planning changes were saved. Review each draft and confirm it, or choose Not now.")
            ->assertJsonPath('pending_actions.0.name', 'create_savings_goal');

        $goalActionId = $goalResponse->json('pending_actions.0.id');
        $conversationId = $goalResponse->json('conversation_id');
        $this->assertDatabaseCount('savings_goals', 0);

        $this->postJson(route('agent.confirm'), [
            'conversation_id' => $conversationId,
            'pending_action_id' => $goalActionId,
            'confirmed' => true,
        ])->assertOk()
            ->assertJsonPath('saved.saved', true);
        $this->assertDatabaseHas('savings_goals', [
            'user_id' => $user->id,
            'title' => 'Emergency fund',
        ]);

        $budgetResponse = $this->postJson(route('agent.chat'), [
            'message' => 'Prepare a monthly books budget.',
            'conversation_id' => $conversationId,
        ])->assertOk()
            ->assertJsonPath('pending_actions.0.name', 'create_budget');

        $this->postJson(route('agent.cancel'), [
            'conversation_id' => $conversationId,
            'pending_action_id' => $budgetResponse->json('pending_actions.0.id'),
            'discarded' => true,
        ])->assertOk();

        $this->assertDatabaseCount('budgets', 0);
        $this->assertDatabaseHas('agent_pending_actions', [
            'id' => $budgetResponse->json('pending_actions.0.id'),
            'status' => 'cancelled',
        ]);
    }

    public function test_history_route_is_scoped_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $owner->agentConversations()->create(['title' => 'Private']);

        $this->actingAs($other)
            ->getJson(route('agent.history', $conversation))
            ->assertNotFound();

        $this->assertDatabaseCount('agent_audit_logs', 0);
    }

    public function test_budget_and_saved_plan_writes_are_also_confirmation_gated(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Budget plan']);
        $registry = app(AgentToolRegistry::class);

        foreach ([
            ['create_budget', ['category' => 'Books', 'amount' => '250.00', 'currency' => 'USD']],
            ['save_plan', ['title' => 'Review tuition', 'summary' => 'Set aside funds monthly and revisit the estimate.']],
        ] as [$name, $arguments]) {
            $prepared = $registry->execute($user, $name, $arguments, $conversation);

            $this->assertTrue($prepared['confirmation_required']);
            $this->assertSame($name, $prepared['pending_action']['name']);

            if ($name === 'create_budget') {
                $this->assertDatabaseCount('budgets', 0);
            } else {
                $this->assertDatabaseCount('financial_plans', 0);
            }

            $saved = $registry->confirm($user, $conversation, (int) $prepared['pending_action']['id']);
            $this->assertTrue($saved['saved']);
        }

        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category' => 'Books']);
        $this->assertDatabaseHas('financial_plans', ['user_id' => $user->id, 'title' => 'Review tuition']);
        $this->assertDatabaseCount('agent_pending_actions', 2);
    }

    public function test_agent_confirmation_requires_an_unexpired_user_owned_pending_action(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Pending']);
        $otherConversation = $other->agentConversations()->create(['title' => 'Other']);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'save_plan', [
            'title' => 'My plan',
            'summary' => 'Check the monthly budget before changing contributions.',
        ], $conversation);
        $pendingId = (int) $prepared['pending_action']['id'];

        $this->expectException(ModelNotFoundException::class);
        $registry->confirm($other, $otherConversation, $pendingId);
    }

    public function test_confirmed_pending_action_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Single use']);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'save_plan', [
            'title' => 'One plan',
            'summary' => 'Keep the plan within the existing monthly budget.',
        ], $conversation);
        $pendingId = (int) $prepared['pending_action']['id'];

        $registry->confirm($user, $conversation, $pendingId);

        $this->expectException(InvalidArgumentException::class);
        $registry->confirm($user, $conversation, $pendingId);
    }

    public function test_user_can_discard_a_pending_action_and_it_cannot_later_be_confirmed(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Discard']);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'save_plan', [
            'title' => 'Maybe later',
            'summary' => 'Review this planning note before saving it.',
        ], $conversation);
        $pendingId = (int) $prepared['pending_action']['id'];

        $registry->cancel($user, $conversation, $pendingId);

        $this->assertDatabaseHas('agent_pending_actions', ['id' => $pendingId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('agent_audit_logs', ['user_id' => $user->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('financial_plans', 0);
        $this->expectException(InvalidArgumentException::class);
        $registry->confirm($user, $conversation, $pendingId);
    }

    public function test_expired_confirmation_is_rejected_without_saving_a_plan(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Expired']);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'save_plan', [
            'title' => 'Expired plan',
            'summary' => 'Review this plan before the deadline.',
        ], $conversation);
        $pendingId = (int) $prepared['pending_action']['id'];
        $user->agentPendingActions()->whereKey($pendingId)->update(['expires_at' => now()->subSecond()]);

        $this->expectException(InvalidArgumentException::class);
        $registry->confirm($user, $conversation, $pendingId);
    }
}

<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\User;
use App\Services\CurrencyCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_and_authentication_pages_render(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('Your next chapter.')->assertSee('data-theme-toggle');
        $this->get(route('login'))->assertOk()->assertSee('Sign in securely')->assertSee('data-theme-toggle');
        $this->get(route('register'))->assertOk()->assertSee('Create account')->assertSee('data-theme-toggle');
    }

    public function test_dashboard_requires_authentication_and_registration_starts_onboarding(): void
    {
        $this->get('/app')->assertRedirect(route('login'));

        $this->post(route('register.store'), [
            'name' => 'Student One',
            'email' => 'student@example.test',
            'password' => 'very-secure-password',
            'password_confirmation' => 'very-secure-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->get(route('dashboard'))->assertOk()->assertSee('Welcome to your financial map.');
        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();
        $this->post(route('login.store'), [
            'email' => 'student@example.test',
            'password' => 'very-secure-password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_profile_requires_valid_supported_currency_and_stores_privacy_consent(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.save'), [
            'destination_country' => 'Canada',
            'home_currency' => 'XYZ',
            'monthly_budget' => '1800.00',
        ])->assertSessionHasErrors('home_currency');

        $this->actingAs($user)->put(route('profile.save'), [
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1800.00',
            'monthly_income' => '2500.00',
            'financial_goals' => 'Build an emergency fund',
            'memory_consent' => '1',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'home_currency' => 'USD',
            'memory_consent' => 1,
        ]);
    }

    public function test_dashboard_and_financial_activity_are_isolated_per_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        StudentProfile::create([
            'user_id' => $owner->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1800.00',
        ]);
        $owner->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Owner rent',
            'amount' => '800.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $other->transactions()->create([
            'kind' => 'expense',
            'category' => 'Tuition',
            'description' => 'Other person record',
            'amount' => '999.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Scenario lab')
            ->assertSee('name="monthly_rent"', false)
            ->assertSee('name="scholarship_month"', false)
            ->assertSee('name="rate_stress_percent"', false)
            ->assertSee('Owner rent')
            ->assertDontSee('Other person record')
            ->assertSee('800.00');
    }

    public function test_dashboard_derives_financial_warnings_from_owned_records_and_restores_pending_approvals(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1000.00',
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Rent entry',
            'amount' => '900.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $user->tuitionPlans()->create([
            'title' => 'Upcoming tuition',
            'amount' => '5000.00',
            'saved_amount' => '0.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(20),
            'installments' => 2,
        ]);
        $other->tuitionPlans()->create([
            'title' => 'Other account overdue tuition',
            'amount' => '9000.00',
            'saved_amount' => '0.00',
            'currency' => 'USD',
            'due_date' => today()->subDays(3),
            'installments' => 1,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Review my plan']);
        $pending = $user->agentPendingActions()->create([
            'conversation_id' => $conversation->id,
            'tool_name' => 'create_savings_goal',
            'arguments' => ['title' => 'Emergency fund', 'target_amount' => '1200.00', 'currency' => 'USD'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => 'Review this draft before saving.',
            'tool_result' => [[
                'name' => 'create_savings_goal',
                'result' => [
                    'confirmation_required' => true,
                    'pending_action' => [
                        'id' => $pending->id,
                        'name' => 'create_savings_goal',
                        'arguments' => $pending->arguments,
                    ],
                ],
            ]],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['conversation_id' => $conversation->id]))
            ->assertOk()
            ->assertSee('Financial signals')
            ->assertSee('used at least 80%')
            ->assertSee('Upcoming tuition is due soon')
            ->assertSee('Pending approvals')
            ->assertSee('Emergency fund')
            ->assertSee('data-tool-results')
            ->assertSee('Confirm and save')
            ->assertDontSee('Other account overdue tuition');
    }

    public function test_manual_activity_is_saved_as_a_record_and_never_claimed_as_a_transfer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Rent record',
            'amount' => '800.00',
            'currency' => 'USD',
            'occurred_on' => today()->toDateString(),
            'is_simulated' => '1',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'description' => 'Rent record',
            'is_simulated' => 1,
        ]);
        $this->get(route('dashboard'))->assertSee('Simulated');
    }

    public function test_unchecked_simulation_flag_creates_a_manual_record_not_a_simulated_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'kind' => 'income',
            'category' => 'Scholarship',
            'description' => 'Scholarship record',
            'amount' => '1000.00',
            'currency' => 'USD',
            'occurred_on' => today()->toDateString(),
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'description' => 'Scholarship record',
            'is_simulated' => 0,
        ]);
    }

    public function test_tuition_goal_and_category_budget_are_saved_with_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('tuition.store'), [
            'title' => 'Autumn tuition',
            'amount' => '1200.00',
            'saved_amount' => '150.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(90)->toDateString(),
            'installments' => 2,
        ])->assertSessionHas('status');

        $this->actingAs($user)->post(route('goals.store'), [
            'title' => 'Travel buffer',
            'target_amount' => '500.00',
            'current_amount' => '100.00',
            'currency' => 'USD',
            'target_date' => today()->addMonths(4)->toDateString(),
        ])->assertSessionHas('status');

        $this->actingAs($user)->post(route('budgets.store'), [
            'category' => 'Books',
            'amount' => '200.00',
            'currency' => 'USD',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('tuition_plans', ['user_id' => $user->id, 'installments' => 2]);
        $this->assertDatabaseHas('savings_goals', ['user_id' => $user->id, 'title' => 'Travel buffer']);
        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category' => 'Books']);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('View generated monthly installment dates')
            ->assertSee('Record savings progress');

        $this->actingAs($user)->post(route('tuition.store'), [
            'title' => 'Invalid tuition',
            'amount' => '100.00',
            'saved_amount' => '101.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(90)->toDateString(),
            'installments' => 1,
        ])->assertSessionHasErrors('saved_amount');
    }

    public function test_tuition_and_goal_progress_are_user_scoped_and_cannot_exceed_targets(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $tuition = $user->tuitionPlans()->create([
            'title' => 'Term tuition',
            'amount' => '1000.00',
            'saved_amount' => '200.00',
            'currency' => 'USD',
            'due_date' => today()->addMonths(4),
            'installments' => 2,
        ]);
        $goal = $user->savingsGoals()->create([
            'title' => 'Travel buffer',
            'target_amount' => '500.00',
            'current_amount' => '100.00',
            'currency' => 'USD',
        ]);
        $foreignTuition = $other->tuitionPlans()->create([
            'title' => 'Private tuition',
            'amount' => '1000.00',
            'saved_amount' => '0.00',
            'currency' => 'USD',
            'due_date' => today()->addMonths(4),
            'installments' => 1,
        ]);

        $this->actingAs($user)->post(route('tuition.progress', $tuition), [
            'additional_amount' => '300.00',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('tuition_plans', ['id' => $tuition->id, 'saved_amount' => '500.00']);

        $this->actingAs($user)->post(route('tuition.progress', $tuition), [
            'additional_amount' => '600.00',
        ])->assertSessionHasErrors('additional_amount');
        $this->assertDatabaseHas('tuition_plans', ['id' => $tuition->id, 'saved_amount' => '500.00']);

        $this->actingAs($user)->post(route('goals.progress', $goal), [
            'additional_amount' => '50.00',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('savings_goals', ['id' => $goal->id, 'current_amount' => '150.00']);

        $this->actingAs($user)->post(route('tuition.progress', $foreignTuition), [
            'additional_amount' => '100.00',
        ])->assertNotFound();
    }

    public function test_funding_opportunities_are_user_entered_validated_and_owner_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post(route('funding-opportunities.store'), [
            'title' => 'International student grant',
            'provider' => 'University funding office',
            'potential_amount' => '2500.00',
            'currency' => 'USD',
            'deadline' => today()->addMonths(2)->toDateString(),
            'status' => 'preparing',
            'source_url' => 'https://example.edu/funding',
        ])->assertSessionHas('status');

        $opportunity = $user->fundingOpportunities()->firstOrFail();
        $this->assertSame('2500.00', $opportunity->potential_amount);
        $this->assertDatabaseHas('funding_opportunities', [
            'user_id' => $user->id,
            'title' => 'International student grant',
            'status' => 'preparing',
        ]);

        $this->actingAs($user)->patch(route('funding-opportunities.status', $opportunity), [
            'status' => 'submitted',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('funding_opportunities', ['id' => $opportunity->id, 'status' => 'submitted']);

        $foreignOpportunity = $other->fundingOpportunities()->create([
            'title' => 'Private award',
            'provider' => 'Other account',
            'potential_amount' => null,
            'currency' => 'USD',
            'deadline' => null,
            'status' => 'researching',
            'source_url' => null,
        ]);
        $this->actingAs($user)->patch(route('funding-opportunities.status', $foreignOpportunity), [
            'status' => 'awarded',
        ])->assertNotFound();

        $this->actingAs($user)->post(route('funding-opportunities.store'), [
            'title' => 'Unsupported award',
            'provider' => 'Unknown',
            'potential_amount' => '200.00',
            'currency' => 'XYZ',
            'status' => 'approved',
        ])->assertSessionHasErrors(['currency', 'status']);

        $this->actingAs($user)->post(route('funding-opportunities.store'), [
            'title' => 'Unsafe link',
            'provider' => 'Unknown',
            'currency' => 'USD',
            'status' => 'researching',
            'source_url' => 'javascript://example.test',
        ])->assertSessionHasErrors('source_url');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Scholarship & funding planner')
            ->assertSee('International student grant')
            ->assertSee('User-entered; not verified');
    }

    public function test_currency_and_simulator_endpoints_validate_inputs_and_return_explicit_estimates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('currency.convert'), [
            'amount' => '100.00',
            'from_currency' => 'USD',
            'to_currency' => 'EUR',
        ])->assertOk()
            ->assertJsonPath('converted_amount', '92.34')
            ->assertJsonPath('live', false);

        $this->actingAs($user)->postJson(route('remittance.compare'), [
            'amount' => '100.00',
            'from_currency' => 'USD',
            'to_currency' => 'EUR',
        ])->assertOk()->assertJsonPath('quotes.0.simulated', true);

        $this->actingAs($user)->postJson(route('currency.convert'), [
            'amount' => '100.00',
            'from_currency' => 'USD',
            'to_currency' => 'XYZ',
        ])->assertUnprocessable();

        $scenarioInputs = [
            'monthly_budget' => '1800.00',
            'monthly_spending' => '1500.00',
            'tuition_contribution' => '200.00',
            'tuition_amount' => '1200.00',
            'saved_amount' => '0.00',
            'transfer_fee' => '5.00',
            'installments' => '3',
            'due_date' => today()->addDays(90)->toDateString(),
            'from_currency' => 'EUR',
            'budget_currency' => 'USD',
            'to_currency' => 'CNY',
            'exchange_rate_change' => '10',
            'monthly_rent' => '500.00',
            'monthly_income' => '500.00',
            'rent_change_percent' => '10',
            'income_delay_months' => '2',
            'scholarship_amount' => '1000.00',
            'scholarship_month' => '3',
            'forecast_months' => '4',
            'rate_stress_percent' => '10',
        ];

        $this->actingAs($user)->postJson(route('scenario.run'), $scenarioInputs)->assertOk()
            ->assertJsonPath('budget.projected_remaining', '100.00')
            ->assertJsonPath('budget.required_tuition_savings', '433.18')
            ->assertJsonPath('budget.tuition_savings_gap', '233.18')
            ->assertJsonPath('exchange.simulated', true)
            ->assertJsonPath('forecasts.0.name', 'Favorable')
            ->assertJsonPath('forecasts.1.rate_change_percent', 0)
            ->assertJsonPath('forecasts.2.rate_change_percent', 10)
            ->assertJsonPath('forecast_assumptions.scholarship_is_unconfirmed', true);

        $this->actingAs($user)->postJson(route('scenario.run'), [
            ...$scenarioInputs,
            'saved_amount' => '1200.01',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Saved progress cannot exceed the tuition amount.');
    }

    public function test_agent_confirmation_endpoint_accepts_only_an_owned_pending_change(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Confirmation']);
        $otherUser = User::factory()->create();
        $otherConversation = $otherUser->agentConversations()->create(['title' => 'Other']);
        $user->agentPendingActions()->create([
            'conversation_id' => $conversation->id,
            'tool_name' => 'save_plan',
            'arguments' => ['title' => 'A plan', 'summary' => 'Review monthly savings against tuition.'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
        $foreignAction = $otherUser->agentPendingActions()->create([
            'conversation_id' => $otherConversation->id,
            'tool_name' => 'save_plan',
            'arguments' => ['title' => 'Private plan', 'summary' => 'This belongs to another account.'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($user)->postJson(route('agent.confirm'), [
            'conversation_id' => $conversation->id,
            'pending_action_id' => $foreignAction->id,
            'confirmed' => true,
        ])->assertNotFound();

        $this->actingAs($user)->postJson(route('agent.confirm'), [
            'conversation_id' => $conversation->id,
            'pending_action_id' => 999999,
            'confirmed' => false,
        ])->assertUnprocessable();

        $pending = $user->agentPendingActions()->create([
            'conversation_id' => $conversation->id,
            'tool_name' => 'save_plan',
            'arguments' => ['title' => 'Confirmed plan', 'summary' => 'Review the savings target monthly.'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
        $this->actingAs($user)->postJson(route('agent.confirm'), [
            'conversation_id' => $conversation->id,
            'pending_action_id' => $pending->id,
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('saved.saved', true);

        $this->assertDatabaseHas('financial_plans', ['user_id' => $user->id, 'title' => 'Confirmed plan']);
    }

    public function test_scenario_converts_home_currency_inputs_to_the_selected_budget_currency(): void
    {
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'CNY',
            'monthly_budget' => '1800.00',
            'monthly_income' => '500.00',
        ]);
        $expectedBudget = app(CurrencyCalculator::class)->convert('1800.00', 'CNY', 'EUR');

        $this->actingAs($user)->postJson(route('scenario.run'), [
            'monthly_budget' => '1800.00',
            'monthly_spending' => '1000.00',
            'tuition_contribution' => '100.00',
            'tuition_amount' => '100.00',
            'saved_amount' => '0.00',
            'transfer_fee' => '1.00',
            'installments' => '1',
            'due_date' => today()->addDays(90)->toDateString(),
            'from_currency' => 'USD',
            'budget_currency' => 'EUR',
            'to_currency' => 'CNY',
            'exchange_rate_change' => '0',
            'monthly_rent' => '200.00',
            'monthly_income' => '500.00',
            'rent_change_percent' => '0',
            'income_delay_months' => '0',
            'scholarship_amount' => '0.00',
            'scholarship_month' => '0',
            'forecast_months' => '3',
            'rate_stress_percent' => '5',
        ])->assertOk()
            ->assertJsonPath('budget.monthly_budget', $expectedBudget['converted_amount'])
            ->assertJsonPath('budget_currency', 'EUR')
            ->assertJsonPath('budget_input_currency', 'CNY')
            ->assertJsonPath('forecast_assumptions.budget_input_conversion.rate', $expectedBudget['rate']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AgentToolDisplayPresenter;
use App\Services\AgentToolRegistry;
use App\Services\CurrencyCalculator;
use App\Services\FinancialPlanner;
use App\Services\OpenRouterAgent;
use App\Services\OpenRouterClient;
use App\Services\OpenRouterProviderException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class AgentToolTest extends TestCase
{
    use RefreshDatabase;

    private const OPENROUTER_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    private const GROQ_ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.openrouter.model', 'openai/gpt-4o-mini');
    }

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

        try {
            app(AgentToolRegistry::class)->execute($owner, 'plan_tuition', ['tuition_plan_id' => $foreignPlan->id], $conversation);
            $this->fail('A user must not read another user’s tuition plan.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('agent_audit_logs', [
                'user_id' => $owner->id,
                'tool_name' => 'plan_tuition',
                'status' => 'rejected',
            ]);
        }
    }

    public function test_financial_summary_includes_only_the_users_current_category_budgets(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $user->budgets()->create([
            'category' => 'Food', 'amount' => '450.00', 'currency' => 'CAD',
            'period_start' => today()->startOfMonth(), 'period_end' => today()->endOfMonth(),
        ]);
        $other->budgets()->create([
            'category' => 'Private housing', 'amount' => '9000.00', 'currency' => 'USD',
            'period_start' => today()->startOfMonth(), 'period_end' => today()->endOfMonth(),
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Budget summary']);

        $result = app(AgentToolRegistry::class)->execute($user, 'get_financial_summary', [], $conversation);
        $receipt = app(AgentToolDisplayPresenter::class)->presentResults([
            ['name' => 'get_financial_summary', 'result' => $result],
        ])[0]['receipt'];

        $this->assertCount(1, $result['monthly_budgets']);
        $this->assertSame('Food', $result['monthly_budgets'][0]['category']);
        $this->assertSame('450.00', $result['monthly_budgets'][0]['amount']);
        $this->assertStringContainsString('Monthly Food budget: 450.00 CAD', json_encode($receipt['inputs']));
        $this->assertStringNotContainsString('Private housing', json_encode($result));
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

        try {
            $registry->execute($user, 'forecast_scenarios', ['tuition_plan_id' => 1, 'user_id' => 123], $conversation);
            $this->fail('Forecast tools must reject unregistered arguments.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('agent_audit_logs', [
                'user_id' => $user->id,
                'tool_name' => 'forecast_scenarios',
                'status' => 'rejected',
            ]);
        }

        try {
            $registry->execute($user, 'forecast_scenarios', ['tuition_plan_id' => 1, 'rent_change_percent' => 101], $conversation);
            $this->fail('Forecast stress inputs must remain inside the planner’s supported range.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('agent_audit_logs', [
                'user_id' => $user->id,
                'tool_name' => 'forecast_scenarios',
                'status' => 'rejected',
            ]);
        }

        $this->expectException(ValidationException::class);
        $registry->execute($user, 'analyze_spending', ['period_days' => 0], $conversation);
    }

    public function test_mutating_tool_waits_for_confirmation_before_saving_and_writes_an_audit_trail(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Savings']);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'create_savings_goal', [
            'title' => 'Emergency fund',
            'target_amount' => '1200.00',
            'currency' => 'USD',
            'current_amount' => '100.00',
        ], $conversation);

        $this->assertTrue($prepared['confirmation_required']);
        $this->assertDatabaseCount('savings_goals', 0);
        $this->assertDatabaseHas('agent_audit_logs', ['status' => 'awaiting_confirmation']);

        $saved = $registry->confirm($user, $conversation, (int) $prepared['pending_action']['id']);

        $this->assertTrue($saved['saved']);
        $this->assertDatabaseHas('savings_goals', ['user_id' => $user->id, 'title' => 'Emergency fund']);
        $this->assertDatabaseHas('agent_audit_logs', ['status' => 'confirmed']);
    }

    public function test_savings_goal_updates_keep_currency_and_require_owned_confirmation(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $goal = $user->savingsGoals()->create([
            'title' => 'Emergency fund',
            'target_amount' => '1000.00',
            'current_amount' => '250.00',
            'currency' => 'CAD',
        ]);
        $foreignGoal = $other->savingsGoals()->create([
            'title' => 'Private goal',
            'target_amount' => '2000.00',
            'current_amount' => '0.00',
            'currency' => 'USD',
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Goal update']);
        $registry = app(AgentToolRegistry::class);

        try {
            $registry->execute($user, 'update_savings_goal', [
                'goal_id' => $foreignGoal->id,
                'target_amount' => '3000.00',
            ], $conversation);
            $this->fail('A goal update must not access another user’s goal.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('savings_goals', [
                'id' => $goal->id,
                'target_amount' => '1000.00',
                'currency' => 'CAD',
            ]);
        }

        $prepared = $registry->execute($user, 'update_savings_goal', [
            'goal_id' => $goal->id,
            'target_amount' => '1500.00',
        ], $conversation);

        $this->assertSame('CAD', $prepared['pending_action']['before_state']['currency']);
        $this->assertDatabaseHas('savings_goals', [
            'id' => $goal->id,
            'target_amount' => '1000.00',
            'currency' => 'CAD',
        ]);

        try {
            $registry->confirm($other, $conversation, (int) $prepared['pending_action']['id']);
            $this->fail('Only the owner may confirm a planning change.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('agent_pending_actions', [
                'id' => $prepared['pending_action']['id'],
                'status' => 'pending',
            ]);
        }

        $saved = $registry->confirm($user, $conversation, (int) $prepared['pending_action']['id']);

        $this->assertSame('1500.00', $saved['target_amount']);
        $this->assertSame('CAD', $saved['currency']);
        $this->assertDatabaseHas('savings_goals', [
            'id' => $goal->id,
            'target_amount' => '1500.00',
            'current_amount' => '250.00',
            'currency' => 'CAD',
        ]);
        $this->assertDatabaseHas('agent_audit_logs', ['user_id' => $user->id, 'status' => 'confirmed']);

        $progress = $registry->execute($user, 'update_savings_goal', [
            'goal_id' => $goal->id,
            'current_amount' => '400.00',
        ], $conversation);
        $progressResult = $registry->confirm($user, $conversation, (int) $progress['pending_action']['id']);

        $this->assertSame('400.00', $progressResult['current_amount']);
        $this->assertDatabaseHas('savings_goals', [
            'id' => $goal->id,
            'target_amount' => '1500.00',
            'current_amount' => '400.00',
            'currency' => 'CAD',
        ]);
    }

    public function test_tuition_plan_updates_are_confirmed_and_reject_stale_or_invalid_changes(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Tuition update']);
        $plan = $user->tuitionPlans()->create([
            'title' => 'Autumn tuition',
            'amount' => '9000.00',
            'saved_amount' => '1000.00',
            'currency' => 'EUR',
            'due_date' => today()->addMonths(5),
            'installments' => 3,
        ]);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'update_tuition_plan', [
            'tuition_plan_id' => $plan->id,
            'amount' => '12000.00',
            'saved_amount' => '1500.00',
            'due_date' => today()->addMonths(7)->toDateString(),
            'installments' => 5,
        ], $conversation);

        $this->assertSame('EUR', $prepared['pending_action']['before_state']['currency']);
        $this->assertDatabaseHas('tuition_plans', [
            'id' => $plan->id,
            'amount' => '9000.00',
            'saved_amount' => '1000.00',
            'currency' => 'EUR',
        ]);

        $saved = $registry->confirm($user, $conversation, (int) $prepared['pending_action']['id']);

        $this->assertSame('12000.00', $saved['amount']);
        $this->assertSame('1500.00', $saved['saved_amount']);
        $this->assertSame('EUR', $saved['currency']);
        $this->assertSame(5, $saved['installments']);
        $this->assertDatabaseHas('tuition_plans', [
            'id' => $plan->id,
            'amount' => '12000.00',
            'saved_amount' => '1500.00',
            'currency' => 'EUR',
            'installments' => 5,
        ]);

        try {
            $registry->execute($user, 'update_tuition_plan', [
                'tuition_plan_id' => $plan->id,
                'saved_amount' => '13000.00',
            ], $conversation);
            $this->fail('Saved progress greater than tuition must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('tuition_plans', ['id' => $plan->id, 'amount' => '12000.00']);
        }

        $staleDraft = $registry->execute($user, 'update_tuition_plan', [
            'tuition_plan_id' => $plan->id,
            'amount' => '13000.00',
        ], $conversation);
        $plan->update(['title' => 'Changed outside the draft']);

        try {
            $registry->confirm($user, $conversation, (int) $staleDraft['pending_action']['id']);
            $this->fail('A stale record must require a new draft instead of saving.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('tuition_plans', [
                'id' => $plan->id,
                'title' => 'Changed outside the draft',
                'amount' => '12000.00',
            ]);
        }
    }

    public function test_budgets_can_be_updated_only_after_confirmation_and_reject_stale_changes(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Budget update']);
        $budget = $user->budgets()->create([
            'category' => 'Books',
            'amount' => '250.00',
            'currency' => 'CAD',
            'period_start' => today()->startOfMonth(),
            'period_end' => today()->endOfMonth(),
        ]);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'update_budget', [
            'budget_id' => $budget->id,
            'category' => 'Course materials',
            'amount' => '325.50',
        ], $conversation);
        $display = app(AgentToolDisplayPresenter::class)->presentResults([
            ['name' => 'update_budget', 'result' => $prepared],
        ])[0];

        $this->assertTrue($display['receipt']['approval_required']);
        $this->assertSame($budget->id, $display['pending_action']['before_state']['id']);
        $this->assertDatabaseHas('budgets', ['id' => $budget->id, 'category' => 'Books', 'amount' => '250.00']);

        $updated = $registry->confirm($user, $conversation, (int) $prepared['pending_action']['id']);

        $this->assertTrue($updated['saved']);
        $this->assertSame('Course materials', $updated['category']);
        $this->assertSame('325.50', $updated['amount']);
        $this->assertSame('CAD', $updated['currency']);
        $this->assertDatabaseHas('budgets', ['id' => $budget->id, 'category' => 'Course materials', 'amount' => '325.50', 'currency' => 'CAD']);

        try {
            $registry->execute($user, 'update_budget', ['budget_id' => $budget->id], $conversation);
            $this->fail('A budget update without changed values must not be staged.');
        } catch (ValidationException) {
            $this->assertSame(1, $user->budgets()->count());
            $this->assertDatabaseHas('budgets', ['id' => $budget->id, 'amount' => '325.50']);
        }

        $stale = $registry->execute($user, 'update_budget', [
            'budget_id' => $budget->id,
            'amount' => '400.00',
        ], $conversation);
        $budget->update(['amount' => '350.00']);

        try {
            $registry->confirm($user, $conversation, (int) $stale['pending_action']['id']);
            $this->fail('A budget changed after staging must not be overwritten.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('budgets', ['id' => $budget->id, 'amount' => '350.00']);
        }
    }

    public function test_owned_planning_records_are_deleted_only_after_exact_confirmation(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Delete plans']);
        $records = [
            [
                'tool' => 'delete_tuition_plan',
                'arguments' => 'tuition_plan_id',
                'table' => 'tuition_plans',
                'record' => $user->tuitionPlans()->create([
                    'title' => 'Autumn tuition', 'amount' => '9000.00', 'saved_amount' => '1000.00',
                    'currency' => 'EUR', 'due_date' => today()->addMonths(5), 'installments' => 3,
                ]),
                'label' => 'Autumn tuition',
            ],
            [
                'tool' => 'delete_savings_goal',
                'arguments' => 'goal_id',
                'table' => 'savings_goals',
                'record' => $user->savingsGoals()->create([
                    'title' => 'Emergency fund', 'target_amount' => '1500.00', 'current_amount' => '300.00',
                    'currency' => 'USD', 'target_date' => today()->addMonths(6),
                ]),
                'label' => 'Emergency fund',
            ],
            [
                'tool' => 'delete_budget',
                'arguments' => 'budget_id',
                'table' => 'budgets',
                'record' => $user->budgets()->create([
                    'category' => 'Food', 'amount' => '400.00', 'currency' => 'USD',
                    'period_start' => today()->startOfMonth(), 'period_end' => today()->endOfMonth(),
                ]),
                'label' => 'Food',
            ],
        ];
        $registry = app(AgentToolRegistry::class);

        foreach ($records as $entry) {
            $record = $entry['record'];
            $prepared = $registry->execute($user, $entry['tool'], [
                $entry['arguments'] => $record->id,
            ], $conversation);
            $display = app(AgentToolDisplayPresenter::class)->presentResults([
                ['name' => $entry['tool'], 'result' => $prepared],
            ])[0];
            $pendingId = (int) $prepared['pending_action']['id'];

            $this->assertDatabaseHas('agent_pending_actions', ['id' => $pendingId, 'status' => 'pending']);
            $this->assertSame($record->id, $display['pending_action']['before_state']['id']);
            $this->assertStringContainsString($entry['label'], $display['receipt']['inputs'][0]);
            $this->assertSame('Permanently delete this record', $display['receipt']['proposed_changes'][0]['proposed']);
            $this->assertTrue($display['receipt']['approval_required']);
            $this->assertDatabaseHas($entry['table'], ['id' => $record->id, 'user_id' => $user->id]);

            $deleted = $registry->confirm($user, $conversation, $pendingId);

            $this->assertTrue($deleted['deleted']);
            $this->assertSame('deleted_'.match ($entry['table']) {
                'tuition_plans' => 'tuition_plan',
                'savings_goals' => 'savings_goal',
                default => 'budget',
            }, $deleted['record_type']);
            $this->assertDatabaseMissing($entry['table'], ['id' => $record->id, 'user_id' => $user->id]);

            try {
                $registry->confirm($user, $conversation, $pendingId);
                $this->fail('A deletion confirmation must not be replayed.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseMissing($entry['table'], ['id' => $record->id]);
            }
        }

        $foreignTuition = $other->tuitionPlans()->create([
            'title' => 'Private tuition', 'amount' => '8000.00', 'saved_amount' => '0.00',
            'currency' => 'USD', 'due_date' => today()->addMonths(6), 'installments' => 1,
        ]);
        $foreignGoal = $other->savingsGoals()->create([
            'title' => 'Private goal', 'target_amount' => '800.00', 'currency' => 'USD',
        ]);
        $foreignBudget = $other->budgets()->create([
            'category' => 'Private books', 'amount' => '600.00', 'currency' => 'USD',
            'period_start' => today()->startOfMonth(), 'period_end' => today()->endOfMonth(),
        ]);

        foreach ([
            ['tool' => 'delete_tuition_plan', 'key' => 'tuition_plan_id', 'record' => $foreignTuition, 'table' => 'tuition_plans'],
            ['tool' => 'delete_savings_goal', 'key' => 'goal_id', 'record' => $foreignGoal, 'table' => 'savings_goals'],
            ['tool' => 'delete_budget', 'key' => 'budget_id', 'record' => $foreignBudget, 'table' => 'budgets'],
        ] as $entry) {
            try {
                $registry->execute($user, $entry['tool'], [$entry['key'] => $entry['record']->id], $conversation);
                $this->fail('A user must not prepare deletion of another account’s planning record.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseHas($entry['table'], ['id' => $entry['record']->id, 'user_id' => $other->id]);
            }
        }
    }

    public function test_planning_record_deletion_cancellation_expiry_and_stale_state_preserve_records(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Deletion safeguards']);
        $goal = $user->savingsGoals()->create([
            'title' => 'Study reserve', 'target_amount' => '1200.00', 'current_amount' => '500.00', 'currency' => 'USD',
        ]);
        $budget = $user->budgets()->create([
            'category' => 'Books', 'amount' => '200.00', 'currency' => 'USD',
            'period_start' => today()->startOfMonth(), 'period_end' => today()->endOfMonth(),
        ]);
        $plan = $user->tuitionPlans()->create([
            'title' => 'Spring tuition', 'amount' => '6000.00', 'saved_amount' => '1200.00',
            'currency' => 'USD', 'due_date' => today()->addMonths(4), 'installments' => 2,
        ]);
        $registry = app(AgentToolRegistry::class);

        $cancelled = $registry->execute($user, 'delete_savings_goal', ['goal_id' => $goal->id], $conversation);
        $registry->cancel($user, $conversation, (int) $cancelled['pending_action']['id']);
        $this->assertDatabaseHas('savings_goals', ['id' => $goal->id, 'current_amount' => '500.00']);

        $expired = $registry->execute($user, 'delete_budget', ['budget_id' => $budget->id], $conversation);
        $expiredId = (int) $expired['pending_action']['id'];
        $user->agentPendingActions()->whereKey($expiredId)->update(['expires_at' => now()->subSecond()]);

        try {
            $registry->confirm($user, $conversation, $expiredId);
            $this->fail('An expired deletion proposal must not delete a record.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('budgets', ['id' => $budget->id, 'amount' => '200.00']);
            $this->assertDatabaseHas('agent_pending_actions', ['id' => $expiredId, 'status' => 'expired']);
        }

        $stale = $registry->execute($user, 'delete_tuition_plan', ['tuition_plan_id' => $plan->id], $conversation);
        $plan->update(['saved_amount' => '1500.00']);

        try {
            $registry->confirm($user, $conversation, (int) $stale['pending_action']['id']);
            $this->fail('A changed tuition plan must be re-reviewed before deletion.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('tuition_plans', ['id' => $plan->id, 'saved_amount' => '1500.00']);
        }
    }

    public function test_tuition_tool_returns_a_deterministic_savings_plan_for_an_owned_record(): void
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

        $this->assertSame($plan->id, $result['id']);
        $this->assertSame('5000.00', $result['schedule']['remaining']);
        $this->assertSame('on_track', $result['schedule']['status']);
    }

    public function test_agent_forecast_uses_owned_records_and_the_existing_deterministic_engine(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'CAD',
            'monthly_budget' => '2000.00',
            'monthly_income' => '600.00',
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Rent',
            'amount' => '700.00',
            'currency' => 'CAD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Food',
            'description' => 'Groceries',
            'amount' => '500.00',
            'currency' => 'CAD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Books',
            'description' => 'Foreign books',
            'amount' => '900.00',
            'currency' => 'EUR',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $other->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Other user rent',
            'amount' => '4000.00',
            'currency' => 'CAD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $plan = $user->tuitionPlans()->create([
            'title' => 'Autumn tuition',
            'amount' => '12000.00',
            'saved_amount' => '2000.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(120),
            'installments' => 4,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Affordability forecast']);

        $arguments = [
            'tuition_plan_id' => $plan->id,
            'rent_change_percent' => 10,
            'income_delay_months' => 1,
            'scholarship_amount' => '1000.00',
            'scholarship_month' => 3,
            'forecast_months' => 4,
            'rate_stress_percent' => 5,
        ];
        $result = app(AgentToolRegistry::class)->execute($user, 'forecast_scenarios', $arguments, $conversation);
        $planner = app(FinancialPlanner::class);
        $calculator = app(CurrencyCalculator::class);
        $schedule = $planner->tuitionSchedule('12000.00', '2000.00', $plan->due_date->toDateString());
        $tuition = $calculator->convert('12000.00', 'USD', 'CAD');
        $saved = $calculator->convert('2000.00', 'USD', 'CAD');
        $monthlyPace = $calculator->convert($schedule['required_monthly'], 'USD', 'CAD');
        $makeForecast = fn (int $rentChange = 0, int $incomeDelay = 0, string $scholarship = '0.00', int $scholarshipMonth = 0, int $rateStress = 0): array => $planner->forecastScenarios(
            '2000.00', '1200.00', '700.00', '600.00', $monthlyPace['converted_amount'],
            $tuition['converted_amount'], $saved['converted_amount'], $scholarship,
            $rentChange, $incomeDelay, $scholarshipMonth, 4, $rateStress,
        );
        $expectedBaseline = $makeForecast()[1];
        $expectedRent = $makeForecast(10)[1];
        $expectedIncomeDelay = $makeForecast(0, 1)[1];
        $expectedScholarship = $makeForecast(0, 0, '1000.00', 3)[1];
        $expectedRateStress = $makeForecast(0, 0, '0.00', 0, 5);
        $receipt = app(AgentToolDisplayPresenter::class)->presentResults([
            ['name' => 'forecast_scenarios', 'result' => $result],
        ])[0]['receipt'];
        $foreignPlan = $other->tuitionPlans()->create([
            'title' => 'Private foreign plan',
            'amount' => '9000.00',
            'saved_amount' => '0.00',
            'currency' => 'CAD',
            'due_date' => today()->addMonths(4),
            'installments' => 1,
        ]);

        $this->assertSame('complete', $result['status']);
        $this->assertSame('1200.00', $result['baseline_inputs']['monthly_spending']);
        $this->assertSame('700.00', $result['baseline_inputs']['monthly_rent']);
        $this->assertSame($expectedBaseline, $result['baseline_forecast']);
        $this->assertSame(['Rent change', 'Delayed income', 'Unconfirmed scholarship', 'Foreign-exchange stress'], array_column($result['comparisons'], 'name'));
        $this->assertSame([$expectedRent], $result['comparisons'][0]['scenarios']);
        $this->assertSame([$expectedIncomeDelay], $result['comparisons'][1]['scenarios']);
        $this->assertSame([$expectedScholarship], $result['comparisons'][2]['scenarios']);
        $this->assertSame($expectedRateStress, $result['comparisons'][3]['scenarios']);
        $this->assertTrue($result['forecast_assumptions']['scholarship_is_unconfirmed']);
        $this->assertSame($tuition['converted_amount'], $result['conversions']['tuition_target']['converted_amount']);
        $this->assertSame($tuition['rate'], $result['conversions']['tuition_target']['rate']);
        $this->assertStringContainsString('demonstration-rate table', $receipt['calculation']);
        $this->assertStringContainsString('Favorable:', $receipt['result']);
        $this->assertStringContainsString('Adverse:', $receipt['result']);
        $this->assertFalse($receipt['approval_required']);
        $this->assertStringNotContainsString('Other user rent', json_encode($result));

        try {
            app(AgentToolRegistry::class)->execute($user, 'forecast_scenarios', ['tuition_plan_id' => $foreignPlan->id], $conversation);
            $this->fail('Forecasts must not resolve tuition plans outside the signed-in user’s relationship.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('agent_audit_logs', [
                'user_id' => $user->id,
                'tool_name' => 'forecast_scenarios',
                'status' => 'rejected',
            ]);
        }
    }

    public function test_agent_forecast_asks_for_missing_profile_and_scenario_information(): void
    {
        $user = User::factory()->create();
        $plan = $user->tuitionPlans()->create([
            'title' => 'Spring tuition',
            'amount' => '5000.00',
            'saved_amount' => '500.00',
            'currency' => 'USD',
            'due_date' => today()->addMonths(4),
            'installments' => 2,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Missing baseline']);
        $registry = app(AgentToolRegistry::class);

        $missingProfile = $registry->execute($user, 'forecast_scenarios', ['tuition_plan_id' => $plan->id], $conversation);
        $this->assertSame('needs_clarification', $missingProfile['status']);
        $this->assertArrayNotHasKey('scenarios', $missingProfile);
        $this->assertStringContainsString('home currency and monthly budget', $missingProfile['questions'][0]);

        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1800.00',
            'monthly_income' => '0.00',
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Food',
            'description' => 'Groceries',
            'amount' => '500.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $missingScenario = $registry->execute($user, 'forecast_scenarios', [
            'tuition_plan_id' => $plan->id,
            'rent_change_percent' => 10,
            'income_delay_months' => 1,
            'scholarship_amount' => '400.00',
        ], $conversation);

        $this->assertSame('needs_clarification', $missingScenario['status']);
        $this->assertArrayNotHasKey('scenarios', $missingScenario);
        $this->assertStringContainsString('might this unconfirmed scholarship arrive', $missingScenario['questions'][0]);

        $missingRentAndIncome = $registry->execute($user, 'forecast_scenarios', [
            'tuition_plan_id' => $plan->id,
            'rent_change_percent' => 10,
            'income_delay_months' => 1,
            'scholarship_amount' => '400.00',
            'scholarship_month' => 3,
        ], $conversation);

        $this->assertSame('needs_clarification', $missingRentAndIncome['status']);
        $this->assertCount(1, $missingRentAndIncome['questions']);
        $this->assertStringContainsString('monthly rent', $missingRentAndIncome['questions'][0]);
    }

    public function test_agent_forecast_can_assess_an_owned_savings_goal_and_requires_its_target_date(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1800.00',
            'monthly_income' => '500.00',
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Rent',
            'amount' => '400.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Food',
            'description' => 'Groceries',
            'amount' => '300.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $goal = $user->savingsGoals()->create([
            'title' => 'Emergency fund',
            'target_amount' => '2400.00',
            'current_amount' => '600.00',
            'currency' => 'EUR',
            'target_date' => today()->addDays(180),
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Savings goal forecast']);
        $result = app(AgentToolRegistry::class)->execute($user, 'forecast_scenarios', [
            'savings_goal_id' => $goal->id,
        ], $conversation);
        $planner = app(FinancialPlanner::class);
        $calculator = app(CurrencyCalculator::class);
        $schedule = $planner->tuitionSchedule('2400.00', '600.00', $goal->target_date->toDateString());
        $target = $calculator->convert('2400.00', 'EUR', 'USD');
        $saved = $calculator->convert('600.00', 'EUR', 'USD');
        $pace = $calculator->convert($schedule['required_monthly'], 'EUR', 'USD');
        $expected = $planner->forecastScenarios(
            '1800.00', '700.00', '400.00', '500.00', $pace['converted_amount'],
            $target['converted_amount'], $saved['converted_amount'], '0.00', 0, 0, 0, 6, 0,
        )[1];
        $foreignGoal = $other->savingsGoals()->create([
            'title' => 'Other private goal',
            'target_amount' => '5000.00',
            'current_amount' => '0.00',
            'currency' => 'USD',
            'target_date' => today()->addMonths(6),
        ]);

        $this->assertSame('savings goal', $result['plan']['type']);
        $this->assertSame($expected, $result['baseline_forecast']);
        $this->assertSame($target['converted_amount'], $result['conversions']['tuition_target']['converted_amount']);
        $this->assertSame($target['rate'], $result['conversions']['tuition_target']['rate']);
        $this->assertSame('USD', $result['baseline_inputs']['currency']);

        try {
            app(AgentToolRegistry::class)->execute($user, 'forecast_scenarios', ['savings_goal_id' => $foreignGoal->id], $conversation);
            $this->fail('A forecast must not read another user’s savings goal.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('agent_audit_logs', [
                'user_id' => $user->id,
                'tool_name' => 'forecast_scenarios',
                'status' => 'rejected',
            ]);
        }

        $goalWithoutDate = $user->savingsGoals()->create([
            'title' => 'Undated savings goal',
            'target_amount' => '800.00',
            'current_amount' => '0.00',
            'currency' => 'USD',
            'target_date' => null,
        ]);
        $missingDate = app(AgentToolRegistry::class)->execute($user, 'forecast_scenarios', [
            'savings_goal_id' => $goalWithoutDate->id,
        ], $conversation);

        $this->assertSame('needs_clarification', $missingDate['status']);
        $this->assertStringContainsString('target date', $missingDate['questions'][0]);
    }

    public function test_new_tuition_plan_is_validated_staged_confirmed_once_and_can_be_cancelled_or_expire(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1500.00',
            'monthly_income' => '0.00',
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Rent',
            'amount' => '600.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Create tuition plan']);
        $registry = app(AgentToolRegistry::class);
        $arguments = [
            'title' => 'Fall tuition',
            'amount' => '3000.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(90)->toDateString(),
            'installments' => 3,
        ];
        $prepared = $registry->execute($user, 'create_tuition_plan', $arguments, $conversation);
        $display = app(AgentToolDisplayPresenter::class)->presentResults([
            ['name' => 'create_tuition_plan', 'result' => $prepared],
        ])[0];
        $pendingId = (int) $prepared['pending_action']['id'];

        $this->assertTrue($prepared['confirmation_required']);
        $this->assertSame('1000.00', $prepared['schedule']['required_monthly']);
        $this->assertSame('Fall tuition', $display['pending_action']['arguments']['title']);
        $this->assertSame('3000.00', $display['pending_action']['arguments']['amount']);
        $this->assertSame('0.00', $display['pending_action']['arguments']['saved_amount']);
        $this->assertSame('USD', $display['pending_action']['arguments']['currency']);
        $this->assertSame($arguments['due_date'], $display['pending_action']['arguments']['due_date']);
        $this->assertSame(3, $display['pending_action']['arguments']['installments']);
        $proposedChanges = collect($display['receipt']['proposed_changes'])->mapWithKeys(
            fn (array $change): array => [$change['field'] => $change['proposed']],
        )->all();
        $this->assertSame('Fall tuition', $proposedChanges['title']);
        $this->assertSame('3000.00 USD', $proposedChanges['amount']);
        $this->assertSame('0.00 USD', $proposedChanges['saved amount']);
        $this->assertSame('USD', $proposedChanges['currency']);
        $this->assertSame($arguments['due_date'], $proposedChanges['due date']);
        $this->assertSame('3', $proposedChanges['installments']);
        $this->assertTrue($display['receipt']['approval_required']);
        $this->assertStringContainsString('Explicit approval is still required', $display['receipt']['result']);
        $this->assertSame(0, $user->tuitionPlans()->count());

        try {
            $registry->confirm($other, $conversation, $pendingId);
            $this->fail('A different account must not confirm a tuition proposal.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('agent_pending_actions', ['id' => $pendingId, 'status' => 'pending']);
        }

        $saved = $registry->confirm($user, $conversation, $pendingId);

        $this->assertTrue($saved['saved']);
        $this->assertSame(1, $user->tuitionPlans()->count());
        $savedPlan = $user->tuitionPlans()->firstOrFail();
        $this->assertSame($user->id, $savedPlan->user_id);
        $this->assertSame('Fall tuition', $savedPlan->title);
        $this->assertEqualsWithDelta(3000.00, (float) $savedPlan->amount, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $savedPlan->saved_amount, 0.001);
        $this->assertSame('USD', $savedPlan->currency);
        $this->assertSame($arguments['due_date'], $savedPlan->due_date->toDateString());
        $this->assertSame(3, (int) $savedPlan->installments);

        try {
            $registry->confirm($user, $conversation, $pendingId);
            $this->fail('A confirmed tuition plan proposal must not be replayed.');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, $user->tuitionPlans()->count());
        }

        $cancelled = $registry->execute($user, 'create_tuition_plan', [
            ...$arguments,
            'title' => 'Cancelled tuition',
        ], $conversation);
        $cancelledId = (int) $cancelled['pending_action']['id'];
        $registry->cancel($user, $conversation, $cancelledId);
        $this->assertDatabaseMissing('tuition_plans', ['title' => 'Cancelled tuition']);

        $expired = $registry->execute($user, 'create_tuition_plan', [
            ...$arguments,
            'title' => 'Expired tuition',
        ], $conversation);
        $expiredId = (int) $expired['pending_action']['id'];
        $user->agentPendingActions()->whereKey($expiredId)->update(['expires_at' => now()->subSecond()]);

        try {
            $registry->confirm($user, $conversation, $expiredId);
            $this->fail('An expired tuition proposal must not be saved.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseMissing('tuition_plans', ['title' => 'Expired tuition']);
            $this->assertDatabaseHas('agent_pending_actions', ['id' => $expiredId, 'status' => 'expired']);
        }
    }

    public function test_new_tuition_plan_rejects_invalid_currency_progress_and_date(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Invalid tuition proposal']);
        $baseArguments = [
            'title' => 'Invalid tuition',
            'amount' => '3000.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(90)->toDateString(),
        ];

        foreach ([
            [...$baseArguments, 'currency' => 'ZZZ'],
            [...$baseArguments, 'saved_amount' => '3000.01'],
            [...$baseArguments, 'due_date' => today()->subDay()->toDateString()],
        ] as $arguments) {
            try {
                app(AgentToolRegistry::class)->execute($user, 'create_tuition_plan', $arguments, $conversation);
                $this->fail('Invalid tuition plan details must be rejected before staging.');
            } catch (ValidationException) {
                $this->assertSame(0, $user->tuitionPlans()->count());
                $this->assertSame(0, $user->agentPendingActions()->count());
            }
        }
    }

    public function test_decision_receipt_uses_the_backend_conversion_result_and_discloses_rate_assumptions(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Explain conversion']);
        $result = app(AgentToolRegistry::class)->execute($user, 'convert_currency', [
            'amount' => '100.00',
            'from_currency' => 'USD',
            'to_currency' => 'CAD',
        ], $conversation);

        $receipt = app(AgentToolDisplayPresenter::class)->presentResults([
            ['name' => 'convert_currency', 'result' => $result],
        ])[0]['receipt'];

        $this->assertSame(['100.00 USD'], $receipt['inputs']);
        $this->assertStringContainsString($result['rate'], $receipt['calculation']);
        $this->assertStringContainsString($result['converted_amount'].' CAD', $receipt['result']);
        $this->assertStringContainsString($result['source'], $receipt['assumptions'][0]);
        $this->assertFalse($receipt['approval_required']);
    }

    public function test_decision_receipt_shows_exact_pending_changes_and_keeps_approval_required(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Explain budget']);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'create_budget', [
            'category' => 'Books',
            'amount' => '250.00',
            'currency' => 'CAD',
        ], $conversation);
        $display = app(AgentToolDisplayPresenter::class)->presentResults([
            ['name' => 'create_budget', 'result' => $prepared],
        ])[0];

        $this->assertTrue($display['receipt']['approval_required']);
        $this->assertSame('250.00 CAD', $display['receipt']['proposed_changes'][1]['proposed']);
        $this->assertStringContainsString('Explicit approval is still required', $display['receipt']['result']);
        $this->assertDatabaseCount('budgets', 0);

        $registry->confirm($user, $conversation, (int) $prepared['pending_action']['id']);

        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category' => 'Books', 'currency' => 'CAD']);
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

    public function test_openrouter_uses_the_configured_model_endpoint_and_openai_tool_schema(): void
    {
        Config::set('services.openrouter.key', 'private-test-key');
        Config::set('services.openrouter.model', 'openai/gpt-4o-mini');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response($this->textResponse('OK')),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Endpoint']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Reply with OK']);

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Reply with OK');

        $this->assertSame('OK', $result['text']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::OPENROUTER_ENDPOINT
            && $request->hasHeader('Authorization', 'Bearer private-test-key')
            && $request['model'] === 'openai/gpt-4o-mini'
            && $request['messages'][1]['content'] === 'Reply with OK'
            && isset($request['tools'][0]['type'], $request['tools'][0]['function']['parameters'])
            && ! str_contains($request->body(), 'private-test-key'));
    }

    public function test_simple_greetings_and_thanks_are_answered_without_provider_or_tool_calls(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Friendly conversation']);
        $agent = app(OpenRouterAgent::class);

        foreach ([
            'Hello' => 'Hi! I’m here and ready to help. What’s on your mind?',
            'Hi Atlas!' => 'Hi! I’m here and ready to help. What’s on your mind?',
            'Good morning' => 'Good day! I’m here to help whenever you’re ready.',
            'How are you?' => 'I’m here and ready to help—thanks for asking. How are you doing?',
            'Thank you.' => 'You’re welcome. I’m glad I could help.',
        ] as $message => $expectedResponse) {
            $result = $agent->respond($user, $conversation, $message);

            $this->assertSame($expectedResponse, $result['text']);
            $this->assertSame([], $result['tool_results']);
            $this->assertSame([], $result['pending_actions']);
        }

        $this->assertSame(0, Http::recorded()->count());
        $this->assertDatabaseCount('agent_audit_logs', 0);
    }

    public function test_general_questions_use_natural_language_without_financial_tool_calls(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response($this->textResponse(
                'A useful study routine usually balances focused work with short breaks. I can help you think through a plan.',
            )),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Study routine']);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => 'Can you suggest a study routine?',
        ]);

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Can you suggest a study routine?');

        $this->assertSame(
            'A useful study routine usually balances focused work with short breaks. I can help you think through a plan.',
            $result['text'],
        );
        $this->assertSame([], $result['tool_results']);
        $this->assertSame([], $result['pending_actions']);
        Http::assertSent(fn (Request $request): bool => ($request['messages'][0]['role'] ?? null) === 'system'
            && str_contains($request['messages'][0]['content'], 'Answer greetings, thanks, ordinary questions and general guidance naturally')
            && str_contains($request['messages'][0]['content'], 'Be clear when a request is outside Atlas’s supported capabilities')
            && ($request['messages'][1]['content'] ?? null) === 'Can you suggest a study routine?');
        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_follow_up_context_is_sent_only_when_the_user_has_opted_in(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response($this->textResponse('What amount should I use?')),
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
        $conversation = $user->agentConversations()->create(['title' => 'Private context']);
        $conversation->messages()->createMany([
            ['user_id' => $user->id, 'role' => 'user', 'content' => 'My rent is 900 USD.'],
            ['user_id' => $user->id, 'role' => 'assistant', 'content' => 'Would you like to test a rent increase?'],
            ['user_id' => $user->id, 'role' => 'user', 'content' => 'Make that amount 1,000.'],
        ]);

        app(OpenRouterAgent::class)->respond($user, $conversation, 'Make that amount 1,000.');

        Http::assertSent(fn (Request $request): bool => count($request['messages'] ?? []) === 2
            && array_column($request['messages'], 'role') === ['system', 'user']
            && ($request['messages'][1]['content'] ?? null) === 'Make that amount 1,000.'
            && ! str_contains($request->body(), 'My rent is 900 USD.')
            && ! str_contains($request->body(), 'Would you like to test a rent increase?'));
    }

    public function test_ambiguous_deletion_request_is_clarified_without_preparing_a_change(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response($this->textResponse(
                'I found more than one monthly budget. Which category and month do you want to delete?',
            )),
        ]);
        $user = User::factory()->create();
        foreach (['Books', 'Housing'] as $category) {
            $user->budgets()->create([
                'category' => $category,
                'amount' => '250.00',
                'currency' => 'USD',
                'period_start' => today()->startOfMonth(),
                'period_end' => today()->endOfMonth(),
            ]);
        }
        $conversation = $user->agentConversations()->create(['title' => 'Ambiguous deletion']);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => 'Delete my budget.',
        ]);

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Delete my budget.');

        $this->assertSame('I found more than one monthly budget. Which category and month do you want to delete?', $result['text']);
        $this->assertSame([], $result['tool_results']);
        $this->assertSame([], $result['pending_actions']);
        $this->assertDatabaseCount('agent_pending_actions', 0);
        $this->assertDatabaseCount('budgets', 2);
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request['messages'][0]['content'],
            'If a requested update or deletion could refer to more than one record, ask the user to identify the exact record before preparing a change',
        ));
    }

    public function test_openrouter_tool_calls_execute_and_return_results_in_the_follow_up_request(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('get_financial_summary', [], 'call_summary'))
                ->push($this->textResponse('Your overview is ready.')),
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

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Show my overview');

        $this->assertSame('Your overview is ready.', $result['text']);
        $this->assertSame('USD', $result['tool_results'][0]['result']['profile']['home_currency']);
        $this->assertSame(2, Http::recorded()->count());
        $this->assertDatabaseHas('agent_audit_logs', ['user_id' => $user->id, 'status' => 'completed']);
        Http::assertSent(fn (Request $request): bool => collect($request['messages'] ?? [])
            ->contains(fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_call_id'] ?? null) === 'call_summary'
                && ($message['name'] ?? null) === 'get_financial_summary'
                && str_contains((string) ($message['content'] ?? ''), 'home_currency')));
    }

    public function test_groq_uses_the_existing_tool_loop_and_returns_a_natural_language_follow_up(): void
    {
        Config::set('services.ai.provider', 'groq');
        Config::set('services.groq.key', 'private-groq-test-key');
        Config::set('services.groq.model', 'openai/gpt-oss-120b');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::sequence()
                ->push($this->toolResponse('get_financial_summary', [], 'call_summary'))
                ->push($this->textResponse('Your overview is ready.')),
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
        $conversation = $user->agentConversations()->create(['title' => 'Groq overview']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Show my overview']);

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Show my overview');

        $this->assertSame('Your overview is ready.', $result['text']);
        $this->assertSame('USD', $result['tool_results'][0]['result']['profile']['home_currency']);
        $this->assertSame(2, Http::recorded()->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request['tool_choice'] === 'auto'
            && count($request['tools']) === 18
            && $request['max_completion_tokens'] === 900
            && ! array_key_exists('max_tokens', $request->data())
            && collect($request['messages'] ?? [])->contains(fn (array $message): bool => ($message['role'] ?? null) === 'tool'
                && ($message['tool_call_id'] ?? null) === 'call_summary'
                && ($message['name'] ?? null) === 'get_financial_summary'
                && str_contains((string) ($message['content'] ?? ''), 'home_currency')));
    }

    public function test_groq_planning_changes_still_require_explicit_server_side_confirmation(): void
    {
        Config::set('services.ai.provider', 'groq');
        Config::set('services.groq.key', 'private-groq-test-key');
        Config::set('services.groq.model', 'openai/gpt-oss-120b');
        Http::preventStrayRequests();
        Http::fake([
            self::GROQ_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('create_savings_goal', [
                    'title' => 'Synthetic reserve',
                    'target_amount' => '400.00',
                    'currency' => 'USD',
                ], 'call_goal'))
                ->push($this->textResponse('Review this proposal before saving.')),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Prepare a synthetic savings goal.',
        ])->assertOk()
            ->assertJsonPath('pending_actions.0.name', 'create_savings_goal')
            ->assertJsonPath('text', "Review this proposal before saving.\n\nNo planning changes were saved. Review each draft and confirm it, or choose Not now.");

        $this->assertDatabaseCount('savings_goals', 0);
        $this->postJson(route('agent.confirm'), [
            'conversation_id' => $response->json('conversation_id'),
            'pending_action_id' => $response->json('pending_actions.0.id'),
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('saved.saved', true);
        $this->assertDatabaseHas('savings_goals', [
            'user_id' => $user->id,
            'title' => 'Synthetic reserve',
            'target_amount' => '400.00',
        ]);
    }

    public function test_chat_and_history_show_tool_summaries_without_exposing_raw_tool_results(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('get_financial_summary', [], 'call_summary'))
                ->push($this->textResponse('Your overview is ready.')),
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

        $response = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Show my overview',
        ])->assertOk()
            ->assertJsonPath('text', 'Your overview is ready.')
            ->assertJsonPath('tool_results.0.name', 'get_financial_summary')
            ->assertJsonPath('tool_results.0.summary', 'Your financial overview is ready for Atlas’s explanation.')
            ->assertJsonPath('tool_results.0.receipt.approval_required', false)
            ->assertJsonMissingPath('tool_results.0.result')
            ->assertJsonMissingPath('tool_results.0.arguments')
            ->assertDontSee('1800.00');

        $this->assertSame([
            'source', 'inputs', 'calculation', 'assumptions', 'result', 'approval_required', 'proposed_changes',
        ], array_keys($response->json('tool_results.0.receipt')));

        $conversationId = $response->json('conversation_id');
        $assistantMessage = $user->agentConversations()->findOrFail($conversationId)
            ->messages()
            ->where('role', 'assistant')
            ->firstOrFail();

        $this->assertSame('USD', data_get($assistantMessage->tool_result, '0.result.profile.home_currency'));

        $this->actingAs($user)->getJson(route('agent.history', $conversationId))
            ->assertOk()
            ->assertJsonPath('messages.1.tool_result.0.name', 'get_financial_summary')
            ->assertJsonPath('messages.1.tool_result.0.summary', 'Your financial overview is ready for Atlas’s explanation.')
            ->assertJsonPath('messages.1.tool_result.0.receipt.approval_required', false)
            ->assertJsonMissingPath('messages.1.tool_result.0.result')
            ->assertJsonMissingPath('messages.1.tool_result.0.arguments');

        $page = $this->actingAs($user)
            ->get(route('dashboard', ['conversation_id' => $conversationId]))
            ->assertOk();
        preg_match('/data-display-tool-results="([^"]*)"/', $page->getContent(), $matches);
        $displayResults = json_decode(
            html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('Your financial overview is ready for Atlas’s explanation.', $displayResults[0]['summary']);
        $this->assertArrayNotHasKey('result', $displayResults[0]);
    }

    public function test_openrouter_can_execute_different_tools_across_multiple_bounded_rounds(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '2200.00',
            'monthly_income' => '700.00',
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Rent',
            'amount' => '800.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
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
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('get_financial_summary', [], 'call_summary'))
                ->push($this->toolResponse('plan_tuition', ['tuition_plan_id' => $plan->id], 'call_tuition'))
                ->push($this->toolResponse('forecast_scenarios', [
                    'tuition_plan_id' => $plan->id,
                    'forecast_months' => 4,
                    'rate_stress_percent' => 5,
                ], 'call_forecast'))
                ->push($this->textResponse('Your tuition pace is ready.')),
        ]);

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Summarize and plan tuition');

        $this->assertSame('Your tuition pace is ready.', $result['text']);
        $this->assertSame(['get_financial_summary', 'plan_tuition', 'forecast_scenarios'], collect($result['tool_results'])->pluck('name')->all());
        $this->assertSame($plan->id, $result['tool_results'][1]['result']['id']);
        $this->assertSame('2500.00', $result['tool_results'][1]['result']['schedule']['remaining']);
        $this->assertSame('USD', $result['tool_results'][2]['result']['baseline_inputs']['currency']);
        $this->assertSame('complete', $result['tool_results'][2]['result']['status']);
        $this->assertSame(4, Http::recorded()->count());
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request['messages'][0]['content'],
            'For a tuition Flight Plan, on-track question, required savings pace or scenario comparison',
        ));
    }

    public function test_complete_openrouter_tool_registry_uses_supported_json_schema_shapes(): void
    {
        $declarations = app(AgentToolRegistry::class)->declarations();
        $expectedNames = [
            'get_financial_summary',
            'get_funding_opportunities',
            'convert_currency',
            'analyze_spending',
            'plan_tuition',
            'forecast_scenarios',
            'create_tuition_plan',
            'update_savings_goal',
            'update_tuition_plan',
            'update_budget',
            'delete_tuition_plan',
            'delete_savings_goal',
            'delete_budget',
            'compare_remittance',
            'create_savings_goal',
            'create_budget',
            'save_plan',
            'update_profile',
        ];

        $this->assertSame($expectedNames, array_column($declarations, 'name'));
        $tuitionCreation = collect($declarations)->firstWhere('name', 'create_tuition_plan');
        $this->assertSame(['title', 'amount', 'currency', 'due_date'], $tuitionCreation['parameters']['required']);

        foreach ($declarations as $declaration) {
            $parameters = $declaration['parameters'];
            $properties = (array) $parameters['properties'];

            $this->assertSame('object', $parameters['type']);
            $this->assertFalse($parameters['additionalProperties']);
            $this->assertSame([], array_diff($parameters['required'], array_keys($properties)));
            $this->assertSame(
                ['type', 'properties', 'required', 'additionalProperties'],
                array_keys($parameters),
            );
            $this->assertJson(json_encode($parameters, JSON_THROW_ON_ERROR));

            foreach ($properties as $property) {
                $this->assertContains($property['type'], ['string', 'integer']);
                $this->assertSame(
                    [],
                    array_diff(array_keys($property), ['type', 'description', 'enum']),
                );

                if (isset($property['enum'])) {
                    $this->assertNotEmpty($property['enum']);
                    $this->assertSame([], array_filter($property['enum'], fn (mixed $value): bool => ! is_string($value)));
                }
            }
        }
    }

    public function test_openrouter_multi_turn_payload_pairs_each_assistant_tool_call_with_ordered_tool_results(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Config::set('services.openrouter.model', 'cohere/north-mini-code:free');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1000.00',
            'monthly_income' => '0.00',
            'memory_consent' => true,
        ]);
        $tuitionPlan = $user->tuitionPlans()->create([
            'title' => 'Synthetic tuition plan',
            'amount' => '3000.00',
            'saved_amount' => '500.00',
            'currency' => 'USD',
            'due_date' => today()->addMonths(4),
            'installments' => 2,
        ]);
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('get_financial_summary', [], 'synthetic_summary_call'))
                ->push($this->toolResponse('plan_tuition', ['tuition_plan_id' => $tuitionPlan->id], 'synthetic_tuition_call'))
                ->push($this->textResponse('Synthetic planning analysis complete.')),
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Synthetic multi-turn']);
        foreach ([
            ['user', 'Synthetic prior question one.'],
            ['assistant', 'Synthetic prior answer one.'],
            ['user', 'Synthetic prior question two.'],
            ['assistant', 'Synthetic prior answer two.'],
            ['user', 'Summarize and plan synthetic tuition.'],
        ] as [$role, $content]) {
            $conversation->messages()->create([
                'user_id' => $user->id,
                'role' => $role,
                'content' => $content,
            ]);
        }

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Summarize and plan synthetic tuition.');

        $this->assertSame('Synthetic planning analysis complete.', $result['text']);
        $this->assertSame(['get_financial_summary', 'plan_tuition'], array_column($result['tool_results'], 'name'));
        $this->assertSame(3, Http::recorded()->count());
        Http::assertSentInOrder([
            fn (Request $request): bool => $request['model'] === 'cohere/north-mini-code:free'
                && $request['tool_choice'] === 'auto'
                && count($request['tools']) === 18
                && count($request['messages']) === 6
                && array_column($request['messages'], 'role') === ['system', 'user', 'assistant', 'user', 'assistant', 'user'],
            fn (Request $request): bool => $request['tool_choice'] === 'auto'
                && count($request['tools']) === 18
                && array_column($request['messages'], 'role') === ['system', 'user', 'assistant', 'user', 'assistant', 'user', 'assistant', 'tool']
                && $request['messages'][6]['tool_calls'][0]['id'] === 'synthetic_summary_call'
                && $request['messages'][7]['tool_call_id'] === 'synthetic_summary_call'
                && is_string($request['messages'][7]['content']),
            fn (Request $request): bool => $request['tool_choice'] === 'auto'
                && count($request['tools']) === 18
                && array_column($request['messages'], 'role') === ['system', 'user', 'assistant', 'user', 'assistant', 'user', 'assistant', 'tool', 'assistant', 'tool']
                && $request['messages'][8]['tool_calls'][0]['id'] === 'synthetic_tuition_call'
                && $request['messages'][9]['tool_call_id'] === 'synthetic_tuition_call'
                && json_decode($request['messages'][9]['content'], true, 512, JSON_THROW_ON_ERROR)['id'] === (string) $tuitionPlan->id,
        ]);
    }

    public function test_openrouter_tool_rounds_stop_at_the_safe_limit(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('get_financial_summary', [], 'call_1'))
                ->push($this->toolResponse('get_financial_summary', [], 'call_2'))
                ->push($this->toolResponse('get_financial_summary', [], 'call_3'))
                ->push($this->toolResponse('get_financial_summary', [], 'call_4'))
                ->push($this->toolResponse('get_financial_summary', [], 'call_5')),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Bounded tools']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Keep checking my summary']);

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Keep checking my summary');

        $this->assertCount(4, $result['tool_results']);
        $this->assertStringContainsString('safe limit', $result['text']);
        $this->assertSame(5, Http::recorded()->count());
    }

    public function test_duplicate_mutating_tool_calls_are_not_repeated_in_a_turn(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        $arguments = ['title' => 'Emergency fund', 'target_amount' => '1000.00', 'currency' => 'USD'];
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                        $this->toolCall('create_savings_goal', $arguments, 'call_first'),
                        $this->toolCall('create_savings_goal', $arguments, 'call_second'),
                    ]]]]])
                ->push($this->textResponse('The draft is ready for review.')),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Duplicate tool calls']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Create an emergency goal']);

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Create an emergency goal');

        $this->assertCount(2, $result['tool_results']);
        $this->assertArrayHasKey('pending_action', $result['tool_results'][0]['result']);
        $this->assertStringContainsString('already handled', $result['tool_results'][1]['result']['error']);
        $this->assertDatabaseCount('agent_pending_actions', 1);
        $this->assertDatabaseCount('savings_goals', 0);
    }

    public function test_oversized_openrouter_tool_batch_is_rejected_before_any_tool_runs(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        $calls = [];

        for ($index = 0; $index < 5; $index++) {
            $calls[] = $this->toolCall('get_financial_summary', [], 'call_'.$index);
        }

        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => $calls]]],
            ]),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Oversized batch']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Summarize my finances']);

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Summarize my finances');

        $this->assertStringContainsString('safe tool-call limit', $result['text']);
        $this->assertSame([], $result['tool_results']);
        $this->assertDatabaseCount('agent_audit_logs', 0);
        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_invalid_model_tool_arguments_are_not_executed(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('analyze_spending', ['period_days' => 9999], 'call_invalid'))
                ->push($this->textResponse('I could not analyze that period.')),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Invalid request']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Analyze everything']);

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Analyze everything');

        $this->assertStringContainsString('I could not analyze that period.', $result['text']);
        $this->assertStringContainsString('At least one requested tool did not complete', $result['text']);
        $this->assertSame('The requested tool arguments were invalid or the record is unavailable to this account.', $result['tool_results'][0]['result']['error']);
        $this->assertDatabaseHas('agent_audit_logs', ['status' => 'rejected']);
    }

    public function test_prepared_openrouter_write_cannot_be_reported_as_already_saved(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('create_savings_goal', [
                    'title' => 'Emergency fund',
                    'target_amount' => '1000.00',
                    'currency' => 'USD',
                ], 'call_goal'))
                ->push($this->textResponse('I saved your goal.')),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Prepare a goal']);
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Save a goal']);

        $result = app(OpenRouterAgent::class)->respond($user, $conversation, 'Save a goal');

        $this->assertStringContainsString('No planning changes were saved.', $result['text']);
        $this->assertTrue($result['tool_results'][0]['result']['confirmation_required']);
        $this->assertDatabaseCount('savings_goals', 0);
    }

    public function test_chat_returns_a_safe_configuration_error_without_sending_a_request_when_key_is_missing(): void
    {
        Config::set('services.openrouter.key', null);
        Http::preventStrayRequests();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Help me plan tuition.',
        ])->assertServiceUnavailable()
            ->assertJsonPath('message', 'Atlas AI is not configured. Set OPENROUTER_API_KEY on the server.');

        $this->assertDatabaseHas('agent_messages', ['user_id' => $user->id, 'role' => 'user']);
        $this->assertSame(0, Http::recorded()->count());
    }

    public function test_chat_hides_invalid_openrouter_api_key_details_from_the_user(): void
    {
        $apiKey = 'private-test-key';
        Config::set('services.openrouter.key', $apiKey);
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'error' => ['code' => 'invalid_api_key', 'message' => 'Rejected key: '.$apiKey],
            ], 401),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Help me plan.',
        ]);

        $response->assertServiceUnavailable()
            ->assertJsonPath('message', 'OpenRouter rejected the configured API key. Verify OPENROUTER_API_KEY on the server.')
            ->assertDontSee($apiKey);
        $this->assertDatabaseMissing('agent_messages', ['content' => $apiKey]);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$apiKey)
            && ! str_contains($request->body(), $apiKey));
    }

    public function test_chat_reports_openrouter_rate_limits_without_raw_provider_details_or_retries(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Provider internal details'],
            ], 429),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('agent.chat'), ['message' => 'Try again']);

        $response->assertTooManyRequests()
            ->assertJsonPath('message', 'OpenRouter is rate limiting Atlas right now. Try again in a moment.')
            ->assertDontSee('Provider internal details');
        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_chat_logs_safe_openrouter_request_and_response_diagnostics_for_invalid_requests(): void
    {
        $apiKey = 'private-test-key';
        $privateFinancialDetails = 'My tuition balance is 9876.54 EUR';
        Config::set('services.openrouter.key', $apiKey);
        Config::set('services.openrouter.model', 'cohere/north-mini-code:free');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'error' => [
                    'code' => 'invalid_request_error',
                    'message' => 'Invalid tool follow-up message format.',
                    'metadata' => ['error_type' => 'invalid_request'],
                ],
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'convert_currency',
                                'arguments' => '{"amount":"9876.54","from_currency":"EUR","to_currency":"USD"}',
                            ],
                        ]],
                    ],
                ]],
            ], 400),
        ]);
        Log::spy();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => $privateFinancialDetails,
        ])->assertStatus(502)
            ->assertJsonPath('message', 'OpenRouter rejected the request. Check the configured model and try again.')
            ->assertDontSee($privateFinancialDetails)
            ->assertDontSee($apiKey);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($apiKey, $privateFinancialDetails): bool {
            $this->assertSame('OpenRouter API request failed.', $message);
            $this->assertSame(400, $context['http_status']);
            $this->assertSame('invalid_request', $context['error_category']);
            $this->assertSame('invalid_request_error', $context['provider_code']);
            $this->assertSame('Invalid tool follow-up message format.', $context['provider_error_message']);
            $this->assertSame('tool_calls', $context['finish_reason']);
            $this->assertTrue($context['response_tool_calls_present']);
            $this->assertSame(1, $context['response_tool_call_count']);
            $this->assertSame('auto', $context['request_tool_choice']);
            $this->assertSame(18, $context['request_tool_count']);
            $this->assertSame(2, $context['request_message_count']);
            $this->assertStringNotContainsString($apiKey, json_encode($context));
            $this->assertStringNotContainsString($privateFinancialDetails, json_encode($context));
            $this->assertStringNotContainsString('9876.54', json_encode($context));

            return true;
        });
    }

    public function test_openrouter_client_captures_sanitized_provider_error_without_logging_request_data(): void
    {
        $apiKey = 'private-test-key';
        $financialDetails = 'My tuition balance is 9876.54 EUR';
        Config::set('services.openrouter.key', $apiKey);
        Config::set('services.openrouter.model', 'cohere/north-mini-code:free');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'error' => [
                    'code' => 'invalid_request_error',
                    'message' => 'Invalid request for key '.$apiKey,
                    'metadata' => ['error_type' => 'invalid_request'],
                ],
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'tool_calls' => [[
                            'function' => ['name' => 'convert_currency', 'arguments' => '{"amount":"9876.54"}'],
                        ]],
                    ],
                ]],
            ], 400),
        ]);
        Log::spy();

        try {
            app(OpenRouterClient::class)->complete([
                'messages' => [
                    ['role' => 'system', 'content' => 'Do not expose user data.'],
                    ['role' => 'user', 'content' => $financialDetails],
                ],
                'tools' => [['type' => 'function', 'function' => ['name' => 'convert_currency']]],
                'tool_choice' => 'auto',
                'max_tokens' => 900,
            ]);
            $this->fail('A provider HTTP 400 should be surfaced as a provider exception.');
        } catch (OpenRouterProviderException $exception) {
            $this->assertSame(400, $exception->diagnostics['http_status']);
            $this->assertSame('invalid_request_error', $exception->diagnostics['provider_code']);
            $this->assertSame('invalid_request', $exception->diagnostics['error_category']);
            $this->assertSame('Invalid request for key [redacted]', $exception->diagnostics['provider_error_message']);
            $this->assertSame('tool_calls', $exception->diagnostics['finish_reason']);
            $this->assertTrue($exception->diagnostics['response_tool_calls_present']);
            $this->assertSame(1, $exception->diagnostics['response_tool_call_count']);
            $this->assertSame('auto', $exception->diagnostics['request_tool_choice']);
            $this->assertSame(1, $exception->diagnostics['request_tool_count']);
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($apiKey, $financialDetails): bool {
            $this->assertSame('OpenRouter API request failed.', $message);
            $this->assertStringNotContainsString($apiKey, json_encode($context));
            $this->assertStringNotContainsString($financialDetails, json_encode($context));
            $this->assertSame('Invalid request for key [redacted]', $context['provider_error_message']);

            return true;
        });
    }

    public function test_chat_reports_an_unavailable_model_without_exposing_provider_response_text(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Config::set('services.openrouter.model', 'unavailable/model');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'error' => ['code' => 404, 'message' => 'No endpoints found for private provider configuration.'],
            ], 404),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('agent.chat'), ['message' => 'Help me plan']);

        $response->assertServiceUnavailable()
            ->assertJsonPath('message', 'The configured OpenRouter model is unavailable. Verify OPENROUTER_MODEL on the server.')
            ->assertDontSee('private provider configuration');
    }

    public function test_random_model_router_alias_is_rejected_before_a_provider_request(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Config::set('services.openrouter.model', 'openrouter/free');
        Http::preventStrayRequests();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('agent.chat'), ['message' => 'Help me plan'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'OPENROUTER_MODEL must be a specific model ID. The configured router can select different models; choose a fixed model that supports tool calling.');

        $this->assertSame(0, Http::recorded()->count());
    }

    public function test_malformed_successful_provider_response_returns_a_safe_error(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response(['choices' => [['message' => ['role' => 'assistant']]]]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('agent.chat'), ['message' => 'Help me plan'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'The AI provider returned a response without readable assistant content.');
    }

    public function test_chat_maps_provider_failures_and_timeouts_to_safe_messages(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'error' => ['code' => 'provider_error', 'message' => 'Private upstream details'],
            ], 500),
        ]);
        $user = User::factory()->create();

        $providerResponse = $this->actingAs($user)->postJson(route('agent.chat'), ['message' => 'Try again']);

        $providerResponse->assertServiceUnavailable()
            ->assertJsonPath('message', 'OpenRouter is temporarily unavailable. Try again later.')
            ->assertDontSee('Private upstream details');

        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::failedConnection('cURL error 28: Operation timed out'),
        ]);

        $timeoutResponse = $this->actingAs($user)->postJson(route('agent.chat'), ['message' => 'Try again']);

        $timeoutResponse->assertStatus(504)
            ->assertJsonPath('message', 'OpenRouter did not respond before the request timed out. Try again later.');
    }

    public function test_groq_provider_failure_is_returned_safely_by_the_chat_endpoint(): void
    {
        Config::set('services.ai.provider', 'groq');
        Config::set('services.groq.key', 'private-groq-test-key');
        Config::set('services.groq.model', 'openai/gpt-oss-120b');
        Http::preventStrayRequests();
        Http::fake([
            self::GROQ_ENDPOINT => Http::response([
                'error' => ['message' => 'Sensitive provider account details'],
            ], 429, ['Retry-After' => '30']),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('agent.chat'), ['message' => 'Help me with a synthetic question'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Groq rate limits or organization quota are preventing Atlas AI from responding. Try again later.')
            ->assertDontSee('Sensitive provider account details')
            ->assertDontSee('private-groq-test-key');

        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_openrouter_connection_command_makes_a_minimal_backend_request_without_printing_the_key(): void
    {
        Config::set('services.openrouter.key', 'private-test-key');
        Config::set('services.openrouter.model', 'openai/gpt-4o-mini');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response($this->toolResponse('atlas_tool_check', [
                'token' => 'atlas-tool-check',
            ], 'call_check')),
        ]);

        $this->artisan('openrouter:test')
            ->expectsOutput('HTTP status: 200.')
            ->expectsOutput('Finish reason: tool_calls.')
            ->expectsOutput('tool_calls present: yes.')
            ->expectsOutput('Tool call count: 1.')
            ->expectsOutput('OpenRouter connection and tool-call smoke test succeeded for model openai/gpt-4o-mini.')
            ->doesntExpectOutput('private-test-key')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::OPENROUTER_ENDPOINT
            && $request->hasHeader('Authorization', 'Bearer private-test-key')
            && $request['messages'][0]['content'] === 'Call the tool with token atlas-tool-check.'
            && $request['tool_choice'] === 'required'
            && $request['tools'][0]['function']['name'] === 'atlas_tool_check'
            && $request['max_tokens'] === 256
            && ! str_contains($request->body(), 'private-test-key'));
    }

    public function test_openrouter_connection_command_rejects_models_that_do_not_call_the_probe_tool(): void
    {
        Config::set('services.openrouter.key', 'private-test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'choices' => [[
                    'finish_reason' => 'length',
                    'message' => ['role' => 'assistant', 'content' => null],
                ]],
            ]),
        ]);

        $this->artisan('openrouter:test')
            ->expectsOutput('The configured OpenRouter model did not complete the required function-calling smoke test. Review the diagnostic details and model tool-call behavior.')
            ->expectsOutput('HTTP status: 200.')
            ->expectsOutput('Finish reason: length.')
            ->expectsOutput('tool_calls present: no.')
            ->expectsOutput('Error category: tool_call_not_returned.')
            ->doesntExpectOutput('private-test-key')
            ->assertFailed();
    }

    public function test_openrouter_connection_command_reports_safe_timeout_diagnostics(): void
    {
        Config::set('services.openrouter.key', 'private-test-key');
        Config::set('services.openrouter.model', 'openai/gpt-4o-mini');
        Config::set('services.openrouter.timeout', 25);
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::failedConnection('cURL error 28: Operation timed out'),
        ]);

        $this->artisan('openrouter:test')
            ->expectsOutput('Model: openai/gpt-4o-mini')
            ->expectsOutput('Total request timeout: 25s (connection timeout: 5s).')
            ->expectsOutput('TLS verification: enabled; project CA bundle certs/cacert.pem is present.')
            ->expectsOutput('Diagnostic: The request timed out, but transport statistics were unavailable to identify the phase.')
            ->doesntExpectOutput('private-test-key')
            ->assertFailed();
    }

    public function test_openrouter_connection_command_displays_redacted_upstream_rate_limit_details_without_logging_response_data(): void
    {
        $apiKey = 'private-test-key';
        $providerMessage = 'Upstream throttled Bearer '.$apiKey.' sk-or-v1-1234567890abcdef';
        Config::set('services.openrouter.key', $apiKey);
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'error' => [
                    'code' => 'rate_limit_exceeded',
                    'message' => $providerMessage,
                    'metadata' => [
                        'error_type' => 'provider',
                        'provider_name' => 'Example Provider',
                    ],
                ],
            ], 429, ['Retry-After' => '17']),
        ]);
        Log::spy();

        $this->artisan('openrouter:test')
            ->expectsOutput('The upstream provider is rate limiting Atlas AI. Try again later or select another supported model.')
            ->expectsOutput('Provider error message: Upstream throttled [redacted] [redacted]')
            ->expectsOutput('Provider error type: provider')
            ->expectsOutput('Provider error code: rate_limit_exceeded')
            ->expectsOutput('Upstream provider: Example Provider')
            ->expectsOutput('Retry-After: 17 seconds.')
            ->expectsOutput('Rate limit source: upstream provider rate limit.')
            ->doesntExpectOutput($apiKey)
            ->assertFailed();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($apiKey, $providerMessage): bool {
            $this->assertSame('OpenRouter API request failed.', $message);
            $this->assertSame('Upstream throttled [redacted] [redacted]', $context['provider_error_message']);
            $this->assertSame('rate_limit_exceeded', $context['provider_code']);
            $this->assertArrayNotHasKey('provider_name', $context);
            $this->assertStringNotContainsString($apiKey, json_encode($context));
            $this->assertStringNotContainsString($providerMessage, json_encode($context));

            return ($context['rate_limit_source'] ?? null) === 'upstream_provider';
        });
    }

    public function test_openrouter_connection_command_identifies_account_rate_limits(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'error' => [
                    'code' => 'rate_limit_exceeded',
                    'message' => 'OpenRouter account usage limit reached.',
                    'metadata' => ['error_type' => 'account'],
                ],
            ], 429, ['Retry-After' => 'Fri, 16 Oct 2026 17:40:00 GMT']),
        ]);

        $this->artisan('openrouter:test')
            ->expectsOutput('OpenRouter account limits are preventing Atlas AI from responding. Check your account usage and credits.')
            ->expectsOutput('Retry-After: Fri, 16 Oct 2026 17:40:00 GMT.')
            ->expectsOutput('Rate limit source: OpenRouter account or usage limits.')
            ->assertFailed();
    }

    public function test_openrouter_free_model_daily_limit_is_reported_as_an_account_limit_without_retrying(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Config::set('services.openrouter.model', 'cohere/north-mini-code:free');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Rate limit exceeded: free-models-per-day. Add credits to increase the daily free-model request quota.',
                ],
            ], 429),
        ]);

        $this->artisan('openrouter:test')
            ->expectsOutput('OpenRouter account limits are preventing Atlas AI from responding. Check your account usage and credits.')
            ->expectsOutput('Rate limit source: OpenRouter account or usage limits.')
            ->doesntExpectOutput('test-key')
            ->assertFailed();

        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_openrouter_connection_command_requires_a_fixed_model_configuration(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Config::set('services.openrouter.model', null);
        Http::preventStrayRequests();

        $this->artisan('openrouter:test')
            ->expectsOutput('Atlas AI has no model configured. Set OPENROUTER_MODEL on the server.')
            ->assertFailed();

        $this->assertSame(0, Http::recorded()->count());
    }

    public function test_openrouter_api_key_is_not_rendered_in_dashboard_html(): void
    {
        Config::set('services.openrouter.key', 'private-test-key');
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Key configured · connection not verified')
            ->assertDontSee('private-test-key');
    }

    public function test_groq_provider_status_uses_its_model_and_never_renders_its_api_key(): void
    {
        Config::set('services.ai.provider', 'groq');
        Config::set('services.groq.key', 'private-groq-test-key');
        Config::set('services.groq.model', 'openai/gpt-oss-120b');
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('ATLAS AI · Groq · openai/gpt-oss-120b')
            ->assertSee('Key configured · connection not verified')
            ->assertDontSee('private-groq-test-key');
    }

    public function test_chat_requires_authentication_valid_input_and_a_user_owned_conversation(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $owner->agentConversations()->create(['title' => 'Private conversation']);

        $this->postJson(route('agent.chat'), ['message' => 'Help me'])
            ->assertUnauthorized();
        $this->actingAs($other)->postJson(route('agent.chat'), ['message' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
        $this->actingAs($other)->postJson(route('agent.chat'), [
            'message' => 'Help me',
            'conversation_id' => $conversation->id,
        ])->assertNotFound();
        $this->assertSame(0, Http::recorded()->count());
    }

    public function test_chat_endpoint_requires_confirmation_before_saving_planning_actions(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('create_savings_goal', [
                    'title' => 'Emergency fund',
                    'target_amount' => '1200.00',
                    'currency' => 'USD',
                ], 'call_goal'))
                ->push($this->textResponse('Review this goal before saving.'))
                ->push($this->toolResponse('create_budget', [
                    'category' => 'Books',
                    'amount' => '250.00',
                    'currency' => 'USD',
                ], 'call_budget'))
                ->push($this->textResponse('Review this budget before saving.')),
        ]);
        $user = User::factory()->create();

        $goalResponse = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Prepare an emergency savings goal.',
        ])->assertOk()
            ->assertJsonPath('text', "Review this goal before saving.\n\nNo planning changes were saved. Review each draft and confirm it, or choose Not now.")
            ->assertJsonPath('pending_actions.0.name', 'create_savings_goal')
            ->assertJsonPath('tool_results.0.summary', 'A proposed planning change is ready for your review. Nothing has been saved.')
            ->assertJsonPath('tool_results.0.pending_action.name', 'create_savings_goal')
            ->assertJsonMissingPath('tool_results.0.result');

        $this->assertDatabaseCount('savings_goals', 0);
        $this->postJson(route('agent.confirm'), [
            'conversation_id' => $goalResponse->json('conversation_id'),
            'pending_action_id' => $goalResponse->json('pending_actions.0.id'),
            'confirmed' => true,
            'arguments' => ['target_amount' => '999999.00'],
            'target_amount' => '999999.00',
        ])->assertOk()->assertJsonPath('saved.saved', true);
        $this->assertDatabaseHas('savings_goals', [
            'user_id' => $user->id,
            'title' => 'Emergency fund',
            'target_amount' => '1200.00',
        ]);
        $this->getJson(route('agent.history', $goalResponse->json('conversation_id')))
            ->assertOk()
            ->assertJsonPath('messages.1.tool_result.0.pending_action.status', 'confirmed')
            ->assertJsonPath('messages.1.tool_result.0.pending_action.outcome.target_amount', '1200.00');

        $budgetResponse = $this->postJson(route('agent.chat'), [
            'message' => 'Prepare a monthly books budget.',
            'conversation_id' => $goalResponse->json('conversation_id'),
        ])->assertOk()->assertJsonPath('pending_actions.0.name', 'create_budget');

        $this->postJson(route('agent.cancel'), [
            'conversation_id' => $goalResponse->json('conversation_id'),
            'pending_action_id' => $budgetResponse->json('pending_actions.0.id'),
            'discarded' => true,
        ])->assertOk();
        $this->assertDatabaseCount('budgets', 0);
        $this->assertDatabaseHas('agent_pending_actions', [
            'id' => $budgetResponse->json('pending_actions.0.id'),
            'status' => 'cancelled',
        ]);
        $this->getJson(route('agent.history', $goalResponse->json('conversation_id')))
            ->assertOk()
            ->assertJsonPath('messages.3.tool_result.0.pending_action.status', 'cancelled');
    }

    public function test_chat_deletion_requires_action_specific_confirmation_and_reports_verified_removal(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $budget = $user->budgets()->create([
            'category' => 'Books', 'amount' => '250.00', 'currency' => 'USD',
            'period_start' => today()->startOfMonth(), 'period_end' => today()->endOfMonth(),
        ]);
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('delete_budget', ['budget_id' => $budget->id], 'delete_budget_call'))
                ->push($this->textResponse('Review this exact deletion.')),
        ]);

        $proposal = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Delete my Books budget.',
        ])->assertOk()
            ->assertJsonPath('pending_actions.0.name', 'delete_budget')
            ->assertJsonPath('tool_results.0.receipt.approval_required', true)
            ->assertJsonPath('tool_results.0.receipt.proposed_changes.0.proposed', 'Permanently delete this record');

        $this->assertDatabaseHas('budgets', ['id' => $budget->id, 'user_id' => $user->id]);
        $this->postJson(route('agent.confirm'), [
            'conversation_id' => $proposal->json('conversation_id'),
            'pending_action_id' => $proposal->json('pending_actions.0.id'),
            'confirmed' => true,
        ])->assertOk()
            ->assertJsonPath('message', 'Confirmed and deleted from your account. No payment was made.')
            ->assertJsonPath('saved.deleted', true);

        $this->assertDatabaseMissing('budgets', ['id' => $budget->id, 'user_id' => $user->id]);
        $this->getJson(route('agent.history', $proposal->json('conversation_id')))
            ->assertOk()
            ->assertJsonPath('messages.1.tool_result.0.pending_action.status', 'confirmed')
            ->assertJsonPath('messages.1.tool_result.0.pending_action.outcome.record_type', 'deleted_budget');

        $this->postJson(route('agent.confirm'), [
            'conversation_id' => $proposal->json('conversation_id'),
            'pending_action_id' => $proposal->json('pending_actions.0.id'),
            'confirmed' => true,
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    public function test_stale_approval_is_marked_failed_and_shows_no_change_result(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $budget = $user->budgets()->create([
            'category' => 'Books', 'amount' => '250.00', 'currency' => 'USD',
            'period_start' => today()->startOfMonth(), 'period_end' => today()->endOfMonth(),
        ]);
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('update_budget', [
                    'budget_id' => $budget->id,
                    'amount' => '300.00',
                ], 'update_budget_call'))
                ->push($this->textResponse('Review this exact budget change.')),
        ]);

        $proposal = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Increase the Books budget to 300 USD.',
        ])->assertOk()
            ->assertJsonPath('tool_results.0.receipt.approval_required', true);
        $budget->update(['amount' => '275.00']);

        $this->postJson(route('agent.confirm'), [
            'conversation_id' => $proposal->json('conversation_id'),
            'pending_action_id' => $proposal->json('pending_actions.0.id'),
            'confirmed' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The proposed change is no longer valid; nothing was saved.')
            ->assertJsonPath('refresh', true);

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'user_id' => $user->id,
            'amount' => '275.00',
        ]);
        $this->assertDatabaseHas('agent_pending_actions', [
            'id' => $proposal->json('pending_actions.0.id'),
            'status' => 'failed',
        ]);
        $this->getJson(route('agent.history', $proposal->json('conversation_id')))
            ->assertOk()
            ->assertJsonPath('messages.1.tool_result.0.pending_action.status', 'failed')
            ->assertJsonPath('messages.1.tool_result.0.pending_action.status_message', 'This proposal became invalid or stale. Nothing was saved.')
            ->assertJsonPath('messages.1.tool_result.0.receipt.approval_required', false)
            ->assertJsonPath('messages.1.tool_result.0.receipt.result', 'This proposal became invalid or stale. Nothing was saved; prepare a new proposal if you still want this change.');
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

    public function test_budget_and_saved_plan_writes_are_confirmation_gated_and_single_use(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Budget plan']);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'create_budget', [
            'category' => 'Books',
            'amount' => '250.00',
            'currency' => 'USD',
        ], $conversation);

        $this->assertTrue($prepared['confirmation_required']);
        $this->assertDatabaseCount('budgets', 0);

        $saved = $registry->confirm($user, $conversation, (int) $prepared['pending_action']['id']);

        $this->assertTrue($saved['saved']);
        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category' => 'Books']);
        $this->expectException(InvalidArgumentException::class);
        $registry->confirm($user, $conversation, (int) $prepared['pending_action']['id']);
    }

    public function test_saved_plan_is_not_written_until_the_user_confirms_it(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Planning note']);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'save_plan', [
            'title' => 'Review tuition',
            'summary' => 'Set aside funds monthly and revisit the estimate.',
        ], $conversation);

        $this->assertTrue($prepared['confirmation_required']);
        $this->assertDatabaseCount('financial_plans', 0);

        $registry->confirm($user, $conversation, (int) $prepared['pending_action']['id']);

        $this->assertDatabaseHas('financial_plans', ['user_id' => $user->id, 'title' => 'Review tuition']);
    }

    public function test_user_can_discard_a_pending_action_without_saving_it(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Discard']);
        $registry = app(AgentToolRegistry::class);
        $prepared = $registry->execute($user, 'save_plan', [
            'title' => 'Maybe later',
            'summary' => 'Review this planning note before saving it.',
        ], $conversation);
        $pendingActionId = (int) $prepared['pending_action']['id'];

        $registry->cancel($user, $conversation, $pendingActionId);

        $this->assertDatabaseHas('agent_pending_actions', ['id' => $pendingActionId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('agent_audit_logs', ['user_id' => $user->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('financial_plans', 0);
        try {
            $registry->confirm($user, $conversation, $pendingActionId);
            $this->fail('A cancelled action must not be confirmed.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('agent_pending_actions', ['id' => $pendingActionId, 'status' => 'cancelled']);
            $this->assertDatabaseCount('financial_plans', 0);
        }
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
        $pendingActionId = (int) $prepared['pending_action']['id'];
        $user->agentPendingActions()->whereKey($pendingActionId)->update(['expires_at' => now()->subSecond()]);

        try {
            $registry->confirm($user, $conversation, $pendingActionId);
            $this->fail('An expired action must not be confirmed.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('agent_pending_actions', ['id' => $pendingActionId, 'status' => 'expired']);
            $this->assertDatabaseHas('agent_audit_logs', ['user_id' => $user->id, 'status' => 'expired']);
            $this->assertDatabaseCount('financial_plans', 0);
        }
    }

    /**
     * @return array{choices: list<array{message: array{role: string, content: string, tool_calls?: list<array<string, mixed>>}}>}
     */
    private function textResponse(string $content): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{choices: list<array{message: array{role: string, content: null, tool_calls: list<array<string, mixed>>}}>}
     */
    private function toolResponse(string $name, array $arguments, string $callId): array
    {
        return [
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [$this->toolCall($name, $arguments, $callId)],
                ],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ id: string, type: string, function: array{name: string, arguments: string}}
     */
    private function toolCall(string $name, array $arguments, string $callId): array
    {
        return [
            'id' => $callId,
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Task-context and multi-turn regression tests
    // -------------------------------------------------------------------------

    public function test_confirm_records_last_action_task_context_on_conversation(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('create_budget', [
                    'category' => 'Transport',
                    'amount' => '1000.00',
                    'currency' => 'BDT',
                ], 'call_budget'))
                ->push($this->textResponse('Review this budget before saving.')),
        ]);
        $user = User::factory()->create();

        $chatResponse = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Add a Transport budget of 1000 BDT.',
        ])->assertOk()
            ->assertJsonPath('pending_actions.0.name', 'create_budget');

        $conversationId = $chatResponse->json('conversation_id');
        $pendingActionId = $chatResponse->json('pending_actions.0.id');

        $this->postJson(route('agent.confirm'), [
            'conversation_id' => $conversationId,
            'pending_action_id' => $pendingActionId,
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('saved.saved', true);

        // After confirmation, task_context must record the last verified action.
        $conversation = $user->agentConversations()->findOrFail($conversationId);
        $taskContext = $conversation->task_context;
        $this->assertIsArray($taskContext);
        $this->assertArrayHasKey('last_action', $taskContext);
        $this->assertSame('budget', $taskContext['last_action']['record_type']);
        $this->assertSame('Transport', $taskContext['last_action']['category']);
        $this->assertSame('1000.00', $taskContext['last_action']['amount']);
        $this->assertSame('BDT', $taskContext['last_action']['currency']);
        $this->assertIsInt($taskContext['last_action']['id']);
    }

    public function test_task_context_note_is_injected_into_system_prompt_when_last_action_exists(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response($this->textResponse('I will delete that budget for you.')),
        ]);
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Germany',
            'home_currency' => 'BDT',
            'monthly_budget' => '50000.00',
            'monthly_income' => '0.00',
            'memory_consent' => false,
        ]);
        $budget = $user->budgets()->create([
            'category' => 'Transport',
            'amount' => '1000.00',
            'currency' => 'BDT',
            'period_start' => today()->startOfMonth(),
            'period_end' => today()->endOfMonth(),
        ]);
        $conversation = $user->agentConversations()->create([
            'title' => 'Budget context test',
            'task_context' => [
                'last_action' => [
                    'tool_name' => 'budget',
                    'record_type' => 'budget',
                    'id' => $budget->id,
                    'category' => 'Transport',
                    'amount' => '1000.00',
                    'currency' => 'BDT',
                ],
            ],
        ]);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => 'Delete the thing you just made.',
        ]);

        app(OpenRouterAgent::class)->respond(
            $user,
            $conversation,
            'Delete the thing you just made.',
        );

        // Task-context note must appear in the system message sent to the provider.
        Http::assertSent(function (Request $request) use ($budget): bool {
            $systemContent = $request['messages'][0]['content'] ?? '';

            return str_contains($systemContent, 'VERIFIED TASK CONTEXT')
                && str_contains($systemContent, 'record_type=budget')
                && str_contains($systemContent, 'id='.$budget->id)
                && str_contains($systemContent, 'category=Transport');
        });
    }

    public function test_task_context_is_cleared_of_pending_intent_on_cancel(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('create_budget', [
                    'category' => 'Food',
                    'amount' => '500.00',
                    'currency' => 'USD',
                ], 'call_food'))
                ->push($this->textResponse('Review this food budget.')),
        ]);
        $user = User::factory()->create();

        $chatResponse = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Add a Food budget of 500 USD.',
        ])->assertOk();

        $conversationId = $chatResponse->json('conversation_id');
        $pendingActionId = $chatResponse->json('pending_actions.0.id');

        // Place a pending_intent to simulate an active workflow.
        $conversation = $user->agentConversations()->findOrFail($conversationId);
        $conversation->update([
            'task_context' => [
                'pending_intent' => [
                    'operation' => 'create',
                    'record_type' => 'budget',
                    'supplied_fields' => ['category' => 'Food'],
                    'missing_fields' => [],
                ],
            ],
        ]);

        $this->postJson(route('agent.cancel'), [
            'conversation_id' => $conversationId,
            'pending_action_id' => $pendingActionId,
            'discarded' => true,
        ])->assertOk();

        $conversation->refresh();
        $this->assertNull(($conversation->task_context ?? [])['pending_intent'] ?? null);
        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_task_context_is_scoped_to_authenticated_user_and_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversationA = $userA->agentConversations()->create([
            'title' => 'User A conversation',
            'task_context' => [
                'last_action' => ['record_type' => 'budget', 'id' => 999, 'category' => 'Private A'],
            ],
        ]);
        $conversationB = $userB->agentConversations()->create(['title' => 'User B conversation']);

        // User B cannot access user A's conversation.
        $this->actingAs($userB)->getJson(route('agent.history', $conversationA->id))->assertNotFound();

        // User B cannot confirm an action in user A's conversation.
        $this->actingAs($userB)->postJson(route('agent.confirm'), [
            'conversation_id' => $conversationA->id,
            'pending_action_id' => 1,
            'confirmed' => true,
        ])->assertNotFound();

        // User B's conversation has no context from user A.
        $this->assertNull($conversationB->fresh()->task_context);
    }

    public function test_delete_the_thing_you_just_made_resolves_to_last_verified_budget(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $budget = $user->budgets()->create([
            'category' => 'Transport',
            'amount' => '1000.00',
            'currency' => 'BDT',
            'period_start' => today()->startOfMonth(),
            'period_end' => today()->endOfMonth(),
        ]);

        // Simulate what AgentController stores after a confirmed creation.
        $conversation = $user->agentConversations()->create([
            'title' => 'Delete test',
            'task_context' => [
                'last_action' => [
                    'tool_name' => 'budget',
                    'record_type' => 'budget',
                    'id' => $budget->id,
                    'category' => 'Transport',
                    'amount' => '1000.00',
                    'currency' => 'BDT',
                ],
            ],
        ]);

        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('delete_budget', ['budget_id' => $budget->id], 'call_delete'))
                ->push($this->textResponse('The Transport budget has been deleted.')),
        ]);

        $chatResponse = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Delete the thing you just made.',
            'conversation_id' => $conversation->id,
        ])->assertOk()
            ->assertJsonPath('pending_actions.0.name', 'delete_budget');

        $pendingActionId = $chatResponse->json('pending_actions.0.id');
        $this->assertDatabaseCount('budgets', 1);

        $this->postJson(route('agent.confirm'), [
            'conversation_id' => $conversation->id,
            'pending_action_id' => $pendingActionId,
            'confirmed' => true,
        ])->assertOk()
            ->assertJsonPath('saved.deleted', true)
            ->assertJsonPath('saved.category', 'Transport');

        $this->assertDatabaseCount('budgets', 0);
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);

        // After deletion, task_context records deleted=true on last_action.
        $conversation->refresh();
        $this->assertTrue(($conversation->task_context['last_action']['deleted'] ?? false));
    }

    public function test_task_context_does_not_bleed_between_conversations(): void
    {
        $user = User::factory()->create();
        $budget = $user->budgets()->create([
            'category' => 'Housing',
            'amount' => '800.00',
            'currency' => 'USD',
            'period_start' => today()->startOfMonth(),
            'period_end' => today()->endOfMonth(),
        ]);

        $conversationA = $user->agentConversations()->create([
            'title' => 'Conversation A',
            'task_context' => [
                'last_action' => ['record_type' => 'budget', 'id' => $budget->id, 'category' => 'Housing'],
            ],
        ]);
        $conversationB = $user->agentConversations()->create(['title' => 'Conversation B']);

        $this->assertNotNull($conversationA->task_context);
        $this->assertNull($conversationB->task_context);
    }

    public function test_update_profile_tool_prepares_pending_action_for_destination_country(): void
    {
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1500.00',
            'monthly_income' => '0.00',
            'memory_consent' => false,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Profile update']);
        $registry = app(AgentToolRegistry::class);

        $result = $registry->execute($user, 'update_profile', ['destination_country' => 'South Korea'], $conversation);

        $this->assertTrue($result['confirmation_required']);
        $this->assertSame('update_profile', $result['pending_action']['name']);
        $this->assertSame('South Korea', $result['pending_action']['arguments']['destination_country']);
        $this->assertSame('Canada', $result['pending_action']['before_state']['destination_country']);
        $this->assertDatabaseHas('student_profiles', ['destination_country' => 'Canada']);
    }

    public function test_update_profile_tool_persists_destination_country_after_confirm(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::sequence()
                ->push($this->toolResponse('update_profile', ['destination_country' => 'South Korea'], 'call_profile'))
                ->push($this->textResponse('Your study destination has been updated to South Korea.')),
        ]);
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1500.00',
            'monthly_income' => '0.00',
            'memory_consent' => false,
        ]);

        $chatResponse = $this->actingAs($user)->postJson(route('agent.chat'), [
            'message' => 'Change my study destination to South Korea.',
        ])->assertOk()
            ->assertJsonPath('pending_actions.0.name', 'update_profile');

        $this->assertDatabaseHas('student_profiles', ['destination_country' => 'Canada']);

        $this->postJson(route('agent.confirm'), [
            'conversation_id' => $chatResponse->json('conversation_id'),
            'pending_action_id' => $chatResponse->json('pending_actions.0.id'),
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('saved.saved', true);

        $this->assertDatabaseHas('student_profiles', ['destination_country' => 'South Korea']);
    }

    public function test_update_profile_tool_rejects_unexpected_fields(): void
    {
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1500.00',
            'monthly_income' => '0.00',
            'memory_consent' => false,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Security check']);
        $registry = app(AgentToolRegistry::class);

        // home_currency is not in the allowlist; must be rejected.
        $this->expectException(InvalidArgumentException::class);
        $registry->execute($user, 'update_profile', ['home_currency' => 'EUR'], $conversation);
    }

    public function test_update_profile_tool_requires_at_least_one_allowed_field(): void
    {
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1500.00',
            'monthly_income' => '0.00',
            'memory_consent' => false,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Empty update']);
        $registry = app(AgentToolRegistry::class);

        $this->expectException(ValidationException::class);
        $registry->execute($user, 'update_profile', [], $conversation);
    }

    public function test_task_context_note_absent_for_fresh_conversation(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response($this->textResponse('Hello! How can I help?')),
        ]);
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Fresh start']);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => 'Hello there.',
        ]);

        app(OpenRouterAgent::class)->respond($user, $conversation, 'Hello there.');

        Http::assertSent(function (Request $request): bool {
            return ! str_contains($request['messages'][0]['content'] ?? '', 'VERIFIED TASK CONTEXT');
        });
    }

    public function test_history_consent_off_does_not_send_prior_user_messages_to_provider(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response($this->textResponse('Your balance is unknown without records.')),
        ]);
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'France',
            'home_currency' => 'EUR',
            'monthly_budget' => '1200.00',
            'monthly_income' => '0.00',
            'memory_consent' => false,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Privacy test']);
        foreach ([['user', 'My salary is 5000 EUR.'], ['assistant', 'Noted.']] as [$role, $content]) {
            $conversation->messages()->create(['user_id' => $user->id, 'role' => $role, 'content' => $content]);
        }

        // Save the current user message first — exactly as AgentController does.
        $conversation->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'What is my balance?']);

        app(OpenRouterAgent::class)->respond($user, $conversation, 'What is my balance?');

        Http::assertSent(function (Request $request): bool {
            foreach ($request['messages'] ?? [] as $message) {
                if (($message['role'] ?? '') === 'user'
                    && str_contains($message['content'] ?? '', '5000 EUR')) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_history_consent_on_sends_recent_conversation_history_to_provider(): void
    {
        Config::set('services.openrouter.key', 'test-key');
        Config::set('services.openrouter.model', 'openai/gpt-4o-mini');
        Http::preventStrayRequests();
        Http::fake([
            self::OPENROUTER_ENDPOINT => Http::response($this->textResponse('Here is your summary.')),
        ]);
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'UK',
            'home_currency' => 'GBP',
            'monthly_budget' => '1000.00',
            'monthly_income' => '0.00',
            'memory_consent' => true,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Memory test']);
        foreach ([['user', 'I need a budget overview.'], ['assistant', 'Sure, let me look.']] as [$role, $content]) {
            $conversation->messages()->create(['user_id' => $user->id, 'role' => $role, 'content' => $content]);
        }

        app(OpenRouterAgent::class)->respond($user, $conversation, 'Show summary.');

        Http::assertSent(function (Request $request): bool {
            $roles = array_column($request['messages'] ?? [], 'role');

            return in_array('assistant', $roles, true) && count($request['messages']) >= 3;
        });
    }
}

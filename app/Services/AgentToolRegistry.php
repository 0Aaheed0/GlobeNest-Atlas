<?php

namespace App\Services;

use App\Models\AgentAuditLog;
use App\Models\AgentConversation;
use App\Models\AgentPendingAction;
use App\Models\Budget;
use App\Models\SavingsGoal;
use App\Models\TuitionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgentToolRegistry
{
    public function __construct(
        private CurrencyCalculator $calculator,
        private FinancialPlanner $planner,
        private ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function declarations(): array
    {
        return [
            $this->declaration('get_financial_summary', 'Read the signed-in user’s own financial profile and high-level totals.', []),
            $this->declaration('get_funding_opportunities', 'List this user’s own user-entered scholarship and funding opportunities. This tool does not search the web or verify eligibility.', []),
            $this->declaration('convert_currency', 'Calculate a deterministic conversion using the clearly labeled Atlas demonstration rate table.', [
                'amount' => ['type' => 'string', 'description' => 'Positive amount, at most two decimal places.'],
                'from_currency' => $this->currencyProperty(),
                'to_currency' => $this->currencyProperty(),
            ], ['amount', 'from_currency', 'to_currency']),
            $this->declaration('analyze_spending', 'Summarize this user’s recorded expenses for a recent period.', [
                'period_days' => ['type' => 'integer', 'description' => 'Number of recent days to analyze, between 1 and 365.'],
            ], ['period_days']),
            $this->declaration('plan_tuition', 'Calculate required weekly and monthly savings for one of this user’s tuition plans.', [
                'tuition_plan_id' => ['type' => 'integer', 'description' => 'ID of a tuition plan belonging to the signed-in user.'],
            ], ['tuition_plan_id']),
            $this->declaration('forecast_scenarios', 'Compare deterministic tuition or savings-goal funding forecasts using the signed-in user’s saved budget, recorded expenses, and owned tuition plan. Alternatives are calculated independently. If baseline or requested-change inputs are missing, ask the returned questions instead of filling values. Optional changes are hypothetical; scholarship amounts are unconfirmed.', [
                'tuition_plan_id' => ['type' => 'integer', 'description' => 'Optional ID of an upcoming tuition plan belonging to the signed-in user; provide this or savings_goal_id.'],
                'savings_goal_id' => ['type' => 'integer', 'description' => 'Optional ID of a savings goal belonging to the signed-in user; provide this or tuition_plan_id. The goal needs a target date.'],
                'monthly_rent' => ['type' => 'string', 'description' => 'Optional monthly rent in the profile home currency; provide it if a rent change is being evaluated and no recorded Rent or Housing category exists.'],
                'rent_change_percent' => ['type' => 'integer', 'description' => 'Optional hypothetical rent change from -50 to 100 percent.'],
                'income_delay_months' => ['type' => 'integer', 'description' => 'Optional hypothetical income delay from 0 to 12 months.'],
                'scholarship_amount' => ['type' => 'string', 'description' => 'Optional hypothetical scholarship amount in the profile home currency; never treated as confirmed.'],
                'scholarship_month' => ['type' => 'integer', 'description' => 'Optional month 1 to 24 when a hypothetical scholarship may arrive; provide its amount too.'],
                'forecast_months' => ['type' => 'integer', 'description' => 'Optional forecast horizon from 1 to 24 months; defaults to the tuition deadline horizon.'],
                'rate_stress_percent' => ['type' => 'integer', 'description' => 'Optional hypothetical foreign-exchange stress from 0 to 50 percent.'],
            ]),
            $this->declaration('create_tuition_plan', 'Use only after the user supplied a title, total amount, currency, and due date. Prepare a deterministic savings schedule and exact proposal for review; the plan is not saved until the user explicitly confirms it.', [
                'title' => ['type' => 'string', 'description' => 'Short tuition plan name, max 120 characters.'],
                'amount' => ['type' => 'string', 'description' => 'Positive total tuition amount, at most two decimals.'],
                'currency' => $this->currencyProperty(),
                'due_date' => ['type' => 'string', 'description' => 'Due date in YYYY-MM-DD format; must be today or later.'],
                'saved_amount' => ['type' => 'string', 'description' => 'Optional amount already saved, defaults to 0.00.'],
                'installments' => ['type' => 'integer', 'description' => 'Optional installment count from 1 to 24, defaults to 1.'],
            ], ['title', 'amount', 'currency', 'due_date']),
            $this->declaration('update_savings_goal', 'Prepare a change to an existing savings target. The current currency is retained and the user must confirm the exact change.', [
                'goal_id' => ['type' => 'integer', 'description' => 'ID of a savings goal belonging to the signed-in user.'],
                'target_amount' => ['type' => 'string', 'description' => 'Optional new positive target amount, in the goal’s existing currency, at most two decimals.'],
                'current_amount' => ['type' => 'string', 'description' => 'Optional corrected saved progress amount, in the goal’s existing currency, at most two decimals.'],
            ], ['goal_id']),
            $this->declaration('update_tuition_plan', 'Prepare changes to an existing tuition plan. Its current currency is retained and the user must confirm the exact changes.', [
                'tuition_plan_id' => ['type' => 'integer', 'description' => 'ID of a tuition plan belonging to the signed-in user.'],
                'amount' => ['type' => 'string', 'description' => 'Optional new total tuition amount, at most two decimals.'],
                'saved_amount' => ['type' => 'string', 'description' => 'Optional new recorded saved amount, at most two decimals.'],
                'due_date' => ['type' => 'string', 'description' => 'Optional new due date in YYYY-MM-DD format.'],
                'installments' => ['type' => 'integer', 'description' => 'Optional new installment count from 1 to 24.'],
            ], ['tuition_plan_id']),
            $this->declaration('update_budget', 'Prepare changes to a monthly category budget. Its current currency and period are retained unless a new supported currency is explicitly proposed; the user must confirm the exact changes.', [
                'budget_id' => ['type' => 'integer', 'description' => 'ID of a monthly budget belonging to the signed-in user.'],
                'category' => ['type' => 'string', 'description' => 'Optional updated category name, max 60 characters.'],
                'amount' => ['type' => 'string', 'description' => 'Optional updated positive monthly limit, at most two decimals.'],
                'currency' => $this->currencyProperty(),
            ], ['budget_id']),
            $this->declaration('delete_tuition_plan', 'Prepare permanent deletion of one owned tuition plan. Show the exact record and require explicit confirmation before deleting.', [
                'tuition_plan_id' => ['type' => 'integer', 'description' => 'ID of the tuition plan to delete, belonging to the signed-in user.'],
            ], ['tuition_plan_id']),
            $this->declaration('delete_savings_goal', 'Prepare permanent deletion of one owned savings goal. Show the exact record and require explicit confirmation before deleting.', [
                'goal_id' => ['type' => 'integer', 'description' => 'ID of the savings goal to delete, belonging to the signed-in user.'],
            ], ['goal_id']),
            $this->declaration('delete_budget', 'Prepare permanent deletion of one owned monthly budget. Show the exact record and require explicit confirmation before deleting.', [
                'budget_id' => ['type' => 'integer', 'description' => 'ID of the monthly budget to delete, belonging to the signed-in user.'],
            ], ['budget_id']),
            $this->declaration('compare_remittance', 'Compare three illustrative fee models; these are not provider quotes.', [
                'amount' => ['type' => 'string', 'description' => 'Positive amount, at most two decimal places.'],
                'from_currency' => $this->currencyProperty(),
                'to_currency' => $this->currencyProperty(),
            ], ['amount', 'from_currency', 'to_currency']),
            $this->declaration('create_savings_goal', 'Prepare a savings goal for user review. It is not saved until explicitly confirmed.', [
                'title' => ['type' => 'string', 'description' => 'Short goal name, max 120 characters.'],
                'target_amount' => ['type' => 'string', 'description' => 'Positive target amount, at most two decimals.'],
                'current_amount' => ['type' => 'string', 'description' => 'Optional amount already saved, at most two decimals.'],
                'currency' => $this->currencyProperty(),
                'target_date' => ['type' => 'string', 'description' => 'Optional target date in YYYY-MM-DD format.'],
            ], ['title', 'target_amount', 'currency']),
            $this->declaration('create_budget', 'Prepare a monthly category budget for user review. It is not saved until explicitly confirmed.', [
                'category' => ['type' => 'string', 'description' => 'Budget category, max 60 characters.'],
                'amount' => ['type' => 'string', 'description' => 'Positive budget amount, at most two decimals.'],
                'currency' => $this->currencyProperty(),
            ], ['category', 'amount', 'currency']),
            $this->declaration('save_plan', 'Prepare a financial planning note for user review. It is not saved until explicitly confirmed.', [
                'title' => ['type' => 'string', 'description' => 'Plan title, max 120 characters.'],
                'summary' => ['type' => 'string', 'description' => 'Short plan with assumptions, max 500 characters.'],
            ], ['title', 'summary']),
            $this->declaration('update_profile', 'Prepare a change to one or more allowlisted student profile fields. Only destination_country and financial_goals may be changed through the agent. Home currency, password, email, MFA, and security settings cannot be changed through this tool. The user must confirm the exact before-and-after values.', [
                'destination_country' => ['type' => 'string', 'description' => 'Optional updated study destination country, max 80 characters.'],
                'financial_goals' => ['type' => 'string', 'description' => 'Optional updated financial goals text, max 1000 characters.'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(User $user, string $name, array $arguments, AgentConversation $conversation): array
    {
        $this->assertConversationOwner($user, $conversation);

        try {
            $data = $this->validateArguments($name, $arguments);
            $result = match ($name) {
                'get_financial_summary' => $this->financialSummary($user),
                'get_funding_opportunities' => $this->fundingOpportunities($user),
                'convert_currency' => $this->calculator->convert($data['amount'], $data['from_currency'], $data['to_currency']),
                'analyze_spending' => $this->analyzeSpending($user, $data['period_days']),
                'plan_tuition' => $this->planTuition($user, $data['tuition_plan_id']),
                'forecast_scenarios' => $this->forecastScenarios($user, $data),
                'create_tuition_plan' => $this->prepareTuitionPlan($user, $conversation, $data),
                'update_savings_goal' => $this->prepareSavingsGoalUpdate($user, $conversation, $data),
                'update_tuition_plan' => $this->prepareTuitionPlanUpdate($user, $conversation, $data),
                'update_budget' => $this->prepareBudgetUpdate($user, $conversation, $data),
                'delete_tuition_plan' => $this->prepareTuitionPlanDeletion($user, $conversation, $data),
                'delete_savings_goal' => $this->prepareSavingsGoalDeletion($user, $conversation, $data),
                'delete_budget' => $this->prepareBudgetDeletion($user, $conversation, $data),
                'compare_remittance' => [
                    'quotes' => $this->calculator->compareRemittance($data['amount'], $data['from_currency'], $data['to_currency']),
                    'reference' => $this->calculator->convert($data['amount'], $data['from_currency'], $data['to_currency']),
                    'source' => 'Simulated fee profiles, ranked by estimated recipient amount after fees. Not provider offers, live rates, or delivery guarantees.',
                ],
                'create_savings_goal', 'create_budget', 'save_plan' => $this->pendingAction($user, $conversation, $name, $data),
                'update_profile' => $this->prepareProfileUpdate($user, $conversation, $data),
                default => throw new \InvalidArgumentException('This tool is not available.'),
            };

            $this->audit($user, $conversation, $name, isset($result['pending_action']) ? 'awaiting_confirmation' : 'completed');

            return $result;
        } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException $exception) {
            $this->audit($user, $conversation, $name, 'rejected');
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function confirm(User $user, AgentConversation $conversation, int $pendingActionId): array
    {
        $this->assertConversationOwner($user, $conversation);

        try {
            $result = DB::transaction(function () use ($user, $conversation, $pendingActionId): array {
                $pending = $user->agentPendingActions()
                    ->where('conversation_id', $conversation->id)
                    ->lockForUpdate()
                    ->findOrFail($pendingActionId);

                if ($pending->status !== 'pending') {
                    throw new \InvalidArgumentException('This confirmation has expired or has already been used.');
                }

                if ($pending->expires_at->isPast()) {
                    $pending->update(['status' => 'expired']);
                    $this->audit($user, $conversation, $pending->tool_name, 'expired');
                    $this->recordPendingActionOutcome($conversation, $pending->id, 'expired', null);

                    return ['_confirmation_expired' => true];
                }

                $name = $pending->tool_name;
                $data = $this->validateArguments($name, $pending->arguments);

                try {
                    $saved = match ($name) {
                        'create_savings_goal' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->saveGoal($user, $data)),
                        'create_budget' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->saveBudget($user, $data)),
                        'create_tuition_plan' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->saveTuitionPlan($user, $data)),
                        'save_plan' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->savePlan($user, $data)),
                        'update_savings_goal' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->updateSavingsGoal($user, $data, $pending->before_state ?? [])),
                        'update_tuition_plan' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->updateTuitionPlan($user, $data, $pending->before_state ?? [])),
                        'update_budget' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->updateBudget($user, $data, $pending->before_state ?? [])),
                        'delete_tuition_plan' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->deleteTuitionPlan($user, $data, $pending->before_state ?? [])),
                        'delete_savings_goal' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->deleteSavingsGoal($user, $data, $pending->before_state ?? [])),
                        'delete_budget' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->deleteBudget($user, $data, $pending->before_state ?? [])),
                        'update_profile' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->saveProfileUpdate($user, $data, $pending->before_state ?? [])),
                        default => throw new \InvalidArgumentException('This action does not support confirmation.'),
                    };
                } catch (ModelNotFoundException $exception) {
                    throw new \InvalidArgumentException('The target record is no longer available. Prepare a new action for review.', 0, $exception);
                }

                $this->recordPendingActionOutcome($conversation, $pending->id, 'confirmed', $saved);

                return $saved;
            });
        } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException $exception) {
            $this->markPendingActionFailed($user, $conversation, $pendingActionId);

            throw $exception;
        }

        if (isset($result['_confirmation_expired'])) {
            throw new \InvalidArgumentException('This confirmation has expired or has already been used.');
        }

        return $result;
    }

    public function cancel(User $user, AgentConversation $conversation, int $pendingActionId): void
    {
        $this->assertConversationOwner($user, $conversation);

        $expired = DB::transaction(function () use ($user, $conversation, $pendingActionId): bool {
            $pending = $user->agentPendingActions()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->findOrFail($pendingActionId);

            if ($pending->status !== 'pending') {
                throw new \InvalidArgumentException('This pending action has expired or has already been handled.');
            }

            if ($pending->expires_at->isPast()) {
                $pending->update(['status' => 'expired']);
                $this->audit($user, $conversation, $pending->tool_name, 'expired');
                $this->recordPendingActionOutcome($conversation, $pending->id, 'expired', null);

                return true;
            }

            $pending->update(['status' => 'cancelled']);
            $this->audit($user, $conversation, $pending->tool_name, 'cancelled');
            $this->recordPendingActionOutcome($conversation, $pending->id, 'cancelled', null);

            return false;
        });

        if ($expired) {
            throw new \InvalidArgumentException('This pending action has expired or has already been handled.');
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    private function declaration(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'parameters' => [
                'type' => 'object',
                'properties' => (object) $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array{type: string, description: string, enum: list<string>}
     */
    private function currencyProperty(): array
    {
        return [
            'type' => 'string',
            'description' => 'Three-letter currency code from the supported list.',
            'enum' => $this->exchangeRates->currencies(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function validateArguments(string $name, array $arguments): array
    {
        $rules = match ($name) {
            'get_financial_summary', 'get_funding_opportunities' => [],
            'convert_currency', 'compare_remittance' => [
                'amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'from_currency' => ['required', 'string', Rule::in($this->exchangeRates->currencies())],
                'to_currency' => ['required', 'string', Rule::in($this->exchangeRates->currencies())],
            ],
            'analyze_spending' => ['period_days' => ['required', 'integer', 'min:1', 'max:365']],
            'plan_tuition' => ['tuition_plan_id' => ['required', 'integer', 'min:1']],
            'forecast_scenarios' => [
                'tuition_plan_id' => ['sometimes', 'integer', 'min:1'],
                'savings_goal_id' => ['sometimes', 'integer', 'min:1'],
                'monthly_rent' => ['sometimes', 'string', 'numeric', 'gte:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'rent_change_percent' => ['sometimes', 'integer', 'min:-50', 'max:100'],
                'income_delay_months' => ['sometimes', 'integer', 'min:0', 'max:12'],
                'scholarship_amount' => ['sometimes', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'scholarship_month' => ['sometimes', 'integer', 'min:1', 'max:24'],
                'forecast_months' => ['sometimes', 'integer', 'min:1', 'max:24'],
                'rate_stress_percent' => ['sometimes', 'integer', 'min:0', 'max:50'],
            ],
            'create_tuition_plan' => [
                'title' => ['required', 'string', 'max:120'],
                'amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'currency' => ['required', 'string', Rule::in($this->exchangeRates->currencies())],
                'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
                'saved_amount' => ['sometimes', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'installments' => ['sometimes', 'integer', 'min:1', 'max:24'],
            ],
            'update_savings_goal' => [
                'goal_id' => ['required', 'integer', 'min:1'],
                'target_amount' => ['sometimes', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'current_amount' => ['sometimes', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            ],
            'update_tuition_plan' => [
                'tuition_plan_id' => ['required', 'integer', 'min:1'],
                'amount' => ['sometimes', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'saved_amount' => ['sometimes', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'due_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:today'],
                'installments' => ['sometimes', 'integer', 'min:1', 'max:24'],
            ],
            'update_budget' => [
                'budget_id' => ['required', 'integer', 'min:1'],
                'category' => ['sometimes', 'string', 'min:1', 'max:60'],
                'amount' => ['sometimes', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'currency' => ['sometimes', 'string', Rule::in($this->exchangeRates->currencies())],
            ],
            'delete_tuition_plan' => ['tuition_plan_id' => ['required', 'integer', 'min:1']],
            'delete_savings_goal' => ['goal_id' => ['required', 'integer', 'min:1']],
            'delete_budget' => ['budget_id' => ['required', 'integer', 'min:1']],
            'create_savings_goal' => [
                'title' => ['required', 'string', 'max:120'],
                'target_amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'current_amount' => ['nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'currency' => ['required', 'string', Rule::in($this->exchangeRates->currencies())],
                'target_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            ],
            'create_budget' => [
                'category' => ['required', 'string', 'max:60'],
                'amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'currency' => ['required', 'string', Rule::in($this->exchangeRates->currencies())],
            ],
            'save_plan' => [
                'title' => ['required', 'string', 'max:120'],
                'summary' => ['required', 'string', 'max:500'],
            ],
            'update_profile' => [
                'destination_country' => ['sometimes', 'string', 'min:1', 'max:80'],
                'financial_goals' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ],
            default => throw new \InvalidArgumentException('This tool is not available.'),
        };

        if (array_diff(array_keys($arguments), array_keys($rules)) !== []) {
            throw new \InvalidArgumentException('Unexpected tool arguments were rejected.');
        }

        $data = Validator::make($arguments, $rules)->validate();

        if ($name === 'create_tuition_plan') {
            $data['amount'] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data['amount']));
            $data['saved_amount'] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data['saved_amount'] ?? '0.00'));
            $data['installments'] = (int) ($data['installments'] ?? 1);

            if (CurrencyCalculator::toMinorUnits($data['saved_amount']) > CurrencyCalculator::toMinorUnits($data['amount'])) {
                throw ValidationException::withMessages(['saved_amount' => 'Saved progress cannot exceed the tuition amount.']);
            }
        }

        if ($name === 'forecast_scenarios') {
            if (isset($data['tuition_plan_id'], $data['savings_goal_id'])) {
                throw ValidationException::withMessages(['tuition_plan_id' => 'Choose one tuition plan or savings goal to forecast.']);
            }

            foreach (['rent_change_percent', 'income_delay_months', 'scholarship_month', 'forecast_months', 'rate_stress_percent'] as $field) {
                if (isset($data[$field])) {
                    $data[$field] = (int) $data[$field];
                }
            }

            foreach (['monthly_rent', 'scholarship_amount'] as $field) {
                if (isset($data[$field])) {
                    $data[$field] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data[$field]));
                }
            }
        }

        if ($name === 'create_savings_goal') {
            $data['current_amount'] = $data['current_amount'] ?? '0.00';

            if (CurrencyCalculator::toMinorUnits($data['current_amount']) > CurrencyCalculator::toMinorUnits($data['target_amount'])) {
                throw ValidationException::withMessages(['current_amount' => 'Saved progress cannot exceed the goal amount.']);
            }
        }

        if ($name === 'update_savings_goal') {
            if (array_intersect(array_keys($data), ['target_amount', 'current_amount']) === []) {
                throw ValidationException::withMessages(['goal_id' => 'Provide a new target amount or saved progress amount.']);
            }

            foreach (['target_amount', 'current_amount'] as $field) {
                if (isset($data[$field])) {
                    $data[$field] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data[$field]));
                }
            }
        }

        if ($name === 'update_budget') {
            if (array_intersect(array_keys($data), ['category', 'amount', 'currency']) === []) {
                throw ValidationException::withMessages(['budget_id' => 'Provide at least one budget change.']);
            }

            if (isset($data['amount'])) {
                $data['amount'] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data['amount']));
            }
        }

        if ($name === 'update_tuition_plan') {
            foreach (['amount', 'saved_amount'] as $field) {
                if (isset($data[$field])) {
                    $data[$field] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data[$field]));
                }
            }
        }

        if ($name === 'update_tuition_plan'
            && array_intersect(array_keys($data), ['amount', 'saved_amount', 'due_date', 'installments']) === []) {
            throw ValidationException::withMessages(['tuition_plan_id' => 'Provide at least one tuition plan change.']);
        }

        if ($name === 'update_profile'
            && array_intersect(array_keys($data), ['destination_country', 'financial_goals']) === []) {
            throw ValidationException::withMessages(['destination_country' => 'Provide at least one profile field to update.']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function financialSummary(User $user): array
    {
        $profile = $user->studentProfile()->first();
        $upcomingTuition = $user->tuitionPlans()->whereDate('due_date', '>=', today())->orderBy('due_date')->limit(5)->get([
            'id', 'title', 'amount', 'saved_amount', 'currency', 'due_date', 'installments',
        ]);
        $goals = $user->savingsGoals()->latest()->limit(5)->get(['id', 'title', 'target_amount', 'current_amount', 'currency', 'target_date']);
        $budgets = $user->budgets()
            ->whereDate('period_start', '<=', today())
            ->whereDate('period_end', '>=', today())
            ->orderBy('category')
            ->limit(12)
            ->get(['id', 'category', 'amount', 'currency', 'period_start', 'period_end']);

        return [
            'profile' => $profile === null ? null : [
                'destination_country' => $profile->destination_country,
                'home_currency' => $profile->home_currency,
                'monthly_budget' => $profile->monthly_budget,
            ],
            'recorded_activity_count' => $user->transactions()->count(),
            'upcoming_tuition' => $upcomingTuition,
            'savings_goals' => $goals->map(fn ($goal): array => [
                'id' => $goal->id,
                'title' => $goal->title,
                'target_amount' => $goal->target_amount,
                'current_amount' => $goal->current_amount,
                'currency' => $goal->currency,
                'target_date' => $goal->target_date?->toDateString(),
            ]),
            'monthly_budgets' => $budgets->map(fn ($budget): array => [
                'id' => $budget->id,
                'category' => $budget->category,
                'amount' => $budget->amount,
                'currency' => $budget->currency,
                'period_start' => $budget->period_start->toDateString(),
                'period_end' => $budget->period_end->toDateString(),
            ]),
            'note' => 'Only the signed-in user’s records are included. This is planning information, not financial advice.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fundingOpportunities(User $user): array
    {
        return [
            'opportunities' => $user->fundingOpportunities()
                ->orderByRaw('deadline IS NULL')
                ->orderBy('deadline')
                ->limit(10)
                ->get(['title', 'provider', 'potential_amount', 'currency', 'deadline', 'status', 'source_url'])
                ->map(fn ($opportunity): array => [
                    'title' => $opportunity->title,
                    'provider' => $opportunity->provider,
                    'potential_amount' => $opportunity->potential_amount,
                    'currency' => $opportunity->currency,
                    'deadline' => $opportunity->deadline?->toDateString(),
                    'status' => $opportunity->status,
                    'source_url' => $opportunity->source_url,
                    'user_entered' => true,
                ])
                ->all(),
            'note' => 'These are user-entered records only. Atlas did not search, verify eligibility, confirm deadlines, or guarantee awards.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeSpending(User $user, int $periodDays): array
    {
        $transactions = $user->transactions()
            ->where('kind', 'expense')
            ->whereDate('occurred_on', '>=', today()->subDays($periodDays - 1))
            ->get(['category', 'amount', 'currency']);
        $totals = [];

        foreach ($transactions as $transaction) {
            $key = $transaction->currency.'|'.$transaction->category;
            $totals[$key] = ($totals[$key] ?? 0) + CurrencyCalculator::toMinorUnits((string) $transaction->amount);
        }

        $summary = [];

        foreach ($totals as $key => $minor) {
            [$currency, $category] = explode('|', $key, 2);
            $summary[] = [
                'category' => $category,
                'currency' => $currency,
                'amount' => CurrencyCalculator::formatMinorUnits($minor),
            ];
        }

        return [
            'period_days' => $periodDays,
            'expense_entries' => $transactions->count(),
            'totals_by_category_and_currency' => $summary,
            'note' => 'Values in different currencies are kept separate and are not converted into a false total.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planTuition(User $user, int $tuitionPlanId): array
    {
        $plan = $user->tuitionPlans()->whereKey($tuitionPlanId)->firstOrFail();

        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'amount' => $plan->amount,
            'saved_amount' => $plan->saved_amount,
            'currency' => $plan->currency,
            'due_date' => $plan->due_date->toDateString(),
            'installment_count' => $plan->installments,
            'schedule' => $this->planner->tuitionSchedule((string) $plan->amount, (string) $plan->saved_amount, $plan->due_date->toDateString()),
            'installments' => $this->planner->installmentSchedule((string) $plan->amount, (string) $plan->saved_amount, $plan->due_date->toDateString(), $plan->installments),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function forecastScenarios(User $user, array $data): array
    {
        if (! isset($data['tuition_plan_id']) && ! isset($data['savings_goal_id'])) {
            return [
                'status' => 'needs_clarification',
                'questions' => ['Which saved tuition plan or savings goal should Atlas forecast? Provide its name or choose one from your saved plans.'],
                'assumptions' => ['No forecast was calculated because a signed-in-user-owned target was not selected.'],
            ];
        }

        if (isset($data['tuition_plan_id'])) {
            $tuitionPlan = $user->tuitionPlans()->whereKey($data['tuition_plan_id'])->firstOrFail();
            $plan = [
                'id' => $tuitionPlan->id,
                'type' => 'tuition plan',
                'title' => $tuitionPlan->title,
                'amount' => (string) $tuitionPlan->amount,
                'saved_amount' => (string) $tuitionPlan->saved_amount,
                'currency' => $tuitionPlan->currency,
                'due_date' => $tuitionPlan->due_date->toDateString(),
            ];
        } else {
            $savingsGoal = $user->savingsGoals()->whereKey($data['savings_goal_id'])->firstOrFail();

            if ($savingsGoal->target_date === null) {
                return [
                    'status' => 'needs_clarification',
                    'goal' => ['id' => $savingsGoal->id, 'title' => $savingsGoal->title],
                    'questions' => ['What target date should be used for this savings goal? Add a target date before forecasting its required monthly contribution.'],
                    'assumptions' => ['No forecast was calculated because the saved goal has no target date.'],
                ];
            }

            $plan = [
                'id' => $savingsGoal->id,
                'type' => 'savings goal',
                'title' => $savingsGoal->title,
                'amount' => (string) $savingsGoal->target_amount,
                'saved_amount' => (string) $savingsGoal->current_amount,
                'currency' => $savingsGoal->currency,
                'due_date' => $savingsGoal->target_date->toDateString(),
            ];
        }

        $profile = $user->studentProfile()->first();
        $schedule = $this->planner->tuitionSchedule($plan['amount'], $plan['saved_amount'], $plan['due_date']);
        $questions = [];

        if ($profile === null || $profile->home_currency === null || $profile->monthly_budget === null) {
            $questions[] = 'Set a home currency and monthly budget in your financial profile before running an affordability forecast.';
        }

        if ($schedule['due_in_days'] <= 0) {
            $questions[] = 'This tuition deadline is today or has passed. Provide a future deadline before forecasting a savings pace.';
        }

        if (array_key_exists('scholarship_amount', $data) !== array_key_exists('scholarship_month', $data)) {
            $questions[] = array_key_exists('scholarship_amount', $data)
                ? 'When might this unconfirmed scholarship arrive? Provide a month from 1 to 24, or omit the scholarship.'
                : 'What hypothetical scholarship amount should be tested? Provide the amount in your home currency, or omit the scholarship.';
        }

        if (($data['income_delay_months'] ?? 0) > 0 && $profile?->monthly_income === null) {
            $questions[] = 'Add expected monthly income to your profile before testing an income delay.';
        }

        if ($questions !== []) {
            return [
                'status' => 'needs_clarification',
                'plan' => $plan,
                'questions' => $questions,
                'assumptions' => ['No forecast was calculated because required information is missing or the deadline is not in the future.'],
            ];
        }

        $currency = $profile->home_currency;
        $expenseBaseline = $this->currentMonthExpenseBaseline($user, $currency);
        $questions = [];

        if ($expenseBaseline['count'] === 0) {
            $questions[] = "Add current-month expense records in {$currency} before using them as an expected-spending baseline.";
        }

        if (($data['rent_change_percent'] ?? 0) !== 0 && ! isset($data['monthly_rent']) && $expenseBaseline['rent'] === null) {
            $questions[] = "What is your monthly rent in {$currency}? No current-month Rent or Housing category was recorded.";
        }

        if ($questions !== []) {
            return [
                'status' => 'needs_clarification',
                'plan' => $plan,
                'questions' => $questions,
                'assumptions' => ['No forecast was calculated without an expense baseline and any rent amount required for the requested scenario.'],
            ];
        }

        $rent = $data['monthly_rent'] ?? $expenseBaseline['rent'] ?? '0.00';
        $tuitionTarget = $this->calculator->convert($plan['amount'], $plan['currency'], $currency);
        $savedTuition = $this->calculator->convert($plan['saved_amount'], $plan['currency'], $currency);
        $tuitionPace = $this->calculator->convert($schedule['required_monthly'], $plan['currency'], $currency);
        $forecastMonths = $data['forecast_months'] ?? min(24, max(1, (int) ceil($schedule['due_in_days'] / 30)));
        $scholarshipAmount = $data['scholarship_amount'] ?? '0.00';
        $scholarshipMonth = $data['scholarship_month'] ?? 0;
        $rentChangePercent = $data['rent_change_percent'] ?? 0;
        $incomeDelayMonths = $data['income_delay_months'] ?? 0;
        $rateStressPercent = $data['rate_stress_percent'] ?? 0;
        $runForecast = fn (int $rentChange = 0, int $incomeDelay = 0, string $scholarship = '0.00', int $scholarshipTiming = 0, int $rateStress = 0): array => $this->planner->forecastScenarios(
            (string) $profile->monthly_budget,
            $expenseBaseline['amount'],
            $rent,
            (string) ($profile->monthly_income ?? '0.00'),
            $tuitionPace['converted_amount'],
            $tuitionTarget['converted_amount'],
            $savedTuition['converted_amount'],
            $scholarship,
            $rentChange,
            $incomeDelay,
            $scholarshipTiming,
            $forecastMonths,
            $rateStress,
        );
        $baselineForecast = $runForecast()[1];
        $comparisons = [];

        if ($rentChangePercent !== 0) {
            $comparisons[] = [
                'name' => 'Rent change',
                'scenarios' => [$runForecast($rentChangePercent)[1]],
            ];
        }

        if ($incomeDelayMonths > 0) {
            $comparisons[] = [
                'name' => 'Delayed income',
                'scenarios' => [$runForecast(0, $incomeDelayMonths)[1]],
            ];
        }

        if ($scholarshipMonth > 0) {
            $comparisons[] = [
                'name' => 'Unconfirmed scholarship',
                'scenarios' => [$runForecast(0, 0, $scholarshipAmount, $scholarshipMonth)[1]],
            ];
        }

        if ($rateStressPercent > 0) {
            $comparisons[] = [
                'name' => 'Foreign-exchange stress',
                'scenarios' => $runForecast(0, 0, '0.00', 0, $rateStressPercent),
            ];
        }

        return [
            'status' => 'complete',
            'plan' => [
                ...$plan,
                'schedule' => $schedule,
            ],
            'baseline_inputs' => [
                'monthly_budget' => (string) $profile->monthly_budget,
                'monthly_spending' => $expenseBaseline['amount'],
                'monthly_rent' => $rent,
                'monthly_income' => $profile->monthly_income === null ? null : (string) $profile->monthly_income,
                'currency' => $currency,
                'expense_entries' => $expenseBaseline['count'],
                'rent_source' => isset($data['monthly_rent']) ? 'explicit hypothetical input; not independently verified' : ($expenseBaseline['rent'] === null ? 'no rent amount recorded; modeled as zero' : 'current-month Rent or Housing expense records'),
            ],
            'conversions' => [
                'tuition_target' => $tuitionTarget,
                'saved_tuition' => $savedTuition,
                'required_monthly_saving' => $tuitionPace,
            ],
            'baseline_forecast' => $baselineForecast,
            'comparisons' => $comparisons,
            'forecast_assumptions' => [
                'months' => $forecastMonths,
                'rent_change_percent' => $rentChangePercent,
                'income_delay_months' => $incomeDelayMonths,
                'scholarship_amount' => $scholarshipAmount,
                'scholarship_month' => $scholarshipMonth,
                'scholarship_is_unconfirmed' => $scholarshipMonth > 0,
                'rate_stress_percent' => $rateStressPercent,
                'currency' => $currency,
                'comparisons_are_independent' => true,
                'income_included_in_budget' => true,
                'rent_included_in_spending' => true,
            ],
            'assumption' => 'Forecasts repeat current-month recorded expenses as the monthly spending baseline. The profile budget already includes expected income; rent is included in recorded spending. Each requested change is compared independently with the baseline. Delayed income is unavailable during the first delayed months. Any scholarship is hypothetical and unconfirmed. Foreign tuition amounts use the Atlas demonstration-rate table, not a live market rate.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareTuitionPlan(User $user, AgentConversation $conversation, array $data): array
    {
        $prepared = $this->pendingAction($user, $conversation, 'create_tuition_plan', $data);
        $schedule = $this->planner->tuitionSchedule($data['amount'], $data['saved_amount'], $data['due_date']);
        $profile = $user->studentProfile;
        $affordability = ['available' => false, 'reason' => 'A saved home-currency budget and current-month expense baseline are required.'];

        if ($profile?->home_currency !== null && $profile->monthly_budget !== null) {
            $expenseBaseline = $this->currentMonthExpenseBaseline($user, $profile->home_currency);

            if ($expenseBaseline['count'] > 0) {
                $budgetConversion = $this->calculator->convert((string) $profile->monthly_budget, $profile->home_currency, $data['currency']);
                $spendingConversion = $this->calculator->convert($expenseBaseline['amount'], $profile->home_currency, $data['currency']);
                $paceConversion = $this->calculator->convert($schedule['required_monthly'], $data['currency'], $data['currency']);
                $affordability = [
                    'available' => true,
                    'currency' => $data['currency'],
                    'budget' => $this->planner->simulate(
                        $budgetConversion['converted_amount'],
                        $spendingConversion['converted_amount'],
                        $paceConversion['converted_amount'],
                        $paceConversion['converted_amount'],
                    ),
                    'assumptions' => [
                        'Current-month recorded expenses repeat as the monthly spending baseline.',
                        'Home-currency amounts use the Atlas demonstration-rate table when conversion is required.',
                    ],
                    'conversions' => [$budgetConversion, $spendingConversion],
                ];
            }
        }

        return [
            ...$prepared,
            'schedule' => $schedule,
            'affordability' => $affordability,
            'assumption' => 'The savings pace is calculated by the existing tuition planner and rounded up to cents. The affordability comparison is an estimate based on current-month records and Atlas demonstration rates where conversion is needed.',
        ];
    }

    /**
     * @return array{amount: string, count: int, rent: string|null}
     */
    private function currentMonthExpenseBaseline(User $user, string $currency): array
    {
        $expenses = $user->transactions()
            ->where('kind', 'expense')
            ->where('currency', $currency)
            ->whereBetween('occurred_on', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()])
            ->get(['category', 'amount']);
        $total = 0;
        $rent = 0;
        $rentEntries = 0;

        foreach ($expenses as $expense) {
            $amount = CurrencyCalculator::toMinorUnits((string) $expense->amount);
            $total += $amount;

            if (in_array(mb_strtolower(trim($expense->category)), ['rent', 'housing'], true)) {
                $rent += $amount;
                $rentEntries++;
            }
        }

        return [
            'amount' => CurrencyCalculator::formatMinorUnits($total),
            'count' => $expenses->count(),
            'rent' => $rentEntries === 0 ? null : CurrencyCalculator::formatMinorUnits($rent),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareSavingsGoalUpdate(User $user, AgentConversation $conversation, array $data): array
    {
        $goal = $user->savingsGoals()->whereKey($data['goal_id'])->firstOrFail();
        $targetAmount = $data['target_amount'] ?? (string) $goal->target_amount;
        $currentAmount = $data['current_amount'] ?? (string) $goal->current_amount;

        if (CurrencyCalculator::toMinorUnits($currentAmount) > CurrencyCalculator::toMinorUnits($targetAmount)) {
            throw ValidationException::withMessages(['current_amount' => 'Saved progress cannot exceed the goal target.']);
        }

        foreach (['target_amount', 'current_amount'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data[$field]));
            }
        }

        return $this->pendingAction($user, $conversation, 'update_savings_goal', $data, $this->savingsGoalSnapshot($goal));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareBudgetUpdate(User $user, AgentConversation $conversation, array $data): array
    {
        $budget = $user->budgets()->whereKey($data['budget_id'])->firstOrFail();

        if (isset($data['amount'])) {
            $data['amount'] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data['amount']));
        }

        return $this->pendingAction($user, $conversation, 'update_budget', $data, $this->budgetSnapshot($budget));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareTuitionPlanDeletion(User $user, AgentConversation $conversation, array $data): array
    {
        $plan = $user->tuitionPlans()->whereKey($data['tuition_plan_id'])->firstOrFail();

        return $this->pendingAction($user, $conversation, 'delete_tuition_plan', $data, $this->tuitionPlanSnapshot($plan));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareSavingsGoalDeletion(User $user, AgentConversation $conversation, array $data): array
    {
        $goal = $user->savingsGoals()->whereKey($data['goal_id'])->firstOrFail();

        return $this->pendingAction($user, $conversation, 'delete_savings_goal', $data, $this->savingsGoalSnapshot($goal));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareBudgetDeletion(User $user, AgentConversation $conversation, array $data): array
    {
        $budget = $user->budgets()->whereKey($data['budget_id'])->firstOrFail();

        return $this->pendingAction($user, $conversation, 'delete_budget', $data, $this->budgetSnapshot($budget));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareTuitionPlanUpdate(User $user, AgentConversation $conversation, array $data): array
    {
        $plan = $user->tuitionPlans()->whereKey($data['tuition_plan_id'])->firstOrFail();

        foreach (['amount', 'saved_amount'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data[$field]));
            }
        }

        $amount = $data['amount'] ?? (string) $plan->amount;
        $savedAmount = $data['saved_amount'] ?? (string) $plan->saved_amount;

        if (CurrencyCalculator::toMinorUnits($savedAmount) > CurrencyCalculator::toMinorUnits($amount)) {
            throw ValidationException::withMessages(['saved_amount' => 'Saved progress cannot exceed the updated tuition amount.']);
        }

        return $this->pendingAction($user, $conversation, 'update_tuition_plan', $data, $this->tuitionPlanSnapshot($plan));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $beforeState
     * @return array<string, mixed>
     */
    private function updateSavingsGoal(User $user, array $data, array $beforeState): array
    {
        $goal = $user->savingsGoals()->lockForUpdate()->findOrFail($data['goal_id']);
        $this->assertUnchangedSnapshot($beforeState, $this->savingsGoalSnapshot($goal));
        $targetAmount = $data['target_amount'] ?? (string) $goal->target_amount;
        $currentAmount = $data['current_amount'] ?? (string) $goal->current_amount;

        if (CurrencyCalculator::toMinorUnits($currentAmount) > CurrencyCalculator::toMinorUnits($targetAmount)) {
            throw ValidationException::withMessages(['current_amount' => 'Saved progress cannot exceed the goal target.']);
        }

        $goal->update([
            'target_amount' => CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($targetAmount)),
            'current_amount' => CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($currentAmount)),
        ]);

        return [
            'saved' => true,
            'record_type' => 'savings_goal',
            'title' => $goal->title,
            'id' => $goal->id,
            'target_amount' => $goal->target_amount,
            'current_amount' => $goal->current_amount,
            'currency' => $goal->currency,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $beforeState
     * @return array<string, mixed>
     */
    private function updateBudget(User $user, array $data, array $beforeState): array
    {
        $budget = $user->budgets()->lockForUpdate()->findOrFail($data['budget_id']);
        $this->assertUnchangedSnapshot($beforeState, $this->budgetSnapshot($budget));
        $budget->update(array_intersect_key($data, array_flip(['category', 'amount', 'currency'])));

        return [
            'saved' => true,
            'record_type' => 'budget',
            ...$this->budgetSnapshot($budget->refresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $beforeState
     * @return array<string, mixed>
     */
    private function updateTuitionPlan(User $user, array $data, array $beforeState): array
    {
        $plan = $user->tuitionPlans()->lockForUpdate()->findOrFail($data['tuition_plan_id']);
        $this->assertUnchangedSnapshot($beforeState, $this->tuitionPlanSnapshot($plan));
        $amount = $data['amount'] ?? (string) $plan->amount;
        $savedAmount = $data['saved_amount'] ?? (string) $plan->saved_amount;

        if (CurrencyCalculator::toMinorUnits($savedAmount) > CurrencyCalculator::toMinorUnits($amount)) {
            throw ValidationException::withMessages(['saved_amount' => 'Saved progress cannot exceed the updated tuition amount.']);
        }

        $plan->update([
            'amount' => CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($amount)),
            'saved_amount' => CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($savedAmount)),
            ...array_intersect_key($data, array_flip(['due_date', 'installments'])),
        ]);

        return [
            'saved' => true,
            'record_type' => 'tuition_plan',
            'title' => $plan->title,
            'id' => $plan->id,
            'amount' => $plan->amount,
            'saved_amount' => $plan->saved_amount,
            'currency' => $plan->currency,
            'due_date' => $plan->due_date->toDateString(),
            'installments' => $plan->installments,
        ];
    }

    /** @param array<string, mixed> $beforeState
     * @return array<string, mixed>
     */
    private function deleteTuitionPlan(User $user, array $data, array $beforeState): array
    {
        $plan = $user->tuitionPlans()->lockForUpdate()->findOrFail($data['tuition_plan_id']);
        $this->assertUnchangedSnapshot($beforeState, $this->tuitionPlanSnapshot($plan));
        $deleted = $this->tuitionPlanSnapshot($plan);
        $plan->delete();

        if ($user->tuitionPlans()->whereKey($data['tuition_plan_id'])->exists()) {
            throw new \LogicException('The tuition plan was not deleted.');
        }

        return ['deleted' => true, 'record_type' => 'deleted_tuition_plan', ...$deleted];
    }

    /** @param array<string, mixed> $beforeState
     * @return array<string, mixed>
     */
    private function deleteSavingsGoal(User $user, array $data, array $beforeState): array
    {
        $goal = $user->savingsGoals()->lockForUpdate()->findOrFail($data['goal_id']);
        $this->assertUnchangedSnapshot($beforeState, $this->savingsGoalSnapshot($goal));
        $deleted = $this->savingsGoalSnapshot($goal);
        $goal->delete();

        if ($user->savingsGoals()->whereKey($data['goal_id'])->exists()) {
            throw new \LogicException('The savings goal was not deleted.');
        }

        return ['deleted' => true, 'record_type' => 'deleted_savings_goal', ...$deleted];
    }

    /** @param array<string, mixed> $beforeState
     * @return array<string, mixed>
     */
    private function deleteBudget(User $user, array $data, array $beforeState): array
    {
        $budget = $user->budgets()->lockForUpdate()->findOrFail($data['budget_id']);
        $this->assertUnchangedSnapshot($beforeState, $this->budgetSnapshot($budget));
        $deleted = $this->budgetSnapshot($budget);
        $budget->delete();

        if ($user->budgets()->whereKey($data['budget_id'])->exists()) {
            throw new \LogicException('The monthly budget was not deleted.');
        }

        return ['deleted' => true, 'record_type' => 'deleted_budget', ...$deleted];
    }

    /**
     * @return array<string, mixed>
     */
    private function savingsGoalSnapshot(SavingsGoal $goal): array
    {
        return [
            'id' => $goal->id,
            'title' => $goal->title,
            'target_amount' => (string) $goal->target_amount,
            'current_amount' => (string) $goal->current_amount,
            'currency' => $goal->currency,
            'target_date' => $goal->target_date?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tuitionPlanSnapshot(TuitionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'amount' => (string) $plan->amount,
            'saved_amount' => (string) $plan->saved_amount,
            'currency' => $plan->currency,
            'due_date' => $plan->due_date->toDateString(),
            'installments' => $plan->installments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetSnapshot(Budget $budget): array
    {
        return [
            'id' => $budget->id,
            'category' => $budget->category,
            'amount' => (string) $budget->amount,
            'currency' => $budget->currency,
            'period_start' => $budget->period_start->toDateString(),
            'period_end' => $budget->period_end->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $current
     */
    private function assertUnchangedSnapshot(array $expected, array $current): void
    {
        if ($expected !== $current) {
            throw new \InvalidArgumentException('The record changed after this draft. Prepare a new action for review.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareProfileUpdate(User $user, AgentConversation $conversation, array $data): array
    {
        $profile = $user->studentProfile()->first();
        $before = $this->profileSnapshot($profile);

        return $this->pendingAction($user, $conversation, 'update_profile', $data, $before);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $beforeState
     * @return array<string, mixed>
     */
    private function saveProfileUpdate(User $user, array $data, array $beforeState): array
    {
        // Reload with lock for update.
        $profile = $user->studentProfile()->lockForUpdate()->first();
        $this->assertUnchangedSnapshot($beforeState, $this->profileSnapshot($profile));

        $updates = array_intersect_key($data, array_flip(['destination_country', 'financial_goals']));

        if ($profile === null) {
            throw new \InvalidArgumentException('Profile not found; complete the profile setup before updating it via the agent.');
        }

        $profile->update($updates);

        $fresh = $profile->refresh();

        return [
            'saved' => true,
            'record_type' => 'student_profile',
            'destination_country' => $fresh->destination_country,
            'financial_goals' => $fresh->financial_goals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileSnapshot(?object $profile): array
    {
        if ($profile === null) {
            return ['destination_country' => null, 'financial_goals' => null];
        }

        return [
            'destination_country' => $profile->destination_country,
            'financial_goals' => $profile->financial_goals,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{confirmation_required: bool, pending_action: array<string, mixed>, message: string}
     */
    private function pendingAction(User $user, AgentConversation $conversation, string $name, array $data, ?array $beforeState = null): array
    {
        $pending = AgentPendingAction::create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'tool_name' => $name,
            'arguments' => $data,
            'before_state' => $beforeState,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        return [
            'confirmation_required' => true,
            'pending_action' => ['id' => $pending->id, 'name' => $name, 'arguments' => $data, 'before_state' => $beforeState],
            'message' => 'This change has not been saved. Ask the user to review it and confirm explicitly.',
        ];
    }

    private function confirmAndSave(User $user, AgentConversation $conversation, AgentPendingAction $pending, callable $save): array
    {
        $result = $save();
        $pending->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        $this->audit($user, $conversation, $pending->tool_name, 'confirmed');

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $outcome
     */
    private function recordPendingActionOutcome(AgentConversation $conversation, int $pendingActionId, string $status, ?array $outcome): void
    {
        foreach ($conversation->messages()->whereNotNull('tool_result')->get() as $message) {
            $toolResults = $message->tool_result;

            if (! is_array($toolResults)) {
                continue;
            }

            $changed = false;

            foreach ($toolResults as $index => $toolResult) {
                $action = data_get($toolResult, 'result.pending_action');

                if (! is_array($action) || (int) ($action['id'] ?? 0) !== $pendingActionId) {
                    continue;
                }

                $toolResults[$index]['result']['pending_action'] = [
                    ...$action,
                    'status' => $status,
                    'outcome' => $outcome,
                    'status_message' => match ($status) {
                        'confirmed' => 'Confirmed and saved to your Atlas account.',
                        'cancelled' => 'Discarded. No planning changes were saved.',
                        'expired' => 'This draft expired. No planning changes were saved.',
                        'failed' => 'This proposal became invalid or stale. Nothing was saved.',
                        default => 'This planning action was handled.',
                    },
                ];
                $changed = true;
                break;
            }

            if ($changed) {
                $message->tool_result = $toolResults;
                $message->save();

                return;
            }
        }
    }

    private function markPendingActionFailed(User $user, AgentConversation $conversation, int $pendingActionId): void
    {
        DB::transaction(function () use ($user, $conversation, $pendingActionId): void {
            $pending = $user->agentPendingActions()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->find($pendingActionId);

            if ($pending === null || $pending->status !== 'pending') {
                return;
            }

            $pending->update(['status' => 'failed']);
            $this->audit($user, $conversation, $pending->tool_name, 'rejected');
            $this->recordPendingActionOutcome($conversation, $pending->id, 'failed', null);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function saveGoal(User $user, array $data): array
    {
        $goal = $user->savingsGoals()->create([
            ...$data,
            'current_amount' => $data['current_amount'] ?? '0.00',
        ]);

        return [
            'saved' => true,
            'record_type' => 'savings_goal',
            'title' => $goal->title,
            'target_amount' => $goal->target_amount,
            'current_amount' => $goal->current_amount,
            'currency' => $goal->currency,
            'id' => $goal->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function saveTuitionPlan(User $user, array $data): array
    {
        $plan = $user->tuitionPlans()->create($data);

        return [
            'saved' => true,
            'record_type' => 'tuition_plan',
            'title' => $plan->title,
            'amount' => (string) $plan->amount,
            'saved_amount' => (string) $plan->saved_amount,
            'currency' => $plan->currency,
            'due_date' => $plan->due_date->toDateString(),
            'installments' => $plan->installments,
            'id' => $plan->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function saveBudget(User $user, array $data): array
    {
        $budget = $user->budgets()->create([
            ...$data,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ]);

        return [
            'saved' => true,
            'record_type' => 'budget',
            'category' => $budget->category,
            'amount' => $budget->amount,
            'currency' => $budget->currency,
            'period_start' => $budget->period_start->toDateString(),
            'period_end' => $budget->period_end->toDateString(),
            'id' => $budget->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function savePlan(User $user, array $data): array
    {
        $plan = $user->financialPlans()->create($data);

        return ['saved' => true, 'record_type' => 'financial_plan', 'title' => $plan->title, 'id' => $plan->id];
    }

    private function audit(User $user, AgentConversation $conversation, string $name, string $status): void
    {
        AgentAuditLog::create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'tool_name' => mb_substr($name, 0, 80),
            'status' => $status,
            'summary' => match ($status) {
                'completed' => 'Tool completed using the signed-in user’s authorized records.',
                'awaiting_confirmation' => 'A reversible planning change is pending explicit user confirmation.',
                'confirmed' => str_starts_with($name, 'delete_')
                    ? 'The user explicitly confirmed deletion of an owned planning record.'
                    : 'The user explicitly confirmed a planning change.',
                'cancelled' => 'The user discarded a pending planning change before it was saved.',
                'expired' => 'A pending planning change expired before it was confirmed.',
                default => 'Tool arguments or requested records were rejected.',
            },
        ]);
    }

    private function assertConversationOwner(User $user, AgentConversation $conversation): void
    {
        if ($conversation->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }
}

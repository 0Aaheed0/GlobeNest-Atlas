<?php

namespace App\Services;

use App\Models\AgentAuditLog;
use App\Models\AgentConversation;
use App\Models\AgentPendingAction;
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
            $this->declaration('update_savings_goal', 'Prepare a change to an existing savings target. The current currency is retained and the user must confirm the exact change.', [
                'goal_id' => ['type' => 'integer', 'description' => 'ID of a savings goal belonging to the signed-in user.'],
                'target_amount' => ['type' => 'string', 'description' => 'New positive target amount, in the goal’s existing currency, at most two decimals.'],
            ], ['goal_id', 'target_amount']),
            $this->declaration('update_tuition_plan', 'Prepare changes to an existing tuition plan. Its current currency is retained and the user must confirm the exact changes.', [
                'tuition_plan_id' => ['type' => 'integer', 'description' => 'ID of a tuition plan belonging to the signed-in user.'],
                'amount' => ['type' => 'string', 'description' => 'Optional new total tuition amount, at most two decimals.'],
                'saved_amount' => ['type' => 'string', 'description' => 'Optional new recorded saved amount, at most two decimals.'],
                'due_date' => ['type' => 'string', 'description' => 'Optional new due date in YYYY-MM-DD format.'],
                'installments' => ['type' => 'integer', 'description' => 'Optional new installment count from 1 to 24.'],
            ], ['tuition_plan_id']),
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
                'update_savings_goal' => $this->prepareSavingsGoalUpdate($user, $conversation, $data),
                'update_tuition_plan' => $this->prepareTuitionPlanUpdate($user, $conversation, $data),
                'compare_remittance' => [
                    'quotes' => $this->calculator->compareRemittance($data['amount'], $data['from_currency'], $data['to_currency']),
                    'reference' => $this->calculator->convert($data['amount'], $data['from_currency'], $data['to_currency']),
                    'source' => 'Simulated fee profiles, ranked by estimated recipient amount after fees. Not provider offers, live rates, or delivery guarantees.',
                ],
                'create_savings_goal', 'create_budget', 'save_plan' => $this->pendingAction($user, $conversation, $name, $data),
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

            $saved = match ($name) {
                'create_savings_goal' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->saveGoal($user, $data)),
                'create_budget' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->saveBudget($user, $data)),
                'save_plan' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->savePlan($user, $data)),
                'update_savings_goal' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->updateSavingsGoal($user, $data, $pending->before_state ?? [])),
                'update_tuition_plan' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->updateTuitionPlan($user, $data, $pending->before_state ?? [])),
                default => throw new \InvalidArgumentException('This action does not support confirmation.'),
            };

            $this->recordPendingActionOutcome($conversation, $pending->id, 'confirmed', $saved);

            return $saved;
        });

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
            'update_savings_goal' => [
                'goal_id' => ['required', 'integer', 'min:1'],
                'target_amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            ],
            'update_tuition_plan' => [
                'tuition_plan_id' => ['required', 'integer', 'min:1'],
                'amount' => ['sometimes', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'saved_amount' => ['sometimes', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'due_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:today'],
                'installments' => ['sometimes', 'integer', 'min:1', 'max:24'],
            ],
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
            default => throw new \InvalidArgumentException('This tool is not available.'),
        };

        if (array_diff(array_keys($arguments), array_keys($rules)) !== []) {
            throw new \InvalidArgumentException('Unexpected tool arguments were rejected.');
        }

        $data = Validator::make($arguments, $rules)->validate();

        if ($name === 'create_savings_goal') {
            $data['current_amount'] = $data['current_amount'] ?? '0.00';

            if (CurrencyCalculator::toMinorUnits($data['current_amount']) > CurrencyCalculator::toMinorUnits($data['target_amount'])) {
                throw ValidationException::withMessages(['current_amount' => 'Saved progress cannot exceed the goal amount.']);
            }
        }

        if ($name === 'update_savings_goal') {
            $data['target_amount'] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data['target_amount']));
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

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function financialSummary(User $user): array
    {
        $profile = $user->studentProfile;
        $upcomingTuition = $user->tuitionPlans()->whereDate('due_date', '>=', today())->orderBy('due_date')->limit(5)->get([
            'id', 'title', 'amount', 'saved_amount', 'currency', 'due_date', 'installments',
        ]);
        $goals = $user->savingsGoals()->latest()->limit(5)->get(['id', 'title', 'target_amount', 'current_amount', 'currency', 'target_date']);

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
    private function prepareSavingsGoalUpdate(User $user, AgentConversation $conversation, array $data): array
    {
        $goal = $user->savingsGoals()->whereKey($data['goal_id'])->firstOrFail();
        $data['target_amount'] = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data['target_amount']));

        if (CurrencyCalculator::toMinorUnits((string) $goal->current_amount) > CurrencyCalculator::toMinorUnits($data['target_amount'])) {
            throw ValidationException::withMessages(['target_amount' => 'The new target cannot be less than the saved progress.']);
        }

        return $this->pendingAction($user, $conversation, 'update_savings_goal', $data, $this->savingsGoalSnapshot($goal));
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
        $targetAmount = CurrencyCalculator::formatMinorUnits(CurrencyCalculator::toMinorUnits($data['target_amount']));

        if (CurrencyCalculator::toMinorUnits((string) $goal->current_amount) > CurrencyCalculator::toMinorUnits($targetAmount)) {
            throw ValidationException::withMessages(['target_amount' => 'The new target cannot be less than the saved progress.']);
        }

        $goal->update(['target_amount' => $targetAmount]);

        return [
            'saved' => true,
            'record_type' => 'savings_goal',
            'title' => $goal->title,
            'id' => $goal->id,
            'target_amount' => $goal->target_amount,
            'currency' => $goal->currency,
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
                'confirmed' => 'The user explicitly confirmed a planning change.',
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

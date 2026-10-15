<?php

namespace App\Services;

use App\Models\AgentAuditLog;
use App\Models\AgentConversation;
use App\Models\AgentPendingAction;
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
                'amount' => ['type' => 'STRING', 'description' => 'Positive amount, at most two decimal places.'],
                'from_currency' => $this->currencyProperty(),
                'to_currency' => $this->currencyProperty(),
            ], ['amount', 'from_currency', 'to_currency']),
            $this->declaration('analyze_spending', 'Summarize this user’s recorded expenses for a recent period.', [
                'period_days' => ['type' => 'INTEGER', 'description' => 'Number of recent days to analyze, between 1 and 365.'],
            ], ['period_days']),
            $this->declaration('plan_tuition', 'Calculate required weekly and monthly savings for one of this user’s tuition plans.', [
                'tuition_plan_id' => ['type' => 'INTEGER', 'description' => 'ID of a tuition plan belonging to the signed-in user.'],
            ], ['tuition_plan_id']),
            $this->declaration('compare_remittance', 'Compare three illustrative fee models; these are not provider quotes.', [
                'amount' => ['type' => 'STRING', 'description' => 'Positive amount, at most two decimal places.'],
                'from_currency' => $this->currencyProperty(),
                'to_currency' => $this->currencyProperty(),
            ], ['amount', 'from_currency', 'to_currency']),
            $this->declaration('create_savings_goal', 'Prepare a savings goal for user review. It is not saved until explicitly confirmed.', [
                'title' => ['type' => 'STRING', 'description' => 'Short goal name, max 120 characters.'],
                'target_amount' => ['type' => 'STRING', 'description' => 'Positive target amount, at most two decimals.'],
                'current_amount' => ['type' => 'STRING', 'description' => 'Optional amount already saved, at most two decimals.'],
                'currency' => $this->currencyProperty(),
                'target_date' => ['type' => 'STRING', 'description' => 'Optional target date in YYYY-MM-DD format.'],
            ], ['title', 'target_amount', 'currency']),
            $this->declaration('create_budget', 'Prepare a monthly category budget for user review. It is not saved until explicitly confirmed.', [
                'category' => ['type' => 'STRING', 'description' => 'Budget category, max 60 characters.'],
                'amount' => ['type' => 'STRING', 'description' => 'Positive budget amount, at most two decimals.'],
                'currency' => $this->currencyProperty(),
            ], ['category', 'amount', 'currency']),
            $this->declaration('save_plan', 'Prepare a financial planning note for user review. It is not saved until explicitly confirmed.', [
                'title' => ['type' => 'STRING', 'description' => 'Plan title, max 120 characters.'],
                'summary' => ['type' => 'STRING', 'description' => 'Short plan with assumptions, max 500 characters.'],
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

        return DB::transaction(function () use ($user, $conversation, $pendingActionId): array {
            $pending = $user->agentPendingActions()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->findOrFail($pendingActionId);

            if ($pending->status !== 'pending' || $pending->expires_at->isPast()) {
                throw new \InvalidArgumentException('This confirmation has expired or has already been used.');
            }

            $name = $pending->tool_name;
            $data = $this->validateArguments($name, $pending->arguments);

            return match ($name) {
                'create_savings_goal' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->saveGoal($user, $data)),
                'create_budget' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->saveBudget($user, $data)),
                'save_plan' => $this->confirmAndSave($user, $conversation, $pending, fn (): array => $this->savePlan($user, $data)),
                default => throw new \InvalidArgumentException('This action does not support confirmation.'),
            };
        });
    }

    public function cancel(User $user, AgentConversation $conversation, int $pendingActionId): void
    {
        $this->assertConversationOwner($user, $conversation);

        DB::transaction(function () use ($user, $conversation, $pendingActionId): void {
            $pending = $user->agentPendingActions()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->findOrFail($pendingActionId);

            if ($pending->status !== 'pending' || $pending->expires_at->isPast()) {
                throw new \InvalidArgumentException('This pending action has expired or has already been handled.');
            }

            $pending->update(['status' => 'cancelled']);
            $this->audit($user, $conversation, $pending->tool_name, 'cancelled');
        });
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
                'type' => 'OBJECT',
                'properties' => (object) $properties,
                'required' => $required,
            ],
        ];
    }

    /**
     * @return array{type: string, description: string, enum: list<string>}
     */
    private function currencyProperty(): array
    {
        return [
            'type' => 'STRING',
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
            'create_savings_goal' => [
                'title' => ['required', 'string', 'max:120'],
                'target_amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'current_amount' => ['nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
                'currency' => ['required', 'string', Rule::in($this->exchangeRates->currencies())],
                'target_date' => ['nullable', 'date', 'after_or_equal:today'],
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

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function financialSummary(User $user): array
    {
        $profile = $user->studentProfile;
        $upcomingTuition = $user->tuitionPlans()->whereDate('due_date', '>=', today())->orderBy('due_date')->limit(5)->get([
            'title', 'amount', 'saved_amount', 'currency', 'due_date',
        ]);
        $goals = $user->savingsGoals()->latest()->limit(5)->get(['title', 'target_amount', 'current_amount', 'currency', 'target_date']);

        return [
            'profile' => $profile === null ? null : [
                'destination_country' => $profile->destination_country,
                'home_currency' => $profile->home_currency,
                'monthly_budget' => $profile->monthly_budget,
            ],
            'recorded_activity_count' => $user->transactions()->count(),
            'upcoming_tuition' => $upcomingTuition,
            'savings_goals' => $goals,
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
            'title' => $plan->title,
            'amount' => $plan->amount,
            'saved_amount' => $plan->saved_amount,
            'currency' => $plan->currency,
            'due_date' => $plan->due_date->toDateString(),
            'schedule' => $this->planner->tuitionSchedule((string) $plan->amount, (string) $plan->saved_amount, $plan->due_date->toDateString()),
            'installments' => $this->planner->installmentSchedule((string) $plan->amount, (string) $plan->saved_amount, $plan->due_date->toDateString(), $plan->installments),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{confirmation_required: bool, pending_action: array<string, mixed>, message: string}
     */
    private function pendingAction(User $user, AgentConversation $conversation, string $name, array $data): array
    {
        $pending = AgentPendingAction::create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'tool_name' => $name,
            'arguments' => $data,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        return [
            'confirmation_required' => true,
            'pending_action' => ['id' => $pending->id, 'name' => $name, 'arguments' => $data],
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function saveGoal(User $user, array $data): array
    {
        $goal = $user->savingsGoals()->create([
            ...$data,
            'current_amount' => $data['current_amount'] ?? '0.00',
        ]);

        return ['saved' => true, 'record_type' => 'savings_goal', 'title' => $goal->title, 'id' => $goal->id];
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

        return ['saved' => true, 'record_type' => 'budget', 'category' => $budget->category, 'id' => $budget->id];
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

<?php

namespace App\Http\Controllers;

use App\Models\AgentMessage;
use App\Models\Budget;
use App\Models\FundingOpportunity;
use App\Models\SavingsGoal;
use App\Models\TuitionPlan;
use App\Models\User;
use App\Services\AgentToolDisplayPresenter;
use App\Services\CurrencyCalculator;
use App\Services\ExchangeRateService;
use App\Services\FinancialPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function landing(): View
    {
        return view('landing');
    }

    public function dashboard(Request $request, FinancialPlanner $planner, AgentToolDisplayPresenter $toolPresenter): View
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->studentProfile;
        $filter = $request->query('type', 'all');
        $filter = in_array($filter, ['all', 'income', 'expense'], true) ? $filter : 'all';
        $transactions = $user->transactions()
            ->when($filter !== 'all', fn ($query) => $query->where('kind', $filter))
            ->latest('occurred_on')
            ->latest()
            ->limit(30)
            ->get();
        $monthTransactions = $user->transactions()
            ->whereBetween('occurred_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->get();
        $spentMinor = 0;
        $currencyBreakdown = [];
        $categoryBreakdown = [];
        $categorySpendByCurrency = [];

        foreach ($monthTransactions as $transaction) {
            $currency = $transaction->currency;
            $amountMinor = CurrencyCalculator::toMinorUnits((string) $transaction->amount);
            $currencyBreakdown[$currency] = ($currencyBreakdown[$currency] ?? 0) + ($transaction->kind === 'income' ? $amountMinor : -$amountMinor);

            if ($transaction->kind === 'expense') {
                $categoryKey = $currency.'|'.$transaction->category;
                $categorySpendByCurrency[$categoryKey] = ($categorySpendByCurrency[$categoryKey] ?? 0) + $amountMinor;

                if ($profile !== null && $currency === $profile->home_currency) {
                    $spentMinor += $amountMinor;
                    $categoryBreakdown[$transaction->category] = ($categoryBreakdown[$transaction->category] ?? 0) + $amountMinor;
                }
            }
        }

        $budgetMinor = $profile === null ? 0 : CurrencyCalculator::toMinorUnits((string) $profile->monthly_budget);
        $budgetUsagePercent = $budgetMinor === 0 ? 0 : min(100, intdiv($spentMinor * 100, $budgetMinor));
        $tuition = $user->tuitionPlans()->orderBy('due_date')->limit(8)->get()->map(function (TuitionPlan $plan) use ($planner): array {
            $amountMinor = CurrencyCalculator::toMinorUnits((string) $plan->amount);
            $savedMinor = CurrencyCalculator::toMinorUnits((string) $plan->saved_amount);

            return [
                'plan' => $plan,
                'schedule' => $planner->tuitionSchedule((string) $plan->amount, (string) $plan->saved_amount, $plan->due_date->toDateString()),
                'installments' => $planner->installmentSchedule((string) $plan->amount, (string) $plan->saved_amount, $plan->due_date->toDateString(), $plan->installments),
                'progress' => $amountMinor === 0 ? 0 : min(100, intdiv($savedMinor * 100, $amountMinor)),
            ];
        });
        $goals = $user->savingsGoals()->latest()->limit(8)->get()->map(function (SavingsGoal $goal): array {
            $targetMinor = CurrencyCalculator::toMinorUnits((string) $goal->target_amount);
            $currentMinor = CurrencyCalculator::toMinorUnits((string) $goal->current_amount);

            return [
                'goal' => $goal,
                'progress' => $targetMinor === 0 ? 0 : min(100, intdiv($currentMinor * 100, $targetMinor)),
            ];
        });
        $largestCategory = max($categoryBreakdown ?: [1]);
        $categoryCards = collect($categoryBreakdown)->map(fn (int $amount, string $category): array => [
            'category' => $category,
            'amount' => CurrencyCalculator::formatMinorUnits($amount),
            'share' => min(100, intdiv($amount * 100, $largestCategory)),
        ])->values();
        $conversationId = filter_var($request->query('conversation_id'), FILTER_VALIDATE_INT);
        $selectedConversation = $conversationId
            ? $user->agentConversations()->with(['messages' => fn ($query) => $query->orderBy('id')])->find($conversationId)
            : null;
        $aiProvider = config('services.ai.provider') ?? 'openrouter';
        $aiProviderSupported = in_array($aiProvider, ['openrouter', 'groq'], true);
        $aiProviderConfig = $aiProviderSupported ? config('services.'.$aiProvider, []) : [];
        $aiModel = is_array($aiProviderConfig) ? ($aiProviderConfig['model'] ?? null) : null;
        $aiKeyConfigured = is_array($aiProviderConfig) && is_string($aiProviderConfig['key'] ?? null)
            && trim($aiProviderConfig['key']) !== '';
        $aiProviderNeedsFixedModel = $aiProvider === 'openrouter'
            && in_array(strtolower((string) $aiModel), ['openrouter/free', 'openrouter/auto'], true);

        if ($selectedConversation !== null) {
            $selectedConversation->messages->each(function (AgentMessage $message) use ($toolPresenter): void {
                $message->setAttribute('display_tool_result', $toolPresenter->presentResults($message->tool_result ?? []));
            });
        }

        $monthlyBudgets = $user->budgets()
            ->whereDate('period_start', '<=', today())
            ->whereDate('period_end', '>=', today())
            ->orderBy('category')
            ->limit(12)
            ->get()
            ->map(function (Budget $budget) use ($categorySpendByCurrency): array {
                $limitMinor = CurrencyCalculator::toMinorUnits((string) $budget->amount);
                $spent = $categorySpendByCurrency[$budget->currency.'|'.$budget->category] ?? 0;

                return [
                    'budget' => $budget,
                    'spent' => CurrencyCalculator::formatMinorUnits($spent),
                    'remaining' => CurrencyCalculator::formatMinorUnits(max(0, $limitMinor - $spent)),
                    'progress' => $limitMinor === 0 ? 0 : min(100, intdiv($spent * 100, $limitMinor)),
                    'over_budget' => max(0, $spent - $limitMinor),
                ];
            });
        $financialAlerts = [];

        foreach ($tuition as $item) {
            $plan = $item['plan'];
            $schedule = $item['schedule'];

            if ($schedule['remaining'] === '0.00') {
                continue;
            }

            if ($schedule['status'] === 'overdue') {
                $financialAlerts[] = [
                    'level' => 'urgent',
                    'title' => $plan->title.' is past due',
                    'detail' => $plan->currency.' '.$schedule['remaining'].' remains unpaid in your record; update the plan or contact your institution.',
                    'date' => $plan->due_date->toDateString(),
                ];
            } elseif ($schedule['status'] === 'due_today') {
                $financialAlerts[] = [
                    'level' => 'urgent',
                    'title' => $plan->title.' is due today',
                    'detail' => $plan->currency.' '.$schedule['remaining'].' remains due today based on your plan; check the current amount with your institution.',
                    'date' => $plan->due_date->toDateString(),
                ];
            } elseif ($schedule['due_in_days'] <= 30) {
                $financialAlerts[] = [
                    'level' => 'urgent',
                    'title' => $plan->title.' is due soon',
                    'detail' => $plan->currency.' '.$schedule['remaining'].' remains over '.$schedule['due_in_days'].' days (about '.$plan->currency.' '.$schedule['required_weekly'].' per week).',
                    'date' => $plan->due_date->toDateString(),
                ];
            } elseif ($schedule['due_in_days'] <= 60) {
                $financialAlerts[] = [
                    'level' => 'notice',
                    'title' => $plan->title.' deadline is approaching',
                    'detail' => $plan->currency.' '.$schedule['remaining'].' remains over '.$schedule['due_in_days'].' days (about '.$plan->currency.' '.$schedule['required_monthly'].' per month).',
                    'date' => $plan->due_date->toDateString(),
                ];
            }
        }

        if ($profile !== null && $budgetMinor > 0 && $spentMinor > $budgetMinor) {
            $financialAlerts[] = [
                'level' => 'urgent',
                'title' => 'Recorded spending is over your monthly budget',
                'detail' => $profile->home_currency.' '.CurrencyCalculator::formatMinorUnits($spentMinor - $budgetMinor).' over based on same-currency expense records this month.',
                'date' => today()->toDateString(),
            ];
        } elseif ($profile !== null && $budgetMinor > 0 && $spentMinor >= intdiv($budgetMinor * 80, 100)) {
            $financialAlerts[] = [
                'level' => 'notice',
                'title' => 'You have used at least 80% of your monthly budget',
                'detail' => $profile->home_currency.' '.CurrencyCalculator::formatMinorUnits($spentMinor).' in same-currency recorded expenses against '.$profile->home_currency.' '.$profile->monthly_budget.'.',
                'date' => today()->toDateString(),
            ];
        }

        foreach ($monthlyBudgets as $item) {
            if ($item['over_budget'] > 0) {
                $budget = $item['budget'];
                $financialAlerts[] = [
                    'level' => 'notice',
                    'title' => $budget->category.' category budget exceeded',
                    'detail' => $budget->currency.' '.CurrencyCalculator::formatMinorUnits($item['over_budget']).' over its monthly limit based on recorded expenses.',
                    'date' => today()->toDateString(),
                ];
            }
        }

        return view('atlas', [
            'profile' => $profile,
            'transactions' => $transactions,
            'tuitionPlans' => $tuition,
            'goals' => $goals,
            'budgets' => $monthlyBudgets,
            'fundingOpportunities' => $user->fundingOpportunities()->orderByRaw('deadline IS NULL')->orderBy('deadline')->latest()->limit(12)->get(),
            'financialAlerts' => $financialAlerts,
            'pendingActions' => $user->agentPendingActions()
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->latest()
                ->limit(10)
                ->get(),
            'financialPlans' => $user->financialPlans()->latest()->limit(6)->get(),
            'agentAuditLogs' => $user->agentAuditLogs()->latest()->limit(10)->get(),
            'conversations' => $user->agentConversations()->with(['messages' => fn ($query) => $query->latest()->limit(1)])->latest()->limit(8)->get(),
            'monthlySpending' => CurrencyCalculator::formatMinorUnits($spentMinor),
            'availableFunds' => CurrencyCalculator::formatMinorUnits(max(0, $budgetMinor - $spentMinor)),
            'overBudget' => max(0, $spentMinor - $budgetMinor),
            'budgetUsagePercent' => $budgetUsagePercent,
            'currencyBreakdown' => collect($currencyBreakdown)->map(fn (int $amount, string $currency): array => [
                'currency' => $currency,
                'amount' => CurrencyCalculator::formatMinorUnits(abs($amount)),
                'kind' => $amount >= 0 ? 'net inflow' : 'net outflow',
            ])->values(),
            'categoryBreakdown' => $categoryCards,
            'filter' => $filter,
            'aiProviderName' => match ($aiProvider) {
                'openrouter' => 'OpenRouter',
                'groq' => 'Groq',
                default => 'Unsupported provider',
            },
            'aiModel' => is_string($aiModel) && $aiModel !== '' ? $aiModel : '(model not configured)',
            'aiKeyConfigured' => $aiKeyConfigured,
            'aiProviderNeedsFixedModel' => $aiProviderNeedsFixedModel,
            'aiProviderSupported' => $aiProviderSupported,
            'currencies' => app(ExchangeRateService::class)->currencies(),
            'currencyNames' => app(ExchangeRateService::class)->currencyNames(),
            'selectedConversation' => $selectedConversation,
        ]);
    }

    public function saveProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'destination_country' => ['required', 'string', 'max:80'],
            'home_currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
            'monthly_budget' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'monthly_income' => ['nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'financial_goals' => ['nullable', 'string', 'max:1000'],
            'memory_consent' => ['nullable', 'boolean'],
        ]);

        $request->user()->studentProfile()->updateOrCreate([], [
            ...$data,
            'monthly_income' => $data['monthly_income'] ?? '0.00',
            'memory_consent' => $request->boolean('memory_consent'),
        ]);

        return back()->with('status', 'Your profile has been updated.');
    }

    public function storeTuition(Request $request, FinancialPlanner $planner): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'saved_amount' => ['nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'installments' => ['required', 'integer', 'min:1', 'max:24'],
        ]);
        $saved = $data['saved_amount'] ?? '0.00';

        if (CurrencyCalculator::toMinorUnits($saved) > CurrencyCalculator::toMinorUnits($data['amount'])) {
            return back()->withErrors(['saved_amount' => 'Saved progress cannot exceed the tuition amount.'])->withInput();
        }

        $request->user()->tuitionPlans()->create([
            ...$data,
            'saved_amount' => $saved,
        ]);

        return back()->with('status', 'Tuition deadline added to your plan.');
    }

    public function addTuitionSavings(Request $request, int $tuitionPlan): RedirectResponse
    {
        $data = $request->validate([
            'additional_amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
        ]);

        DB::transaction(function () use ($request, $tuitionPlan, $data): void {
            $plan = $request->user()->tuitionPlans()->lockForUpdate()->findOrFail($tuitionPlan);
            $updatedAmount = CurrencyCalculator::toMinorUnits((string) $plan->saved_amount) + CurrencyCalculator::toMinorUnits($data['additional_amount']);

            if ($updatedAmount > CurrencyCalculator::toMinorUnits((string) $plan->amount)) {
                throw ValidationException::withMessages(['additional_amount' => 'Savings progress cannot exceed the tuition amount.']);
            }

            $plan->update(['saved_amount' => CurrencyCalculator::formatMinorUnits($updatedAmount)]);
        });

        return back()->with('status', 'Tuition savings progress updated. Atlas did not move money.');
    }

    public function storeGoal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'target_amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'current_amount' => ['nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
            'target_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $current = $data['current_amount'] ?? '0.00';

        if (CurrencyCalculator::toMinorUnits($current) > CurrencyCalculator::toMinorUnits($data['target_amount'])) {
            return back()->withErrors(['current_amount' => 'Saved progress cannot exceed the goal amount.'])->withInput();
        }

        $request->user()->savingsGoals()->create([
            ...$data,
            'current_amount' => $current,
        ]);

        return back()->with('status', 'Savings goal added.');
    }

    public function addGoalSavings(Request $request, int $goal): RedirectResponse
    {
        $data = $request->validate([
            'additional_amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
        ]);

        DB::transaction(function () use ($request, $goal, $data): void {
            $savingsGoal = $request->user()->savingsGoals()->lockForUpdate()->findOrFail($goal);
            $updatedAmount = CurrencyCalculator::toMinorUnits((string) $savingsGoal->current_amount) + CurrencyCalculator::toMinorUnits($data['additional_amount']);

            if ($updatedAmount > CurrencyCalculator::toMinorUnits((string) $savingsGoal->target_amount)) {
                throw ValidationException::withMessages(['additional_amount' => 'Savings progress cannot exceed the goal amount.']);
            }

            $savingsGoal->update(['current_amount' => CurrencyCalculator::formatMinorUnits($updatedAmount)]);
        });

        return back()->with('status', 'Savings goal progress updated. Atlas did not move money.');
    }

    public function storeBudget(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
        ]);

        $request->user()->budgets()->create([
            ...$data,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ]);

        return back()->with('status', 'Monthly category budget saved.');
    }

    public function storeFundingOpportunity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'max:120'],
            'potential_amount' => ['nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
            'deadline' => ['nullable', 'date'],
            'status' => ['required', Rule::in(FundingOpportunity::STATUSES)],
            'source_url' => [
                'nullable',
                'url',
                'max:2048',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    $scheme = parse_url((string) $value, PHP_URL_SCHEME);

                    if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
                        $fail('The source link must use HTTP or HTTPS.');
                    }
                },
            ],
        ]);

        $request->user()->fundingOpportunities()->create($data);

        return back()->with('status', 'Funding opportunity saved as user-entered information. Verify details with the source.');
    }

    public function updateFundingOpportunityStatus(Request $request, int $fundingOpportunity): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(FundingOpportunity::STATUSES)],
        ]);
        $opportunity = $request->user()->fundingOpportunities()->findOrFail($fundingOpportunity);
        $opportunity->update(['status' => $data['status']]);

        return back()->with('status', 'Funding application status updated.');
    }

    public function storeTransaction(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['income', 'expense'])],
            'category' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'is_simulated' => ['sometimes', 'boolean'],
        ]);

        $request->user()->transactions()->create([
            ...$data,
            'is_simulated' => $request->boolean('is_simulated'),
        ]);

        return back()->with('status', 'Activity record added. Atlas did not move money.');
    }

    public function convert(Request $request, CurrencyCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'from_currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
            'to_currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
        ]);

        return $this->calculate(fn (): array => $calculator->convert($data['amount'], $data['from_currency'], $data['to_currency']));
    }

    public function compareRemittance(Request $request, CurrencyCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'from_currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
            'to_currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
        ]);

        return $this->calculate(fn (): array => [
            'quotes' => $calculator->compareRemittance($data['amount'], $data['from_currency'], $data['to_currency']),
            'reference' => $calculator->convert($data['amount'], $data['from_currency'], $data['to_currency']),
            'source' => 'Simulated fee profiles, ranked by estimated recipient amount after fees. Not provider offers, live rates, or delivery guarantees.',
        ]);
    }

    public function scenario(Request $request, CurrencyCalculator $calculator, FinancialPlanner $planner): JsonResponse
    {
        $data = $request->validate([
            'monthly_budget' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'monthly_spending' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'tuition_contribution' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'tuition_amount' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'saved_amount' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'transfer_fee' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'installments' => ['required', 'integer', 'min:1', 'max:24'],
            'due_date' => ['required', 'date'],
            'from_currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
            'budget_currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
            'to_currency' => ['required', 'string', Rule::in(app(ExchangeRateService::class)->currencies())],
            'exchange_rate_change' => ['required', 'integer', 'min:-50', 'max:50'],
            'monthly_rent' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'monthly_income' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'rent_change_percent' => ['required', 'integer', 'min:-50', 'max:100'],
            'income_delay_months' => ['required', 'integer', 'min:0', 'max:12'],
            'scholarship_amount' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'scholarship_month' => ['required', 'integer', 'min:0', 'max:24'],
            'forecast_months' => ['required', 'integer', 'min:1', 'max:24'],
            'rate_stress_percent' => ['required', 'integer', 'min:0', 'max:50'],
        ]);

        $amountMinor = CurrencyCalculator::toMinorUnits($data['tuition_amount']);
        $feeMinor = CurrencyCalculator::toMinorUnits($data['transfer_fee']);

        if (CurrencyCalculator::toMinorUnits($data['saved_amount']) > $amountMinor) {
            return response()->json(['message' => 'Saved progress cannot exceed the tuition amount.'], 422);
        }

        if ($feeMinor >= $amountMinor) {
            return response()->json(['message' => 'The transfer fee must be less than the tuition amount.'], 422);
        }

        $budgetInputCurrency = $request->user()->studentProfile?->home_currency ?? $data['budget_currency'];

        return $this->calculate(function () use ($data, $amountMinor, $feeMinor, $planner, $calculator, $budgetInputCurrency): array {
            $tuition = $planner->tuitionSchedule($data['tuition_amount'], $data['saved_amount'], $data['due_date']);
            $tuitionSavingsConversion = $calculator->convert($tuition['required_monthly'], $data['from_currency'], $data['budget_currency']);
            $tuitionTargetConversion = $calculator->convert($data['tuition_amount'], $data['from_currency'], $data['budget_currency']);
            $savedTuitionConversion = $calculator->convert($data['saved_amount'], $data['from_currency'], $data['budget_currency']);
            $monthlyBudgetConversion = $calculator->convert($data['monthly_budget'], $budgetInputCurrency, $data['budget_currency']);
            $monthlySpendingConversion = $calculator->convert($data['monthly_spending'], $budgetInputCurrency, $data['budget_currency']);
            $tuitionContributionConversion = $calculator->convert($data['tuition_contribution'], $budgetInputCurrency, $data['budget_currency']);
            $monthlyRentConversion = $calculator->convert($data['monthly_rent'], $budgetInputCurrency, $data['budget_currency']);
            $monthlyIncomeConversion = $calculator->convert($data['monthly_income'], $budgetInputCurrency, $data['budget_currency']);
            $scholarshipConversion = $calculator->convert($data['scholarship_amount'], $budgetInputCurrency, $data['budget_currency']);

            return [
                'budget' => $planner->simulate(
                    $monthlyBudgetConversion['converted_amount'],
                    $monthlySpendingConversion['converted_amount'],
                    $tuitionContributionConversion['converted_amount'],
                    $tuitionSavingsConversion['converted_amount'],
                ),
                'budget_currency' => $data['budget_currency'],
                'budget_input_currency' => $budgetInputCurrency,
                'tuition_savings_conversion' => $tuitionSavingsConversion,
                'tuition' => $tuition,
                'installments' => $planner->installmentSchedule(
                    $data['tuition_amount'],
                    $data['saved_amount'],
                    $data['due_date'],
                    (int) $data['installments'],
                ),
                'exchange' => $calculator->simulateRateChange(
                    CurrencyCalculator::formatMinorUnits($amountMinor - $feeMinor),
                    $data['from_currency'],
                    $data['to_currency'],
                    (int) $data['exchange_rate_change'],
                ),
                'forecasts' => $planner->forecastScenarios(
                    $monthlyBudgetConversion['converted_amount'],
                    $monthlySpendingConversion['converted_amount'],
                    $monthlyRentConversion['converted_amount'],
                    $monthlyIncomeConversion['converted_amount'],
                    $tuitionContributionConversion['converted_amount'],
                    $tuitionTargetConversion['converted_amount'],
                    $savedTuitionConversion['converted_amount'],
                    $scholarshipConversion['converted_amount'],
                    (int) $data['rent_change_percent'],
                    (int) $data['income_delay_months'],
                    (int) $data['scholarship_month'],
                    (int) $data['forecast_months'],
                    (int) $data['rate_stress_percent'],
                ),
                'forecast_assumptions' => [
                    'months' => (int) $data['forecast_months'],
                    'rate_stress_percent' => (int) $data['rate_stress_percent'],
                    'rent_change_percent' => (int) $data['rent_change_percent'],
                    'income_delay_months' => (int) $data['income_delay_months'],
                    'scholarship_month' => (int) $data['scholarship_month'],
                    'budget_currency' => $data['budget_currency'],
                    'budget_input_currency' => $budgetInputCurrency,
                    'budget_input_conversion' => $monthlyBudgetConversion,
                    'tuition_conversion' => $tuitionTargetConversion,
                    'scholarship_is_unconfirmed' => true,
                ],
                'transfer_fee' => $data['transfer_fee'],
                'assumption' => 'All outputs are hypothetical. The fee is deducted before conversion; the rate change is relative to the demonstration reference rate. Monthly income is assumed included in the monthly budget, rent is included in monthly spending, delayed income is unavailable only during the first delayed months, and the scholarship is unconfirmed.',
            ];
        });
    }

    private function calculate(callable $calculation): JsonResponse
    {
        try {
            return response()->json($calculation());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}

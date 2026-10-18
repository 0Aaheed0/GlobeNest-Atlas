<?php

namespace App\Services;

class AgentToolDisplayPresenter
{
    /**
     * @param  array<array<string, mixed>>  $toolResults
     * @return list<array{name: string, summary: string, receipt: array<string, mixed>, pending_action?: array<string, mixed>}>
     */
    public function presentResults(array $toolResults): array
    {
        $presented = [];

        foreach ($toolResults as $toolResult) {
            if (! is_array($toolResult)
                || ! is_string($toolResult['name'] ?? null)
                || ! is_array($toolResult['result'] ?? null)) {
                continue;
            }

            $name = $toolResult['name'];
            $result = $toolResult['result'];
            $action = is_array($result['pending_action'] ?? null)
                ? $this->presentAction($result['pending_action'])
                : null;

            $presented[] = [
                'name' => $name,
                'summary' => $this->summary($name, $result, $action),
                'receipt' => $this->receipt($name, $result, $action),
                ...($action === null ? [] : ['pending_action' => $action]),
            ];
        }

        return $presented;
    }

    /**
     * @param  array<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    public function presentActions(array $actions): array
    {
        return array_values(array_map(
            fn (array $action): array => $this->presentAction($action),
            array_filter($actions, 'is_array'),
        ));
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function presentAction(array $action): array
    {
        $name = is_string($action['name'] ?? null) ? $action['name'] : '';
        $arguments = is_array($action['arguments'] ?? null) ? $action['arguments'] : [];
        $beforeState = is_array($action['before_state'] ?? null) ? $action['before_state'] : [];
        $outcome = is_array($action['outcome'] ?? null) ? $action['outcome'] : [];

        $presented = [
            'id' => (int) ($action['id'] ?? 0),
            'name' => $name,
            'arguments' => $this->only($arguments, match ($name) {
                'create_savings_goal' => ['title', 'target_amount', 'currency', 'current_amount', 'target_date'],
                'create_tuition_plan' => ['title', 'amount', 'saved_amount', 'currency', 'due_date', 'installments'],
                'create_budget' => ['category', 'amount', 'currency'],
                'save_plan' => ['title', 'summary'],
                'update_savings_goal' => ['goal_id', 'target_amount', 'current_amount'],
                'update_tuition_plan' => ['tuition_plan_id', 'amount', 'saved_amount', 'due_date', 'installments'],
                'update_budget' => ['budget_id', 'category', 'amount', 'currency'],
                'delete_tuition_plan' => ['tuition_plan_id'],
                'delete_savings_goal' => ['goal_id'],
                'delete_budget' => ['budget_id'],
                default => [],
            }),
            'before_state' => $this->only($beforeState, match ($name) {
                'update_savings_goal' => ['id', 'title', 'target_amount', 'current_amount', 'currency', 'target_date'],
                'update_tuition_plan' => ['id', 'title', 'amount', 'saved_amount', 'currency', 'due_date', 'installments'],
                'update_budget', 'delete_budget' => ['id', 'category', 'amount', 'currency', 'period_start', 'period_end'],
                'delete_savings_goal' => ['id', 'title', 'target_amount', 'current_amount', 'currency', 'target_date'],
                'delete_tuition_plan' => ['id', 'title', 'amount', 'saved_amount', 'currency', 'due_date', 'installments'],
                default => [],
            }),
        ];

        $status = $action['status'] ?? null;

        if (is_string($status) && in_array($status, ['pending', 'confirmed', 'cancelled', 'expired', 'failed'], true)) {
            $presented['status'] = $status;
        }

        if (($presented['status'] ?? null) === 'confirmed') {
            $presented['outcome'] = $this->only($outcome, [
                'record_type', 'id', 'title', 'category', 'amount', 'target_amount', 'current_amount',
                'saved_amount', 'currency', 'due_date', 'installments', 'period_start', 'period_end',
            ]);
        }

        if (isset($presented['status'])) {
            $presented['status_message'] = match ($presented['status']) {
                'confirmed' => str_starts_with($name, 'delete_')
                    ? 'Confirmed and deleted from your Atlas account.'
                    : 'Confirmed and saved to your Atlas account.',
                'cancelled' => 'Discarded. No planning changes were saved.',
                'expired' => 'This draft expired. No planning changes were saved.',
                'failed' => 'This proposal became invalid or stale. Nothing was saved.',
                default => '',
            };
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $action
     */
    private function summary(string $name, array $result, ?array $action): string
    {
        if ($action !== null) {
            return match ($action['status'] ?? 'pending') {
                'confirmed' => str_starts_with($action['name'] ?? '', 'delete_')
                    ? 'This record was deleted from your Atlas account after your confirmation.'
                    : 'This planning change was saved to your Atlas account. No payment was made.',
                'cancelled', 'expired' => 'This planning proposal was not saved and cannot be confirmed.',
                'failed' => 'This proposal became invalid or stale. Nothing was saved.',
                default => 'A proposed planning change is ready for your review. Nothing has been saved.',
            };
        }

        if (isset($result['error'])) {
            return 'Atlas could not complete this request. Review the assistant response for next steps.';
        }

        return match ($name) {
            'get_financial_summary' => 'Your financial overview is ready for Atlas’s explanation.',
            'get_funding_opportunities' => 'Your saved funding opportunities are ready to review; Atlas does not verify them.',
            'convert_currency' => 'The conversion was calculated using Atlas’s demonstration-rate table.',
            'analyze_spending' => 'Your recorded spending was summarized by category and currency.',
            'plan_tuition' => 'Your tuition savings pace was calculated from your saved plan.',
            'forecast_scenarios' => ($result['status'] ?? null) === 'needs_clarification'
                ? 'I need a few details before Atlas can calculate this forecast.'
                : 'A deterministic savings and tuition funding forecast is ready to compare.',
            'compare_remittance' => 'A comparison using simulated fee profiles is ready; these are not live offers.',
            default => 'Atlas completed a validated planning calculation.',
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $action
     * @return array{source: string, inputs: list<string>, calculation: string, assumptions: list<string>, result: string, approval_required: bool, proposed_changes: list<array{field: string, current: string, proposed: string}>}
     */
    private function receipt(string $name, array $result, ?array $action): array
    {
        if ($action !== null) {
            return $this->actionReceipt($action, $result);
        }

        if (isset($result['error'])) {
            return [
                'source' => 'No completed server-side calculation.',
                'inputs' => [],
                'calculation' => 'No verified result was produced.',
                'assumptions' => ['Do not rely on an incomplete result.'],
                'result' => 'The requested tool did not complete.',
                'approval_required' => false,
                'proposed_changes' => [],
            ];
        }

        $receipt = match ($name) {
            'convert_currency' => [
                'inputs' => ["{$result['amount']} {$result['from_currency']}"],
                'calculation' => "Source amount × {$result['rate']} {$result['to_currency']} per {$result['from_currency']}; rounded to the target currency's minor unit.",
                'assumptions' => ["{$result['source']}; table read at {$result['retrieved_at']}; live rate: ".($result['live'] ? 'yes' : 'no').'.'],
                'result' => "{$result['converted_amount']} {$result['to_currency']}.",
            ],
            'compare_remittance' => $this->remittanceReceipt($result),
            'analyze_spending' => $this->spendingReceipt($result),
            'plan_tuition' => $this->tuitionReceipt($result),
            'forecast_scenarios' => $this->forecastReceipt($result),
            'get_financial_summary' => $this->summaryReceipt($result),
            'get_funding_opportunities' => [
                'inputs' => collect($result['opportunities'] ?? [])->map(fn (array $opportunity): string => ($opportunity['title'] ?? 'Untitled opportunity').': '.(($opportunity['potential_amount'] ?? null) === null ? 'amount not provided' : $opportunity['potential_amount'].' '.($opportunity['currency'] ?? 'currency unavailable')).'; deadline '.($opportunity['deadline'] ?? 'not provided').'; status '.($opportunity['status'] ?? 'not provided').'.')->all(),
                'calculation' => 'No award or eligibility calculation is performed.',
                'assumptions' => [$result['note'] ?? 'Funding entries are user-entered and unverified.'],
                'result' => count($result['opportunities'] ?? []).' user-entered opportunities returned.',
            ],
            default => [
                'inputs' => ['Validated arguments and records owned by the signed-in user.'],
                'calculation' => 'The application validated the request; no additional financial calculation is available in this result.',
                'assumptions' => ['Use only values included in this server-side result.'],
                'result' => 'A validated tool result was returned.',
            ],
        };

        return [
            'source' => 'Deterministic Atlas server-side tool result; no AI-generated calculation.',
            'inputs' => $receipt['inputs'],
            'calculation' => $receipt['calculation'],
            'assumptions' => $receipt['assumptions'],
            'result' => $receipt['result'],
            'approval_required' => false,
            'proposed_changes' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{inputs: list<string>, calculation: string, assumptions: list<string>, result: string}
     */
    private function remittanceReceipt(array $result): array
    {
        $reference = $result['reference'] ?? [];
        $quotes = $result['quotes'] ?? [];

        return [
            'inputs' => ["{$reference['amount']} {$reference['from_currency']} sent toward {$reference['to_currency']}"],
            'calculation' => 'Source amount − simulated fee in the source currency, then convert the post-fee amount using the reference rate.',
            'assumptions' => [
                $result['source'] ?? 'Fee profiles and rates are simulations.',
                "Reference rate: {$reference['rate']} {$reference['to_currency']} per {$reference['from_currency']}; {$reference['source']}; table read at {$reference['retrieved_at']}; live rate: ".($reference['live'] ? 'yes' : 'no').'.',
            ],
            'result' => implode('; ', array_map(
                fn (array $quote): string => "{$quote['name']}: fee {$quote['fee']} {$quote['fee_currency']}, recipient {$quote['recipient_amount']} {$reference['to_currency']} ({$quote['delivery_estimate']})",
                $quotes,
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{inputs: list<string>, calculation: string, assumptions: list<string>, result: string}
     */
    private function spendingReceipt(array $result): array
    {
        return [
            'inputs' => ["{$result['expense_entries']} expense entries from the last {$result['period_days']} days."],
            'calculation' => 'Sum recorded expenses by category and currency; currencies are not combined.',
            'assumptions' => [$result['note']],
            'result' => implode('; ', array_map(
                fn (array $item): string => "{$item['category']}: {$item['amount']} {$item['currency']} over {$result['period_days']} days",
                $result['totals_by_category_and_currency'],
            )) ?: 'No expense records were found for this period.',
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{inputs: list<string>, calculation: string, assumptions: list<string>, result: string}
     */
    private function tuitionReceipt(array $result): array
    {
        return [
            'inputs' => ["{$result['title']}: total {$result['amount']} {$result['currency']}; saved {$result['saved_amount']} {$result['currency']}; due {$result['due_date']}."],
            'calculation' => 'Outstanding = max(0, total − saved). The existing tuition planner rounds weekly and monthly saving pace upward to cents based on days until the deadline.',
            'assumptions' => ['This is a schedule from the user’s saved plan, not an institution invoice or proof of funds.'],
            'result' => "{$result['schedule']['remaining']} {$result['currency']} remains; save {$result['schedule']['required_weekly']} {$result['currency']} per week or {$result['schedule']['required_monthly']} {$result['currency']} per month.",
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{inputs: list<string>, calculation: string, assumptions: list<string>, result: string}
     */
    private function summaryReceipt(array $result): array
    {
        $profile = $result['profile'] ?? null;
        $inputs = ["{$result['recorded_activity_count']} activity records belonging to the signed-in user."];

        if (is_array($profile)) {
            $inputs[] = "Study destination: {$profile['destination_country']}; home currency: {$profile['home_currency']}.";
            $inputs[] = 'The signed-in user has a saved monthly budget in the home currency; its amount is withheld from chat and history display.';
        }

        foreach (collect($result['upcoming_tuition'] ?? []) as $plan) {
            $inputs[] = 'Tuition record '.data_get($plan, 'title').': '.data_get($plan, 'amount').' '.data_get($plan, 'currency').'; saved '.data_get($plan, 'saved_amount').' '.data_get($plan, 'currency').'; due '.data_get($plan, 'due_date').'.';
        }

        foreach (collect($result['savings_goals'] ?? []) as $goal) {
            $inputs[] = 'Savings goal '.data_get($goal, 'title').': current '.data_get($goal, 'current_amount').' '.data_get($goal, 'currency').' of '.data_get($goal, 'target_amount').' '.data_get($goal, 'currency').'.';
        }

        foreach (collect($result['monthly_budgets'] ?? []) as $budget) {
            $inputs[] = 'Monthly '.data_get($budget, 'category').' budget: '.data_get($budget, 'amount').' '.data_get($budget, 'currency').'; period '.data_get($budget, 'period_start').' to '.data_get($budget, 'period_end').'.';
        }

        return [
            'inputs' => $inputs,
            'calculation' => 'This tool returns saved profile, tuition, goal, and current monthly budget values; it does not aggregate unlike currencies or project a cash flow.',
            'assumptions' => [$result['note']],
            'result' => count($result['upcoming_tuition'] ?? []).' upcoming tuition plans, '.count($result['savings_goals'] ?? []).' savings goals, and '.count($result['monthly_budgets'] ?? []).' monthly budgets returned.',
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{source: string, inputs: list<string>, calculation: string, assumptions: list<string>, result: string, approval_required: bool, proposed_changes: list<array{field: string, current: string, proposed: string}>}
     */
    private function actionReceipt(array $action, ?array $toolResult = null): array
    {
        $name = $action['name'];
        $arguments = $action['arguments'];
        $before = $action['before_state'];

        if (str_starts_with($name, 'delete_')) {
            return $this->deletionReceipt($action);
        }

        $changes = [];
        $currency = $before['currency'] ?? $arguments['currency'] ?? null;

        foreach ($arguments as $field => $proposed) {
            if (in_array($field, ['goal_id', 'tuition_plan_id', 'budget_id'], true)) {
                continue;
            }

            $current = $before[$field] ?? 'Not set';
            $isMoneyField = in_array($field, ['amount', 'saved_amount', 'target_amount', 'current_amount'], true);
            $changes[] = [
                'field' => str_replace('_', ' ', $field),
                'current' => (is_scalar($current) ? (string) $current : (string) json_encode($current)).($isMoneyField && is_string($currency) ? ' '.$currency : ''),
                'proposed' => (is_scalar($proposed) ? (string) $proposed : (string) json_encode($proposed)).($isMoneyField && is_string($currency) ? ' '.$currency : ''),
            ];
        }

        $approvalRequired = ! in_array($action['status'] ?? null, ['confirmed', 'cancelled', 'expired', 'failed'], true);

        $receipt = [
            'source' => 'Validated proposal based on signed-in-user records and validated tool inputs.',
            'inputs' => ["Planning tool: {$name}."],
            'calculation' => 'No financial calculation is implied by this proposal; the displayed field values are the exact staged changes.',
            'assumptions' => ['No changes are saved unless explicitly approved. Approval saves planning data only and cannot move money.'],
            'result' => match ($action['status'] ?? null) {
                'failed' => 'This proposal became invalid or stale. Nothing was saved; prepare a new proposal if you still want this change.',
                'cancelled', 'expired' => 'This proposal was not saved and cannot be reused.',
                'confirmed' => 'This proposal was confirmed and saved; it cannot be reused.',
                default => 'Draft only. Explicit approval is still required.',
            },
            'approval_required' => $approvalRequired,
            'proposed_changes' => $changes,
        ];

        $target = match ($name) {
            'update_savings_goal' => 'Savings goal',
            'update_tuition_plan' => 'Tuition plan',
            'update_budget' => 'Monthly budget',
            default => null,
        };

        if ($target !== null) {
            $recordName = $before['title'] ?? $before['category'] ?? 'Untitled record';
            $receipt['inputs'] = ["{$target} #{$before['id']}: {$recordName}."];
        }

        if ($name === 'create_tuition_plan' && is_array($toolResult['schedule'] ?? null)) {
            $schedule = $toolResult['schedule'];
            $receipt['calculation'] = 'The existing tuition planner computes remaining tuition, then rounds required weekly and monthly savings upward to cents using days until the due date.';
            $receipt['assumptions'][] = $toolResult['assumption'] ?? 'The schedule is based on the proposed tuition amount and due date.';
            $receipt['result'] = match ($action['status'] ?? 'pending') {
                'confirmed' => 'The tuition plan was saved to your account. No payment was made. Required weekly saving: '.$schedule['required_weekly'].' '.$currency.'; required monthly saving: '.$schedule['required_monthly'].' '.$currency.'.',
                'cancelled', 'expired', 'failed' => 'This proposal was not saved. No tuition plan was created.',
                default => 'Draft only. Explicit approval is still required. Required weekly saving: '.$schedule['required_weekly'].' '.$currency.'; required monthly saving: '.$schedule['required_monthly'].' '.$currency.'.',
            };

            $affordability = $toolResult['affordability'] ?? [];

            if (($affordability['available'] ?? false) === true) {
                $budget = $affordability['budget'];
                $receipt['result'] .= ' Recorded-spending comparison in '.$affordability['currency'].': '.$budget['status'];
                $receipt['assumptions'] = [...$receipt['assumptions'], ...($affordability['assumptions'] ?? [])];

                foreach ($affordability['conversions'] ?? [] as $conversion) {
                    $receipt['assumptions'][] = $conversion['amount'].' '.$conversion['from_currency'].' converted at '.$conversion['rate'].' to '.$conversion['converted_amount'].' '.$conversion['to_currency'].' using '.$conversion['source'].'; live rate: '.($conversion['live'] ? 'yes' : 'no').'.';
                }
            } else {
                $receipt['assumptions'][] = $affordability['reason'] ?? 'Affordability could not be compared from available records.';
            }
        }

        return $receipt;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function deletionReceipt(array $action): array
    {
        $name = $action['name'];
        $before = $action['before_state'];
        $recordType = match ($name) {
            'delete_tuition_plan' => 'Tuition plan',
            'delete_savings_goal' => 'Savings goal',
            'delete_budget' => 'Monthly budget',
            default => 'Planning record',
        };
        $identifier = (string) ($before['id'] ?? 'unknown');
        $summary = match ($name) {
            'delete_tuition_plan' => ($before['title'] ?? 'Untitled').'; '.($before['amount'] ?? 'unknown').' '.($before['currency'] ?? 'currency unavailable').'; saved '.($before['saved_amount'] ?? 'unknown').' '.($before['currency'] ?? '').'; due '.($before['due_date'] ?? 'not provided').'; installments '.($before['installments'] ?? 'unknown'),
            'delete_savings_goal' => ($before['title'] ?? 'Untitled').'; target '.($before['target_amount'] ?? 'unknown').' '.($before['currency'] ?? 'currency unavailable').'; progress '.($before['current_amount'] ?? 'unknown').' '.($before['currency'] ?? '').'; target date '.($before['target_date'] ?? 'not provided'),
            'delete_budget' => ($before['category'] ?? 'Uncategorized').'; '.($before['amount'] ?? 'unknown').' '.($before['currency'] ?? 'currency unavailable').'; period '.($before['period_start'] ?? 'unknown').' to '.($before['period_end'] ?? 'unknown'),
            default => 'Exact record details unavailable.',
        };
        $status = $action['status'] ?? 'pending';
        $approvalRequired = ! in_array($status, ['confirmed', 'cancelled', 'expired', 'failed'], true);

        return [
            'source' => 'Exact record loaded from the signed-in user’s account and staged for deletion.',
            'inputs' => ["{$recordType} #{$identifier}: {$summary}"],
            'calculation' => 'No financial calculation is performed. This action deletes only the displayed record.',
            'assumptions' => ['Deletion is permanent. No record is removed before explicit confirmation.'],
            'result' => match ($status) {
                'confirmed' => "{$recordType} #{$identifier} was deleted from your account.",
                'cancelled', 'expired' => "{$recordType} #{$identifier} was not deleted.",
                'failed' => "{$recordType} #{$identifier} was not deleted because the reviewed record changed or is no longer available.",
                default => "Deletion draft only. Explicit confirmation is required to delete {$recordType} #{$identifier}.",
            },
            'approval_required' => $approvalRequired,
            'proposed_changes' => [[
                'field' => 'Deletion target',
                'current' => "{$recordType} #{$identifier}: {$summary}",
                'proposed' => $approvalRequired ? 'Permanently delete this record' : ($status === 'confirmed' ? 'Deleted' : 'No deletion'),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{inputs: list<string>, calculation: string, assumptions: list<string>, result: string}
     */
    private function forecastReceipt(array $result): array
    {
        if (($result['status'] ?? null) !== 'complete') {
            return [
                'inputs' => [data_get($result, 'plan.title', data_get($result, 'goal.title', 'Owned savings target'))],
                'calculation' => 'No forecast was calculated because required baseline information is missing.',
                'assumptions' => $result['assumptions'] ?? [],
                'result' => implode(' ', $result['questions'] ?? ['Provide the missing information before relying on a forecast.']),
            ];
        }

        $baseline = $result['baseline_inputs'];
        $plan = $result['plan'];
        $scenarioSummaries = [['name' => 'Baseline', 'scenario' => $result['baseline_forecast']]];

        foreach ($result['comparisons'] as $comparison) {
            foreach ($comparison['scenarios'] as $scenario) {
                $scenarioSummaries[] = [
                    'name' => $comparison['name'].' — '.$scenario['name'],
                    'scenario' => $scenario,
                ];
            }
        }

        $conversionAssumptions = array_map(
            fn (array $conversion): string => $conversion['amount'].' '.$conversion['from_currency'].' × '.$conversion['rate'].' '.$conversion['to_currency'].' per '.$conversion['from_currency'].' = '.$conversion['converted_amount'].' '.$conversion['to_currency'].'; '.$conversion['source'].'; live rate: '.($conversion['live'] ? 'yes' : 'no').'; read at '.$conversion['retrieved_at'].'.',
            array_values($result['conversions']),
        );

        return [
            'inputs' => [
                'Monthly budget: '.$baseline['monthly_budget'].' '.$baseline['currency'].'.',
                'Current-month recorded expenses: '.$baseline['monthly_spending'].' '.$baseline['currency'].' across '.$baseline['expense_entries'].' entries.',
                'Monthly rent used: '.$baseline['monthly_rent'].' '.$baseline['currency'].' ('.$baseline['rent_source'].').',
                ucfirst($plan['type']).': '.$plan['amount'].' '.$plan['currency'].'; saved '.$plan['saved_amount'].' '.$plan['currency'].'; target date '.$plan['due_date'].'.',
                'Required monthly tuition saving converted to '.$baseline['currency'].': '.$result['conversions']['required_monthly_saving']['converted_amount'].'.',
            ],
            'calculation' => 'The existing FinancialPlanner generated a baseline and independent requested comparisons. Foreign-exchange stress includes Favorable, Baseline, and Adverse rates. Each projection reports accumulated contributions, target shortfall, cash flow, and first shortfall months. Foreign-currency targets use the deterministic Atlas demonstration-rate table.',
            'assumptions' => [
                $result['assumption'],
                'Forecast assumptions: '.json_encode($result['forecast_assumptions'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'.',
                'These are hypothetical projections, not guarantees or confirmed scholarship awards.',
                ...$conversionAssumptions,
            ],
            'result' => implode('; ', array_map(
                fn (array $entry): string => $entry['name'].': projected fund '.$entry['scenario']['projected_tuition_fund'].' '.$baseline['currency'].' against target '.$entry['scenario']['tuition_target'].' '.$baseline['currency'].'; target shortfall '.$entry['scenario']['tuition_shortfall'].' '.$baseline['currency'].'; cash flow '.$entry['scenario']['projected_cash_flow'].' '.$baseline['currency'].'; first cash-flow shortfall month '.($entry['scenario']['first_cash_flow_shortfall_month'] ?? 'none').'; first contribution gap month '.($entry['scenario']['first_tuition_contribution_gap_month'] ?? 'none'),
                $scenarioSummaries,
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $values, array $keys): array
    {
        return array_intersect_key($values, array_flip($keys));
    }
}

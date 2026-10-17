<?php

namespace App\Services;

class AgentToolDisplayPresenter
{
    /**
     * @param  array<array<string, mixed>>  $toolResults
     * @return list<array{name: string, summary: string, pending_action?: array<string, mixed>}>
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
                'create_budget' => ['category', 'amount', 'currency'],
                'save_plan' => ['title', 'summary'],
                'update_savings_goal' => ['goal_id', 'target_amount'],
                'update_tuition_plan' => ['tuition_plan_id', 'amount', 'saved_amount', 'due_date', 'installments'],
                default => [],
            }),
            'before_state' => $this->only($beforeState, match ($name) {
                'update_savings_goal' => ['title', 'target_amount', 'current_amount', 'currency', 'target_date'],
                'update_tuition_plan' => ['title', 'amount', 'saved_amount', 'currency', 'due_date', 'installments'],
                default => [],
            }),
        ];

        $status = $action['status'] ?? null;

        if (is_string($status) && in_array($status, ['pending', 'confirmed', 'cancelled', 'expired'], true)) {
            $presented['status'] = $status;
        }

        if (($presented['status'] ?? null) === 'confirmed') {
            $presented['outcome'] = $this->only($outcome, [
                'record_type', 'title', 'category', 'amount', 'target_amount', 'current_amount',
                'saved_amount', 'currency', 'due_date', 'installments', 'period_start', 'period_end',
            ]);
        }

        if (isset($presented['status'])) {
            $presented['status_message'] = match ($presented['status']) {
                'confirmed' => 'Confirmed and saved to your Atlas account.',
                'cancelled' => 'Discarded. No planning changes were saved.',
                'expired' => 'This draft expired. No planning changes were saved.',
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
                'confirmed' => 'This planning change was saved to your Atlas account. No payment was made.',
                'cancelled', 'expired' => 'This planning proposal was not saved and cannot be confirmed.',
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
            'compare_remittance' => 'A comparison using simulated fee profiles is ready; these are not live offers.',
            default => 'Atlas completed a validated planning calculation.',
        };
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

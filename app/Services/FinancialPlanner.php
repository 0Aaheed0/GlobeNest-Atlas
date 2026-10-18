<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class FinancialPlanner
{
    /**
     * @return array{remaining: string, due_in_days: int, weeks_available: int, months_available: int, required_weekly: string, required_monthly: string, shortfall: string, status: string}
     */
    public function tuitionSchedule(string $amount, string $savedAmount, string $dueDate): array
    {
        $amountMinor = CurrencyCalculator::toMinorUnits($amount);
        $savedMinor = CurrencyCalculator::toMinorUnits($savedAmount);
        $remainingMinor = max(0, $amountMinor - $savedMinor);
        $due = CarbonImmutable::parse($dueDate)->startOfDay();
        $dueInDays = (int) now()->startOfDay()->diffInDays($due, false);
        $daysAvailable = max(1, $dueInDays);
        $weeks = max(1, intdiv($daysAvailable + 6, 7));
        $months = max(1, intdiv($daysAvailable + 29, 30));

        return [
            'remaining' => CurrencyCalculator::formatMinorUnits($remainingMinor),
            'due_in_days' => $dueInDays,
            'weeks_available' => $weeks,
            'months_available' => $months,
            'required_weekly' => CurrencyCalculator::formatMinorUnits(self::ceilDivide($remainingMinor, $weeks)),
            'required_monthly' => CurrencyCalculator::formatMinorUnits(self::ceilDivide($remainingMinor, $months)),
            'shortfall' => $dueInDays <= 0 ? CurrencyCalculator::formatMinorUnits($remainingMinor) : '0.00',
            'status' => $remainingMinor === 0 ? 'funded' : ($dueInDays < 0 ? 'overdue' : ($dueInDays === 0 ? 'due_today' : 'on_track')),
        ];
    }

    /**
     * @return array{monthly_budget: string, monthly_spending: string, tuition_contribution: string, required_tuition_savings: string, tuition_savings_gap: string, tuition_savings_status: string, projected_remaining: string, status: string}
     */
    public function simulate(string $monthlyBudget, string $monthlySpending, string $tuitionContribution, string $requiredMonthlyTuitionSavings = '0.00'): array
    {
        $budgetMinor = CurrencyCalculator::toMinorUnits($monthlyBudget);
        $spendingMinor = CurrencyCalculator::toMinorUnits($monthlySpending);
        $tuitionMinor = CurrencyCalculator::toMinorUnits($tuitionContribution);
        $requiredTuitionMinor = CurrencyCalculator::toMinorUnits($requiredMonthlyTuitionSavings);
        $remainingMinor = $budgetMinor - $spendingMinor - $tuitionMinor;
        $tuitionGapMinor = max(0, $requiredTuitionMinor - $tuitionMinor);

        return [
            'monthly_budget' => CurrencyCalculator::formatMinorUnits($budgetMinor),
            'monthly_spending' => CurrencyCalculator::formatMinorUnits($spendingMinor),
            'tuition_contribution' => CurrencyCalculator::formatMinorUnits($tuitionMinor),
            'required_tuition_savings' => CurrencyCalculator::formatMinorUnits($requiredTuitionMinor),
            'tuition_savings_gap' => CurrencyCalculator::formatMinorUnits($tuitionGapMinor),
            'tuition_savings_status' => $tuitionGapMinor > 0 ? 'below_target' : 'meets_target',
            'projected_remaining' => CurrencyCalculator::formatMinorUnits(max(0, $remainingMinor)),
            'status' => $remainingMinor < 0 ? 'shortfall' : 'within_budget',
        ];
    }

    /**
     * @return list<array{name: string, rate_change_percent: int, projected_tuition_fund: string, tuition_target: string, tuition_shortfall: string, total_contributions: string, scholarship_received: bool, projected_cash_flow: string, first_cash_flow_shortfall_month: int|null, first_tuition_contribution_gap_month: int|null}>
     */
    public function forecastScenarios(
        string $monthlyBudget,
        string $monthlySpending,
        string $monthlyRent,
        string $monthlyIncome,
        string $tuitionContribution,
        string $tuitionTarget,
        string $savedTuition,
        string $scholarshipAmount,
        int $rentChangePercent,
        int $incomeDelayMonths,
        int $scholarshipMonth,
        int $forecastMonths,
        int $rateStressPercent,
    ): array {
        if ($rentChangePercent < -50 || $rentChangePercent > 100) {
            throw new \InvalidArgumentException('Rent change must be between -50% and +100%.');
        }

        if ($incomeDelayMonths < 0 || $incomeDelayMonths > 12 || $scholarshipMonth < 0 || $scholarshipMonth > 24) {
            throw new \InvalidArgumentException('Income delay and scholarship timing are outside the supported range.');
        }

        if ($forecastMonths < 1 || $forecastMonths > 24 || $rateStressPercent < 0 || $rateStressPercent > 50) {
            throw new \InvalidArgumentException('Forecast length and exchange-rate stress are outside the supported range.');
        }

        $budgetMinor = CurrencyCalculator::toMinorUnits($monthlyBudget);
        $spendingMinor = CurrencyCalculator::toMinorUnits($monthlySpending);
        $rentMinor = CurrencyCalculator::toMinorUnits($monthlyRent);
        $incomeMinor = CurrencyCalculator::toMinorUnits($monthlyIncome);
        $contributionMinor = CurrencyCalculator::toMinorUnits($tuitionContribution);
        $targetMinor = CurrencyCalculator::toMinorUnits($tuitionTarget);
        $savedMinor = CurrencyCalculator::toMinorUnits($savedTuition);
        $scholarshipMinor = CurrencyCalculator::toMinorUnits($scholarshipAmount);

        if ($rentMinor > $spendingMinor) {
            throw new \InvalidArgumentException('Monthly rent cannot exceed total monthly spending.');
        }

        $rentChangeMinor = self::multiplyPercent($rentMinor, $rentChangePercent);
        $scenarios = [];

        foreach ([
            ['name' => 'Favorable', 'rate_change_percent' => -$rateStressPercent],
            ['name' => 'Baseline', 'rate_change_percent' => 0],
            ['name' => 'Adverse', 'rate_change_percent' => $rateStressPercent],
        ] as $scenario) {
            $scenarioTargetMinor = self::multiplyPercent($targetMinor, 100 + $scenario['rate_change_percent']);
            $scenarioSavedMinor = self::multiplyPercent($savedMinor, 100 + $scenario['rate_change_percent']);
            $fundMinor = $scenarioSavedMinor;
            $contributionsMinor = 0;
            $cashFlowMinor = 0;
            $firstCashFlowShortfallMonth = null;
            $firstTuitionContributionGapMonth = null;
            $scholarshipReceived = $scholarshipMinor > 0 && $scholarshipMonth >= 1 && $scholarshipMonth <= $forecastMonths;

            for ($month = 1; $month <= $forecastMonths; $month++) {
                $incomeLossMinor = $month <= $incomeDelayMonths ? $incomeMinor : 0;
                $availableMinor = $budgetMinor - $spendingMinor - $rentChangeMinor - $incomeLossMinor;
                $monthContributionMinor = min($contributionMinor, max(0, $availableMinor));

                if ($contributionMinor > 0 && $monthContributionMinor < $contributionMinor && $firstTuitionContributionGapMonth === null) {
                    $firstTuitionContributionGapMonth = $month;
                }

                $contributionsMinor += $monthContributionMinor;
                $fundMinor += $monthContributionMinor;
                $monthlyCashFlowMinor = $availableMinor - $monthContributionMinor;
                $cashFlowMinor += $monthlyCashFlowMinor;

                if ($monthlyCashFlowMinor < 0 && $firstCashFlowShortfallMonth === null) {
                    $firstCashFlowShortfallMonth = $month;
                }
            }

            if ($scholarshipReceived) {
                $fundMinor += $scholarshipMinor;
            }

            $scenarios[] = [
                'name' => $scenario['name'],
                'rate_change_percent' => $scenario['rate_change_percent'],
                'projected_tuition_fund' => CurrencyCalculator::formatMinorUnits($fundMinor),
                'tuition_target' => CurrencyCalculator::formatMinorUnits($scenarioTargetMinor),
                'tuition_shortfall' => CurrencyCalculator::formatMinorUnits(max(0, $scenarioTargetMinor - $fundMinor)),
                'total_contributions' => CurrencyCalculator::formatMinorUnits($contributionsMinor),
                'scholarship_received' => $scholarshipReceived,
                'projected_cash_flow' => self::formatSignedMinorUnits($cashFlowMinor),
                'first_cash_flow_shortfall_month' => $firstCashFlowShortfallMonth,
                'first_tuition_contribution_gap_month' => $firstTuitionContributionGapMonth,
            ];
        }

        return $scenarios;
    }

    /**
     * @return list<array{number: int, amount: string, saved: string, remaining: string, due_date: string, status: string}>
     */
    public function installmentSchedule(string $amount, string $savedAmount, string $dueDate, int $installments): array
    {
        if ($installments < 1 || $installments > 24) {
            throw new \InvalidArgumentException('An installment plan must contain between 1 and 24 installments.');
        }

        $amountMinor = CurrencyCalculator::toMinorUnits($amount);
        $savedMinor = CurrencyCalculator::toMinorUnits($savedAmount);
        $baseInstallment = intdiv($amountMinor, $installments);
        $extraCents = $amountMinor % $installments;
        $lastDueDate = CarbonImmutable::parse($dueDate)->startOfDay();
        $schedule = [];

        for ($index = 0; $index < $installments; $index++) {
            $installmentMinor = $baseInstallment + ($index < $extraCents ? 1 : 0);
            $savedForInstallment = min($savedMinor, $installmentMinor);
            $savedMinor -= $savedForInstallment;
            $installmentDueDate = $lastDueDate->subMonthsNoOverflow($installments - $index - 1);
            $remainingMinor = $installmentMinor - $savedForInstallment;

            $schedule[] = [
                'number' => $index + 1,
                'amount' => CurrencyCalculator::formatMinorUnits($installmentMinor),
                'saved' => CurrencyCalculator::formatMinorUnits($savedForInstallment),
                'remaining' => CurrencyCalculator::formatMinorUnits($remainingMinor),
                'due_date' => $installmentDueDate->toDateString(),
                'status' => $remainingMinor === 0 ? 'funded' : ($installmentDueDate->lt(today()) ? 'overdue' : 'upcoming'),
            ];
        }

        return $schedule;
    }

    private static function ceilDivide(int $amount, int $periods): int
    {
        return intdiv($amount + $periods - 1, $periods);
    }

    private static function multiplyPercent(int $amount, int $percent): int
    {
        $product = $amount * $percent;
        $sign = $product < 0 ? -1 : 1;

        return $sign * intdiv(abs($product) + 50, 100);
    }

    private static function formatSignedMinorUnits(int $amount): string
    {
        $formatted = CurrencyCalculator::formatMinorUnits(abs($amount));

        return $amount < 0 ? '-'.$formatted : $formatted;
    }
}

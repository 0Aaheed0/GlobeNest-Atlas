<?php

namespace Tests\Unit;

use App\Services\FinancialPlanner;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class FinancialPlannerTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_tuition_schedule_calculates_weekly_and_monthly_targets_in_cents(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 12:00:00');

        $result = app(FinancialPlanner::class)->tuitionSchedule('1200.00', '0.00', '2026-04-01');

        $this->assertSame(90, $result['due_in_days']);
        $this->assertSame('92.31', $result['required_weekly']);
        $this->assertSame('400.00', $result['required_monthly']);
        $this->assertSame('on_track', $result['status']);
        $this->assertSame('0.00', $result['shortfall']);
    }

    public function test_funded_and_overdue_tuition_plans_are_distinguished(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 12:00:00');
        $planner = app(FinancialPlanner::class);

        $funded = $planner->tuitionSchedule('1000.00', '1200.00', '2026-02-01');
        $overdue = $planner->tuitionSchedule('1000.00', '250.00', '2025-12-31');

        $this->assertSame('funded', $funded['status']);
        $this->assertSame('0.00', $funded['remaining']);
        $this->assertSame('overdue', $overdue['status']);
        $this->assertSame('750.00', $overdue['shortfall']);
    }

    public function test_tuition_due_today_is_not_mislabeled_as_overdue(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 12:00:00');

        $dueToday = app(FinancialPlanner::class)->tuitionSchedule('1000.00', '250.00', '2026-01-01');

        $this->assertSame(0, $dueToday['due_in_days']);
        $this->assertSame('due_today', $dueToday['status']);
        $this->assertSame('750.00', $dueToday['shortfall']);
    }

    public function test_installments_split_cents_evenly_generate_monthly_dates_and_allocate_savings(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 12:00:00');

        $schedule = app(FinancialPlanner::class)->installmentSchedule('1000.01', '400.00', '2026-04-01', 3);

        $this->assertSame(['333.34', '333.34', '333.33'], array_column($schedule, 'amount'));
        $this->assertSame(['2026-02-01', '2026-03-01', '2026-04-01'], array_column($schedule, 'due_date'));
        $this->assertSame(['333.34', '66.66', '0.00'], array_column($schedule, 'saved'));
        $this->assertSame(['funded', 'upcoming', 'upcoming'], array_column($schedule, 'status'));
    }

    public function test_scenario_shows_monthly_shortfall_without_negative_available_funds(): void
    {
        $result = app(FinancialPlanner::class)->simulate('1800.00', '1500.00', '400.00', '600.00');

        $this->assertSame('0.00', $result['projected_remaining']);
        $this->assertSame('shortfall', $result['status']);
        $this->assertSame('200.00', $result['tuition_savings_gap']);
        $this->assertSame('below_target', $result['tuition_savings_status']);
    }

    public function test_forecasts_include_rent_change_delayed_income_scholarship_timing_and_fx_stress(): void
    {
        $forecasts = app(FinancialPlanner::class)->forecastScenarios(
            monthlyBudget: '1800.00',
            monthlySpending: '1500.00',
            monthlyRent: '600.00',
            monthlyIncome: '500.00',
            tuitionContribution: '200.00',
            tuitionTarget: '1200.00',
            savedTuition: '100.00',
            scholarshipAmount: '300.00',
            rentChangePercent: 10,
            incomeDelayMonths: 2,
            scholarshipMonth: 3,
            forecastMonths: 4,
            rateStressPercent: 10,
        );

        $this->assertSame(['Favorable', 'Baseline', 'Adverse'], array_column($forecasts, 'name'));
        $this->assertSame([-10, 0, 10], array_column($forecasts, 'rate_change_percent'));
        $this->assertSame(['290.00', '400.00', '510.00'], array_column($forecasts, 'tuition_shortfall'));
        $this->assertSame(['790.00', '800.00', '810.00'], array_column($forecasts, 'projected_tuition_fund'));
        $this->assertSame('400.00', $forecasts[1]['total_contributions']);
        $this->assertSame('-440.00', $forecasts[1]['projected_cash_flow']);
        $this->assertTrue($forecasts[1]['scholarship_received']);
    }

    public function test_scenario_forecast_does_not_count_scholarship_after_horizon_and_rejects_rent_exceeding_spending(): void
    {
        $planner = app(FinancialPlanner::class);
        $forecasts = $planner->forecastScenarios(
            '2000.00', '1200.00', '400.00', '0.00', '100.00',
            '500.00', '0.00', '1000.00', 0, 0, 4, 3, 0,
        );

        $this->assertFalse($forecasts[1]['scholarship_received']);
        $this->assertSame('300.00', $forecasts[1]['projected_tuition_fund']);

        $this->expectException(\InvalidArgumentException::class);
        $planner->forecastScenarios(
            '2000.00', '1200.00', '1300.00', '0.00', '100.00',
            '500.00', '0.00', '0.00', 0, 0, 0, 3, 0,
        );
    }
}

<?php

namespace Tests\Unit;

use App\Services\CurrencyCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class CurrencyCalculatorTest extends TestCase
{
    public function test_conversion_uses_integer_minor_units_and_discloses_demo_rates(): void
    {
        $result = app(CurrencyCalculator::class)->convert('100.00', 'USD', 'EUR');

        $this->assertSame('92.34', $result['converted_amount']);
        $this->assertSame('0.923400', $result['rate']);
        $this->assertSame('Atlas demonstration rate table; not a live market quote', $result['source']);
        $this->assertFalse($result['live']);
        $this->assertNotEmpty($result['retrieved_at']);
    }

    public function test_conversion_rounds_half_up_to_target_minor_units(): void
    {
        $result = app(CurrencyCalculator::class)->convert('0.01', 'USD', 'EUR');

        $this->assertSame('0.01', $result['converted_amount']);
    }

    public function test_zero_amount_can_be_used_for_a_funded_plan_calculation(): void
    {
        $result = app(CurrencyCalculator::class)->convert('0.00', 'USD', 'EUR');

        $this->assertSame('0.00', $result['converted_amount']);
        $this->assertSame('0.923400', $result['rate']);
    }

    public function test_remittance_estimates_deduct_fees_and_are_explicitly_simulated(): void
    {
        $quotes = app(CurrencyCalculator::class)->compareRemittance('100.00', 'USD', 'EUR');

        $this->assertCount(3, $quotes);
        $this->assertSame(1, $quotes[0]['rank']);
        $this->assertSame('Everyday model', $quotes[0]['name']);
        $this->assertSame('0.75', $quotes[0]['fee']);
        $this->assertSame('91.65', $quotes[0]['recipient_amount']);
        $this->assertTrue($quotes[0]['simulated']);
        $this->assertStringContainsString('estimate', $quotes[0]['delivery_estimate']);
        $this->assertGreaterThanOrEqual($quotes[1]['recipient_amount'], $quotes[0]['recipient_amount']);
        $this->assertGreaterThanOrEqual($quotes[2]['recipient_amount'], $quotes[1]['recipient_amount']);
    }

    public function test_invalid_money_and_unsupported_currencies_are_rejected(): void
    {
        $calculator = app(CurrencyCalculator::class);

        $this->expectException(InvalidArgumentException::class);
        $calculator->convert('1e5', 'USD', 'EUR');
    }

    public function test_unknown_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(CurrencyCalculator::class)->convert('1.00', 'USD', 'XYZ');
    }

    public function test_rate_change_scenario_is_bounded_and_hypothetical(): void
    {
        $result = app(CurrencyCalculator::class)->simulateRateChange('100.00', 'USD', 'EUR', -10);

        $this->assertSame('92.34', $result['baseline_amount']);
        $this->assertSame('83.11', $result['scenario_amount']);
        $this->assertTrue($result['simulated']);
    }
}

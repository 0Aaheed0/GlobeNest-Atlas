<?php

namespace App\Services;

class CurrencyCalculator
{
    private const RATE_SCALE = 1_000_000;

    public function __construct(private ExchangeRateService $exchangeRates) {}

    /**
     * @return array{amount: string, converted_amount: string, from_currency: string, to_currency: string, rate: string, source: string, retrieved_at: string, live: bool}
     */
    public function convert(string $amount, string $fromCurrency, string $toCurrency): array
    {
        $rates = $this->exchangeRates->rates();
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);
        $this->assertSupported($fromCurrency, $rates['rates']);
        $this->assertSupported($toCurrency, $rates['rates']);

        $amountMinor = self::toMinorUnits($amount);
        $convertedMinor = $this->convertMinorUnits($amountMinor, $fromCurrency, $toCurrency, $rates['rates']);
        $rate = intdiv(($rates['rates'][$toCurrency] * self::RATE_SCALE) + intdiv($rates['rates'][$fromCurrency], 2), $rates['rates'][$fromCurrency]);

        return [
            'amount' => self::formatMinorUnits($amountMinor),
            'converted_amount' => self::formatMinorUnits($convertedMinor),
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'rate' => self::formatScaled($rate, self::RATE_SCALE, 6),
            'source' => $rates['source'],
            'retrieved_at' => $rates['retrieved_at'],
            'live' => $rates['live'],
        ];
    }

    /**
     * @return list<array{rank: int, name: string, fee: string, fee_currency: string, recipient_amount: string, delivery_estimate: string, limitation: string, simulated: bool}>
     */
    public function compareRemittance(string $amount, string $fromCurrency, string $toCurrency): array
    {
        $rates = $this->exchangeRates->rates();
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);
        $this->assertSupported($fromCurrency, $rates['rates']);
        $this->assertSupported($toCurrency, $rates['rates']);

        $amountMinor = self::toMinorUnits($amount);
        $options = [
            ['name' => 'Low-fee model', 'flat_fee_minor' => 125, 'basis_points' => 0, 'delivery' => '2-3 business days (estimate)', 'limitation' => 'Illustrative fixed fee; no provider quote'],
            ['name' => 'Everyday model', 'flat_fee_minor' => 0, 'basis_points' => 75, 'delivery' => '1-2 business days (estimate)', 'limitation' => 'Illustrative 0.75% fee; no provider quote'],
            ['name' => 'Expedited model', 'flat_fee_minor' => 500, 'basis_points' => 0, 'delivery' => 'Same day may be possible; not guaranteed', 'limitation' => 'Illustrative flat fee; no provider quote'],
        ];
        $quotes = [];

        foreach ($options as $option) {
            $percentageFee = intdiv(($amountMinor * $option['basis_points']) + 5_000, 10_000);
            $feeMinor = $option['flat_fee_minor'] + $percentageFee;

            if ($feeMinor >= $amountMinor) {
                throw new \InvalidArgumentException('The amount must be greater than the estimated fee.');
            }

            $recipientMinor = $this->convertMinorUnits($amountMinor - $feeMinor, $fromCurrency, $toCurrency, $rates['rates']);
            $quotes[] = [
                'name' => $option['name'],
                'fee' => self::formatMinorUnits($feeMinor),
                'fee_currency' => $fromCurrency,
                'recipient_amount' => self::formatMinorUnits($recipientMinor),
                'recipient_minor' => $recipientMinor,
                'delivery_estimate' => $option['delivery'],
                'limitation' => $option['limitation'],
                'simulated' => true,
            ];
        }

        usort($quotes, fn (array $left, array $right): int => ($right['recipient_minor'] <=> $left['recipient_minor']) ?: ($left['name'] <=> $right['name']));

        return array_map(
            fn (array $quote, int $index): array => [
                'rank' => $index + 1,
                'name' => $quote['name'],
                'fee' => $quote['fee'],
                'fee_currency' => $quote['fee_currency'],
                'recipient_amount' => $quote['recipient_amount'],
                'delivery_estimate' => $quote['delivery_estimate'],
                'limitation' => $quote['limitation'],
                'simulated' => $quote['simulated'],
            ],
            $quotes,
            array_keys($quotes),
        );
    }

    /**
     * @return array{baseline_amount: string, scenario_amount: string, from_currency: string, to_currency: string, rate_change_percent: int, source: string, retrieved_at: string, simulated: bool}
     */
    public function simulateRateChange(string $amount, string $fromCurrency, string $toCurrency, int $rateChangePercent): array
    {
        if ($rateChangePercent < -50 || $rateChangePercent > 50) {
            throw new \InvalidArgumentException('Exchange-rate scenarios must be between -50% and +50%.');
        }

        $baseline = $this->convert($amount, $fromCurrency, $toCurrency);
        $baselineMinor = self::toMinorUnits($baseline['converted_amount']);
        $scenarioMinor = intdiv(($baselineMinor * (100 + $rateChangePercent)) + 50, 100);

        return [
            'baseline_amount' => self::formatMinorUnits($baselineMinor),
            'scenario_amount' => self::formatMinorUnits($scenarioMinor),
            'from_currency' => $baseline['from_currency'],
            'to_currency' => $baseline['to_currency'],
            'rate_change_percent' => $rateChangePercent,
            'source' => $baseline['source'],
            'retrieved_at' => $baseline['retrieved_at'],
            'simulated' => true,
        ];
    }

    public static function toMinorUnits(string $amount): int
    {
        if (! preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException('Amount must be a non-negative number with at most two decimals and no more than 99,999,999.99.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function formatMinorUnits(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    private function convertMinorUnits(int $amountMinor, string $from, string $to, array $rates): int
    {
        $numerator = $amountMinor * $rates[$to];
        $denominator = $rates[$from];

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    private function assertSupported(string $currency, array $rates): void
    {
        if (! array_key_exists($currency, $rates)) {
            throw new \InvalidArgumentException('Choose a currency supported by the demonstration rate table.');
        }
    }

    private static function formatScaled(int $amount, int $scale, int $decimals): string
    {
        $whole = intdiv($amount, $scale);
        $fraction = $amount % $scale;

        return $whole.'.'.str_pad((string) intdiv($fraction * (10 ** $decimals), $scale), $decimals, '0', STR_PAD_LEFT);
    }
}

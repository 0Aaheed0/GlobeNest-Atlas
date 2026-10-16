<?php

namespace App\Services;

class ExchangeRateService
{
    private const DEMO_RATES_MICROS_PER_USD = [
        'USD' => 1_000_000,
        'EUR' => 923_400,
        'GBP' => 789_300,
        'CAD' => 1_368_200,
        'AUD' => 1_527_600,
        'CNY' => 7_183_000,
        'INR' => 83_450_000,
        'BDT' => 119_200_000,
    ];

    /**
     * @return array{rates: array<string, int>, source: string, retrieved_at: string, live: bool}
     */
    public function rates(): array
    {
        return [
            'rates' => self::DEMO_RATES_MICROS_PER_USD,
            'source' => 'Atlas demonstration rate table; not a live market quote',
            'retrieved_at' => now()->toIso8601String(),
            'live' => false,
        ];
    }

    /**
     * @return list<string>
     */
    public function currencies(): array
    {
        return array_keys(self::DEMO_RATES_MICROS_PER_USD);
    }

    /**
     * @return array<string, string>
     */
    public function currencyNames(): array
    {
        return [
            'USD' => 'United States dollar',
            'EUR' => 'Euro',
            'GBP' => 'British pound sterling',
            'CAD' => 'Canadian dollar',
            'AUD' => 'Australian dollar',
            'CNY' => 'Chinese yuan',
            'INR' => 'Indian rupee',
            'BDT' => 'Bangladeshi taka',
        ];
    }
}

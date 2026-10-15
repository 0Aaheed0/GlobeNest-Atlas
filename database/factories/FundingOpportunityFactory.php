<?php

namespace Database\Factories;

use App\Models\FundingOpportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundingOpportunity>
 */
class FundingOpportunityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'International student award',
            'provider' => 'User-entered funding office',
            'potential_amount' => '1000.00',
            'currency' => 'USD',
            'deadline' => today()->addMonths(2)->toDateString(),
            'status' => 'researching',
            'source_url' => null,
        ];
    }
}

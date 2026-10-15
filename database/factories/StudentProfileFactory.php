<?php

namespace Database\Factories;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentProfile>
 */
class StudentProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'destination_country' => fake()->randomElement(['Canada', 'Australia', 'United Kingdom']),
            'home_currency' => 'USD',
            'monthly_budget' => '1800.00',
            'monthly_income' => '2500.00',
            'financial_goals' => 'Build a steady emergency fund.',
            'memory_consent' => false,
        ];
    }
}

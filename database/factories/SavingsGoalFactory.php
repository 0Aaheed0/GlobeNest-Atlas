<?php

namespace Database\Factories;

use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsGoal>
 */
class SavingsGoalFactory extends Factory
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
            'title' => fake()->randomElement(['Emergency fund', 'Tuition buffer', 'Travel fund']),
            'target_amount' => '1500.00',
            'current_amount' => '300.00',
            'currency' => 'USD',
            'target_date' => today()->addMonths(8)->toDateString(),
        ];
    }
}

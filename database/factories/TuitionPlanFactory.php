<?php

namespace Database\Factories;

use App\Models\TuitionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TuitionPlan>
 */
class TuitionPlanFactory extends Factory
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
            'title' => fake()->randomElement(['Fall tuition', 'Spring tuition', 'Housing deposit']),
            'amount' => '8000.00',
            'saved_amount' => '1200.00',
            'currency' => 'USD',
            'due_date' => today()->addMonths(6)->toDateString(),
            'installments' => 2,
        ];
    }
}

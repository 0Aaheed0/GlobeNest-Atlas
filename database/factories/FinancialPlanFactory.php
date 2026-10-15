<?php

namespace Database\Factories;

use App\Models\FinancialPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialPlan>
 */
class FinancialPlanFactory extends Factory
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
            'title' => 'Tuition savings plan',
            'summary' => 'Set aside a consistent amount each month and review the plan before the due date.',
        ];
    }
}

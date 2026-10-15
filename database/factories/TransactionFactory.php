<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'kind' => fake()->randomElement(['income', 'expense']),
            'category' => fake()->randomElement(['Housing', 'Food', 'Transit', 'Books']),
            'description' => fake()->sentence(3),
            'amount' => fake()->randomElement(['18.40', '54.00', '920.00']),
            'currency' => 'USD',
            'occurred_on' => today()->toDateString(),
            'is_simulated' => false,
        ];
    }
}

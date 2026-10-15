<?php

namespace Database\Factories;

use App\Models\AgentConversation;
use App\Models\AgentPendingAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentPendingAction>
 */
class AgentPendingActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'conversation_id' => AgentConversation::factory()->for($user),
            'tool_name' => 'save_plan',
            'arguments' => ['title' => 'A planning note', 'summary' => 'Review monthly progress and revisit assumptions.'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
            'confirmed_at' => null,
        ];
    }
}

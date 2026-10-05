<?php

namespace Database\Factories;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
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
            'subject' => fake()->sentence(5),
            'type' => 'question',
            'priority' => 'normal',
            'status' => 'new',
            'first_response_due_at' => now()->addDay(),
            'last_activity_at' => now(),
        ];
    }

    public function bug(): static
    {
        return $this->state(['type' => 'bug']);
    }
}

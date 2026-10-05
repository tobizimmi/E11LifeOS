<?php

namespace Database\Factories;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportMessage>
 */
class SupportMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_ticket_id' => SupportTicket::factory(),
            'kind' => SupportMessage::REPLY,
            'body' => fake()->paragraph(),
        ];
    }
}

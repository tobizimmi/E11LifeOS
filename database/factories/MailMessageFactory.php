<?php

namespace Database\Factories;

use App\Models\MailAccount;
use App\Models\MailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailMessage>
 */
class MailMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mail_account_id' => MailAccount::factory(),
            'folder' => 'INBOX',
            'uid' => fake()->unique()->numberBetween(1, 100000),
            'message_id' => fake()->uuid().'@example.com',
            'from_address' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'to_addresses' => fake()->safeEmail(),
            'subject' => fake()->sentence(5),
            'body_text' => fake()->paragraph(),
            'body_html' => '<p>'.fake()->paragraph().'</p>',
            'date' => fake()->dateTimeBetween('-1 month'),
            'size' => fake()->numberBetween(500, 50000),
            'is_read' => false,
            'is_flagged' => false,
            'has_attachments' => false,
        ];
    }

    public function unread(): static
    {
        return $this->state(['is_read' => false]);
    }
}

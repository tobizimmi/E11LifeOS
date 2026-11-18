<?php

namespace Database\Factories;

use App\Models\MailAccount;
use App\Models\MailDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailDraft>
 */
class MailDraftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mail_account_id' => MailAccount::factory(),
            'user_id' => User::factory(),
            'to_addresses' => fake()->safeEmail(),
            'cc' => null,
            'bcc' => null,
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'reply_to_id' => null,
        ];
    }
}

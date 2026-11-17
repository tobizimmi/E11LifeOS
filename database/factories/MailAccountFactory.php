<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailAccount>
 */
class MailAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'household_id' => Household::factory(),
            'label' => fake()->words(2, true),
            'provider' => MailAccount::PROVIDER_GENERIC,
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'validate_cert' => true,
            'username' => fake()->safeEmail(),
            'password' => fake()->password(),
            'active' => true,
        ];
    }

    public function proton(): static
    {
        return $this->state([
            'provider' => MailAccount::PROVIDER_PROTON_BRIDGE,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1143,
            'imap_encryption' => 'tls',
            'smtp_host' => '127.0.0.1',
            'smtp_port' => 1025,
            'smtp_encryption' => 'tls',
            'validate_cert' => false,
        ]);
    }
}

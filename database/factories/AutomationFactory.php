<?php

namespace Database\Factories;

use App\Models\Automation;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'name' => 'Abends Licht an',
            'active' => true,
            'trigger' => ['type' => 'time', 'at' => '18:00', 'days' => []],
            'conditions' => [],
            'actions' => [['type' => 'push', 'to' => 'me', 'title' => 'Hallo', 'body' => '']],
            'cooldown_minutes' => 1,
        ];
    }
}

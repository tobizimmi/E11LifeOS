<?php

namespace Database\Factories;

use App\Models\FocusEntry;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FocusEntry>
 */
class FocusEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'date' => now()->toDateString(),
            'title' => fake()->randomElement(['Angebot schreiben', 'Sport', 'Rechnungen prüfen', 'Konzept Deep Work', 'Team-Call vorbereiten']),
            'quadrant' => null,
        ];
    }
}

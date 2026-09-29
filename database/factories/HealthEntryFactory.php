<?php

namespace Database\Factories;

use App\Models\HealthEntry;
use App\Models\HealthProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthEntry>
 */
class HealthEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'health_profile_id' => HealthProfile::factory(),
            'kind' => HealthEntry::CHECKUP,
            'title' => 'Zahnarzt-Kontrolle',
            'interval_months' => 6,
            'next_due_on' => fake()->dateTimeBetween('now', '+6 months')->format('Y-m-d'),
        ];
    }

    /**
     * A medication taken at fixed times of the day.
     *
     * @param  list<string>  $times
     */
    public function medication(array $times = ['08:00']): static
    {
        return $this->state(fn () => ['kind' => HealthEntry::MEDICATION, 'title' => 'Ibuprofen', 'dosage' => '400 mg', 'times' => $times, 'interval_months' => null, 'next_due_on' => null]);
    }
}

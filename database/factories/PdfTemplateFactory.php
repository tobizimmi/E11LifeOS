<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\PdfTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PdfTemplate>
 */
class PdfTemplateFactory extends Factory
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
            'type' => 'general',
            'settings' => [],
        ];
    }
}

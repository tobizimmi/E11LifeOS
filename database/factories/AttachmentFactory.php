<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
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
            'path' => 'attachments/'.fake()->uuid().'.pdf',
            'original_name' => 'Dokument.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
        ];
    }
}

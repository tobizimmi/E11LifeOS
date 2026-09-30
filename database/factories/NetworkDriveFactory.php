<?php

namespace Database\Factories;

use App\Models\NetworkDrive;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkDrive>
 */
class NetworkDriveFactory extends Factory
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
            'name' => 'NAS',
            'url' => 'https://dav.example.com/home/',
            'username' => 'tobi',
            'password' => 'secret',
        ];
    }
}

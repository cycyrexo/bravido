<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'telephone' => '+22507'.fake()->unique()->numerify('########'),
            'nom' => fake()->name(),
            'telephone_verified_at' => now(),
        ];
    }
}

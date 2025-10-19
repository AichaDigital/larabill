<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Database\Factories;

use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TestUserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = TestUser::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name'              => $this->faker->name(),
            'email'             => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => Hash::make('password'),
            'remember_token'    => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

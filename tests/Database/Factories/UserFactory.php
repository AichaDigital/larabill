<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Database\Factories;

use AichaDigital\Larabill\Tests\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Factory for the test-only User model bound to the UUID `users` table.
 *
 * Replaces Orchestra\Testbench\Factories\UserFactory which defaults to
 * Illuminate\Foundation\Auth\User and lacks HasUuids — that combination
 * fails against the UUID primary key with a NOT NULL constraint violation.
 */
class UserFactory extends Factory
{
    /**
     * @var class-string<User>
     */
    protected $model = User::class;

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

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

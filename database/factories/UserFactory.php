<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Marketplace\Models\User;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /** An account that has not confirmed its email yet. */
    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function admin(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['is_admin' => true]));
    }
}

<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Legal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Users have accepted the current Terms & Privacy Policy, as anyone who
     * signed up through the app has; without this EnsureLegalAccepted would
     * redirect every signed-in test request. Gate tests use
     * withoutLegalAcceptance().
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => Legal::recordAcceptance($user, 'seed'));
    }

    /**
     * An account that hasn't accepted the current Terms & Privacy Policy.
     * (A callback rather than a flag: factory properties don't survive the
     * new instance create([...]) makes; callbacks do, and run after the
     * default one above.)
     */
    public function withoutLegalAcceptance(): static
    {
        return $this->afterCreating(fn (User $user) => $user->legalAcceptances()->delete());
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

<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The model lives in a module namespace, so the convention-based guesser
     * (Database\Factories\UserFactory -> App\Models\User) can't find it.
     *
     * @var class-string<User>
     */
    #[\Override]
    protected $model = User::class;

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
            // A ULID local part, not fake()->unique(): faker's uniqueness lasts one test (the app —
            // and its Faker — is rebuilt per test) while the suite's database is never rolled back,
            // so users pile up across tests and runs and a random address eventually repeats
            // (users_email_unique, CI run 35900519878).
            'email' => Str::lower((string) Str::ulid()).'@'.fake()->safeEmailDomain(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}

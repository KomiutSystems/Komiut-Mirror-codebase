<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Gender;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The plaintext password used by every factory-built user.
     */
    public const string PASSWORD = 'password123';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            // 10 digits, unique, matching the `digits:10` rule used by the API.
            'phone' => (string) fake()->unique()->numerify('07########'),
            'email_verified_at' => now(),
            // The User model casts `password` => `hashed`, so this is hashed on save.
            'password' => self::PASSWORD,
            'dob' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'gender_id' => Gender::factory(),
            'sacco_id' => null,
            'status' => true,
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

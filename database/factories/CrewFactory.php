<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Crew;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Crew>
 */
class CrewFactory extends Factory
{
    protected $model = Crew::class;

    /**
     * The plaintext password used by every factory-built crew.
     */
    public const string PASSWORD = 'crewpass123';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'id_number' => (string) fake()->unique()->numerify('########'),
            'badge_number' => (string) fake()->unique()->numerify('BDG####'),
            'phone' => (string) fake()->unique()->numerify('07########'),
            // Crew has NO `hashed` cast, so the hash must be applied here.
            'password' => Hash::make(self::PASSWORD),
            'image' => null,
            'user_id' => User::factory(),
            'created_by' => User::factory(),
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => false,
        ]);
    }
}

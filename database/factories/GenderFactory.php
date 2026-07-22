<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Gender;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Gender>
 */
class GenderFactory extends Factory
{
    protected $model = Gender::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // `genders.name` is unique, so never reuse a literal like "Male".
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'status' => true,
        ];
    }
}

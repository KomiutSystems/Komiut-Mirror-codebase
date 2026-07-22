<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Sacco;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sacco>
 */
class SaccoFactory extends Factory
{
    protected $model = Sacco::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'slogan' => fake()->catchPhrase(),
            'phone' => (string) fake()->numerify('07########'),
            'status' => 1,
            // Matches the base TestCase's 'testing' brand so brand-scoped queries
            // resolve factory-made saccos.
            'brand' => 'testing',
        ];
    }
}

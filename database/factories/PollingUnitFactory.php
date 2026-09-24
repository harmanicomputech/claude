<?php

namespace Database\Factories;

use App\Models\PollingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PollingUnit>
 */
class PollingUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => '11'.fake()->unique()->numerify('#######'),
            'name' => fake()->randomElement(['Community Pry Sch', 'Town Hall', 'Village Square', 'Health Centre']).' '.fake()->numerify('##'),
            'ward' => 'Ward '.fake()->numberBetween(1, 12),
            'lga' => fake()->randomElement(['Abakaliki', 'Afikpo North', 'Ezza North', 'Ikwo', 'Ohaukwu']),
            'registered_voters' => 1000,
        ];
    }
}

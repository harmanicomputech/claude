<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone_number' => '+23480'.fake()->unique()->numerify('########'),
            'polling_unit_code' => null,
            'pin' => '1234',
        ];
    }

    public function assignedTo(string $pollingUnitCode): static
    {
        return $this->state(['polling_unit_code' => $pollingUnitCode]);
    }
}

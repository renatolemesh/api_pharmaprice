<?php

namespace Database\Factories;
use App\Models\Farmacia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Link>
 */
class LinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'farmacia_id' => Farmacia::factory(),
            'link' => $this->faker->unique()->url,
        ];
    }
}

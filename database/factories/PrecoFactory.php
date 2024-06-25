<?php

namespace Database\Factories;
use App\Models\Preco;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Farmacia;
use App\Models\Produto;


/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Preco>
 */
class PrecoFactory extends Factory
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
            'produto_id' => Produto::factory(),
            'preco' => $this->faker->randomFloat(2, 1, 100),
            'data' => $this->faker->date,
        ];
    }
}

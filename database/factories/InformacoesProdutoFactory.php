<?php

namespace Database\Factories;
use App\Models\Farmacia;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InformacoesProduto>
 */
class InformacoesProdutoFactory extends Factory
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
            'link' => $this->faker->unique()->url,
            'sku' => $this->faker->unique()->uuid,
        ];
    }
}

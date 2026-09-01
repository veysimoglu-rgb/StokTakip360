<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->bothify('URN-####'),
            'barcode' => null,
            'name' => $this->faker->words(2, true),
            'unit' => 'Adet',
            'min_stock' => 0,
            'current_stock' => 0,
            'purchase_price' => 10,
            'sale_price' => 15,
            'currency' => 'TL',
            'vat_rate' => 20,
            'active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Product\Entities\Product;

class ConsignmentReceivalLineFactory extends Factory
{
    protected $model = ConsignmentReceivalLine::class;

    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 20);
        $unitCost = $this->faker->numberBetween(10000, 500000);
        $subtotal = $quantity * $unitCost;

        return [
            'consignment_receival_id' => ConsignmentReceival::factory(),
            'product_id' => Product::factory(),
            'product_name' => $this->faker->words(3, true),
            'product_code' => 'PRD-' . $this->faker->numerify('####'),
            'unit_id' => null,
            'unit_code' => 'PCS',
            'tax_id' => null,
            'tax_name' => null,
            'tax_rate' => 0,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'unit_dpp' => $unitCost,
            'subtotal_cost' => $subtotal,
            'tax_amount' => 0,
            'total_cost' => $subtotal,
            'is_serialized' => false,
            'notes' => null,
        ];
    }

    public function serialized(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_serialized' => true,
        ]);
    }
}

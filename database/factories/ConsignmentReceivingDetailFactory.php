<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Product\Entities\Product;

class ConsignmentReceivingDetailFactory extends Factory
{
    protected $model = ConsignmentReceivingDetail::class;

    public function definition(): array
    {
        $qty = $this->faker->numberBetween(1, 10);
        $cost = $this->faker->numberBetween(10000, 200000);

        return [
            'consignment_receiving_id' => ConsignmentReceiving::factory(),
            'consignment_receival_line_id' => ConsignmentReceivalLine::factory(),
            'product_id' => Product::factory(),
            'quantity_received' => $qty,
            'unit_cost' => $cost,
            'unit_dpp' => $cost,
            'tax_id' => null,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'pending_serial_numbers' => null,
            'stock_before' => 0,
            'stock_after' => $qty,
            'stock_tax_before' => 0,
            'stock_tax_after' => 0,
            'stock_non_tax_before' => 0,
            'stock_non_tax_after' => $qty,
            'setting_quantity_before' => 0,
            'setting_quantity_after' => $qty,
            'setting_avg_cost_before' => 0,
            'setting_avg_cost_after' => $cost,
        ];
    }
}

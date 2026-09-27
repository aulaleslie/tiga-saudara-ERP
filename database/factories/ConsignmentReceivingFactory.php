<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class ConsignmentReceivingFactory extends Factory
{
    protected $model = ConsignmentReceiving::class;

    public function definition(): array
    {
        return [
            'consignment_receival_id' => ConsignmentReceival::factory()->approved(),
            'setting_id' => Setting::factory(),
            'location_id' => Location::factory()->consignment(),
            'receiving_number' => 'CRN-' . $this->faker->unique()->numerify('#####'),
            'external_delivery_number' => 'SJ-' . $this->faker->numerify('#####'),
            'date' => now()->toDateString(),
            'status' => ConsignmentReceiving::STATUS_PENDING,
            'note' => $this->faker->sentence,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConsignmentReceiving::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejection_reason' => 'Barang fisik rusak/tidak sesuai',
        ]);
    }

    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConsignmentReceiving::STATUS_REVERSED,
            'approved_at' => now()->subHour(),
            'reversed_at' => now(),
            'reversal_reason' => 'Salah input lokasi',
        ]);
    }
}

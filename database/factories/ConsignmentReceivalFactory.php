<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;

class ConsignmentReceivalFactory extends Factory
{
    protected $model = ConsignmentReceival::class;

    public function definition(): array
    {
        return [
            'setting_id' => Setting::factory(),
            'supplier_id' => Supplier::factory(),
            'reference' => 'CR-' . $this->faker->unique()->numerify('#####'),
            'supplier_delivery_reference' => 'SJ-' . $this->faker->numerify('#####'),
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_DRAFT,
            'note' => $this->faker->sentence,
        ];
    }

    public function waitingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConsignmentReceival::STATUS_WAITING_APPROVAL,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConsignmentReceival::STATUS_APPROVED,
            'submitted_at' => now()->subHour(),
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConsignmentReceival::STATUS_REJECTED,
            'submitted_at' => now()->subHour(),
            'rejected_at' => now(),
            'rejection_reason' => 'Data tidak sesuai',
        ]);
    }
}

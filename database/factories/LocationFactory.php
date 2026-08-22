<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Setting\Entities\Location;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->city,
            'setting_id' => \Modules\Setting\Entities\Setting::factory(),
            'is_consignment' => false,
        ];
    }

    public function consignment(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_consignment' => true,
        ]);
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\PosSession;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;

class PosSessionFactory extends Factory
{
    protected $model = PosSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'setting_id' => Setting::factory(),
            'location_id' => Location::factory(),
            'device_name' => $this->faker->userAgent,
            'cash_float' => 100000,
            'status' => 'active',
            'started_at' => now(),
        ];
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;

class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return [
            'company_name' => $this->faker->company,
            'company_email' => $this->faker->email,
            'company_phone' => $this->faker->phoneNumber,
            'default_currency_id' => Currency::factory()->state(['code' => 'IDR', 'symbol' => 'Rp']),
            'default_currency_position' => 'prefix',
            'notification_email' => $this->faker->email,
            'footer_text' => $this->faker->sentence,
            'company_address' => $this->faker->address,
        ];
    }
}

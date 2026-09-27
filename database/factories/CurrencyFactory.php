<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Currency\Entities\Currency;

class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        return [
            'currency_name' => $this->faker->currencyCode,
            'code' => $this->faker->currencyCode,
            'symbol' => '$',
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'exchange_rate' => 1,
        ];
    }
}

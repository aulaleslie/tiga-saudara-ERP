<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductPriceAccessorsTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);
    }

    public function test_accessors_return_values_from_price_row(): void
    {
        $settingA = $this->createSetting('Tenant A');
        $settingB = $this->createSetting('Tenant B');

        $product = Product::create([
            'setting_id'              => $settingA->id,
            'product_name'            => 'Scoped Product',
            'product_code'            => 'CODE-A',
            'product_quantity'        => 5,
            'product_cost'            => 0,
            'product_price'           => 0,
            'product_stock_alert'     => 0,
            'is_purchased'            => true,
            'is_sold'                 => true,
            'sale_price'              => 9999,
            'tier_1_price'            => 9999,
            'tier_2_price'            => 9999,
            'last_purchase_price'     => 9999,
            'average_purchase_price'  => 9999,
        ]);

        ProductPrice::create([
            'product_id'             => $product->id,
            'setting_id'             => $settingA->id,
            'sale_price'             => '123.45',
            'tier_1_price'           => '223.45',
            'tier_2_price'           => '323.45',
            'last_purchase_price'    => '423.45',
            'average_purchase_price' => '523.45',
        ]);

        ProductPrice::create([
            'product_id'             => $product->id,
            'setting_id'             => $settingB->id,
            'sale_price'             => '999.99',
            'tier_1_price'           => '888.88',
            'tier_2_price'           => '777.77',
            'last_purchase_price'    => '666.66',
            'average_purchase_price' => '555.55',
        ]);

        $this->assertSame('123.45', $product->salePrice());
        $this->assertSame('223.45', $product->tier1Price());
        $this->assertSame('323.45', $product->tier2Price());
        $this->assertSame('423.45', $product->lastPurchasePrice());
        $this->assertSame('523.45', $product->averagePurchasePrice());

        $this->assertSame('999.99', $product->salePrice($settingB->id));
        $this->assertSame('888.88', $product->tier1Price($settingB->id));
        $this->assertSame('777.77', $product->tier2Price($settingB->id));
        $this->assertSame('666.66', $product->lastPurchasePrice($settingB->id));
        $this->assertSame('555.55', $product->averagePurchasePrice($settingB->id));
    }

    public function test_accessors_return_null_when_price_row_missing(): void
    {
        $setting = $this->createSetting('Tenant C');

        $product = Product::create([
            'setting_id'             => $setting->id,
            'product_name'           => 'Unpriced Product',
            'product_code'           => 'CODE-C',
            'product_quantity'       => 0,
            'product_cost'           => 0,
            'product_price'          => 0,
            'product_stock_alert'    => 0,
            'sale_price'             => 1234,
            'tier_1_price'           => 1234,
            'tier_2_price'           => 1234,
            'last_purchase_price'    => 1234,
            'average_purchase_price' => 1234,
        ]);

        $this->assertNull($product->salePrice());
        $this->assertNull($product->tier1Price());
        $this->assertNull($product->tier2Price());
        $this->assertNull($product->lastPurchasePrice());
        $this->assertNull($product->averagePurchasePrice());
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name'              => $name,
            'company_email'             => strtolower(str_replace(' ', '', $name)) . '@example.com',
            'company_phone'             => '123456789',
            'site_logo'                 => null,
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'left',
            'notification_email'        => 'notify@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => 'Address',
        ]);
    }
}

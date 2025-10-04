<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductPriceViewTest extends TestCase
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

    public function test_show_uses_per_setting_price_row_when_available(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse();

        $setting = $this->createSetting('Active Co');
        $user    = User::factory()->create();

        $product = Product::create([
            'setting_id'              => $setting->id,
            'product_name'            => 'Test Product',
            'product_code'            => 'CODE-1',
            'product_quantity'        => 5,
            'product_cost'            => 0,
            'product_price'           => 0,
            'product_stock_alert'     => 0,
            'is_purchased'            => false,
            'is_sold'                 => false,
            'sale_price'              => 9999, // Should be ignored
            'tier_1_price'            => 8888, // Should be ignored
            'tier_2_price'            => 7777, // Should be ignored
            'last_purchase_price'     => 6666, // Should be ignored
            'average_purchase_price'  => 5555, // Should be ignored
        ]);

        $priceRow = ProductPrice::create([
            'product_id'             => $product->id,
            'setting_id'             => $setting->id,
            'sale_price'             => 1234,
            'tier_1_price'           => 2234,
            'tier_2_price'           => 3234,
            'last_purchase_price'    => 4234,
            'average_purchase_price' => 5234,
        ]);

        $this->actingAs($user);
        $this->withoutMiddleware([CheckUserRoleForSetting::class]);

        $response = $this->withSession(['setting_id' => $setting->id])
            ->get(route('products.show', $product));

        $response->assertOk();
        $response->assertViewIs('product::products.show');
        $response->assertViewHas('price', function ($price) use ($priceRow) {
            $this->assertSame($priceRow->sale_price, $price->sale_price);
            $this->assertSame($priceRow->tier_1_price, $price->tier_1_price);
            $this->assertSame($priceRow->tier_2_price, $price->tier_2_price);
            $this->assertSame($priceRow->last_purchase_price, $price->last_purchase_price);
            $this->assertSame($priceRow->average_purchase_price, $price->average_purchase_price);

            return true;
        });
    }

    public function test_show_defaults_to_zero_when_price_row_missing(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse();

        $setting = $this->createSetting('Defaultless Co');
        $user    = User::factory()->create();

        $product = Product::create([
            'setting_id'             => $setting->id,
            'product_name'           => 'No Price Product',
            'product_code'           => 'CODE-2',
            'product_quantity'       => 5,
            'product_cost'           => 0,
            'product_price'          => 0,
            'product_stock_alert'    => 0,
            'sale_price'             => 9999, // Should not leak through
            'tier_1_price'           => 8888,
            'tier_2_price'           => 7777,
            'last_purchase_price'    => 6666,
            'average_purchase_price' => 5555,
        ]);

        $this->actingAs($user);
        $this->withoutMiddleware([CheckUserRoleForSetting::class]);

        $response = $this->withSession(['setting_id' => $setting->id])
            ->get(route('products.show', $product));

        $response->assertOk();
        $response->assertViewHas('price', function ($price) {
            $this->assertSame(0, $price->sale_price);
            $this->assertSame(0, $price->tier_1_price);
            $this->assertSame(0, $price->tier_2_price);
            $this->assertSame(0, $price->last_purchase_price);
            $this->assertSame(0, $price->average_purchase_price);
            $this->assertNull($price->purchase_tax_id);
            $this->assertNull($price->sale_tax_id);

            return true;
        });
    }

    public function test_edit_uses_per_setting_price_row_when_available(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse();

        $setting = $this->createSetting('Editable Co');
        $user    = User::factory()->create();

        $product = Product::create([
            'setting_id'          => $setting->id,
            'product_name'        => 'Editable Product',
            'product_code'        => 'CODE-3',
            'product_quantity'    => 5,
            'product_cost'        => 0,
            'product_price'       => 0,
            'product_stock_alert' => 0,
        ]);

        $priceRow = ProductPrice::create([
            'product_id'          => $product->id,
            'setting_id'          => $setting->id,
            'sale_price'          => 4321,
            'tier_1_price'        => 3321,
            'tier_2_price'        => 2321,
            'last_purchase_price' => 1321,
        ]);

        $this->actingAs($user);
        $this->withoutMiddleware([CheckUserRoleForSetting::class]);

        $response = $this->withSession(['setting_id' => $setting->id])
            ->get(route('products.edit', $product));

        $response->assertOk();
        $response->assertViewIs('product::products.edit');
        $response->assertViewHas('price', function ($price) use ($priceRow) {
            $this->assertSame($priceRow->last_purchase_price, $price->purchase_price);
            $this->assertSame($priceRow->sale_price, $price->sale_price);
            $this->assertSame($priceRow->tier_1_price, $price->tier_1_price);
            $this->assertSame($priceRow->tier_2_price, $price->tier_2_price);

            return true;
        });
    }

    public function test_edit_defaults_to_zero_when_price_row_missing(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse();

        $setting = $this->createSetting('Zero Co');
        $user    = User::factory()->create();

        $product = Product::create([
            'setting_id'          => $setting->id,
            'product_name'        => 'Zero Product',
            'product_code'        => 'CODE-4',
            'product_quantity'    => 5,
            'product_cost'        => 0,
            'product_price'       => 0,
            'product_stock_alert' => 0,
        ]);

        $this->actingAs($user);
        $this->withoutMiddleware([CheckUserRoleForSetting::class]);

        $response = $this->withSession(['setting_id' => $setting->id])
            ->get(route('products.edit', $product));

        $response->assertOk();
        $response->assertViewHas('price', function ($price) {
            $this->assertSame(0, $price->purchase_price);
            $this->assertSame(0, $price->sale_price);
            $this->assertSame(0, $price->tier_1_price);
            $this->assertSame(0, $price->tier_2_price);
            $this->assertNull($price->purchase_tax_id);
            $this->assertNull($price->sale_tax_id);

            return true;
        });
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


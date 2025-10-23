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

class ProductPriceCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_store_creates_price_rows_for_all_settings(): void
    {
        Gate::shouldReceive('denies')->with('products.create')->andReturnFalse();
        Gate::shouldReceive('allows')->with('products.create')->andReturnTrue();

        $currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $activeSetting = Setting::create([
            'company_name'              => 'Active Co',
            'company_email'             => 'active@example.com',
            'company_phone'             => '123456789',
            'site_logo'                 => null,
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'left',
            'notification_email'        => 'notify@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => 'Address 1',
        ]);

        $inactiveSetting = Setting::create([
            'company_name'              => 'Inactive Co',
            'company_email'             => 'inactive@example.com',
            'company_phone'             => '987654321',
            'site_logo'                 => null,
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'left',
            'notification_email'        => 'notify@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => 'Address 2',
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->withoutMiddleware([CheckUserRoleForSetting::class]);

        $payload = [
            'product_name'  => 'Test Product',
            'product_code'  => 'CODE123',
            'is_purchased'  => true,
            'purchase_price'=> 1000,
            'is_sold'       => true,
            'sale_price'    => 2000,
            'tier_1_price'  => 1500,
            'tier_2_price'  => 1800,
        ];

        $response = $this->withSession(['setting_id' => $activeSetting->id])
            ->post(route('products.store'), $payload);

        $response->assertRedirect(route('products.index'));

        $product = Product::first();
        $this->assertNotNull($product);

        $this->assertSame(2, ProductPrice::count());

        foreach ([$activeSetting->id, $inactiveSetting->id] as $settingId) {
            $this->assertDatabaseHas('product_prices', [
                'product_id' => $product->id,
                'setting_id' => $settingId,
                'sale_price' => 2000,
            ]);
        }
    }
}

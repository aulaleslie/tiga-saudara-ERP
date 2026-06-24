<?php

namespace Tests\Feature;

use App\Livewire\PricePoint\Browser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PricePointBrowserCustomerTieringTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $setting;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'                  => 'Test Outlet',
            'company_email'                 => 'test@example.com',
            'company_phone'                 => '123456789',
            'notification_email'            => 'notify@example.com',
            'default_currency_id'           => $this->currency->id,
            'default_currency_position'     => 'prefix',
            'footer_text'                   => 'Footer',
            'company_address'               => 'Address'
        ]);

        $this->user = User::factory()->create();
    }

    private function createProduct(string $name = 'Test Product', int $settingId = null): Product
    {
        return Product::create([
            'setting_id'              => $settingId ?? $this->setting->id,
            'product_name'            => $name,
            'product_code'            => 'CODE-' . uniqid(),
            'product_quantity'        => 5,
            'product_cost'            => 0,
            'product_price'           => 0,
            'product_stock_alert'     => 0,
            'is_purchased'            => false,
            'is_sold'                 => false,
            'sale_price'              => 0,
            'tier_1_price'            => 0,
            'tier_2_price'            => 0,
        ]);
    }

    private function createProductPrice(
        Product $product,
        int $salePrice,
        ?int $tier1Price = null,
        ?int $tier2Price = null,
        int $settingId = null
    ): ProductPrice {
        return ProductPrice::create([
            'product_id'             => $product->id,
            'setting_id'             => $settingId ?? $this->setting->id,
            'sale_price'             => $salePrice,
            'tier_1_price'           => $tier1Price ?? 0,
            'tier_2_price'           => $tier2Price ?? 0,
        ]);
    }

    private function createCustomer(string $name, ?string $contactName = null, ?string $tier = null, ?int $settingId = null): Customer
    {
        return Customer::create([
            'customer_name'          => $name,
            'contact_name'           => $contactName,
            'setting_id'             => $settingId,
            'tier'                   => $tier,
        ]);
    }

    public function test_default_display_shows_sale_price_without_tiers(): void
    {
        $product = $this->createProduct('Widget');
        $this->createProductPrice($product, salePrice: 100000, tier1Price: 80000, tier2Price: 60000);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        // No customer selected, so should show sale_price
        $this->assertNull($component->viewData('selectedCustomerId'));
        $products = $component->viewData('products');
        $this->assertTrue($products->count() > 0);

        // Verify contextual price is sale_price
        $firstProduct = $products->first();
        $this->assertEquals(100000, $firstProduct->contextual_price['price']);
        $this->assertEquals('Umum', $firstProduct->contextual_price['label']);

        // Verify all tier prices are available for resolver fallback
        $this->assertEquals(80000, $firstProduct->display_tier_1_price);
        $this->assertEquals(60000, $firstProduct->display_tier_2_price);
    }

    public function test_global_customer_search_includes_customer_with_different_setting_id(): void
    {
        $otherSetting = Setting::create([
            'company_name'                  => 'Other Outlet',
            'company_email'                 => 'other@example.com',
            'company_phone'                 => '987654321',
            'notification_email'            => 'notify2@example.com',
            'default_currency_id'           => $this->currency->id,
            'default_currency_position'     => 'prefix',
            'footer_text'                   => 'Footer',
            'company_address'               => 'Address'
        ]);

        $customerInOtherSetting = $this->createCustomer(
            'Cross-Setting Customer',
            tier: 'WHOLESALER',
            settingId: $otherSetting->id
        );

        $product = $this->createProduct('Widget');
        $this->createProductPrice($product, salePrice: 100000, tier1Price: 80000);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->call('searchCustomers', 'Cross-Setting');

        $customerResults = $component->viewData('customerSearchResults') ?? [];
        $this->assertTrue(
            collect($customerResults)->pluck('id')->contains($customerInOtherSetting->id),
            'Customer from different setting should appear in global search results'
        );
    }

    public function test_global_customer_search_includes_customer_by_contact_name(): void
    {
        $customer = $this->createCustomer(
            'Business Name',
            contactName: 'John Contact Name',
            tier: 'WHOLESALER'
        );

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->call('searchCustomers', 'John');

        $customerResults = $component->viewData('customerSearchResults') ?? [];
        $this->assertTrue(
            collect($customerResults)->pluck('id')->contains($customer->id),
            'Customer should be searchable by contact_name'
        );
    }

    public function test_wholesaler_displays_tier_1_price_when_positive(): void
    {
        $customer = $this->createCustomer('Wholesale Buyer', tier: 'WHOLESALER');
        $product = $this->createProduct('Widget');
        $this->createProductPrice($product, salePrice: 100000, tier1Price: 80000);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->call('selectCustomer', $customer->id);

        $this->assertEquals($customer->id, $component->viewData('selectedCustomerId'));
        $this->assertEquals('WHOLESALER', $component->viewData('selectedCustomerTier'));

        // Verify contextual price is tier_1_price with Grosir label
        $products = $component->viewData('products');
        $firstProduct = $products->first();
        $this->assertEquals(80000, $firstProduct->contextual_price['price']);
        $this->assertEquals('Grosir', $firstProduct->contextual_price['label']);
    }

    public function test_wholesaler_falls_back_to_sale_price_when_tier_1_missing(): void
    {
        $customer = $this->createCustomer('Wholesale Buyer', tier: 'WHOLESALER');
        $product = $this->createProduct('Widget');
        $this->createProductPrice($product, salePrice: 100000, tier1Price: 0);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->call('selectCustomer', $customer->id);

        $this->assertEquals($customer->id, $component->viewData('selectedCustomerId'));
        $this->assertEquals('WHOLESALER', $component->viewData('selectedCustomerTier'));

        // Verify contextual price falls back to sale_price with Umum label
        $products = $component->viewData('products');
        $firstProduct = $products->first();
        $this->assertEquals(100000, $firstProduct->contextual_price['price']);
        $this->assertEquals('Umum', $firstProduct->contextual_price['label']);
    }

    public function test_reseller_displays_tier_2_price_when_positive(): void
    {
        $customer = $this->createCustomer('Reseller', tier: 'RESELLER');
        $product = $this->createProduct('Widget');
        $this->createProductPrice($product, salePrice: 100000, tier2Price: 60000);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->call('selectCustomer', $customer->id);

        $this->assertEquals($customer->id, $component->viewData('selectedCustomerId'));
        $this->assertEquals('RESELLER', $component->viewData('selectedCustomerTier'));

        // Verify contextual price is tier_2_price with Reseller label
        $products = $component->viewData('products');
        $firstProduct = $products->first();
        $this->assertEquals(60000, $firstProduct->contextual_price['price']);
        $this->assertEquals('Reseller', $firstProduct->contextual_price['label']);
    }

    public function test_reseller_falls_back_to_sale_price_when_tier_2_missing(): void
    {
        $customer = $this->createCustomer('Reseller', tier: 'RESELLER');
        $product = $this->createProduct('Widget');
        $this->createProductPrice($product, salePrice: 100000, tier2Price: 0);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->call('selectCustomer', $customer->id);

        $this->assertEquals($customer->id, $component->viewData('selectedCustomerId'));
        $this->assertEquals('RESELLER', $component->viewData('selectedCustomerTier'));

        // Verify contextual price falls back to sale_price with Umum label
        $products = $component->viewData('products');
        $firstProduct = $products->first();
        $this->assertEquals(100000, $firstProduct->contextual_price['price']);
        $this->assertEquals('Umum', $firstProduct->contextual_price['label']);
    }

    public function test_customer_without_recognized_tier_uses_sale_price(): void
    {
        $customer = $this->createCustomer('Regular Customer', tier: null);
        $product = $this->createProduct('Widget');
        $this->createProductPrice($product, salePrice: 100000, tier1Price: 80000, tier2Price: 60000);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->call('selectCustomer', $customer->id);

        $this->assertEquals($customer->id, $component->viewData('selectedCustomerId'));
        $this->assertNull($component->viewData('selectedCustomerTier'));

        // Verify contextual price is sale_price with Umum label
        $products = $component->viewData('products');
        $firstProduct = $products->first();
        $this->assertEquals(100000, $firstProduct->contextual_price['price']);
        $this->assertEquals('Umum', $firstProduct->contextual_price['label']);
    }

    public function test_clearing_customer_restores_sale_price_and_resets_pagination(): void
    {
        $customer = $this->createCustomer('Wholesale Buyer', tier: 'WHOLESALER');
        $product = $this->createProduct('Widget');
        $this->createProductPrice($product, salePrice: 100000, tier1Price: 80000);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        $component->call('selectCustomer', $customer->id);
        $this->assertEquals($customer->id, $component->viewData('selectedCustomerId'));

        $component->call('clearCustomer');
        $this->assertNull($component->viewData('selectedCustomerId'));
        $this->assertEquals(1, $component->viewData('products')->currentPage());
    }

    public function test_empty_contact_name_falls_back_to_customer_name(): void
    {
        // contact_name is empty string, should fall back to customer_name
        $customer = $this->createCustomer(
            'Business Name',
            contactName: '',
            tier: 'WHOLESALER'
        );

        $product = $this->createProduct('Widget');
        $this->createProductPrice($product, salePrice: 100000, tier1Price: 80000);

        $component = Livewire::actingAs($this->user)
            ->test(Browser::class, ['setting' => $this->setting]);

        // Test search results show customer_name, not empty contact_name
        $component->call('searchCustomers', 'Business');
        $customerResults = $component->viewData('customerSearchResults') ?? [];
        $found = collect($customerResults)->first(fn ($c) => $c['id'] === $customer->id);

        $this->assertNotNull($found, 'Customer should be found in search results');
        // BaseModel uppercases text, so expect uppercase
        $this->assertEquals('BUSINESS NAME', $found['label'], 'Label should be customer_name, not empty contact_name');

        // Test selectCustomer also shows customer_name
        $component->call('selectCustomer', $customer->id);
        $this->assertEquals('BUSINESS NAME', $component->viewData('selectedCustomerLabel'));
    }
}

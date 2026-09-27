<?php

namespace Tests\Feature;

use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PurchaseSalePkpValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_purchase_store_requires_tax_per_line_when_pkp_enabled(): void
    {
        [$setting, $user, $supplier, $paymentTerm] = $this->createPurchaseSetup(true);
        $product = $this->createProduct($setting);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('purchases.store'), [
                'supplier_id' => $supplier->id,
                'reference' => 'PR-MANUAL-001',
                'date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => 100000,
                'payment_term' => $paymentTerm->id,
                'cart' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 100000,
                        'discount_type' => 'fixed',
                        'discount' => 0,
                        'tax_id' => null,
                    ],
                ],
            ]);

        $response->assertSessionHasErrors(['cart']);
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_sale_store_requires_tax_per_line_when_pkp_enabled(): void
    {
        [$setting, $user, $customer, $paymentTerm] = $this->createSaleSetup(true);
        $product = $this->createProduct($setting);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'SALE-LINE-1',
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 100000,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 100000,
                'sub_total_before_tax' => 100000,
                'code' => $product->product_code,
                'stock' => 10,
                'unit' => 'PCS',
                'product_tax' => null,
                'unit_price' => 100000,
            ],
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.store'), [
                'customer_id' => $customer->id,
                'date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => 100000,
                'payment_term_id' => $paymentTerm->id,
            ]);

        $response->assertSessionHasErrors(['cart']);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_purchase_store_allows_non_tax_line_when_pkp_disabled(): void
    {
        [$setting, $user, $supplier, $paymentTerm] = $this->createPurchaseSetup(false);
        $product = $this->createProduct($setting);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('purchases.store'), [
                'supplier_id' => $supplier->id,
                'reference' => 'PR-MANUAL-002',
                'date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => 100000,
                'payment_term' => $paymentTerm->id,
                'cart' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 100000,
                        'discount_type' => 'fixed',
                        'discount' => 0,
                        'tax_id' => null,
                    ],
                ],
            ]);

        $response->assertRedirect(route('purchases.index'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchases', [
            'supplier_id' => $supplier->id,
            'setting_id' => $setting->id,
        ]);
    }

    private function createPurchaseSetup(bool $isPkp): array
    {
        $setting = $this->createSetting($isPkp);
        $user = User::factory()->create();

        Permission::firstOrCreate(['name' => 'purchases.create']);
        $user->givePermissionTo('purchases.create');

        $paymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
        ]);

        $supplier = Supplier::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => $paymentTerm->id,
        ]);

        return [$setting, $user, $supplier, $paymentTerm];
    }

    private function createSaleSetup(bool $isPkp): array
    {
        $setting = $this->createSetting($isPkp);
        $user = User::factory()->create();

        Permission::firstOrCreate(['name' => 'sales.create']);
        $user->givePermissionTo('sales.create');

        $paymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
        ]);

        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => $paymentTerm->id,
        ]);

        return [$setting, $user, $customer, $paymentTerm];
    }

    private function createSetting(bool $isPkp): Setting
    {
        return Setting::create([
            'company_name' => 'TEST COMPANY ' . ($isPkp ? 'PKP' : 'NONPKP'),
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
            'is_pkp' => $isPkp,
        ]);
    }

    private function createProduct(Setting $setting): Product
    {
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;

        $category = \Modules\Product\Entities\Category::create([
            'category_name' => 'Category',
            'category_code' => 'CAT',
            'setting_id' => $setting->id,
            'created_by' => $createdBy,
        ]);

        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Piece',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $setting->id,
        ]);

        $location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'PRD-' . uniqid(),
            'setting_id' => $setting->id,
            'product_quantity' => 100,
            'product_cost' => 100000,
            'product_price' => 120000,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
            'stock_managed' => true,
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
            'quantity_tax' => 0,
            'quantity_non_tax' => 100,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);

        return $product;
    }
}

<?php

namespace Tests\Feature\Sale;

use App\Models\User;
use Carbon\Carbon;
use Exception;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Services\SaleService;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SaleAtomicCrossBusinessUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $activeBusiness;
    private Setting $targetBusiness;
    private User $user;
    private Customer $customer;
    private Product $product;
    private PaymentTerm $paymentTerm;
    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->activeBusiness = Setting::create([
            'company_name' => 'Active Business',
            'company_email' => 'active@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify-active@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Active St',
            'is_pkp' => false,
            'document_prefix' => 'AB',
            'sale_prefix_document' => 'SL',
        ]);

        $this->targetBusiness = Setting::create([
            'company_name' => 'Target Business',
            'company_email' => 'target@example.com',
            'company_phone' => '654321',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify-target@example.com',
            'footer_text' => 'Footer',
            'company_address' => '456 Target Ave',
            'is_pkp' => false,
            'document_prefix' => 'TB',
            'sale_prefix_document' => 'SL',
        ]);

        $this->user = User::factory()->create();
        Permission::create(['name' => 'documents.business.override']);
        $this->user->givePermissionTo('documents.business.override');

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '222222',
            'address' => 'Customer Address',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->activeBusiness->id,
        ]);

        $category = Category::create([
            'created_by' => $this->user->id,
            'category_name' => 'Test Category',
            'category_code' => 'TC01',
            'setting_id' => $this->activeBusiness->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'setting_id' => $this->activeBusiness->id,
            'category_id' => $category->id,
            'product_quantity' => 100,
            'product_cost' => 100000,
            'product_price' => 150000,
        ]);

        $this->paymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->activeBusiness->id,
        ]);

        // Create a draft sale
        $this->sale = Sale::create([
            'setting_id' => $this->activeBusiness->id,
            'reference' => 'AB-SL-2026-08-00001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now(),
            'due_date' => now(),
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 150000,
            'due_amount' => 150000,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
            'is_tax_included' => false,
        ]);
    }

    public function test_sale_cross_business_update_is_atomic_with_reference_allocation(): void
    {
        $this->actingAs($this->user);
        session()->put('setting_id', $this->activeBusiness->id);

        // Prepare cart
        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 150000,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'product_discount' => 0,
                'product_discount_type' => 'percentage',
                'sub_total' => 150000,
                'sub_total_before_tax' => 150000,
                'product_tax_amount' => 0,
                'code' => $this->product->product_code,
                'stock' => $this->product->product_quantity,
                'unit' => 'pcs',
                'unit_price' => 150000,
                'sale_price' => 150000,
                'tier_1_price' => 150000,
                'tier_2_price' => 150000,
            ],
        ]);

        $originalSettingId = $this->sale->setting_id;
        $originalReference = $this->sale->reference;

        // Update with business change
        $service = app(SaleService::class);
        $service->updateSale($this->sale, [
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $this->customer->id,
            'setting_id' => $this->targetBusiness->id,
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Sale::STATUS_DRAFTED,
        ], Cart::instance('sale')->content());

        // Verify sale was moved to new business with new reference
        $this->sale->refresh();
        $this->assertNotEquals($originalSettingId, $this->sale->setting_id);
        $this->assertEquals($this->targetBusiness->id, $this->sale->setting_id);
        $this->assertNotEquals($originalReference, $this->sale->reference);
        $this->assertStringStartsWith('TB-SL', $this->sale->reference);
    }

    public function test_sale_cross_business_update_rolls_back_on_failure(): void
    {
        $this->actingAs($this->user);
        session()->put('setting_id', $this->activeBusiness->id);

        // Create a sale detail to verify it survives rollback
        $detail = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $this->sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'unit_price' => 150000,
            'price' => 150000,
            'product_discount_type' => 'percentage',
            'product_discount_amount' => 0,
            'sub_total' => 150000,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Prepare cart
        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 150000,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'product_discount' => 0,
                'product_discount_type' => 'percentage',
                'sub_total' => 150000,
                'sub_total_before_tax' => 150000,
                'product_tax_amount' => 0,
                'code' => $this->product->product_code,
                'stock' => $this->product->product_quantity,
                'unit' => 'pcs',
                'unit_price' => 150000,
                'sale_price' => 150000,
                'tier_1_price' => 150000,
                'tier_2_price' => 150000,
            ],
        ]);

        $originalSettingId = $this->sale->setting_id;
        $originalReference = $this->sale->reference;
        $originalDetailCount = $this->sale->saleDetails()->count();

        // Inject a mock SalesCostSnapshotService that throws during detail save
        // This failure occurs AFTER reference has been allocated and header updated,
        // simulating a late persistence failure during detail creation loop
        $this->app->bind(\Modules\Sale\Services\SalesCostSnapshotService::class, function () {
            return new class {
                private int $callCount = 0;

                public function snapshotSaleDetailCost($detail) {
                    $this->callCount++;
                    // Fail on the first detail to simulate failure after lock+reference allocation
                    if ($this->callCount === 1) {
                        throw new Exception('Simulated detail save failure after reference allocation');
                    }
                }
            };
        });

        $service = app(SaleService::class);

        try {
            $service->updateSale($this->sale, [
                'date' => now()->format('Y-m-d'),
                'due_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'setting_id' => $this->targetBusiness->id,
                'payment_term_id' => $this->paymentTerm->id,
                'status' => Sale::STATUS_DRAFTED,
            ], Cart::instance('sale')->content());
        } catch (Exception $e) {
            // Expected failure during detail replacement
        }

        // Verify entire transaction rolled back: original sale state preserved
        $this->sale->refresh();
        $this->assertEquals($originalSettingId, $this->sale->setting_id,
            'Sale setting_id should not have changed after failed update');
        $this->assertEquals($originalReference, $this->sale->reference,
            'Sale reference should not have changed after failed update');
        $this->assertEquals($originalDetailCount, $this->sale->saleDetails()->count(),
            'Sale detail count should be preserved after rollback');

        // Verify original detail still exists (new details not created)
        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $this->sale->id,
            'product_id' => $detail->product_id,
            'id' => $detail->id,
        ]);
    }

    public function test_multiple_concurrent_cross_business_updates_get_sequential_references(): void
    {
        $this->actingAs($this->user);
        session()->put('setting_id', $this->activeBusiness->id);

        $references = [];

        // Create and move 3 sales to target business
        for ($i = 0; $i < 3; $i++) {
            $sale = Sale::create([
                'setting_id' => $this->activeBusiness->id,
                'reference' => "AB-SL-2026-08-" . str_pad(10000 + $i, 5, '0', STR_PAD_LEFT),
                'customer_id' => $this->customer->id,
                'customer_name' => $this->customer->customer_name,
                'date' => now(),
                'due_date' => now(),
                'status' => Sale::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'total_amount' => 150000,
                'due_amount' => 150000,
                'paid_amount' => 0,
                'payment_method' => 'Cash',
                'is_tax_included' => false,
            ]);

            Cart::instance('sale')->destroy();
            Cart::instance('sale')->add([
                'id' => $this->product->id,
                'name' => $this->product->product_name,
                'qty' => 1,
                'price' => 150000,
                'weight' => 1,
                'options' => [
                    'product_id' => $this->product->id,
                    'product_discount' => 0,
                    'product_discount_type' => 'percentage',
                    'sub_total' => 150000,
                    'sub_total_before_tax' => 150000,
                    'product_tax_amount' => 0,
                    'code' => $this->product->product_code,
                    'stock' => $this->product->product_quantity,
                    'unit' => 'pcs',
                    'unit_price' => 150000,
                    'sale_price' => 150000,
                    'tier_1_price' => 150000,
                    'tier_2_price' => 150000,
                ],
            ]);

            $service = app(SaleService::class);
            $service->updateSale($sale, [
                'date' => now()->format('Y-m-d'),
                'due_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'setting_id' => $this->targetBusiness->id,
                'payment_term_id' => $this->paymentTerm->id,
                'status' => Sale::STATUS_DRAFTED,
            ], Cart::instance('sale')->content());

            $sale->refresh();
            $references[] = $sale->reference;
        }

        // Verify all references start with target prefix and are sequential
        foreach ($references as $ref) {
            $this->assertStringStartsWith('TB-SL', $ref);
        }

        // Extract numbers from references
        $numbers = array_map(function ($ref) {
            $parts = explode('-', $ref);
            return (int) end($parts);
        }, $references);

        // Verify sequential
        for ($i = 1; $i < count($numbers); $i++) {
            $this->assertEquals($numbers[$i - 1] + 1, $numbers[$i]);
        }
    }
}

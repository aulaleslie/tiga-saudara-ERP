<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;

class PurchaseReturnApprovalStockValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;
    private Supplier $supplier;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckUserRoleForSetting::class,
            VerifyCsrfToken::class,
        ]);

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'test@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
        ]);

        $this->user = User::factory()->create();
        $this->supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);
        
        session(['setting_id' => $this->setting->id]);
    }

    private function setupData()
    {
        $category = Category::create([
            'category_name' => 'General',
            'category_code' => 'GEN',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP01',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        return $product;
    }

    public function test_approval_succeeds_when_stock_is_sufficient(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();
        $product = $this->setupData();

        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'approval_status' => 'pending',
            'status' => 'Pending Approval',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'location_id' => $this->location->id,
            'price' => 50000,
            'unit_price' => 10000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('purchase-returns.approve', $purchaseReturn));

        $response->assertStatus(302);
        $this->assertEquals('approved', strtolower($purchaseReturn->fresh()->approval_status));
    }

    public function test_approval_fails_when_stock_is_insufficient(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();
        $product = $this->setupData();

        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'approval_status' => 'pending',
            'status' => 'Pending Approval',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 15, // More than 10 available
            'location_id' => $this->location->id,
            'price' => 150000,
            'unit_price' => 10000,
            'sub_total' => 150000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('purchase-returns.approve', $purchaseReturn));

        $response->assertStatus(302);
        $this->assertEquals('pending', strtolower($purchaseReturn->fresh()->approval_status));
    }

    public function test_approval_fails_when_serial_moved_to_different_location(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();
        $product = $this->setupData();
        
        $otherLocation = Location::create([
            'name' => 'Other Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $sn = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-APPROVAL-TEST',
            'status' => 'active',
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'approval_status' => 'pending',
            'status' => 'Pending Approval',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'location_id' => $this->location->id,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$sn->id],
        ]);

        // Manually move serial to another location before approval
        $sn->update(['location_id' => $otherLocation->id]);

        $response = $this->actingAs($this->user)
            ->post(route('purchase-returns.approve', $purchaseReturn));

        $response->assertStatus(302);
        $this->assertEquals('pending', strtolower($purchaseReturn->fresh()->approval_status));
    }
}

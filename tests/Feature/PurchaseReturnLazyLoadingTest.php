<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\PurchasesReturn\Entities\PurchaseReturnSettlement;
use Tests\TestCase;
use App\Models\User;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\Product;

class PurchaseReturnLazyLoadingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Enforce strict mode to catch lazy loading
        Model::preventLazyLoading(true);
        
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /** @test */
    public function show_page_does_not_trigger_lazy_loading_error_with_settlement_items()
    {
        // 1. Setup Data
        // Create a setting first as it is required for multitenancy/global scope usually
        $setting = \Modules\Setting\Entities\Setting::create([
             'company_name' => 'Test Company',
             'company_email' => 'test@company.com',
             'company_phone' => '123456789',
             'company_address' => 'Test Address',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'notification_email' => 'notification@test.com',
             'footer_text' => 'Test Footer',
        ]);
        
        
        $supplier = Supplier::factory()->create([
            'setting_id' => $setting->id
        ]);
        
        
        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'TEST_CAT',
            'category_name' => 'Test Category',
            // 'slug' => 'test-category',
            'created_by' => $this->user->id,
            'setting_id' => $setting->id,
        ]);
        
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Test Unit',
            'short_name' => 'TU',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $setting->id,
        ]);
        
        $product = Product::forceCreate([
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'product_quantity' => 100,
            'product_price' => 10000,
            'product_cost' => 5000,
            'product_stock_alert' => 10,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => 'Test',
            'category_id' => $category->id,
            'unit_id' => $unit->id, 
            'setting_id' => $setting->id,
        ]);

        $location = Location::create([
             'name' => 'Test Location',
            //  'slug' => 'test-location',
             'setting_id' => $setting->id,
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-TEST-LAZY',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'setting_id' => $setting->id,
            'location_id' => $location->id, // Usually stored in header too in older schema? Or new schema? 
            // The model has location() belongsTo relationship, so likely location_id exists.
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'total_amount' => 10000,
            'due_amount' => 10000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Test',
            'tax_amount' => 0,
            'discount_amount' => 0,
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // Create Header Settlement
        $purchaseReturn->settlement()->create([
            'method' => 'mixed',
            'status' => 'pending',
            'submitted_by' => $this->user->id,
            'submitted_at' => now(),
        ]);

        // Create Item Settlement
        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'status' => 'SUBMITTED',
            'method' => 'CASH',
            'nominal' => 10000,
            'submitted_by' => $this->user->id,
            'submitted_at' => now(),
        ]);

        // 2. Hit the show route
        $response = $this->get(route('purchase-returns.show', $purchaseReturn->id));

        // 3. Assert Success
        $response->assertStatus(200);
        $response->assertSee($product->product_name);
        $response->assertSee('CASH');
    }
}

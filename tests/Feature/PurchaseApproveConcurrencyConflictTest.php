<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Location;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class PurchaseApproveConcurrencyConflictTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $unit;
    protected $category;
    protected $location;
    protected $currency;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        // Create dependencies manually since factories are missing
        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Street 1',
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);
        
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $this->category = Category::create([
            'created_by' => $user->id,
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Location',
        ]);
        
        session(['setting_id' => $this->setting->id]);
    }

    private function createProduct()
    {
        return Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Sample Product ' . uniqid(),
            'product_code' => 'PRD-' . uniqid(),
            'product_quantity' => 0,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'sale_price' => 1000,
        ]);
    }

    /** @test */
    public function it_blocks_concurrent_approval_requests_with_409_conflict()
    {
        // Given: A pending receiving exists
        $product = $this->createProduct();
        
        $supplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Supplier',
            'supplier_phone' => '123',
            'supplier_email' => 'sup@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_purchase_number' => 'SUP-001',
            'tax_ref_no' => 'TAX-001',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => null,
            'note' => null,
            'setting_id' => $this->setting->id,
            'paid_amount' => 0,
            'is_tax_included' => false,
            'payment_method' => '',
            'reference' => 'PO-001',
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'status' => ReceivedNote::STATUS_PENDING,
            'date' => now(),
            'location_id' => $this->location->id,
        ]);
        
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);

        // And: The lock for this purchase is already held by another process
        // We simulate this by taking the lock manually before the request
        $lockName = 'purchase_approval_' . $purchase->id;
        $lock = Cache::lock($lockName, 10);
        $lock->get(); // Acquire the lock
        
        try {
            // When: We try to approve the receiving
        $response = $this->post(route('receivings.approve', $receivedNote->id));

            // Then: It returns 409 Conflict
            $response->assertStatus(409);
            
            // And: The JSON response contains explainable error
            if ($response->headers->get('Content-Type') === 'application/json') {
                 $response->assertJson([
                    'success' => false,
                    'error' => 'conflict',
                ]);
            }
            
            // And: The receiving is still pending (modify simulation didn't go through)
            $this->assertEquals(ReceivedNote::STATUS_PENDING, $receivedNote->fresh()->status);
            
        } finally {
            $lock->release();
        }
    }

    /** @test */
    public function it_succeeds_when_lock_is_available()
    {
        // Given: A pending receiving exists
        $product = $this->createProduct();
        
        $supplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Supplier',
            'supplier_phone' => '123',
            'supplier_email' => 'sup@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_purchase_number' => 'SUP-002',
            'tax_ref_no' => 'TAX-002',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => null,
            'note' => null,
            'setting_id' => $this->setting->id,
            'paid_amount' => 0,
            'is_tax_included' => false,
            'payment_method' => '',
            'reference' => 'PO-002',
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'status' => ReceivedNote::STATUS_PENDING,
            'date' => now(),
            'location_id' => $this->location->id,
        ]);
        
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 10,
        ]);

        // When: We try to approve when no lock is held
        $response = $this->post(route('receivings.approve', $receivedNote->id));

        // Then: It succeeds
        $response->assertStatus(302); // Redirect back on success
        // $response->dumpSession();
        $response->assertSessionHas('alert');
        
        // And: Status is approved
        $this->assertEquals(ReceivedNote::STATUS_APPROVED, $receivedNote->fresh()->status);
    }
    
    private function createAdminUser()
    {
        $user = \App\Models\User::factory()->create([
            'email' => 'admin@test.com',
        ]);
        
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchaseReceivings.approval']);
        $user->givePermissionTo($permission);
        
        return $user;
    }
}

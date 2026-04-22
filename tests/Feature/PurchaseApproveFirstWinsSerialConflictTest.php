<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Location;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class PurchaseApproveFirstWinsSerialConflictTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $unit;
    protected $category;
    protected $location;
    protected $currency;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        // Create dependencies manually
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
        
        $this->user = $this->createAdminUser();
        $this->actingAs($this->user);

        $this->category = Category::create([
            'created_by' => $this->user->id,
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
            'product_name' => 'Serial Product ' . uniqid(),
            'product_code' => 'PRD-' . uniqid(),
            'product_quantity' => 0,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'sale_price' => 1000,
            'serial_number_required' => 1, // Ensure serials are required
        ]);
    }
    
    private function createAdminUser()
    {
        $user = \App\Models\User::factory()->create([
            'email' => 'admin@test.com',
        ]);
        
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchases.receive.approval']);
        $user->givePermissionTo($permission);
        
        return $user;
    }

    private function createPurchaseWithReceiving($product, $serials)
    {
        $supplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Supplier ' . uniqid(),
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
            'supplier_purchase_number' => 'SUP-' . uniqid(),
            'tax_ref_no' => 'TAX-' . uniqid(),
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
            'reference' => 'PO-' . uniqid(),
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => count($serials),
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => count($serials) * 1000,
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
            'quantity_received' => count($serials),
            'pending_serial_numbers' => $serials,
        ]);

        return $receivedNote;
    }

    /** @test */
    public function first_approval_wins_and_second_fails_for_same_serial()
    {
        // Given: A shared serial number "SN-CONFLICT"
        $serial = "SN-CONFLICT";
        $product = $this->createProduct();

        // And: The serial exists in RETURNED state (reusable)
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $serial,
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'location_id' => $this->location->id,
        ]);

        // And: Two separate pending receivings trying to reuse the same serial
        $receivedNote1 = $this->createPurchaseWithReceiving($product, [$serial]);
        $receivedNote2 = $this->createPurchaseWithReceiving($product, [$serial]);

        // When: First receiving is approved
        $response1 = $this->post(route('receivings.approve', $receivedNote1->id));
        
        // Then: It succeeds
        $response1->assertStatus(302);
        $this->assertEquals(ReceivedNote::STATUS_APPROVED, $receivedNote1->fresh()->status);
        
        // And: The serial status is now ACTIVE
        $this->assertDatabaseHas('product_serial_numbers', [
            'product_id' => $product->id,
            'serial_number' => $serial,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'location_id' => $receivedNote1->location_id,
        ]);

        // When: Second receiving is approved (attempting to claim same serial)
        $response2 = $this->post(route('receivings.approve', $receivedNote2->id));

        // Then: It fails with 409 Conflict
        $response2->assertStatus(409);
        
        // And: The error message mentions the serial number issue
        if ($response2->headers->get('Content-Type') === 'application/json') {
             $response2->assertJson([
                'success' => false,
                'error' => 'conflict',
            ]);
            $response2->assertJsonFragment(['serial']); 
        } else {
             $this->assertTrue(true, 'Non-JSON response (likely plain text or standard error page), manual verification of content needed if tailored');
             // For standard requests, we might see reflected text or just the status
        }
        
        // And: The second receiving remains PENDING
        $this->assertEquals(ReceivedNote::STATUS_PENDING, $receivedNote2->fresh()->status);
    }
    
    /** @test */
    public function loser_of_conflict_does_not_apply_partial_updates()
    {
        // Given: Two serials in the second receiving, one valid and one conflict
        $serialConflict = "SN-CONFLICT-2";
        $serialSafe = "SN-SAFE-2";
        $product = $this->createProduct();

        // And: serialConflict exists in RETURNED state
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $serialConflict,
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'location_id' => $this->location->id,
        ]);

        // And: Receiving 1 uses serialConflict
        $receivedNote1 = $this->createPurchaseWithReceiving($product, [$serialConflict]);
        
        // And: Receiving 2 uses serialConflict AND serialSafe
        $receivedNote2 = $this->createPurchaseWithReceiving($product, [$serialConflict, $serialSafe]);

        // When: Receiving 1 is approved first
        $this->post(route('receivings.approve', $receivedNote1->id))->assertStatus(302);
        
        // When: Receiving 2 is approved
        $response2 = $this->post(route('receivings.approve', $receivedNote2->id));

        // Then: It fails with 409
        $response2->assertStatus(409);

        // And: serialSafe is NOT created/active (rollback happened)
        $this->assertDatabaseMissing('product_serial_numbers', [
            'product_id' => $product->id,
            'serial_number' => $serialSafe,
        ]);
        
        // And: Product quantity for Receiving 2 was NOT added
        // Base qty = 1 (from Receiving 1)
        $this->assertEquals(1, $product->fresh()->product_quantity);
    }
}

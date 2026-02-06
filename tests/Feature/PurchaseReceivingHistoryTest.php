<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseReceivingHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $location;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'purchaseReceivings.approval']);

        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '1234567890',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => '123 Street',
        ]);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('purchaseReceivings.approval');
        $this->actingAs($this->user);

        $this->location = Location::create([
            'name' => 'Test Location',
            'setting_id' => 1
        ]);

        Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '123456789',
            'address' => 'Supplier Address',
            'city' => 'Test City',
            'country' => 'Test Country',
            'setting_id' => 1,
        ]);

        $category = Category::create([
            'category_name' => 'Test Category',
            'category_code' => 'TEST',
            'setting_id' => 1,
            'created_by' => $this->user->id
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP01',
            'product_quantity' => 0,
            'product_cost' => 5000000,
            'product_price' => 7000000,
            'category_id' => $category->id,
            'setting_id' => 1,
            'serial_number_required' => true
        ]);
    }

    /**
     * Test that approving a receiving creates RECEIVED history for serial numbers.
     */
    public function test_approving_receiving_creates_received_history_for_serial_numbers()
    {
        // 1. Setup Purchase and Pending Receiving
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => 1,
            'status' => Purchase::STATUS_APPROVED,
            'total_amount' => 5000000,
            'paid_amount' => 0,
            'due_amount' => 5000000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => 1,
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'unit_price' => 2500000,
            'price' => 2500000,
            'sub_total' => 5000000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'quantity_received' => 2,
            'po_detail_id' => $purchaseDetail->id,
            'pending_serial_numbers' => ['SN-001', 'SN-002'],
        ]);

        // 2. Approve Receiving
        session(['setting_id' => 1]);
        $response = $this->post(route('receivings.approve', $receivedNote));
        $response->assertStatus(302); // Redirect back

        // 3. Verify Serial Numbers Created
        $this->assertEquals(2, ProductSerialNumber::where('product_id', $this->product->id)->count());
        $sn1 = ProductSerialNumber::where('serial_number', 'SN-001')->first();
        $sn2 = ProductSerialNumber::where('serial_number', 'SN-002')->first();

        $this->assertNotNull($sn1);
        $this->assertNotNull($sn2);

        // 4. Verify History Recorded
        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $sn1->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'location_id' => $this->location->id,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $receivedNoteDetail->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $sn2->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'location_id' => $this->location->id,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $receivedNoteDetail->id,
            'user_id' => $this->user->id,
        ]);
    }
}

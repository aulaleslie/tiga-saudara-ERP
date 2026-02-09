<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseStoreReceiveSerialPolicyTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected $setting;
    protected $unit;
    protected $category;
    protected $user;
    protected $location;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        // Create dependencies manually since factories are missing or flaky
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
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

        $this->category = Category::create([
            'created_by' => $this->user->id,
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
            'setting_id' => $this->setting->id,
        ]);

        // Mock Location
        $this->location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
            'is_default' => true,
        ]);
    }

    private function createProduct()
    {
        return Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Sample Product ' . uniqid(),
            'product_code' => 'PRD-' . uniqid(),
            'product_barcode_symbology' => null,
            'product_quantity' => 100,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'stock_managed' => true,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'sale_price' => 1000,
            'tier_1_price' => 1000,
            'tier_2_price' => 1000,
            'serial_number_required' => true,
        ]);
    }

    private function createPurchase($product, $qty = 1)
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'reference' => 'PO-001',
            'supplier_id' => 1, // dummy
            'supplier_name' => 'Supplier A',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000 * $qty,
            'paid_amount' => 0,
            'due_amount' => 1000 * $qty,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $qty,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000 * $qty,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'product_order_tax' => 0,
            'product_unit' => 'PCS',
        ]);

        return [$purchase, $detail];
    }

    /** @test */
    public function it_rejects_duplicate_serial_in_same_receive_submission_for_same_product()
    {
        // Actually, this behavior is a bit subtle.
        // If the user inputs the same serial twice for the same product in the UI, 
        // the controller might filter it out or attempt to save both. 
        // But typically we want to reject it if it results in duplicate active serials eventually.
        // However, the controller check `existingSerialNumbers` only checks against DB.
        // The `pending` check checks against other pending notes.
        // The `inputtedSerialNumbers` collection uses `unique()`, so duplicates in input are merged.
        // If merged, we are effectively receiving 1 item with that serial, and another with... what?
        // If I say Qty 2, Serial A, Serial A. Merged -> Serial A.
        // The code loops through details.
        // If I provide `serial_numbers[$detail->id] = ['A', 'A']`,
        // The array_unique happening implicitly? No.
        // `inputtedSerialNumbers` flattens and uniques for validation, but `data['serial_numbers']` is used for creation.
        // If we want to reject duplicates within the same submission, we need to add validation logic or rely on DB constraint (which won't trigger yet as it's pending).
        // Let's assume strict validation is desired.

        $product = $this->createProduct();
        list($purchase, $detail) = $this->createPurchase($product, 2);

        $response = $this->post(route('purchase.store-receive', $purchase->id), [
            'received' => [$detail->id => 2],
            'serial_numbers' => [$detail->id => ['SN-DUP', 'SN-DUP']],
            'location_id' => $this->location->id,
            'external_delivery_number' => 'DEL-001',
        ]);

        // If logic is "unique within submission", this should fail or be handled.
        // But wait, `unique()` is used on `inputtedSerialNumbers` (line 668) just to check DB.
        // It does NOT validate that input itself is unique.
        // The strict requirement says "Given duplicate serial ... request is rejected".
        // So I should implement that check if it's missing.
        
        // For now, let's assume it SHOULD fail. 
        // If it succeeds currently (because of unique()), then I need to fix it.
        // Since I'm writing the test first, I expect this to FAIL if the logic isn't there.
        // Or if the unique() hides it, it passes validation and creates duplicates in Pending?
        // Pending creation doesn't check uniqueness against itself in the loop.
        // So this will create 2 pending items with same SN.
        // When approved, it will try to create 2 Active SNs with same code -> DB Error.
        // So validation SHOULD reject it.

        $response->assertSessionHasErrors(); 
    }

    /** @test */
    public function it_rejects_duplicate_serial_already_existing_for_same_product()
    {
        $product = $this->createProduct();
        
        // Existing serial
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-EXIST',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'location_id' => $this->location->id,
        ]);

        list($purchase, $detail) = $this->createPurchase($product, 1);

        $response = $this->post(route('purchase.store-receive', $purchase->id), [
            'received' => [$detail->id => 1],
            'serial_numbers' => [$detail->id => ['SN-EXIST']],
            'location_id' => $this->location->id,
        ]);

        $response->assertSessionHasErrors('serial_numbers');
    }

    /** @test */
    public function it_allows_returned_serial_existing_for_same_product()
    {
        $product = $this->createProduct();
        
        // Existing RETURNED serial
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-RETURNED',
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'location_id' => $this->location->id,
        ]);

        list($purchase, $detail) = $this->createPurchase($product, 1);

        $response = $this->post(route('purchase.store-receive', $purchase->id), [
            'received' => [$detail->id => 1],
            'serial_numbers' => [$detail->id => ['SN-RETURNED']],
            'location_id' => $this->location->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('received_notes', [
            'po_id' => $purchase->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
    }

    /** @test */
    public function it_allows_same_serial_on_different_product()
    {
        $productA = $this->createProduct();
        $productB = $this->createProduct();
        
        // Active serial for Product A
        ProductSerialNumber::create([
            'product_id' => $productA->id,
            'serial_number' => 'SN-COMMON',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'location_id' => $this->location->id,
        ]);

        // Purchase for Product B
        list($purchase, $detail) = $this->createPurchase($productB, 1);

        $response = $this->post(route('purchase.store-receive', $purchase->id), [
            'received' => [$detail->id => 1],
            'serial_numbers' => [$detail->id => ['SN-COMMON']],
            'location_id' => $this->location->id,
        ]);

        $response->assertSessionHasNoErrors();
    }

    /** @test */
    public function it_allows_serial_existing_in_another_pending_receiving_for_different_product()
    {
        $productA = $this->createProduct();
        
        // Pending receiving for Product A with 'SN-PENDING'
        $rn = ReceivedNote::create([
            'po_id' => 1, 
            'status' => ReceivedNote::STATUS_PENDING, 
            'location_id' => $this->location->id, 
            'date' => now()
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'product_id' => $productA->id,
            'quantity_received' => 1,
            'pending_serial_numbers' => ['SN-PENDING'],
        ]);

        // Purchase for Product B with 'SN-PENDING'
        $productB = $this->createProduct();
        list($purchase, $detail) = $this->createPurchase($productB, 1);

        $response = $this->post(route('purchase.store-receive', $purchase->id), [
            'received' => [$detail->id => 1],
            'serial_numbers' => [$detail->id => ['SN-PENDING']],
            'location_id' => $this->location->id,
        ]);

        $response->assertSessionHasNoErrors();
    }

    /** @test */
    public function it_allows_serial_existing_in_another_pending_receiving_for_same_product()
    {
        // "Given serial appears in another pending receiving document, when store receive is submitted, then request is not rejected for that reason."
        // This is a relaxing of rules.
        
        $product = $this->createProduct();
        
        // Pending receiving for Product with 'SN-PENDING-SAME'
        $rn = ReceivedNote::create([
            'po_id' => 1, 
            'status' => ReceivedNote::STATUS_PENDING, 
            'location_id' => $this->location->id, 
            'date' => now()
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'product_id' => $product->id,
            'quantity_received' => 1,
            'pending_serial_numbers' => ['SN-PENDING-SAME'],
        ]);

        // Another purchase for same Product with 'SN-PENDING-SAME'
        list($purchase, $detail) = $this->createPurchase($product, 1);

        $response = $this->post(route('purchase.store-receive', $purchase->id), [
            'received' => [$detail->id => 1],
            'serial_numbers' => [$detail->id => ['SN-PENDING-SAME']],
            'location_id' => $this->location->id,
        ]);

        $response->assertSessionHasNoErrors();
    }
}

<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchaseReceivingCompletion;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseReceivalCancellationEligibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected Setting $setting;
    protected Location $location;
    protected Supplier $supplier;
    protected Unit $unit;
    protected Tax $tax;
    protected Category $category;
    protected ProductReceivalCancellationHelper $helper;
    protected PurchaseReceivalCancellationEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        \Modules\Currency\Entities\Currency::firstOrCreate(
            ['id' => 1],
            [
                'currency_name' => 'Rupiah',
                'code' => 'IDR',
                'symbol' => 'Rp',
                'thousand_separator' => '.',
                'decimal_separator' => ',',
                'exchange_rate' => 1,
            ]
        );

        $this->setting = Setting::firstOrCreate(
            ['id' => 1],
            [
                'company_name' => 'Test Company',
                'company_email' => 'test@test.com',
                'company_phone' => '123456789',
                'company_address' => 'Test Address',
                'default_currency_id' => 1,
                'default_currency_position' => 'prefix',
                'notification_email' => 'test@test.com',
                'footer_text' => 'Footer',
                'is_pkp' => true,
            ]
        );
        session(['setting_id' => $this->setting->id]);

        $this->location = Location::firstOrCreate(
            ['id' => 1],
            ['name' => 'Main Warehouse', 'setting_id' => $this->setting->id]
        );

        $this->supplier = Supplier::create([
            'id' => 1,
            'supplier_name' => 'Main Supplier',
            'supplier_email' => 'supp@example.com',
            'supplier_phone' => '112233',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Supplier Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->unit = Unit::firstOrCreate(
            ['id' => 1],
            ['name' => 'PCS', 'short_name' => 'PCS', 'setting_id' => $this->setting->id]
        );

        $this->tax = Tax::firstOrCreate(
            ['id' => 1],
            ['name' => 'PPN 11%', 'value' => 11]
        );

        $this->user = \App\Models\User::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Test User',
                'email' => 'test@test.com',
                'password' => bcrypt('secret'),
                'setting_id' => $this->setting->id,
                'is_active' => 1,
            ]
        );

        $this->category = Category::firstOrCreate(
            ['id' => 1],
            [
                'category_name' => 'General',
                'category_code' => 'GEN',
                'setting_id' => $this->setting->id,
                'created_by' => $this->user->id,
            ]
        );

        $this->service = app(PurchaseReceivalCancellationEligibilityService::class);
    }

    private function createPurchase(array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'supplier_id' => $this->supplier->id,
            'reference' => 'PO-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'source_type' => Purchase::SOURCE_ORDINARY,
        ], $overrides));
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Item ' . uniqid(),
            'product_code' => 'ITM-' . uniqid(),
            'product_cost' => 10000,
            'product_price' => 15000,
            'product_quantity' => 10,
            'product_unit' => $this->unit->id,
            'category_id' => $this->category->id,
            'setting_id' => $this->setting->id,
        ], $overrides));
    }

    private function createBuyTransaction(array $attributes): Transaction
    {
        return Transaction::create(array_merge([
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'BUY',
            'quantity' => 10,
            'current_quantity' => 10,
            'previous_quantity' => 0,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ], $attributes));
    }

    private function createProductStock(array $attributes): ProductStock
    {
        return ProductStock::create(array_merge([
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ], $attributes));
    }

    public function test_eligibility_accepts_clean_approved_receival_with_sufficient_stock(): void
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase();
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now(),
            'date' => now()->toDateString(),
        ]);

        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $poDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 10,
        ]);

        $buyTxn = $this->createBuyTransaction([
            'product_id' => $product->id,
            'received_note_detail_id' => $rnDetail->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
        ]);

        $preview = $this->service->preview($receivedNote->purchase, 'Wrong delivery', $this->setting->id);

        $this->assertTrue($preview['eligible']);
        $this->assertEmpty($preview['blockers']);
        $this->assertCount(1, $preview['lines']);
        $this->assertEquals($buyTxn->id, $preview['lines'][0]['original_transaction_id']);
    }

    public function test_eligibility_rejects_insufficient_stock_at_location(): void
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase();
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now(),
            'date' => now()->toDateString(),
        ]);

        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $poDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 10,
        ]);

        $this->createBuyTransaction([
            'product_id' => $product->id,
            'received_note_detail_id' => $rnDetail->id,
        ]);

        // Only 5 in stock (some was sold)
        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
        ]);

        $preview = $this->service->preview($receivedNote->purchase, 'Mistake', $this->setting->id);

        $this->assertFalse($preview['eligible']);
        $this->assertNotEmpty($preview['blockers']);
        $this->assertStringContainsString('Insufficient total stock', $preview['blockers'][0]);
    }

    public function test_eligibility_rejects_purchase_with_supplier_shortfall_completion(): void
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase();

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now(),
            'date' => now()->toDateString(),
        ]);

        // Add shortfall completion record
        PurchaseReceivingCompletion::create([
            'purchase_id' => $purchase->id,
            'setting_id' => $this->setting->id,
            'actor_user_id' => $this->user->id,
            'reason' => 'Supplier out of stock',
            'source_snapshot' => [],
            'final_snapshot' => [],
            'financial_before_after' => [],
        ]);

        $preview = $this->service->preview($receivedNote->purchase, 'Cancel', $this->setting->id);

        $this->assertFalse($preview['eligible']);
        $this->assertStringContainsString('supplier shortfall completion', $preview['blockers'][0]);
    }

    public function test_eligibility_rejects_when_serial_is_sold_or_moved(): void
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase();
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now(),
            'date' => now()->toDateString(),
        ]);

        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $poDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 1,
        ]);

        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 1,
            'current_quantity' => 1,
            'quantity_non_tax' => 1,
            'received_note_detail_id' => $rnDetail->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
        ]);

        // Serial is SOLD
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-1001',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $rnDetail->productSerialNumbers()->attach($serial->id, ['linked_at' => now()]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $rnDetail->id,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
        ]);

        $preview = $this->service->preview($receivedNote->purchase, 'Cancel', $this->setting->id);

        $this->assertFalse($preview['eligible']);
        $this->assertStringContainsString('ineligible for reversal', $preview['blockers'][0]);
        $this->assertStringContainsString('Status is SOLD', $preview['blockers'][0]);
    }

    public function test_eligibility_accepts_pending_receival_for_manual_cancellation(): void
    {
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_APPROVED]);
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
            'date' => now()->toDateString(),
        ]);

        $preview = $this->service->preview($receivedNote->purchase, 'Cancel pending', $this->setting->id);

        $this->assertTrue($preview['eligible']);
        $this->assertCount(1, $preview['pending_notes']);
        $this->assertCount(0, $preview['approved_notes']);
        $this->assertEmpty($preview['lines']);
        $this->assertEmpty($preview['lines']);
        $this->assertEmpty($preview['stock_requirements']);
    }

    public function test_eligibility_blocks_serial_with_missing_or_mismatched_receipt_history(): void
    {
        $purchase = $this->createPurchase();
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'date' => now()->toDateString(),
        ]);

        $product = $this->createProduct(['serial_number_required' => true, 'product_quantity' => 5]);
        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 1,
        ]);

        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 1,
            'current_quantity' => 1,
            'quantity_non_tax' => 1,
            'received_note_detail_id' => $rnDetail->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-NO-HIST',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        $rnDetail->productSerialNumbers()->attach($serial->id, ['linked_at' => now()]);

        // No SerialNumberHistory created!
        $preview = $this->service->preview($receivedNote->purchase, 'Cancel', $this->setting->id);

        $this->assertFalse($preview['eligible']);
        $this->assertStringContainsString('Has no receipt history provenance', $preview['blockers'][0]);
    }

    public function test_eligibility_blocks_serial_with_tax_identity_mismatch(): void
    {
        $purchase = $this->createPurchase();
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'date' => now()->toDateString(),
        ]);

        $product = $this->createProduct(['serial_number_required' => true, 'product_quantity' => 5]);
        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'tax_id' => $this->tax->id,
            'quantity_received' => 1,
        ]);

        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 1,
            'current_quantity' => 1,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'tax_id' => $this->tax->id,
            'received_note_detail_id' => $rnDetail->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 1,
            'quantity_non_tax' => 0,
            'quantity_tax' => 1,
        ]);

        // Serial has null tax_id while detail has tax_id
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-TAX-MISMATCH',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'tax_id' => null,
        ]);

        $rnDetail->productSerialNumbers()->attach($serial->id, ['linked_at' => now()]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $rnDetail->id,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
        ]);

        $preview = $this->service->preview($receivedNote->purchase, 'Cancel', $this->setting->id);

        $this->assertFalse($preview['eligible']);
        $this->assertStringContainsString('Tax identity', $preview['blockers'][0]);
        $this->assertStringContainsString('differs from receipt detail', $preview['blockers'][0]);
    }

    public function test_eligibility_blocks_mismatched_serial_count_vs_received_qty(): void
    {
        $purchase = $this->createPurchase();
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'date' => now()->toDateString(),
        ]);

        $product = $this->createProduct(['serial_number_required' => true, 'product_quantity' => 5]);
        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 2, // Received 2 units
        ]);

        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 2,
            'current_quantity' => 2,
            'quantity_non_tax' => 2,
            'received_note_detail_id' => $rnDetail->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 2,
            'quantity_non_tax' => 2,
            'quantity_tax' => 0,
        ]);

        // But only 1 serial attached!
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-ONLY-ONE',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        $rnDetail->productSerialNumbers()->attach($serial->id, ['linked_at' => now()]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $rnDetail->id,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
        ]);

        $preview = $this->service->preview($receivedNote->purchase, 'Cancel', $this->setting->id);

        $this->assertFalse($preview['eligible']);
        $this->assertStringContainsString('has 1 linked serial(s), but received quantity is 2', $preview['blockers'][0]);
    }

    public function test_eligibility_blocks_fractional_serialized_quantity(): void
    {
        $purchase = $this->createPurchase();
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'date' => now()->toDateString(),
        ]);

        $product = $this->createProduct(['serial_number_required' => true, 'product_quantity' => 5]);
        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 1.2, // Fractional corrupted quantity
        ]);

        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 1.2,
            'current_quantity' => 1.2,
            'quantity_non_tax' => 1.2,
            'received_note_detail_id' => $rnDetail->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 1.2,
            'quantity_non_tax' => 1.2,
            'quantity_tax' => 0,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-FRAC',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        $rnDetail->productSerialNumbers()->attach($serial->id, ['linked_at' => now()]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $rnDetail->id,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
        ]);

        $preview = $this->service->preview($receivedNote->purchase, 'Cancel', $this->setting->id);

        $this->assertFalse($preview['eligible']);
        $this->assertStringContainsString('invalid fractional/non-positive quantity', $preview['blockers'][0]);
    }
}

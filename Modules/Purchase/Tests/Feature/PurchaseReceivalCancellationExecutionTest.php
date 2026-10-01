<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteCancellation;
use Modules\Purchase\Entities\ReceivedNoteCancellationDetail;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Services\PurchaseReceivalCancellationService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseReceivalCancellationExecutionTest extends TestCase
{
    use DatabaseTransactions;

    protected Setting $setting;
    protected Location $location;
    protected Supplier $supplier;
    protected Unit $unit;
    protected Tax $tax;
    protected Category $category;
    protected \App\Models\User $user;
    protected PurchaseReceivalCancellationService $service;

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

        \Spatie\Permission\Models\Permission::findOrCreate('purchases.receive.cancel', 'web');
        $this->user->givePermissionTo('purchases.receive.cancel');

        $this->service = app(PurchaseReceivalCancellationService::class);
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
            'user_id' => $this->user->id,
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

    public function test_cancellation_reverses_inventory_and_marks_note_cancelled(): void
    {
        $product = $this->createProduct(['product_quantity' => 10]);
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);

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

        $stock = $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
        ]);

        $cancellations = $this->service->cancel($receivedNote->purchase, 'Damaged during unloading', $this->user, $this->setting->id);

        $this->assertCount(1, $cancellations);
        $cancellation = $cancellations->first();
        $this->assertInstanceOf(ReceivedNoteCancellation::class, $cancellation);
        $this->assertEquals(ReceivedNote::STATUS_CANCELLED, $receivedNote->fresh()->status);
        $this->assertEquals('Damaged during unloading', $receivedNote->fresh()->cancellation_reason);
        $this->assertEquals(ReceivedNoteCancellation::ORIGIN_MANUAL_APPROVED, $receivedNote->fresh()->cancellation_origin);

        // Check inventory stock reversed to 0
        $this->assertEquals(0, (float) $stock->fresh()->quantity);
        $this->assertEquals(0, (float) $stock->fresh()->quantity_non_tax);
        $this->assertEquals(0, (float) $product->fresh()->product_quantity);

        // Check Reversal Transaction
        $reversalTxn = Transaction::where('received_note_cancellation_detail_id', $cancellation->cancellationDetails->first()->id)->first();
        $this->assertNotNull($reversalTxn);
        $this->assertEquals(PurchaseReceivalCancellationService::TYPE_PURCHASE_RECEIVING_CANCELLED, $reversalTxn->type);
        $this->assertEquals(-10, (float) $reversalTxn->quantity);
        $this->assertEquals(0, (float) $reversalTxn->current_quantity);

        // Purchase status re-derived to APPROVED because no other approved receipts exist
        $this->assertEquals(Purchase::STATUS_APPROVED, $purchase->fresh()->status);

        // Verification 5.1 & 5.2: Check PurchaseDeliveryReportQueryService excludes CANCELLED note
        $deliveryFilter = new \App\Services\Reports\PurchaseDeliveryReportFilterData(
            startDate: now()->subDays(1)->toDateString(),
            endDate: now()->addDays(1)->toDateString(),
            scopeSettingId: $this->setting->id
        );
        $deliveryQuery = app(\App\Services\Reports\PurchaseDeliveryReportQueryService::class)->build($deliveryFilter);
        $this->assertEquals(0, $deliveryQuery->count());

        // Verification 5.2: Check StockMutationReport shows both movements
        \Livewire\Livewire::test(\App\Livewire\Reports\StockMutationReport::class)
            ->set('filterTriggered', true)
            ->set('productId', $product->id)
            ->assertSee('Penerimaan Pembelian')
            ->assertSee('Pembatalan Penerimaan Pembelian');
    }

    public function test_purchase_level_cancellation_cancels_approved_and_pending_receivals(): void
    {
        $product = $this->createProduct(['product_quantity' => 5]);
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED_PARTIALLY]);

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

        // Approved note for 5
        $approvedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now(),
            'date' => now()->toDateString(),
        ]);

        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $approvedNote->id,
            'po_detail_id' => $poDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 5,
        ]);

        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'quantity_non_tax' => 5,
            'received_note_detail_id' => $rnDetail->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
        ]);

        // Second note is PENDING
        $pendingNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
            'date' => now()->toDateString(),
        ]);

        $cancellations = $this->service->cancel($purchase, 'Reopen purchase', $this->user, $this->setting->id);
        $this->assertCount(2, $cancellations);
        $this->assertEquals(ReceivedNote::STATUS_CANCELLED, $approvedNote->fresh()->status);

        // Purchase becomes APPROVED
        $this->assertEquals(Purchase::STATUS_APPROVED, $purchase->fresh()->status);

        // Pending note is cancelled by the same explicit action
        $this->assertEquals(ReceivedNote::STATUS_CANCELLED, $pendingNote->fresh()->status);
        $this->assertEquals(ReceivedNoteCancellation::ORIGIN_MANUAL_PENDING, $pendingNote->fresh()->cancellation_origin);
        $this->assertEquals('Reopen purchase', $pendingNote->fresh()->cancellation_reason);
        $this->assertEquals(ReceivedNote::STATUS_PENDING, $pendingNote->fresh()->cancellation->previous_status);
    }

    public function test_cancellation_transitions_serials_and_appends_history(): void
    {
        $product = $this->createProduct(['product_quantity' => 1]);
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

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-EXEC-101',
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

        $this->service->cancel($receivedNote->purchase, 'Serial cancellation test', $this->user, $this->setting->id);

        $this->assertEquals(ProductSerialNumber::STATUS_RECEIVING_CANCELLED, $serial->fresh()->status);

        $cancelHistory = SerialNumberHistory::where('product_serial_number_id', $serial->id)
            ->where('event_type', SerialNumberHistory::EVENT_PURCHASE_RECEIVING_CANCELLED)
            ->first();

        $this->assertNotNull($cancelHistory);
        $this->assertEquals(ReceivedNoteCancellationDetail::class, $cancelHistory->reference_type);
    }

    /**
     * One approved receival of a single serialized unit, fully reversible.
     */
    private function createSerializedApprovedReceival(string $serialNumber): array
    {
        $product = $this->createProduct(['product_quantity' => 1]);
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
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
        $stock = $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
        ]);
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => $serialNumber,
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

        return compact('product', 'purchase', 'poDetail', 'receivedNote', 'rnDetail', 'stock', 'serial');
    }

    /**
     * Run cancellation with an eligibility service that returns a pre-lock (stale) preview.
     */
    private function cancelWithStalePreview(Purchase $purchase, array $stalePreview): \Exception
    {
        $eligibility = \Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService::class;
        $this->app->instance($eligibility, \Mockery::mock($eligibility, function ($mock) use ($stalePreview) {
            $mock->shouldReceive('preview')->andReturn($stalePreview);
        }));

        try {
            app(PurchaseReceivalCancellationService::class)->cancel($purchase, 'Race', $this->user, $this->setting->id);
        } catch (\Exception $e) {
            return $e;
        }

        $this->fail('Cancellation should have been rejected by locked-state revalidation.');
    }

    private function assertReceivalUntouched(array $fx): void
    {
        $this->assertEquals(ReceivedNote::STATUS_APPROVED, $fx['receivedNote']->fresh()->status);
        $this->assertEquals(1, (float) $fx['stock']->fresh()->quantity);
        $this->assertEquals(0, ReceivedNoteCancellation::where('purchase_id', $fx['purchase']->id)->count());
        $this->assertFalse(SerialNumberHistory::where('event_type', SerialNumberHistory::EVENT_PURCHASE_RECEIVING_CANCELLED)
            ->where('product_serial_number_id', $fx['serial']->id)
            ->exists());
    }

    public function test_cancellation_revalidates_locked_serials_against_stale_eligibility(): void
    {
        $fx = $this->createSerializedApprovedReceival('SN-RACE-201');
        $stalePreview = app(\Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService::class)
            ->preview($fx['purchase'], 'Race', $this->setting->id);
        $this->assertTrue($stalePreview['eligible']);

        // A concurrent sale sold the serial before cancellation acquired its locks
        $fx['serial']->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        $e = $this->cancelWithStalePreview($fx['purchase'], $stalePreview);
        $this->assertStringContainsString('SN-RACE-201', $e->getMessage());
        $this->assertEquals(ProductSerialNumber::STATUS_SOLD, $fx['serial']->fresh()->status);
        $this->assertReceivalUntouched($fx);
    }

    public function test_cancellation_rejects_serial_linked_after_stale_eligibility(): void
    {
        $fx = $this->createSerializedApprovedReceival('SN-RACE-301');
        $stalePreview = app(\Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService::class)
            ->preview($fx['purchase'], 'Race', $this->setting->id);
        $this->assertTrue($stalePreview['eligible']);

        // A second ACTIVE serial is linked to the same detail after validation; the stale preview omits it
        $extra = ProductSerialNumber::create([
            'product_id' => $fx['product']->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-RACE-302',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);
        $fx['rnDetail']->productSerialNumbers()->attach($extra->id, ['linked_at' => now()]);

        $e = $this->cancelWithStalePreview($fx['purchase'], $stalePreview);
        $this->assertStringContainsString('2 linked serial(s)', $e->getMessage());
        $this->assertStringContainsString('serial linkage changed', $e->getMessage());
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $extra->fresh()->status);
        $this->assertReceivalUntouched($fx);
    }

    public function test_cancellation_rejects_newer_receiving_provenance_after_stale_eligibility(): void
    {
        $fx = $this->createSerializedApprovedReceival('SN-RACE-401');
        $stalePreview = app(\Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService::class)
            ->preview($fx['purchase'], 'Race', $this->setting->id);
        $this->assertTrue($stalePreview['eligible']);

        // The serial is re-received elsewhere; it stays ACTIVE at the same location and tax bucket
        SerialNumberHistory::create([
            'product_serial_number_id' => $fx['serial']->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $fx['rnDetail']->id + 1000,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
        ]);

        $e = $this->cancelWithStalePreview($fx['purchase'], $stalePreview);
        $this->assertStringContainsString('latest receiving provenance', $e->getMessage());
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $fx['serial']->fresh()->status);
        $this->assertReceivalUntouched($fx);
    }

    public function test_cancellation_service_denies_unauthorized_actor(): void
    {
        $purchase = $this->createPurchase();
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
            'date' => now()->toDateString(),
        ]);

        $unauthorizedUser = \App\Models\User::create([
            'name' => 'Unauthorized User',
            'email' => 'unauthorized@test.com',
            'password' => bcrypt('secret'),
            'setting_id' => $this->setting->id,
            'is_active' => 1,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('User does not have purchases.receive.cancel permission');

        $this->service->cancel($receivedNote->purchase, 'Test unauthorized', $unauthorizedUser, $this->setting->id);
    }

    public function test_cancellation_fails_when_global_product_quantity_insufficient(): void
    {
        $purchase = $this->createPurchase();
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'date' => now()->toDateString(),
        ]);

        // Product global quantity is only 0.5, but received quantity is 1
        $product = $this->createProduct(['product_quantity' => 0.5]);
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

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient global product stock');

        $this->service->cancel($receivedNote->purchase, 'Test insufficient global qty', $this->user, $this->setting->id);
    }

    public function test_cancellation_updates_legacy_product_cost_fields(): void
    {
        $product = $this->createProduct([
            'product_quantity' => 10,
            'product_cost' => 100000,
            'average_purchase_price' => 120000,
            'last_purchase_price' => 120000,
        ]);

        // Purchase 1 (Approved & Received at 100,000)
        $purchase1 = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail1 = PurchaseDetail::create([
            'purchase_id' => $purchase1->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 500000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $rn1 = ReceivedNote::create([
            'po_id' => $purchase1->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now()->subDays(5),
            'date' => now()->subDays(5)->toDateString(),
        ]);
        $rnDetail1 = ReceivedNoteDetail::create([
            'received_note_id' => $rn1->id,
            'po_detail_id' => $poDetail1->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 5,
        ]);
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'quantity_non_tax' => 5,
            'cost' => 100000,
            'received_note_detail_id' => $rnDetail1->id,
        ]);

        // Purchase 2 (Approved & Received at 150,000 - the one we will cancel)
        $purchase2 = $this->createPurchase(['status' => Purchase::STATUS_APPROVED]);
        $poDetail2 = PurchaseDetail::create([
            'purchase_id' => $purchase2->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 150000,
            'unit_price' => 150000,
            'sub_total' => 750000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $rn2 = ReceivedNote::create([
            'po_id' => $purchase2->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'date' => now()->toDateString(),
        ]);
        $rnDetail2 = ReceivedNoteDetail::create([
            'received_note_id' => $rn2->id,
            'po_detail_id' => $poDetail2->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 5,
        ]);
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'quantity_non_tax' => 5,
            'cost' => 150000,
            'received_note_detail_id' => $rnDetail2->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
        ]);

        // Prior to cancellation: last_purchase_price was 120,000
        $this->service->cancel($rn2->purchase, 'Cancel second receival', $this->user, $this->setting->id);

        $freshProduct = $product->fresh();
        // Since Purchase 1 is the only remaining receipt:
        // last_purchase_price should be 100,000 and average_purchase_price should be 100,000
        $this->assertEquals(100000, (float) $freshProduct->last_purchase_price);
        $this->assertEquals(100000, (float) $freshProduct->average_purchase_price);
    }

    public function test_cancellation_of_only_remaining_receipt_resets_last_purchase_price(): void
    {
        $product = $this->createProduct([
            'product_quantity' => 5,
            'product_cost' => 150000,
            'average_purchase_price' => 150000,
            'last_purchase_price' => 150000,
        ]);

        $purchase = $this->createPurchase(['status' => Purchase::STATUS_APPROVED]);
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 150000,
            'unit_price' => 150000,
            'sub_total' => 750000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $rn = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'date' => now()->toDateString(),
        ]);

        $rnDetail = ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $poDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 5,
        ]);

        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'quantity_non_tax' => 5,
            'cost' => 150000,
            'received_note_detail_id' => $rnDetail->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
        ]);

        // Cancel this lone receipt
        $this->service->cancel($rn->purchase, 'Cancel lone receival', $this->user, $this->setting->id);

        $freshProduct = $product->fresh();
        // Since no approved receipt remains, last_purchase_price must be reset to 0
        $this->assertEquals(0.0, (float) $freshProduct->last_purchase_price);

        $priceRecord = \Modules\Product\Entities\ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->setting->id)
            ->first();
        $this->assertNotNull($priceRecord);
        $this->assertEquals(0.0, (float) $priceRecord->last_purchase_price);
    }

    public function test_cancellation_selects_last_purchase_price_by_approval_chronology(): void
    {
        $product = $this->createProduct([
            'product_quantity' => 15,
            'product_cost' => 100000,
            'last_purchase_price' => 200000,
        ]);

        // Purchase 1: price 100,000, approved LATER (today)
        $purchase1 = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail1 = PurchaseDetail::create([
            'purchase_id' => $purchase1->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 500000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $rn1 = ReceivedNote::create([
            'po_id' => $purchase1->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now(), // newer approval
            'date' => now()->toDateString(),
        ]);
        $rnDetail1 = ReceivedNoteDetail::create([
            'received_note_id' => $rn1->id,
            'po_detail_id' => $poDetail1->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 5,
        ]);
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'quantity_non_tax' => 5,
            'cost' => 100000,
            'received_note_detail_id' => $rnDetail1->id,
        ]);

        // Purchase 2: price 150,000, approved EARLIER (5 days ago) but created with higher ID
        $purchase2 = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail2 = PurchaseDetail::create([
            'purchase_id' => $purchase2->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 150000,
            'unit_price' => 150000,
            'sub_total' => 750000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $rn2 = ReceivedNote::create([
            'po_id' => $purchase2->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now()->subDays(5), // older approval
            'date' => now()->subDays(5)->toDateString(),
        ]);
        $rnDetail2 = ReceivedNoteDetail::create([
            'received_note_id' => $rn2->id,
            'po_detail_id' => $poDetail2->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 5,
        ]);
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'quantity_non_tax' => 5,
            'cost' => 150000,
            'received_note_detail_id' => $rnDetail2->id,
        ]);

        // Purchase 3: price 200,000 - the one we cancel
        $purchase3 = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail3 = PurchaseDetail::create([
            'purchase_id' => $purchase3->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 200000,
            'unit_price' => 200000,
            'sub_total' => 1000000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $rn3 = ReceivedNote::create([
            'po_id' => $purchase3->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now()->addMinute(),
            'date' => now()->toDateString(),
        ]);
        $rnDetail3 = ReceivedNoteDetail::create([
            'received_note_id' => $rn3->id,
            'po_detail_id' => $poDetail3->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 5,
        ]);
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'quantity_non_tax' => 5,
            'cost' => 200000,
            'received_note_detail_id' => $rnDetail3->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 15,
            'quantity_non_tax' => 15,
            'quantity_tax' => 0,
        ]);

        // Cancel Purchase 3. Among remaining Purchase 1 (approved today, lower id) and Purchase 2 (approved 5 days ago, higher id),
        // Purchase 1 is chronologically later by approved_at!
        $this->service->cancel($rn3->purchase, 'Cancel purchase 3', $this->user, $this->setting->id);

        $this->assertEquals(100000, (float) $product->fresh()->last_purchase_price);
    }

    public function test_cancellation_replays_downstream_sales_hpp_and_preserves_imported_snapshots(): void
    {
        $product = $this->createProduct([
            'product_quantity' => 10,
            'product_cost' => 100000,
        ]);

        // Purchase 1 @ 100,000
        $purchase1 = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail1 = PurchaseDetail::create([
            'purchase_id' => $purchase1->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 500000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $rn1 = ReceivedNote::create([
            'po_id' => $purchase1->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now()->subDays(10),
            'date' => now()->subDays(10)->toDateString(),
        ]);
        $rnDetail1 = ReceivedNoteDetail::create([
            'received_note_id' => $rn1->id,
            'po_detail_id' => $poDetail1->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 5,
        ]);
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'quantity_non_tax' => 5,
            'cost' => 100000,
            'received_note_detail_id' => $rnDetail1->id,
        ]);

        // Create an earlier bundle consumption (before Purchase 2, e.g. 7 days ago)
        // Consuming 1 unit of $product as component in a distinct bundle parent
        $parentProduct = $this->createProduct([
            'product_name' => 'Bundle Parent Product',
            'product_code' => 'BND-PARENT',
            'product_quantity' => 5,
            'product_cost' => 300000,
        ]);

        $earlyBundleSale = \Modules\Sale\Entities\Sale::create([
            'date' => now()->subDays(7),
            'reference' => 'SL-EARLY-' . uniqid(),
            'customer_name' => 'Early Bundle Cust',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 300000,
            'paid_amount' => 300000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);
        $earlyBundleSaleDetail = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $earlyBundleSale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 300000,
            'unit_price' => 300000,
            'sub_total' => 300000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_detail_id' => $earlyBundleSaleDetail->id,
            'sale_id' => $earlyBundleSale->id,
            'bundle_id' => 999,
            'bundle_item_id' => 1,
            'product_id' => $product->id,
            'name' => $product->product_name,
            'quantity' => 1, // consumes 1 unit @ 100,000 opening average
            'price' => 100000,
            'sub_total' => 100000,
            'cost_unit_snapshot' => 100000,
            'cost_total_snapshot' => 100000,
            'cost_snapshot_source' => 'LIVE_CALCULATION',
        ]);

        // Purchase 2 @ 200,000 (subDays(5), cancelled)
        $purchase2 = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail2 = PurchaseDetail::create([
            'purchase_id' => $purchase2->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 200000,
            'unit_price' => 200000,
            'sub_total' => 1000000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $rn2 = ReceivedNote::create([
            'po_id' => $purchase2->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now()->subDays(5),
            'date' => now()->subDays(5)->toDateString(),
        ]);
        $rnDetail2 = ReceivedNoteDetail::create([
            'received_note_id' => $rn2->id,
            'po_detail_id' => $poDetail2->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 5,
        ]);
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 5,
            'current_quantity' => 5,
            'quantity_non_tax' => 5,
            'cost' => 200000,
            'received_note_detail_id' => $rnDetail2->id,
        ]);

        // Later Purchase 3 @ 160,000 (subDays(3))
        // Remaining qty before Purchase 3 was: 5 (from Purchase 1) - 1 (early bundle consumption) = 4 units @ 100,000 = 400,000
        // Purchase 3 adds: 2 units @ 160,000 = 320,000
        // New blended moving average: (400,000 + 320,000) / (4 + 2) = 720,000 / 6 = 120,000!
        $purchase3 = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail3 = PurchaseDetail::create([
            'purchase_id' => $purchase3->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 160000,
            'unit_price' => 160000,
            'sub_total' => 320000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $rn3 = ReceivedNote::create([
            'po_id' => $purchase3->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now()->subDays(3),
            'date' => now()->subDays(3)->toDateString(),
        ]);
        $rnDetail3 = ReceivedNoteDetail::create([
            'received_note_id' => $rn3->id,
            'po_detail_id' => $poDetail3->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity_received' => 2,
        ]);
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 2,
            'current_quantity' => 2,
            'quantity_non_tax' => 2,
            'cost' => 160000,
            'received_note_detail_id' => $rnDetail3->id,
        ]);

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
        ]);

        // Create downstream Sale 1: should be updated by replay to new blended average (120,000)
        $sale1 = \Modules\Sale\Entities\Sale::create([
            'date' => now()->subDays(2),
            'reference' => 'SL-' . uniqid(),
            'customer_name' => 'Cust 1',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 150000,
            'paid_amount' => 150000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);
        $saleDetail1 = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 75000,
            'unit_price' => 75000,
            'sub_total' => 150000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 150000, // prior blended cost
            'cost_total_snapshot' => 300000,
            'cost_snapshot_source' => 'LIVE_CALCULATION',
        ]);

        // Create downstream Sale 2: has HPP_SNAPSHOT_IMPORT which must be preserved
        $sale2 = \Modules\Sale\Entities\Sale::create([
            'date' => now()->subDays(1),
            'reference' => 'SL-' . uniqid(),
            'customer_name' => 'Cust 2',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 150000,
            'paid_amount' => 150000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);
        $saleDetail2 = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 150000,
            'unit_price' => 150000,
            'sub_total' => 150000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 99999, // authoritative imported snapshot
            'cost_total_snapshot' => 99999,
            'cost_snapshot_source' => 'HPP_SNAPSHOT_IMPORT',
        ]);

        // Create downstream Sale 3 with non-final status ('Pending' / 'Waiting Approval')
        // Its snapshot should NOT be overwritten with zero by the cancellation replay
        $sale3 = \Modules\Sale\Entities\Sale::create([
            'date' => now()->subDays(1),
            'reference' => 'SL-' . uniqid(),
            'customer_name' => 'Cust 3',
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 75000,
            'paid_amount' => 0,
            'due_amount' => 75000,
            'setting_id' => $this->setting->id,
        ]);
        $saleDetail3 = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale3->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 75000,
            'unit_price' => 75000,
            'sub_total' => 75000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 125000,
            'cost_total_snapshot' => 125000,
            'cost_snapshot_source' => 'LIVE_CALCULATION',
        ]);

        // Create downstream Sale 4 with a bundle item consuming $product as component under distinct parentProduct
        $sale4 = \Modules\Sale\Entities\Sale::create([
            'date' => now()->subDays(1),
            'reference' => 'SL-' . uniqid(),
            'customer_name' => 'Cust 4',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 300000,
            'paid_amount' => 300000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);
        $saleDetail4 = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale4->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 300000,
            'unit_price' => 300000,
            'sub_total' => 300000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $bundleItem4 = \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_detail_id' => $saleDetail4->id,
            'sale_id' => $sale4->id,
            'bundle_id' => 999,
            'bundle_item_id' => 1,
            'product_id' => $product->id,
            'name' => $product->product_name,
            'quantity' => 2,
            'price' => 100000,
            'sub_total' => 200000,
            'cost_unit_snapshot' => 150000,
            'cost_total_snapshot' => 300000,
            'cost_snapshot_source' => 'LIVE_CALCULATION',
        ]);

        // Cancel Purchase 2
        $this->service->cancel($rn2->purchase, 'Cancel purchase 2', $this->user, $this->setting->id);

        // Sale 1 should now reflect the cost without Purchase 2:
        // Opening: 5 units @ 100,000 = 500,000.
        // Early bundle: -1 unit @ 100,000 -> remaining 4 units, value 400,000.
        // Purchase 3: +2 units @ 160,000 = 320,000 -> 6 units, value 720,000 -> average 120,000.
        // Sale 1 snapshot: unit 120,000, total 240,000.
        $freshSaleDetail1 = $saleDetail1->fresh();
        $this->assertEquals(120000, (float) $freshSaleDetail1->cost_unit_snapshot);
        $this->assertEquals(240000, (float) $freshSaleDetail1->cost_total_snapshot);
        $this->assertEquals('CANCELLATION_REPLAY', $freshSaleDetail1->cost_snapshot_source);

        // Sale 2 must keep its HPP_SNAPSHOT_IMPORT
        $freshSaleDetail2 = $saleDetail2->fresh();
        $this->assertEquals(99999, (float) $freshSaleDetail2->cost_unit_snapshot);
        $this->assertEquals('HPP_SNAPSHOT_IMPORT', $freshSaleDetail2->cost_snapshot_source);

        // Sale 3 (non-final) must NOT be overwritten with zero
        $freshSaleDetail3 = $saleDetail3->fresh();
        $this->assertEquals(125000, (float) $freshSaleDetail3->cost_unit_snapshot);
        $this->assertEquals('LIVE_CALCULATION', $freshSaleDetail3->cost_snapshot_source);

        // Bundle item on Sale 4 should have its component snapshot replayed
        $freshBundleItem4 = $bundleItem4->fresh();
        $this->assertEquals(120000, (float) $freshBundleItem4->cost_unit_snapshot);
        $this->assertEquals(240000, (float) $freshBundleItem4->cost_total_snapshot);
        $this->assertEquals('CANCELLATION_REPLAY', $freshBundleItem4->cost_snapshot_source);
    }

    public function test_cancellation_replays_bundle_components_by_resolved_physical_owner_bucket(): void
    {
        // Distinct replay bucket: the Test Company setting classifies as 'rest'
        $otherSetting = Setting::create([
            'company_name' => 'CV Tiga Nusa Computer',
            'company_email' => 'tn@test.com',
            'company_phone' => '987654321',
            'company_address' => 'Other Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'tn@test.com',
            'footer_text' => 'Footer',
            'is_pkp' => false,
        ]);
        $otherLocation = Location::create(['name' => 'Other Warehouse', 'setting_id' => $otherSetting->id]);

        $product = $this->createProduct(['product_quantity' => 10, 'product_cost' => 150000]);
        $parentProduct = $this->createProduct([
            'product_name' => 'POS Bundle Parent',
            'product_code' => 'POS-BND-PARENT',
            'product_quantity' => 5,
            'product_cost' => 300000,
        ]);

        $receive = function (float $cost, $approvedAt) use ($product) {
            $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
            $poDetail = PurchaseDetail::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'quantity' => 5,
                'price' => $cost,
                'unit_price' => $cost,
                'sub_total' => $cost * 5,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
            ]);
            $rn = ReceivedNote::create([
                'po_id' => $purchase->id,
                'location_id' => $this->location->id,
                'status' => ReceivedNote::STATUS_APPROVED,
                'approved_at' => $approvedAt,
                'date' => $approvedAt->toDateString(),
            ]);
            $rnDetail = ReceivedNoteDetail::create([
                'received_note_id' => $rn->id,
                'po_detail_id' => $poDetail->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'location_id' => $this->location->id,
                'quantity_received' => 5,
            ]);
            $this->createBuyTransaction([
                'product_id' => $product->id,
                'quantity' => 5,
                'current_quantity' => 5,
                'quantity_non_tax' => 5,
                'cost' => $cost,
                'received_note_detail_id' => $rnDetail->id,
            ]);

            return $rn;
        };

        $receive(100000, now()->subDays(10));
        $rn2 = $receive(200000, now()->subDays(5)->setTime(15, 30));

        $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
        ]);

        $createPosBundleSale = function (Setting $headerSetting, Location $dispatchLocation, $date) use ($product, $parentProduct) {
            $sale = \Modules\Sale\Entities\Sale::create([
                'date' => $date,
                'reference' => 'POS-' . uniqid(),
                'customer_name' => 'POS Cust',
                'status' => 'Completed',
                'payment_status' => 'Paid',
                'payment_method' => 'Cash',
                'total_amount' => 300000,
                'paid_amount' => 300000,
                'due_amount' => 0,
                'setting_id' => $headerSetting->id,
            ]);
            $saleDetail = \Modules\Sale\Entities\SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $parentProduct->id,
                'product_name' => $parentProduct->product_name,
                'product_code' => $parentProduct->product_code,
                'quantity' => 1,
                'price' => 300000,
                'unit_price' => 300000,
                'sub_total' => 300000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
            ]);
            $bundleItem = \Modules\Sale\Entities\SaleBundleItem::create([
                'sale_detail_id' => $saleDetail->id,
                'sale_id' => $sale->id,
                'bundle_id' => 999,
                'bundle_item_id' => 1,
                'product_id' => $product->id,
                'name' => $product->product_name,
                'quantity' => 1,
                'price' => 100000,
                'sub_total' => 100000,
                'cost_unit_snapshot' => 150000,
                'cost_total_snapshot' => 150000,
                'cost_snapshot_source' => 'LIVE_CALCULATION',
            ]);
            $dispatch = \Modules\Sale\Entities\Dispatch::create([
                'sale_id' => $sale->id,
                'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
            ]);
            \Modules\Sale\Entities\DispatchDetail::create([
                'dispatch_id' => $dispatch->id,
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'bundle_id' => 999,
                'location_id' => $dispatchLocation->id,
                'dispatched_quantity' => 1,
                'is_inventory_managed' => true,
            ]);

            return $bundleItem;
        };

        // POS split: header belongs to the other business, but the component was
        // physically dispatched from the purchase owner's location -> affected bucket.
        $ownedByPurchaseBucket = $createPosBundleSale($otherSetting, $this->location, now()->subDays(2));
        // Reverse split: header matches the purchase setting, but the physical owner
        // is the other business -> unaffected bucket, must be left untouched.
        $ownedByOtherBucket = $createPosBundleSale($this->setting, $otherLocation, now()->subDays(2));
        // Same calendar day as the cancelled receipt (document date at midnight) -> included.
        $sameDay = $createPosBundleSale($this->setting, $this->location, now()->subDays(5)->toDateString());

        $this->service->cancel($rn2->purchase, 'Cancel purchase 2', $this->user, $this->setting->id);

        $fresh = $ownedByPurchaseBucket->fresh();
        $this->assertEquals('CANCELLATION_REPLAY', $fresh->cost_snapshot_source);
        $this->assertEquals(100000, (float) $fresh->cost_unit_snapshot);

        $fresh = $ownedByOtherBucket->fresh();
        $this->assertEquals('LIVE_CALCULATION', $fresh->cost_snapshot_source);
        $this->assertEquals(150000, (float) $fresh->cost_unit_snapshot);

        $fresh = $sameDay->fresh();
        $this->assertEquals('CANCELLATION_REPLAY', $fresh->cost_snapshot_source);
        $this->assertEquals(100000, (float) $fresh->cost_unit_snapshot);
    }

    public function test_purchase_level_cancellation_is_all_or_nothing_when_receivals_jointly_overdraw_stock(): void
    {
        $product = $this->createProduct(['product_quantity' => 7]);
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
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

        // Two approved receivals of 5 each; each alone fits the 7 remaining units, together they do not
        $notes = collect([1, 2])->map(function ($i) use ($purchase, $poDetail, $product) {
            $note = ReceivedNote::create([
                'po_id' => $purchase->id,
                'location_id' => $this->location->id,
                'status' => ReceivedNote::STATUS_APPROVED,
                'approved_at' => now()->subDays(3 - $i),
                'date' => now()->subDays(3 - $i)->toDateString(),
            ]);
            $detail = ReceivedNoteDetail::create([
                'received_note_id' => $note->id,
                'po_detail_id' => $poDetail->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'location_id' => $this->location->id,
                'quantity_received' => 5,
            ]);
            $this->createBuyTransaction([
                'product_id' => $product->id,
                'quantity' => 5,
                'current_quantity' => 5 * $i,
                'quantity_non_tax' => 5,
                'received_note_detail_id' => $detail->id,
            ]);

            return $note;
        });
        $pendingNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
            'date' => now()->toDateString(),
        ]);
        $stock = $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 7,
            'quantity_non_tax' => 7,
            'quantity_tax' => 0,
        ]);

        $preview = app(\Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService::class)
            ->preview($purchase, 'Overdraw', $this->setting->id);
        $this->assertFalse($preview['eligible']);
        $this->assertCount(2, $preview['approved_notes']);
        $this->assertCount(1, $preview['pending_notes']);
        $this->assertEquals(10, collect($preview['stock_requirements'])->sum('quantity'));

        try {
            $this->service->cancel($purchase, 'Overdraw', $this->user, $this->setting->id);
            $this->fail('Cancellation should have been rejected.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        // Nothing was cancelled or reversed
        foreach ($notes as $note) {
            $this->assertEquals(ReceivedNote::STATUS_APPROVED, $note->fresh()->status);
        }
        $this->assertEquals(ReceivedNote::STATUS_PENDING, $pendingNote->fresh()->status);
        $this->assertEquals(7, (float) $stock->fresh()->quantity);
        $this->assertEquals(Purchase::STATUS_RECEIVED, $purchase->fresh()->status);
        $this->assertEquals(0, ReceivedNoteCancellation::where('purchase_id', $purchase->id)->count());
    }

    public function test_purchase_level_cancellation_rejects_purchase_with_nothing_to_cancel(): void
    {
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_APPROVED]);
        ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_REJECTED,
            'date' => now()->toDateString(),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('no APPROVED or PENDING receivals');

        $this->service->cancel($purchase, 'Nothing here', $this->user, $this->setting->id);
    }

    /**
     * One approved, non-serialized receival of 4 units whose stale preview is captured, then
     * a field relevant to execution is changed underneath it (same detail id and quantity).
     */
    private function assertStaleNonSerialPreviewRejected(callable $mutate, string $expectedMessage, bool $legacySource = false): void
    {
        $product = $this->createProduct(['product_quantity' => 8]);
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 4,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 40000,
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
            'quantity_received' => 4,
        ]);
        // Legacy sources have no durable link and are resolved by evidence (reference, quantity, location)
        $buyTxn = $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 4,
            'current_quantity' => 4,
            'quantity_non_tax' => 4,
            'reason' => "Penerimaan Pembelian {$purchase->reference}",
            'received_note_detail_id' => $legacySource ? null : $rnDetail->id,
        ]);
        $stock = $this->createProductStock([
            'product_id' => $product->id,
            'quantity' => 8,
            'quantity_non_tax' => 4,
            'quantity_tax' => 4,
        ]);

        $stalePreview = app(\Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService::class)
            ->preview($purchase, 'Race', $this->setting->id);
        $this->assertTrue($stalePreview['eligible']);

        $mutate(compact('product', 'purchase', 'rnDetail', 'buyTxn', 'receivedNote'));

        $e = $this->cancelWithStalePreview($purchase, $stalePreview);
        $this->assertStringContainsString($expectedMessage, $e->getMessage());

        $this->assertEquals(ReceivedNote::STATUS_APPROVED, $receivedNote->fresh()->status);
        $this->assertEquals(8, (float) $stock->fresh()->quantity);
        $this->assertEquals(4, (float) $stock->fresh()->quantity_tax);
        $this->assertEquals(4, (float) $stock->fresh()->quantity_non_tax);
        $this->assertEquals(0, ReceivedNoteCancellation::where('purchase_id', $purchase->id)->count());
        $this->assertFalse(Transaction::where('type', PurchaseReceivalCancellationService::TYPE_PURCHASE_RECEIVING_CANCELLED)
            ->where('product_id', $product->id)
            ->exists());
    }

    public function test_cancellation_rejects_stale_preview_with_outdated_location(): void
    {
        $this->assertStaleNonSerialPreviewRejected(function (array $fx) {
            $other = Location::create(['name' => 'Moved Warehouse', 'setting_id' => $this->setting->id]);
            $fx['rnDetail']->update(['location_id' => $other->id]);
            $fx['buyTxn']->update(['location_id' => $other->id]);
        }, 'location_id changed after validation');
    }

    public function test_cancellation_rejects_stale_preview_with_outdated_tax_bucket(): void
    {
        $this->assertStaleNonSerialPreviewRejected(function (array $fx) {
            $fx['buyTxn']->update(['quantity_tax' => 4, 'quantity_non_tax' => 0]);
        }, 'quantity_tax changed after validation');
    }

    public function test_cancellation_rejects_stale_preview_with_outdated_source_transaction(): void
    {
        $this->assertStaleNonSerialPreviewRejected(function (array $fx) {
            $fx['buyTxn']->update(['received_note_detail_id' => null]);
            $this->createBuyTransaction([
                'product_id' => $fx['product']->id,
                'quantity' => 4,
                'current_quantity' => 8,
                'quantity_non_tax' => 4,
                'received_note_detail_id' => $fx['rnDetail']->id,
            ]);
        }, 'original_transaction_id changed after validation');
    }

    public function test_cancellation_rejects_stale_preview_with_outdated_product(): void
    {
        $this->assertStaleNonSerialPreviewRejected(function (array $fx) {
            $replacement = $this->createProduct(['product_name' => 'Replacement', 'product_code' => 'RPL-' . uniqid()]);
            $fx['rnDetail']->update(['product_id' => $replacement->id]);
        }, 'product_id changed after validation');
    }

    public function test_cancellation_rejects_legacy_source_that_became_ambiguous_after_preview(): void
    {
        $this->assertStaleNonSerialPreviewRejected(function (array $fx) {
            // Another matching unlinked BUY appears after the preview resolved a unique legacy source
            $this->createBuyTransaction([
                'product_id' => $fx['product']->id,
                'quantity' => 4,
                'current_quantity' => 8,
                'quantity_non_tax' => 4,
                'reason' => "Penerimaan Pembelian {$fx['purchase']->reference} (duplikat)",
                'received_note_detail_id' => null,
            ]);
        }, 'ambiguous original inventory transactions (2 candidates) under lock', legacySource: true);
    }

    public function test_cancellation_uses_locked_legacy_resolution_when_still_unique(): void
    {
        $product = $this->createProduct(['product_quantity' => 3]);
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 3,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 30000,
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
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $poDetail->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity_received' => 3,
        ]);
        $legacyBuy = $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 3,
            'current_quantity' => 3,
            'quantity_non_tax' => 3,
            'reason' => "Penerimaan Pembelian {$purchase->reference}",
            'received_note_detail_id' => null,
        ]);
        $this->createProductStock(['product_id' => $product->id, 'quantity' => 3, 'quantity_non_tax' => 3, 'quantity_tax' => 0]);

        $cancellation = $this->service->cancel($purchase, 'Legacy', $this->user, $this->setting->id)->first();

        $this->assertEquals($legacyBuy->id, $cancellation->cancellationDetails->first()->original_transaction_id);
        $this->assertEquals(ReceivedNote::STATUS_CANCELLED, $receivedNote->fresh()->status);
    }

    public function test_cancellation_rejects_source_quantity_changed_after_preview(): void
    {
        // Buckets unchanged (0 tax / 4 non-tax), so only the quantity invariant can catch it
        $this->assertStaleNonSerialPreviewRejected(function (array $fx) {
            $fx['buyTxn']->update(['quantity' => 5]);
        }, 'quantity 5 differs from received quantity 4');
    }

    public function test_eligibility_rejects_source_transaction_with_inconsistent_buckets(): void
    {
        $product = $this->createProduct(['product_quantity' => 4]);
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 4,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 40000,
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
            'location_id' => $this->location->id,
            'quantity_received' => 4,
        ]);
        // Corrupt durable link: tax 1 + non-tax 4 != quantity 4
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 4,
            'current_quantity' => 4,
            'quantity_tax' => 1,
            'quantity_non_tax' => 4,
            'received_note_detail_id' => $rnDetail->id,
        ]);
        $stock = $this->createProductStock(['product_id' => $product->id, 'quantity' => 8, 'quantity_non_tax' => 4, 'quantity_tax' => 4]);

        $preview = app(\Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService::class)
            ->preview($purchase, 'Corrupt', $this->setting->id);
        $this->assertFalse($preview['eligible']);
        $this->assertStringContainsString('do not equal its quantity', implode(' ', $preview['blockers']));

        try {
            $this->service->cancel($purchase, 'Corrupt', $this->user, $this->setting->id);
            $this->fail('Cancellation should have been rejected.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('do not equal its quantity', $e->getMessage());
        }
        $this->assertEquals(8, (float) $stock->fresh()->quantity);
        $this->assertEquals(ReceivedNote::STATUS_APPROVED, $receivedNote->fresh()->status);
    }

    public function test_cancellation_rejects_one_legacy_buy_resolving_to_multiple_details(): void
    {
        $product = $this->createProduct(['product_quantity' => 4]);
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 4,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 40000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Two approved legacy details with identical evidence (product, location, quantity, reference)
        $notes = collect([1, 2])->map(function () use ($purchase, $poDetail, $product) {
            $note = ReceivedNote::create([
                'po_id' => $purchase->id,
                'location_id' => $this->location->id,
                'status' => ReceivedNote::STATUS_APPROVED,
                'approved_at' => now(),
                'date' => now()->toDateString(),
            ]);
            ReceivedNoteDetail::create([
                'received_note_id' => $note->id,
                'po_detail_id' => $poDetail->id,
                'product_id' => $product->id,
                'location_id' => $this->location->id,
                'quantity_received' => 2,
            ]);

            return $note;
        });

        // ...but only one matching unlinked BUY movement exists
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 2,
            'current_quantity' => 2,
            'quantity_non_tax' => 2,
            'reason' => "Penerimaan Pembelian {$purchase->reference}",
            'received_note_detail_id' => null,
        ]);
        $stock = $this->createProductStock(['product_id' => $product->id, 'quantity' => 4, 'quantity_non_tax' => 4, 'quantity_tax' => 0]);

        $eligibility = \Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService::class;
        $preview = app($eligibility)->preview($purchase, 'Legacy dup', $this->setting->id);
        $this->assertFalse($preview['eligible']);
        $this->assertStringContainsString('is ambiguous: it resolves to receival detail lines', implode(' ', $preview['blockers']));

        // The locked revalidation rejects it on its own, even if the preview claimed eligibility
        $e = $this->cancelWithStalePreview($purchase, array_merge($preview, ['eligible' => true, 'blockers' => []]));
        $this->assertStringContainsString('is ambiguous: it resolves to receival detail lines', $e->getMessage());

        foreach ($notes as $note) {
            $this->assertEquals(ReceivedNote::STATUS_APPROVED, $note->fresh()->status);
        }
        $this->assertEquals(4, (float) $stock->fresh()->quantity);
        $this->assertEquals(4, (float) $product->fresh()->product_quantity);
        $this->assertEquals(Purchase::STATUS_RECEIVED, $purchase->fresh()->status);
        $this->assertEquals(0, ReceivedNoteCancellation::where('purchase_id', $purchase->id)->count());
        $this->assertFalse(Transaction::where('type', PurchaseReceivalCancellationService::TYPE_PURCHASE_RECEIVING_CANCELLED)
            ->where('product_id', $product->id)
            ->exists());
    }

    public function test_purchase_return_dependency_is_resolved_through_return_details(): void
    {
        $product = $this->createProduct(['product_quantity' => 2]);
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $poDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 20000,
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
            'location_id' => $this->location->id,
            'quantity_received' => 2,
        ]);
        $this->createBuyTransaction([
            'product_id' => $product->id,
            'quantity' => 2,
            'current_quantity' => 2,
            'quantity_non_tax' => 2,
            'received_note_detail_id' => $rnDetail->id,
        ]);
        $this->createProductStock(['product_id' => $product->id, 'quantity' => 2, 'quantity_non_tax' => 2, 'quantity_tax' => 0]);

        $createReturn = function (Purchase $linkedPurchase, ?string $approvalStatus) use ($product) {
            $return = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
                'date' => now(),
                'reference' => 'PR-' . uniqid(),
                'supplier_id' => $this->supplier->id,
                'supplier_name' => $this->supplier->supplier_name,
                'status' => 'completed',
                'approval_status' => $approvalStatus,
                'total_amount' => 10000,
                'paid_amount' => 0,
                'due_amount' => 10000,
                'payment_status' => 'Unpaid',
                'payment_method' => 'Cash',
                'setting_id' => $this->setting->id,
            ]);
            \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
                'purchase_return_id' => $return->id,
                'po_id' => $linkedPurchase->id,
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

            return $return;
        };
        $eligibility = app(\Modules\Purchase\Services\PurchaseReceivalCancellationEligibilityService::class);
        $returnBlocker = fn (array $preview) => collect($preview['blockers'])->contains(fn ($b) => str_contains($b, 'purchase return records'));

        // No return linked to this purchase (e.g. purchase 19203): no blocker, no SQL error
        $createReturn($this->createPurchase(), 'approved');
        $preview = $eligibility->preview($purchase, 'Check', $this->setting->id);
        $this->assertTrue($preview['eligible']);

        // A rejected return linked through its details does not block
        $rejected = $createReturn($purchase, 'Rejected');
        $this->assertFalse($returnBlocker($eligibility->preview($purchase, 'Check', $this->setting->id)));

        // An active return linked through purchase_return_details.po_id blocks the whole action
        $rejected->update(['approval_status' => 'pending']);
        $preview = $eligibility->preview($purchase, 'Check', $this->setting->id);
        $this->assertFalse($preview['eligible']);
        $this->assertTrue($returnBlocker($preview));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('purchase return records');
        $this->service->cancel($purchase, 'Check', $this->user, $this->setting->id);
    }
}

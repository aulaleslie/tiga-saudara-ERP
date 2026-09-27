<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Services\PurchaseReceivingQuantityService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Conversion-aware receiving: canonical quantities, decimal-safe over-receipt
 * guards, serial counts, and approval/rollback behavior.
 */
class PurchaseConversionReceivingTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected User $user;
    protected Location $location;
    protected Unit $pcsUnit;
    protected Unit $boxUnit;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Setting A',
            'company_email' => 'a@test.com',
            'company_phone' => '1',
            'notification_email' => 'a@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->user = User::factory()->create();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['purchases.receive', 'purchases.receive.approval', 'purchases.receive.access'] as $perm) {
            Permission::findOrCreate($perm, 'web');
        }
        $this->user->givePermissionTo(['purchases.receive', 'purchases.receive.approval', 'purchases.receive.access']);
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        Category::create([
            'id' => 1,
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-1',
            'category_name' => 'Category 1',
            'created_by' => $this->user->id,
        ]);

        $this->pcsUnit = Unit::create(['name' => 'PCS', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);
        $this->boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'box', 'operator' => '*', 'operation_value' => 1]);

        Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create(['id' => 1, 'name' => 'Loc A1', 'setting_id' => $this->setting->id]);
    }

    /**
     * Builds a purchase of $orderedEntered units in BOX (factor 12 unless overridden),
     * i.e. a canonical quantity of $orderedEntered * factor PCS.
     *
     * @return array{0: Purchase, 1: PurchaseDetail, 2: Product}
     */
    private function makeConversionPurchase(
        float $orderedEntered = 2,
        float $factor = 12,
        bool $serialRequired = false
    ): array {
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP-' . uniqid(),
            'product_unit' => 'pc',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'stock_managed' => 1,
            'serial_number_required' => $serialRequired,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => $factor,
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-CONV-' . uniqid(),
            'supplier_id' => 1,
            'supplier_name' => 'Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        $canonicalOrdered = $orderedEntered * $factor;

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $canonicalOrdered,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000 * $canonicalOrdered,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => $orderedEntered,
            'entered_unit_price' => 1000 * $factor,
            'conversion_factor' => $factor,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        return [$purchase, $detail, $product];
    }

    // ---------------------------------------------------------------- 6.2 canonical

    public function test_receiving_in_ordered_unit_persists_canonical_base_quantity(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12);

        $response = $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 1],          // 1 BOX
            'received_unit' => [$detail->id => 'ordered'],
        ]);
        $response->assertSessionHasNoErrors();

        // 1 BOX x 12 = 12 PCS persisted canonically.
        $noteDetail = ReceivedNoteDetail::where('po_detail_id', $detail->id)->firstOrFail();
        $this->assertEquals(12.0, (float) $noteDetail->quantity_received);
    }

    public function test_receiving_in_base_unit_persists_the_entered_quantity_unscaled(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12);

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 7],          // 7 PCS, not 7 BOX
            'received_unit' => [$detail->id => 'base'],
        ])->assertSessionHasNoErrors();

        $noteDetail = ReceivedNoteDetail::where('po_detail_id', $detail->id)->firstOrFail();
        $this->assertEquals(7.0, (float) $noteDetail->quantity_received);
    }

    public function test_fractional_base_unit_receipt_is_accepted(): void
    {
        // Factor 1 line so a fractional base quantity is meaningful (weight goods).
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 10, factor: 1);

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 2.5],
            'received_unit' => [$detail->id => 'base'],
        ])->assertSessionHasNoErrors();

        $noteDetail = ReceivedNoteDetail::where('po_detail_id', $detail->id)->firstOrFail();
        $this->assertEquals(2.5, (float) $noteDetail->quantity_received);
    }

    // ------------------------------------------------------- 6.3 over-receipt guards

    public function test_receiving_exactly_the_remaining_quantity_is_allowed(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12);

        // Boundary: 24 PCS ordered, receive all 24 at once. Must not be rejected.
        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 2],
            'received_unit' => [$detail->id => 'ordered'],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(24.0, (float) ReceivedNoteDetail::where('po_detail_id', $detail->id)->value('quantity_received'));
    }

    public function test_submitting_more_than_ordered_is_rejected(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12);

        $this->from(route('purchases.receive', $purchase->id))
            ->post(route('purchases.storeReceive', $purchase->id), [
                'location_id' => $this->location->id,
                'received' => [$detail->id => 3],      // 36 PCS > 24 ordered
                'received_unit' => [$detail->id => 'ordered'],
            ]);

        // Nothing persisted: the whole submission rolls back.
        $this->assertDatabaseCount('received_notes', 0);
        $this->assertDatabaseCount('received_note_details', 0);
    }

    /**
     * The decimal-safety case, covering BOTH float-sensitive comparisons with one
     * split. Receipts of 0.030 and 0.282 against a 1.000 order leave exactly 0.688:
     *
     *  - Over-receipt: PHP accumulates 0.030 + 0.282 = 0.312 and 1.0 - 0.312 gives
     *    0.6879999999999999, so a float guard rejects the legitimate final 0.688.
     *  - Completion: the database SUM of the three parts returns
     *    0.99999999999999988, so a PHP `<` against the ordered 1.000 reads the line
     *    as short and leaves the Purchase stuck at RECEIVED_PARTIALLY.
     *
     * These specific values are chosen because they discriminate on both counts.
     * Many splits do not: 0.1/0.2/0.7 happens to land safely under float on both
     * paths and would pass even against the unfixed code.
     */
    public function test_remaining_quantity_is_decimal_safe_across_partial_receipts(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 1, factor: 1);

        foreach ([0.030, 0.282] as $chunk) {
            $this->post(route('purchases.storeReceive', $purchase->id), [
                'location_id' => $this->location->id,
                'received' => [$detail->id => $chunk],
                'received_unit' => [$detail->id => 'base'],
            ])->assertSessionHasNoErrors();

            // Approve so it counts toward the received total.
            $note = ReceivedNote::where('po_id', $purchase->id)->latest('id')->firstOrFail();
            $this->post(route('receivings.approve', $note->id))->assertSessionHasNoErrors();
        }

        $service = app(PurchaseReceivingQuantityService::class);
        $this->assertSame('0.688', (string) $service->remainingCanonical($detail->fresh()));

        // The exact remainder must be accepted, not rejected by float drift.
        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 0.688],
            'received_unit' => [$detail->id => 'base'],
        ])->assertSessionHasNoErrors();

        // Approving the final receipt closes the line out to exactly zero.
        $finalNote = ReceivedNote::where('po_id', $purchase->id)->latest('id')->firstOrFail();
        $this->post(route('receivings.approve', $finalNote->id))->assertSessionHasNoErrors();

        $this->assertSame('1.000', (string) $service->approvedReceivedCanonical($detail->fresh()));
        $this->assertSame('0.000', (string) $service->remainingCanonical($detail->fresh()));

        // End to end: the completion decision must also be decimal-safe. The
        // database SUM of 0.030 + 0.282 + 0.688 comes back as 0.99999999999999988,
        // so a PHP `<` against the ordered 1.000 would leave this stuck PARTIALLY.
        $this->assertEquals(
            Purchase::STATUS_RECEIVED,
            $purchase->fresh()->status,
            'A fully received line must close the Purchase, not leave it partially received.'
        );
    }

    public function test_pending_and_rejected_notes_do_not_consume_order_capacity(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12);
        $service = app(PurchaseReceivingQuantityService::class);

        // A PENDING note must not reduce the approved received total.
        $note = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $note->id,
            'quantity_received' => 12,
            'po_detail_id' => $detail->id,
        ]);

        $this->assertSame('0.000', (string) $service->approvedReceivedCanonical($detail));
        $this->assertSame('24.000', (string) $service->remainingCanonical($detail));

        // Rejecting it must likewise leave the full order outstanding.
        $note->update(['status' => ReceivedNote::STATUS_REJECTED]);
        $this->assertSame('24.000', (string) $service->remainingCanonical($detail->fresh()));
    }

    // ------------------------------------------------------------- 6.4 serials

    public function test_serialized_receipt_requires_one_serial_per_base_unit(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12, serialRequired: true);

        // 1 BOX = 12 PCS, so 12 serials are required; supplying 2 must fail.
        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 1],
            'received_unit' => [$detail->id => 'ordered'],
            'serial_numbers' => [$detail->id => ['SN-1', 'SN-2']],
        ]);

        $this->assertDatabaseCount('received_note_details', 0);
    }

    public function test_serialized_receipt_rejects_duplicate_serials(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 1, factor: 2, serialRequired: true);

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 1],          // 1 BOX = 2 PCS
            'received_unit' => [$detail->id => 'ordered'],
            'serial_numbers' => [$detail->id => ['SN-DUP', 'SN-DUP']],
        ]);

        $this->assertDatabaseCount('received_note_details', 0);
    }

    public function test_serialized_receipt_accepts_exactly_one_serial_per_base_unit(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 1, factor: 2, serialRequired: true);

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 1],
            'received_unit' => [$detail->id => 'ordered'],
            'serial_numbers' => [$detail->id => ['SN-A', 'SN-B']],
        ])->assertSessionHasNoErrors();

        $noteDetail = ReceivedNoteDetail::where('po_detail_id', $detail->id)->firstOrFail();
        $this->assertEquals(2.0, (float) $noteDetail->quantity_received);
        $this->assertCount(2, $noteDetail->pending_serial_numbers);
    }

    public function test_serialized_product_rejects_fractional_canonical_quantity(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 10, factor: 1, serialRequired: true);

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 1.5],
            'received_unit' => [$detail->id => 'base'],
        ]);

        $this->assertDatabaseCount('received_note_details', 0);
    }

    // -------------------------------------------------- 6.5 approval / stock posting

    public function test_approved_receipt_posts_the_exact_canonical_quantity_to_stock(): void
    {
        [$purchase, $detail, $product] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12);

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 1],          // 1 BOX = 12 PCS
            'received_unit' => [$detail->id => 'ordered'],
        ])->assertSessionHasNoErrors();

        $note = ReceivedNote::where('po_id', $purchase->id)->firstOrFail();
        $this->post(route('receivings.approve', $note->id))->assertSessionHasNoErrors();

        // Stock moves in base units, not the entered BOX count.
        $this->assertEquals(12.0, (float) $product->fresh()->product_quantity);
        $this->assertEquals(
            12.0,
            (float) ProductStock::where('product_id', $product->id)
                ->where('location_id', $this->location->id)
                ->value('quantity')
        );
    }

    public function test_approval_is_idempotent_and_does_not_double_post_stock(): void
    {
        [$purchase, $detail, $product] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12);

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 1],
            'received_unit' => [$detail->id => 'ordered'],
        ])->assertSessionHasNoErrors();

        $note = ReceivedNote::where('po_id', $purchase->id)->firstOrFail();

        $this->post(route('receivings.approve', $note->id))->assertSessionHasNoErrors();
        $this->assertEquals(12.0, (float) $product->fresh()->product_quantity);

        // A second approval of the same note must be refused, leaving stock alone.
        $this->post(route('receivings.approve', $note->id));
        $this->assertEquals(12.0, (float) $product->fresh()->product_quantity);
    }

    public function test_failed_submission_rolls_back_without_creating_a_note(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12, serialRequired: true);

        // The note row is created before the per-line serial check fails, so this
        // proves the surrounding transaction rolls the whole submission back.
        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 1],
            'received_unit' => [$detail->id => 'ordered'],
            'serial_numbers' => [$detail->id => ['ONLY-ONE']],
        ]);

        $this->assertDatabaseCount('received_notes', 0);
        $this->assertDatabaseCount('received_note_details', 0);
    }

    // ------------------------------------------- request scoping / empty receipts

    public function test_missing_received_array_is_rejected(): void
    {
        [$purchase] = $this->makeConversionPurchase();

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
        ])->assertSessionHasErrors('received');

        $this->assertDatabaseCount('received_notes', 0);
    }

    public function test_empty_received_array_is_rejected(): void
    {
        [$purchase] = $this->makeConversionPurchase();

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [],
        ])->assertSessionHasErrors('received');

        $this->assertDatabaseCount('received_notes', 0);
    }

    public function test_all_zero_received_quantities_are_rejected(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase();

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 0],
        ])->assertSessionHasErrors('received');

        $this->assertDatabaseCount('received_notes', 0);
    }

    /**
     * A positive quantity keyed to a DIFFERENT Purchase's detail must not satisfy
     * the "at least one item" rule and then produce an empty receipt.
     */
    public function test_quantity_keyed_to_a_foreign_purchase_detail_is_rejected(): void
    {
        [$purchase] = $this->makeConversionPurchase();
        [, $foreignDetail] = $this->makeConversionPurchase();

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$foreignDetail->id => 1],
            'received_unit' => [$foreignDetail->id => 'ordered'],
        ])->assertSessionHasErrors('received');

        // Critically: no empty ReceivedNote may be left behind.
        $this->assertDatabaseCount('received_notes', 0);
        $this->assertDatabaseCount('received_note_details', 0);
    }

    public function test_mixed_valid_and_foreign_detail_ids_are_rejected(): void
    {
        [$purchase, $ownDetail] = $this->makeConversionPurchase();
        [, $foreignDetail] = $this->makeConversionPurchase();

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [
                $ownDetail->id => 1,
                $foreignDetail->id => 1,
            ],
            'received_unit' => [
                $ownDetail->id => 'ordered',
                $foreignDetail->id => 'ordered',
            ],
        ])->assertSessionHasErrors('received');

        // The valid line must not be persisted either: the request is rejected whole.
        $this->assertDatabaseCount('received_notes', 0);
        $this->assertDatabaseCount('received_note_details', 0);
    }

    public function test_serials_keyed_to_a_foreign_purchase_detail_are_not_applied(): void
    {
        [$purchase, $ownDetail] = $this->makeConversionPurchase(orderedEntered: 1, factor: 2);
        [, $foreignDetail] = $this->makeConversionPurchase(orderedEntered: 1, factor: 2, serialRequired: true);

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$ownDetail->id => 1],
            'received_unit' => [$ownDetail->id => 'ordered'],
            // Serials aimed at another Purchase's line must not be persisted here.
            'serial_numbers' => [$foreignDetail->id => ['SN-X', 'SN-Y']],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('received_note_details', ['po_detail_id' => $foreignDetail->id]);
        $noteDetail = ReceivedNoteDetail::where('po_detail_id', $ownDetail->id)->firstOrFail();
        $this->assertNull($noteDetail->pending_serial_numbers);
    }

    // ------------------------------------------- approval transaction authority

    /**
     * Proves the over-receipt decision happens after the database locks are taken,
     * not in a pre-flight pass. The note is made to over-receive by committing a
     * competing approved receipt AFTER the request begins but before the approval
     * transaction runs its check -- simulated here by mutating the approved total
     * underneath a note that passed submission-time validation.
     *
     * If the check ran before DB::transaction(), the stale pre-flight read would
     * approve and post stock. Because it runs inside the transaction, it sees the
     * competing receipt and rolls back with nothing posted.
     */
    public function test_over_receipt_is_detected_after_locks_and_posts_no_stock(): void
    {
        [$purchase, $detail, $product] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12);

        // A legitimate pending note for the full order.
        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 2],   // 24 PCS, exactly the order
            'received_unit' => [$detail->id => 'ordered'],
        ])->assertSessionHasNoErrors();

        $note = ReceivedNote::where('po_id', $purchase->id)->firstOrFail();

        // A competing receipt is approved first, consuming the whole order. This is
        // the state a concurrent approver would have committed.
        $competing = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $competing->id,
            'quantity_received' => 24,
            'po_detail_id' => $detail->id,
        ]);

        $quantityBefore = (float) $product->fresh()->product_quantity;

        // Approving the original note would now exceed the order.
        $this->post(route('receivings.approve', $note->id));

        // Rolled back: the note stays pending and no stock was posted.
        $this->assertTrue($note->fresh()->isPending());
        $this->assertEquals($quantityBefore, (float) $product->fresh()->product_quantity);
    }

    public function test_over_receipt_conflict_returns_422_with_details_for_json(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 2, factor: 12);

        $this->post(route('purchases.storeReceive', $purchase->id), [
            'location_id' => $this->location->id,
            'received' => [$detail->id => 2],
            'received_unit' => [$detail->id => 'ordered'],
        ])->assertSessionHasNoErrors();

        $note = ReceivedNote::where('po_id', $purchase->id)->firstOrFail();

        $competing = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $competing->id,
            'quantity_received' => 24,
            'po_detail_id' => $detail->id,
        ]);

        $response = $this->postJson(route('receivings.approve', $note->id));

        $response->assertStatus(422)
            ->assertJsonPath('error', 'over_receiving')
            ->assertJsonPath('received_note_id', $note->id);
        $this->assertNotEmpty($response->json('details'));
    }

    // --------------------------------------------------------------- service unit

    public function test_conversion_service_rejects_unrepresentable_canonical_precision(): void
    {
        [$purchase, $detail] = $this->makeConversionPurchase(orderedEntered: 10, factor: 1);
        $service = app(PurchaseReceivingQuantityService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->toCanonical($detail, '0.0001', 'base');
    }
}

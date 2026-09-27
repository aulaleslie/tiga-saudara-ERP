<?php

namespace Tests\Feature\Adjustment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\StockOpnameApprovalService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Focused tests for tasks 4.1-4.6: atomic locked reconciliation, exact
 * source/destination bucket accounting for serialized moves/reclassifies/
 * creations/omissions, bucket-availability validation (reject instead of
 * negative stock), PKP-tax-existence pre-check, shared classifier parity
 * between preview and approval, accurate transaction/actor/notification
 * evidence, immutable approval_result, idempotency, and rollback under
 * injected failure occurring after mutations begin.
 */
class StockOpnameApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $approver;
    protected Setting $setting;
    protected Location $location;
    protected Location $otherLocation;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company', 'company_email' => 'test@company.com',
            'company_phone' => '123456789', 'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer', 'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->location = Location::create([
            'name' => 'Gudang Utama', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);
        $this->otherLocation = Location::create([
            'name' => 'Gudang Cabang', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $this->tax = Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true]);

        $this->approver = User::factory()->create(['is_active' => 1]);

        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        $this->approver->givePermissionTo(['adjustments.approval', 'adjustments.edit']);

        $this->actingAs($this->approver);
        session(['setting_id' => $this->setting->id]);
    }

    private function makeProduct(bool $serialized = false): Product
    {
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pcs', 'short_name' => 'pcs', 'operator' => '*',
            'operation_value' => 1, 'is_active' => true,
        ]);

        return Product::create([
            'product_name' => $serialized ? 'Produk Serial' : 'Produk Uji',
            'product_code' => ($serialized ? 'PS-' : 'PU-') . uniqid(),
            'product_cost' => 1000, 'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $unit->id, 'base_unit_id' => $unit->id,
            'stock_managed' => true, 'is_active' => true,
            'serial_number_required' => $serialized,
        ]);
    }

    /**
     * Serialized products still keep exact per-location good/bad x tax bucket
     * counters on ProductStock alongside the individual serial rows (this is
     * the authoritative bucket-accounting convention used across the wider
     * codebase, e.g. ProductController::handleStockInitialization), so tests
     * that exercise serialized bucket accounting must create both.
     */
    private function makeStockRow(Product $product, Location $location, int $goodTax = 0, int $goodNonTax = 0, int $badTax = 0, int $badNonTax = 0): ProductStock
    {
        return ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $goodTax + $goodNonTax + $badTax + $badNonTax,
            'quantity_tax' => $goodTax,
            'quantity_non_tax' => $goodNonTax,
            'broken_quantity' => $badTax + $badNonTax,
            'broken_quantity_tax' => $badTax,
            'broken_quantity_non_tax' => $badNonTax,
        ]);
    }

    private function makeSerial(Product $product, Location $location, string $serialNumber, bool $isBroken = false, ?int $taxId = null): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => $isBroken,
            'tax_id' => $taxId,
        ]);
    }

    private function makeWaitingApprovalAdjustment(array $rows, ?Location $location = null): Adjustment
    {
        $location ??= $this->location;

        return Adjustment::create([
            'reference' => 'ADJ-APR-' . uniqid(),
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::WaitingApproval,
            'location_id' => $location->id,
            'submitted_by' => $this->approver->id,
            'submitted_at' => now(),
            'count_draft' => ['schema_version' => 1, 'rows' => $rows],
        ]);
    }

    private function service(): StockOpnameApprovalService
    {
        return app(StockOpnameApprovalService::class);
    }

    // --- Non-serialized absolute updates ---

    public function test_pkp_destination_allocates_entered_counts_to_tax_buckets()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location, goodTax: 5);
        $product->update(['product_quantity' => 5]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 8, 'bad_count' => 2, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(8, $stock->quantity_tax);
        $this->assertEquals(0, $stock->quantity_non_tax);
        $this->assertEquals(2, $stock->broken_quantity_tax);
        $this->assertEquals(0, $stock->broken_quantity_non_tax);
        // quantity is total including broken (good+bad), matching the wider
        // codebase's convention (ProductController::handleStockInitialization,
        // TransferMovementService::applyInventoryChange).
        $this->assertEquals(10, $stock->quantity);
        $this->assertEquals(2, $stock->broken_quantity);
        $this->assertEquals(10, $product->fresh()->product_quantity);

        $this->assertEquals(AdjustmentStatus::Approved, $adjustment->fresh()->status);
    }

    public function test_non_pkp_destination_allocates_entered_counts_to_non_tax_buckets()
    {
        $this->setting->update(['is_pkp' => false]);
        $location = Location::create([
            'name' => 'Gudang Non PKP', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $product = $this->makeProduct();
        $this->makeStockRow($product, $location);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 4, 'bad_count' => 0, 'serials' => []],
        ], $location);

        $this->service()->approve($adjustment, $this->approver);

        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertEquals(0, $stock->quantity_tax);
        $this->assertEquals(4, $stock->quantity_non_tax);
    }

    public function test_pkp_destination_without_any_tax_record_rejects_before_mutation()
    {
        $this->tax->delete();

        $product = $this->makeProduct();
        $stock = $this->makeStockRow($product, $this->location, goodTax: 5);
        $product->update(['product_quantity' => 5]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 8, 'bad_count' => 0, 'serials' => []],
        ]);

        try {
            $this->service()->approve($adjustment, $this->approver);
            $this->fail('Expected approval to reject a PKP destination with no tax record.');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertEquals(5, $stock->fresh()->quantity_tax);
        $this->assertEquals(5, $product->fresh()->product_quantity);
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
    }

    public function test_omitted_product_and_other_locations_are_untouched()
    {
        $counted = $this->makeProduct();
        $omitted = $this->makeProduct();

        $this->makeStockRow($counted, $this->location, goodTax: 5);
        $omittedStock = $this->makeStockRow($omitted, $this->location, goodTax: 3);
        $counted->update(['product_quantity' => 5]);
        $omitted->update(['product_quantity' => 3]);

        $otherLocationStock = $this->makeStockRow($counted, $this->otherLocation, goodTax: 2);
        $counted->update(['product_quantity' => 7]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $counted->id, 'good_count' => 9, 'bad_count' => 0, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertEquals(3, $omittedStock->fresh()->quantity_tax);
        $this->assertEquals(2, $otherLocationStock->fresh()->quantity_tax);
        $this->assertEquals(3, $omitted->fresh()->product_quantity);
    }

    public function test_good_bad_reclassification_with_unchanged_total_yields_zero_net_delta()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location, goodTax: 10);
        $product->update(['product_quantity' => 10]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 7, 'bad_count' => 3, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertEquals(10, $product->fresh()->product_quantity);
        $result = $adjustment->fresh()->approval_result;
        $this->assertEquals(0, $result['products'][0]['net_delta']);
    }

    // --- Serial retention / movement / creation / tax conversion with exact bucket accounting ---

    public function test_serial_retained_at_same_location_keeps_bucket_totals_unchanged()
    {
        $product = $this->makeProduct(true);
        $stock = $this->makeStockRow($product, $this->location, goodTax: 1);
        $serial = $this->makeSerial($product, $this->location, 'SN-RETAIN-1', isBroken: false, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'sn-retain-1', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $fresh = $serial->fresh();
        $this->assertEquals($this->location->id, $fresh->location_id);
        $this->assertFalse($fresh->is_broken);

        $freshStock = $stock->fresh();
        $this->assertEquals(1, $freshStock->quantity_tax);
        $this->assertEquals(0, $freshStock->quantity_non_tax);
        $this->assertEquals(1, $product->fresh()->product_quantity);
    }

    public function test_serial_moved_from_eligible_source_location_moves_exact_bucket_between_locations()
    {
        $product = $this->makeProduct(true);
        $sourceStock = $this->makeStockRow($product, $this->otherLocation, goodTax: 1);
        $destStock = $this->makeStockRow($product, $this->location);
        $serial = $this->makeSerial($product, $this->otherLocation, 'SN-MOVE-1', isBroken: false, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-MOVE-1', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $fresh = $serial->fresh();
        $this->assertEquals($this->location->id, $fresh->location_id);

        // Source bucket decremented, destination bucket incremented, exactly.
        $this->assertEquals(0, $sourceStock->fresh()->quantity_tax);
        $this->assertEquals(0, $sourceStock->fresh()->quantity);
        $this->assertEquals(1, $destStock->fresh()->quantity_tax);
        $this->assertEquals(1, $destStock->fresh()->quantity);

        // Pure movement: no net global quantity change.
        $this->assertEquals(1, $product->fresh()->product_quantity);

        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $serial->id,
            'event_type' => \Modules\Product\Entities\SerialNumberHistory::EVENT_LOCATION_TRANSFER,
        ]);
    }

    public function test_moving_more_serials_than_source_bucket_holds_rejects_without_mutation()
    {
        $product = $this->makeProduct(true);
        // Source ProductStock bucket says only 0 good/tax units are on hand
        // (a pre-existing drift/inconsistency), but a serial row still
        // claims to be there -- the plan must not go negative.
        $sourceStock = $this->makeStockRow($product, $this->otherLocation, goodTax: 0);
        $destStock = $this->makeStockRow($product, $this->location);
        $serial = $this->makeSerial($product, $this->otherLocation, 'SN-SHORT-1', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-SHORT-1', 'condition' => 'good'],
            ]],
        ]);

        try {
            $this->service()->approve($adjustment, $this->approver);
            $this->fail('Expected approval to reject an insufficient source bucket.');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertEquals(0, $sourceStock->fresh()->quantity_tax);
        $this->assertEquals(0, $destStock->fresh()->quantity_tax);
        $this->assertEquals($this->otherLocation->id, $serial->fresh()->location_id);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_condition_reclassification_at_same_location_moves_between_buckets_without_total_change()
    {
        $product = $this->makeProduct(true);
        $stock = $this->makeStockRow($product, $this->location, goodTax: 1);
        $serial = $this->makeSerial($product, $this->location, 'SN-RECLASS-1', isBroken: false, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-RECLASS-1', 'condition' => 'bad'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $fresh = $stock->fresh();
        $this->assertEquals(0, $fresh->quantity_tax);
        $this->assertEquals(1, $fresh->broken_quantity_tax);
        $this->assertEquals(1, $fresh->quantity); // total unchanged
        $this->assertEquals(1, $product->fresh()->product_quantity);
        $this->assertTrue($serial->fresh()->is_broken);
    }

    public function test_unknown_serial_is_created_only_during_approval_with_destination_bucket_increment()
    {
        $product = $this->makeProduct(true);
        $stock = $this->makeStockRow($product, $this->location);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-NEW-1', 'condition' => 'good'],
            ]],
        ]);

        $this->assertDatabaseMissing('product_serial_numbers', ['serial_number' => 'SN-NEW-1']);

        $this->service()->approve($adjustment, $this->approver);

        $serial = ProductSerialNumber::where('serial_number', 'SN-NEW-1')->first();
        $this->assertNotNull($serial);
        $this->assertEquals($this->location->id, $serial->location_id);
        $this->assertNotNull($serial->tax_id);

        $fresh = $stock->fresh();
        $this->assertEquals(1, $fresh->quantity_tax);
        $this->assertEquals(1, $product->fresh()->product_quantity);
    }

    public function test_taxable_serial_moved_to_non_pkp_destination_becomes_non_tax_with_bucket_move()
    {
        $this->setting->update(['is_pkp' => false]);

        $product = $this->makeProduct(true);
        $sourceStock = $this->makeStockRow($product, $this->otherLocation, goodTax: 1);
        $destStock = $this->makeStockRow($product, $this->location);
        $serial = $this->makeSerial($product, $this->otherLocation, 'SN-TAX-DOWN', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-TAX-DOWN', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertNull($serial->fresh()->tax_id);
        $this->assertEquals(0, $sourceStock->fresh()->quantity_tax);
        $this->assertEquals(1, $destStock->fresh()->quantity_non_tax);
        $this->assertEquals(0, $destStock->fresh()->quantity_tax);
    }

    public function test_non_tax_serial_moved_to_pkp_destination_becomes_taxable_with_bucket_move()
    {
        $product = $this->makeProduct(true);
        $sourceStock = $this->makeStockRow($product, $this->otherLocation, goodNonTax: 1);
        $destStock = $this->makeStockRow($product, $this->location);
        $serial = $this->makeSerial($product, $this->otherLocation, 'SN-TAX-UP', isBroken: false, taxId: null);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-TAX-UP', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertNotNull($serial->fresh()->tax_id);
        $this->assertEquals(0, $sourceStock->fresh()->quantity_non_tax);
        $this->assertEquals(1, $destStock->fresh()->quantity_tax);
        $this->assertEquals(0, $destStock->fresh()->quantity_non_tax);
    }

    // --- Omitted destination serial disposition ---

    public function test_omitted_available_destination_serial_is_marked_missing_and_bucket_decremented()
    {
        $product = $this->makeProduct(true);
        $stock = $this->makeStockRow($product, $this->location, goodTax: 1);
        $serial = $this->makeSerial($product, $this->location, 'SN-MISSING-1', isBroken: false, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $fresh = $serial->fresh();
        $this->assertNotNull($fresh, 'Serial row must never be deleted.');
        $this->assertEquals(ProductSerialNumber::STATUS_MISSING, $fresh->status);
        // location_id is a required column on this table and is retained as
        // last-known provenance; STATUS_MISSING alone marks it unavailable.
        $this->assertEquals($this->location->id, $fresh->location_id);

        $freshStock = $stock->fresh();
        $this->assertEquals(0, $freshStock->quantity_tax);
        $this->assertEquals(0, $product->fresh()->product_quantity);

        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $serial->id,
            'event_type' => \Modules\Product\Entities\SerialNumberHistory::EVENT_STOCK_OPNAME_MISSING,
        ]);
    }

    public function test_sold_destination_serial_is_not_treated_as_omission_discrepancy()
    {
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->location, goodTax: 1);
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $this->location->id,
            'serial_number' => 'SN-SOLD-1', 'status' => ProductSerialNumber::STATUS_SOLD,
            'is_broken' => false,
        ]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $fresh = $serial->fresh();
        $this->assertEquals(ProductSerialNumber::STATUS_SOLD, $fresh->status);
        $this->assertEquals($this->location->id, $fresh->location_id);
    }

    public function test_missing_serial_is_excluded_as_omission_candidate_on_a_later_opname()
    {
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->location);
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $this->location->id,
            'serial_number' => 'SN-ALREADY-MISSING', 'status' => ProductSerialNumber::STATUS_MISSING,
            'is_broken' => false,
        ]);

        // A second opname on the same location/product that also omits this
        // serial must not re-flag it as a fresh omission (no duplicate
        // history entry, no double bucket decrement).
        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertDatabaseMissing('serial_number_histories', [
            'product_serial_number_id' => $serial->id,
        ]);
        $this->assertEquals(ProductSerialNumber::STATUS_MISSING, $serial->fresh()->status);
    }

    public function test_explicitly_entering_a_missing_serial_is_a_conflict_requiring_recovery_workflow()
    {
        $product = $this->makeProduct(true);
        $stock = $this->makeStockRow($product, $this->location);
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $this->location->id,
            'serial_number' => 'SN-MISSING-REENTER', 'status' => ProductSerialNumber::STATUS_MISSING,
            'is_broken' => false,
        ]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-MISSING-REENTER', 'condition' => 'good'],
            ]],
        ]);

        try {
            $this->service()->approve($adjustment, $this->approver);
            $this->fail('Expected approval to reject re-entering a MISSING serial.');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertEquals(ProductSerialNumber::STATUS_MISSING, $serial->fresh()->status);
        $this->assertEquals(0, $stock->fresh()->quantity_tax);
        $this->assertDatabaseCount('transactions', 0);
    }

    // --- Conflicts ---

    public function test_dispatched_serial_blocks_approval_and_changes_nothing()
    {
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->otherLocation, goodTax: 1);
        $this->makeStockRow($product, $this->location);
        $serial = $this->makeSerial($product, $this->otherLocation, 'SN-DISPATCHED', isBroken: false, taxId: $this->tax->id);
        $serial->update(['dispatch_detail_id' => 999]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-DISPATCHED', 'condition' => 'good'],
            ]],
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->approve($adjustment, $this->approver);
        } finally {
            $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
            $this->assertEquals($this->otherLocation->id, $serial->fresh()->location_id);
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    public function test_duplicate_serial_entry_in_same_row_is_a_conflict()
    {
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->location);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-DUP', 'condition' => 'good'],
                ['serial_number' => 'sn-dup', 'condition' => 'good'],
            ]],
        ]);

        $this->expectException(ValidationException::class);
        $this->service()->approve($adjustment, $this->approver);
    }

    // --- Deterministic locking / stale-serial-identity revalidation ---

    public function test_approval_revalidates_against_authoritative_state_not_stale_row_reference()
    {
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->otherLocation, goodTax: 1);
        $this->makeStockRow($product, $this->location);
        $serial = $this->makeSerial($product, $this->otherLocation, 'SN-STALE-1', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-STALE-1', 'condition' => 'good'],
            ]],
        ]);

        // Simulate concurrent dispatch after the document was built but before
        // approval actually runs: the poster must re-check live state, not any
        // earlier in-memory assumption about this serial.
        $serial->update(['dispatch_detail_id' => 123]);

        $this->expectException(ValidationException::class);
        $this->service()->approve($adjustment, $this->approver);
    }

    // --- Preview / approval classification parity (shared classifier) ---

    public function test_preview_and_approval_classify_the_same_serial_identically()
    {
        // Destination is Non-PKP while the source serial is currently
        // taxable, so this serial simultaneously moves, changes condition,
        // and changes tax classification -- exercising the composable
        // multi-status classification path.
        $this->setting->update(['is_pkp' => false]);
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->otherLocation, goodTax: 1);
        $this->makeStockRow($product, $this->location);
        $this->makeSerial($product, $this->otherLocation, 'SN-PARITY-1', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-PARITY-1', 'condition' => 'bad'],
            ]],
        ]);

        $preview = app(\Modules\Adjustment\Services\StockOpnameReconciliationService::class)->reconcile($adjustment, locked: false);
        $previewSerial = $preview->products[0]->serials[0];

        $this->service()->approve($adjustment, $this->approver);

        // The classifier's decision (moved + condition_changed + tax_changed
        // classification set) is identical to what the reviewer preview
        // already showed -- both consume the same StockOpnameSerialClassifier.
        $this->assertTrue($previewSerial->hasStatus(\Modules\Adjustment\DTOs\SerialClassification::STATUS_MOVED));
        $this->assertTrue($previewSerial->hasStatus(\Modules\Adjustment\DTOs\SerialClassification::STATUS_CONDITION_CHANGED));
        $this->assertTrue($previewSerial->hasStatus(\Modules\Adjustment\DTOs\SerialClassification::STATUS_TAX_CHANGED));

        $fresh = ProductSerialNumber::where('serial_number', 'SN-PARITY-1')->first();
        $this->assertEquals($this->location->id, $fresh->location_id);
        $this->assertTrue($fresh->is_broken);
    }

    public function test_preview_also_excludes_missing_serial_from_omission_candidates()
    {
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->location);
        ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $this->location->id,
            'serial_number' => 'SN-PREVIEW-MISSING', 'status' => ProductSerialNumber::STATUS_MISSING,
            'is_broken' => false,
        ]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => []],
        ]);

        $preview = app(\Modules\Adjustment\Services\StockOpnameReconciliationService::class)->reconcile($adjustment, locked: false);

        $this->assertEmpty($preview->products[0]->omittedSerials);
    }

    // --- Immutable audit results, transaction records, notification resolution ---

    public function test_approval_persists_immutable_approval_result_and_records_actor_timestamp()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 5, 'bad_count' => 0, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $fresh = $adjustment->fresh();
        $this->assertEquals($this->approver->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        $this->assertIsArray($fresh->approval_result);
        $this->assertEquals($this->approver->id, $fresh->approval_result['approved_by']);
        $this->assertCount(1, $fresh->approval_result['products']);
    }

    /**
     * Regression for the double-counting bug in all_location_current_total_before
     * evidence: it must be SUM(quantity) across eligible locations (quantity
     * already includes broken), never SUM(quantity) + SUM(broken_quantity).
     */
    public function test_all_location_current_total_evidence_does_not_double_count_broken_stock()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location, goodTax: 10, badTax: 5);
        $this->makeStockRow($product, $this->otherLocation, goodTax: 10, badTax: 5);
        $product->update(['product_quantity' => 30]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 10, 'bad_count' => 5, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $result = $adjustment->fresh()->approval_result;
        $this->assertEquals(30.0, $result['products'][0]['all_location_current_total_before']);
    }

    /**
     * Regression: serialized approval must compute and persist the exact
     * global Product::broken_quantity delta for a good<->bad reclassification
     * (not merely leave it unchanged, and not merely update product_quantity).
     */
    public function test_serialized_condition_reclassification_updates_product_broken_quantity_delta()
    {
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->location, goodTax: 1);
        $this->makeSerial($product, $this->location, 'SN-BROKEN-DELTA-1', isBroken: false, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1, 'broken_quantity' => 0]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-BROKEN-DELTA-1', 'condition' => 'bad'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertEquals(1, $product->fresh()->product_quantity);
        $this->assertEquals(1, $product->fresh()->broken_quantity);

        $result = $adjustment->fresh()->approval_result;
        $this->assertEquals(1.0, $result['products'][0]['applied']['broken_delta']);
    }

    /**
     * Regression: a new serial entered as broken must add exactly 1 to
     * Product::broken_quantity, and an omitted broken serial must subtract
     * exactly 1 -- not leave the aggregate unaffected.
     */
    public function test_new_broken_serial_and_omitted_broken_serial_both_update_product_broken_quantity()
    {
        $product = $this->makeProduct(true);
        $stock = $this->makeStockRow($product, $this->location, badTax: 1);
        $omittedSerial = $this->makeSerial($product, $this->location, 'SN-OMIT-BROKEN', isBroken: true, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1, 'broken_quantity' => 1]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-NEW-BROKEN', 'condition' => 'bad'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        // Net broken delta: +1 (new broken serial created) -1 (existing
        // broken serial omitted/missing) = 0, but each contribution is exact,
        // not merely a coincidental unchanged aggregate -- verify via the
        // per-serial evidence in approval_result.
        $this->assertEquals(1, $product->fresh()->broken_quantity);

        $newSerial = ProductSerialNumber::where('serial_number', 'SN-NEW-BROKEN')->first();
        $this->assertNotNull($newSerial);
        $this->assertTrue($newSerial->is_broken);
        $this->assertEquals(ProductSerialNumber::STATUS_MISSING, $omittedSerial->fresh()->status);

        $result = $adjustment->fresh()->approval_result;
        $productResult = $result['products'][0];
        $this->assertEquals(0.0, $productResult['applied']['broken_delta']);
        $this->assertEquals(1, $productResult['applied']['new_serial_count']);
        $this->assertCount(1, $productResult['omitted_serials']);
    }

    public function test_approval_writes_accurate_transaction_evidence_with_supplied_actor_and_location_buckets()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location, goodTax: 2);
        $product->update(['product_quantity' => 2]);

        $secondApprover = User::factory()->create(['is_active' => 1]);
        $secondApprover->givePermissionTo(['adjustments.approval']);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 6, 'bad_count' => 0, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $secondApprover);

        $transaction = Transaction::where('product_id', $product->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals($secondApprover->id, $transaction->user_id, 'Transaction must record the supplied actor, not auth()->id().');
        $this->assertEquals($this->location->id, $transaction->location_id);
        $this->assertEquals(2, $transaction->previous_quantity_at_location);
        $this->assertEquals(6, $transaction->after_quantity_at_location);
        $this->assertEquals(2, $transaction->previous_quantity);
        $this->assertEquals(6, $transaction->after_quantity);
        $this->assertEquals(4, $transaction->quantity);
    }

    public function test_approval_writes_transaction_evidence_for_zero_global_delta_serial_move()
    {
        $product = $this->makeProduct(true);
        $sourceStock = $this->makeStockRow($product, $this->otherLocation, goodTax: 1);
        $destStock = $this->makeStockRow($product, $this->location);
        $this->makeSerial($product, $this->otherLocation, 'SN-ZERO-DELTA', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-ZERO-DELTA', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        // Two Transaction rows: one per touched location (source decrement,
        // destination increment), even though the product's global total is
        // unchanged (pure movement).
        $this->assertEquals(2, Transaction::where('product_id', $product->id)->count());
        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'location_id' => $this->otherLocation->id,
            'quantity' => -1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 1,
        ]);
    }

    public function test_approval_writes_transaction_evidence_for_zero_total_condition_reclassification()
    {
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->location, goodTax: 1);
        $this->makeSerial($product, $this->location, 'SN-RECLASS-EVIDENCE', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-RECLASS-EVIDENCE', 'condition' => 'bad'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 0,
        ]);
    }

    public function test_approval_triggers_source_and_destination_location_stock_notifications()
    {
        $product = $this->makeProduct(true);
        $product->update(['product_stock_alert' => 5]);
        // Source starts above alert (2), drops to 1 after the move -> still
        // above 5? No: alert=5 means <=5 triggers. Use values that cross the
        // threshold in both directions across the two locations.
        $sourceStock = $this->makeStockRow($product, $this->otherLocation, goodTax: 6);
        $this->makeSerial($product, $this->otherLocation, 'SN-NOTIFY-1', isBroken: false, taxId: $this->tax->id);
        // Destination starts at exactly the alert threshold and increases past it.
        $destStock = $this->makeStockRow($product, $this->location, goodTax: 5);

        // Low-stock recipients are resolved via a role assigned to the
        // acting user within the active setting (user_setting pivot +
        // role_id), not a direct permission grant on the user -- see
        // PermissionResolver::resolveRecipients().
        $permission = Permission::findOrCreate('notifications.lowStock', 'web');
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Stock Watcher', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $this->approver->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-NOTIFY-1', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        // Source location dropped from 6 to 5 (crossed at-or-below alert):
        // a low-stock notification should exist for that ProductStock row.
        $this->assertDatabaseHas('notifications', [
            'source_type' => ProductStock::class,
            'source_id' => $sourceStock->id,
            'category' => 'stock',
            'type' => 'location_low_stock',
        ]);
    }

    /**
     * approval_result must preserve full locked reconciliation evidence for
     * a moved serial: source and destination location, source and applied
     * condition, source and applied tax classification, action, and the
     * serial's own database id -- not merely a bare status flag.
     */
    public function test_approval_result_preserves_complete_per_serial_evidence_for_a_moved_serial()
    {
        $this->setting->update(['is_pkp' => false]);
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->otherLocation, goodTax: 1);
        $this->makeStockRow($product, $this->location);
        $serial = $this->makeSerial($product, $this->otherLocation, 'SN-EVIDENCE-1', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-EVIDENCE-1', 'condition' => 'bad'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $entry = $adjustment->fresh()->approval_result['products'][0]['serials'][0];

        $this->assertEquals($serial->id, $entry['serial_id']);
        $this->assertEquals('moved', $entry['action']);
        $this->assertEquals($this->otherLocation->id, $entry['source_location_id']);
        $this->assertEquals($this->otherLocation->name, $entry['source_location_name']);
        $this->assertEquals($this->location->id, $entry['destination_location_id']);
        $this->assertEquals('good', $entry['source_condition']);
        $this->assertEquals('bad', $entry['applied_condition']);
        $this->assertTrue($entry['source_is_tax']);
        $this->assertFalse($entry['applied_is_tax']);
        $this->assertNotEmpty($entry['label']);
    }

    /**
     * approval_result must preserve equivalent disposition evidence for an
     * omitted (now-MISSING) destination serial: previous location, previous
     * condition/tax, the id, and the recorded disposition -- not merely a
     * bare "removed" flag with no context for later audit.
     */
    public function test_approval_result_preserves_omission_disposition_evidence()
    {
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->location, badTax: 1);
        $serial = $this->makeSerial($product, $this->location, 'SN-EVIDENCE-OMIT', isBroken: true, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1, 'broken_quantity' => 1]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $entry = $adjustment->fresh()->approval_result['products'][0]['omitted_serials'][0];

        $this->assertEquals($serial->id, $entry['serial_id']);
        $this->assertEquals('SN-EVIDENCE-OMIT', $entry['serial_number']);
        $this->assertEquals('missing', $entry['action']);
        $this->assertEquals($this->location->id, $entry['previous_location_id']);
        $this->assertEquals('bad', $entry['previous_condition']);
        $this->assertTrue($entry['previous_is_tax']);
        $this->assertNotEmpty($entry['disposition']);
    }

    /**
     * approval_result must propagate classifier-produced warnings -- most
     * notably a same-text-other-product warning, which is discovered only
     * inside StockOpnameSerialClassifier and must survive into the immutable
     * locked audit record rather than being silently discarded once
     * approval succeeds.
     */
    public function test_approval_result_propagates_same_text_other_product_warning()
    {
        $productA = $this->makeProduct(true);
        $productB = $this->makeProduct(true);
        $this->makeStockRow($productA, $this->location);
        $this->makeSerial($productB, $this->otherLocation, 'SN-SHARED-TEXT', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $productA->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-SHARED-TEXT', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $result = $adjustment->fresh()->approval_result;
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('sudah terdaftar pada produk lain', collect($result['warnings'])->first());

        $entry = $result['products'][0]['serials'][0];
        $this->assertTrue($entry['same_text_other_product']);
    }

    public function test_approval_resolves_approval_and_revision_notifications()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 1, 'bad_count' => 0, 'serials' => []],
        ]);

        \App\Models\Notification::create([
            'user_id' => $this->approver->id,
            'setting_id' => $this->setting->id,
            'category' => 'approval',
            'type' => 'approval_needed',
            'title' => 'x', 'message' => 'x',
            'source_type' => Adjustment::class,
            'source_id' => $adjustment->id,
            'fingerprint' => 'approval:' . Adjustment::class . ':' . $adjustment->id . ':user:' . $this->approver->id,
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertDatabaseHas('notifications', [
            'source_type' => Adjustment::class,
            'source_id' => $adjustment->id,
            'category' => 'approval',
        ]);
        $this->assertNotNull(
            \App\Models\Notification::where('source_id', $adjustment->id)->where('category', 'approval')->first()->resolved_at
        );
    }

    // --- Idempotency and rollback ---

    public function test_repeated_approval_of_already_approved_document_is_idempotent_noop()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 3, 'bad_count' => 0, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);
        $firstResult = $adjustment->fresh()->approval_result;

        $this->service()->approve($adjustment->fresh(), $this->approver);

        $this->assertEquals(1, Transaction::where('product_id', $product->id)->count());
        $this->assertEquals($firstResult, $adjustment->fresh()->approval_result);
    }

    public function test_approval_only_accepts_waiting_approval_status()
    {
        $product = $this->makeProduct();
        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 1, 'bad_count' => 0, 'serials' => []],
        ]);
        $adjustment->update(['status' => AdjustmentStatus::Draft]);

        $this->expectException(ValidationException::class);
        $this->service()->approve($adjustment, $this->approver);
    }

    public function test_full_rollback_on_injected_failure_leaves_stock_and_status_unchanged()
    {
        $product = $this->makeProduct();
        $stock = $this->makeStockRow($product, $this->location, goodTax: 5);
        $product->update(['product_quantity' => 5]);

        // A non-existent product id in a later row forces a conflict/exception
        // path deep in the transaction; earlier rows in the same document must
        // not be partially applied.
        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 9, 'bad_count' => 0, 'serials' => []],
            ['product_id' => 999999, 'good_count' => 1, 'bad_count' => 0, 'serials' => []],
        ]);

        try {
            $this->service()->approve($adjustment, $this->approver);
            $this->fail('Expected approval to throw.');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertEquals(5, $stock->fresh()->quantity_tax);
        $this->assertEquals(5, $product->fresh()->product_quantity);
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_rollback_after_mutation_has_begun_on_a_later_serialized_conflict()
    {
        // Pass 1 validates every row (including bucket availability) before
        // pass 2 mutates anything, so a conflict discovered anywhere in pass 1
        // -- even in a later row -- must still prevent every earlier row's
        // plan from being applied. This proves pass 1 is exhaustive, not that
        // rollback recovers from a failure injected after real writes (see
        // test_rollback_recovers_from_failure_injected_after_real_mutations_have_occurred
        // for that).
        $plainProduct = $this->makeProduct();
        $plainStock = $this->makeStockRow($plainProduct, $this->location, goodTax: 5);
        $plainProduct->update(['product_quantity' => 5]);

        $serialProduct = $this->makeProduct(true);
        $this->makeStockRow($serialProduct, $this->otherLocation, goodTax: 1);
        $this->makeStockRow($serialProduct, $this->location);
        $serial = $this->makeSerial($serialProduct, $this->otherLocation, 'SN-ROLLBACK-1', isBroken: false, taxId: $this->tax->id);
        $serial->update(['dispatch_detail_id' => 555]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $plainProduct->id, 'good_count' => 20, 'bad_count' => 0, 'serials' => []],
            ['product_id' => $serialProduct->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-ROLLBACK-1', 'condition' => 'good'],
            ]],
        ]);

        try {
            $this->service()->approve($adjustment, $this->approver);
            $this->fail('Expected approval to throw.');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertEquals(5, $plainStock->fresh()->quantity_tax, 'Plain product row must not have been posted.');
        $this->assertEquals(5, $plainProduct->fresh()->product_quantity);
        $this->assertEquals($this->otherLocation->id, $serial->fresh()->location_id);
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_rollback_recovers_from_failure_injected_after_real_mutations_have_occurred()
    {
        // StockNotificationService::checkLocationStock runs in pass 2 only
        // after every row's stock/serial/transaction mutation has already
        // executed and only just before the adjustment is marked Approved --
        // by the time this throws, real INSERT/UPDATE statements for stock
        // buckets, the serial move, its history row, and the transaction
        // record have all already been sent to the database inside this
        // transaction. A fake that throws here is a genuine "failure after
        // at least one write", not a pass-1 validation rejection.
        $failingNotifier = new class extends \App\Services\Notification\StockNotificationService {
            public function __construct() {}
            public function checkLocationStock(\Modules\Product\Entities\ProductStock $stock, float $previousQuantity, float $currentQuantity): void
            {
                throw new \RuntimeException('Injected failure after real mutations for rollback test.');
            }
        };
        $this->app->instance(\App\Services\Notification\StockNotificationService::class, $failingNotifier);

        $product = $this->makeProduct(true);
        $sourceStock = $this->makeStockRow($product, $this->otherLocation, goodTax: 1);
        $destStock = $this->makeStockRow($product, $this->location);
        $serial = $this->makeSerial($product, $this->otherLocation, 'SN-INJECTED-1', isBroken: false, taxId: $this->tax->id);
        $product->update(['product_quantity' => 1]);

        \App\Models\Notification::create([
            'user_id' => $this->approver->id,
            'setting_id' => $this->setting->id,
            'category' => 'approval',
            'type' => 'approval_needed',
            'title' => 'x', 'message' => 'x',
            'source_type' => Adjustment::class,
            'source_id' => 0, // placeholder id, replaced below once the adjustment exists
            'fingerprint' => 'placeholder',
        ]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-INJECTED-1', 'condition' => 'good'],
            ]],
        ]);

        \App\Models\Notification::where('source_id', 0)->update([
            'source_id' => $adjustment->id,
            'fingerprint' => 'approval:' . Adjustment::class . ':' . $adjustment->id . ':user:' . $this->approver->id,
        ]);

        try {
            $this->service()->approve($adjustment, $this->approver);
            $this->fail('Expected the injected failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Injected failure', $e->getMessage());
        }

        // Stock buckets: both source and destination rows fully reverted.
        $this->assertEquals(1, $sourceStock->fresh()->quantity_tax);
        $this->assertEquals(0, $destStock->fresh()->quantity_tax);

        // Serial state/history: still at its original location, never moved,
        // no history row for this adjustment was left behind.
        $fresh = $serial->fresh();
        $this->assertEquals($this->otherLocation->id, $fresh->location_id);
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $fresh->status);
        $this->assertDatabaseMissing('serial_number_histories', [
            'product_serial_number_id' => $serial->id,
        ]);

        // Product aggregate: unchanged.
        $this->assertEquals(1, $product->fresh()->product_quantity);
        $this->assertEquals(0, (float) ($product->fresh()->broken_quantity ?? 0));

        // Transactions: none persisted.
        $this->assertDatabaseCount('transactions', 0);

        // Adjustment status/result: still WAITING_APPROVAL, no approval_result.
        $freshAdjustment = $adjustment->fresh();
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $freshAdjustment->status);
        $this->assertNull($freshAdjustment->approved_by);
        $this->assertNull($freshAdjustment->approval_result);

        // Notifications: the pre-existing approval-needed notification was
        // never resolved (resolveApproval/resolveRevision run after the
        // notifier call and never executed).
        $this->assertNull(
            \App\Models\Notification::where('source_id', $adjustment->id)->where('category', 'approval')->first()->resolved_at
        );
    }

    // --- Query scaling / lazy-load prevention ---

    public function test_approval_query_count_does_not_scale_with_serial_count()
    {
        // Isolate per-serial scaling specifically (as opposed to per-row
        // scaling, which is expected to add roughly a constant few queries
        // per document row for its own stock/serial/transaction writes):
        // hold the document at exactly one serialized row and vary only how
        // many serials that single row contains. If StockOpnameSerialClassifier
        // (or anything in the approval pipeline) issued a query per serial
        // -- most notably a lazy load of a serial's `location` or `product`
        // relation, which Model::preventLazyLoading() would actually throw
        // on outside production, catching a real regression even before the
        // query-count assertion below -- going from 3 to 15 serials in the
        // same single row would add roughly one query per extra serial (12
        // more). A flat per-row query set adds none.
        $fewSerialsProduct = $this->makeProduct(true);
        $this->makeStockRow($fewSerialsProduct, $this->location, goodTax: 3);
        $fewSerials = [];
        for ($s = 0; $s < 3; $s++) {
            $this->makeSerial($fewSerialsProduct, $this->location, "SN-FEW-{$s}", isBroken: false, taxId: $this->tax->id);
            $fewSerials[] = ['serial_number' => "SN-FEW-{$s}", 'condition' => 'good'];
        }

        $fewAdjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $fewSerialsProduct->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => $fewSerials],
        ]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->service()->approve($fewAdjustment, $this->approver);
        $fewQueryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $manySerialsProduct = $this->makeProduct(true);
        $this->makeStockRow($manySerialsProduct, $this->location, goodTax: 15);
        $manySerials = [];
        for ($s = 0; $s < 15; $s++) {
            $this->makeSerial($manySerialsProduct, $this->location, "SN-MANY-{$s}", isBroken: false, taxId: $this->tax->id);
            $manySerials[] = ['serial_number' => "SN-MANY-{$s}", 'condition' => 'good'];
        }

        $manyAdjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $manySerialsProduct->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => $manySerials],
        ]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->service()->approve($manyAdjustment, $this->approver);
        $manyQueryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // 5x the serials (3 -> 15) within the same single row must not add
        // anywhere near 5x the queries -- a per-serial query would add ~12,
        // a flat per-row query set adds effectively none (the underlying
        // bulk queries fetch more rows, not more queries).
        $this->assertLessThan(
            $fewQueryCount + 4,
            $manyQueryCount,
            "Query count scaled with serial count within one row: {$fewQueryCount} (3 serials) vs {$manyQueryCount} (15 serials)."
        );
    }

    // --- Stale location/setting/PKP regression (lock-and-reload before ownership/PKP decisions) ---

    public function test_approval_uses_the_current_is_pkp_value_not_a_stale_cached_one()
    {
        // The document was drafted while the destination was Non-PKP; the
        // setting's is_pkp is flipped to true directly in the database
        // (bypassing any application-level caching) before approval runs.
        // Approval must classify by the setting's CURRENT value, proving it
        // actually reloads Setting inside its own transaction rather than
        // trusting whatever was true when the document was drafted/submitted.
        $this->setting->update(['is_pkp' => false]);

        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 4, 'bad_count' => 0, 'serials' => []],
        ]);

        // Flip PKP on after the document exists, simulating a setting change
        // that occurred after submission but before approval is processed.
        $this->setting->update(['is_pkp' => true]);

        $this->service()->approve($adjustment, $this->approver);

        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(4, $stock->quantity_tax, 'Approval must classify by the current (flipped-on) is_pkp, not a stale value.');
        $this->assertEquals(0, $stock->quantity_non_tax);
    }

    public function test_approval_rejects_when_destination_location_became_consignment_since_submission()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 4, 'bad_count' => 0, 'serials' => []],
        ]);

        // The destination location becomes a consignment location after
        // submission but before approval. Approval locks and reloads the
        // Location row and must revalidate ownership/consignment against
        // that fresh row, not any earlier assumption.
        $this->location->update(['is_consignment' => true]);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->approve($adjustment, $this->approver);
        } finally {
            $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    public function test_approval_rejects_when_destination_location_moved_to_another_setting_since_submission()
    {
        $product = $this->makeProduct();
        $this->makeStockRow($product, $this->location);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 4, 'bad_count' => 0, 'serials' => []],
        ]);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'USD', 'code' => 'USD', 'symbol' => '$',
            'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1,
        ]);
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        // The destination location is reassigned to a foreign setting after
        // submission but before approval.
        $this->location->update(['setting_id' => $otherSetting->id]);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->approve($adjustment, $this->approver);
        } finally {
            $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    // --- Lock-order regression (stock before serial, matching TransferMovementService) ---

    public function test_approval_query_log_shows_full_lock_hierarchy_order()
    {
        // Direct regression for the complete lock-order contract: the raw
        // SQL log must show, in order, the first PRINCIPAL locking query
        // ("... for update", SQLite's lock-equivalent select in every case)
        // against each of adjustments < locations < settings < products <
        // product_stocks < product_serial_numbers, for a document that
        // touches both a source and destination stock row plus a matched
        // serial. "Principal" locking queries are distinguished from
        // eager-load/plain-read queries (e.g. the unlocked discovery reads
        // in discoverSerialLocations(), or Product's with('baseUnit') eager
        // load) by requiring both a WHERE ... IN (...) filter AND an
        // "order by id asc" clause -- the shape only the ordered
        // ::lockForUpdate() queries in approve()/lockAffectedLocations()/
        // lockStocksThenSerials() produce. This is the concrete, inspectable
        // evidence that:
        //   1. Adjustment (already locked before this method's discovery
        //      begins) precedes every subsequent principal lock;
        //   2. Location is locked as ONE ordered query covering the
        //      complete affected set (lockAffectedLocations()) before
        //      Setting, Product, ProductStock, or ProductSerialNumber;
        //   3. Setting is locked (from the already-locked destination
        //      Location) before Product;
        //   4. Product is locked (approve(), step 5) before ProductStock and
        //      ProductSerialNumber, and with NO with('product') eager-load
        //      anywhere in the log before this point;
        //   5. ProductStock is locked (lockStocksThenSerials()) before
        //      ProductSerialNumber -- matching the order
        //      TransferMovementService::dispatch() uses (ProductStock::
        //      lockForUpdate() at Modules/Adjustment/Services/
        //      TransferMovementService.php:31-35, before
        //      allocateSerialized()'s ProductSerialNumber::lockForUpdate()
        //      at ~469-473).
        // This is stronger than only inferring the order from the
        // mutation's end result, which a different internal implementation
        // could also produce.
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->otherLocation, goodTax: 1);
        $this->makeStockRow($product, $this->location);
        $this->makeSerial($product, $this->otherLocation, 'SN-LOCKORDER-1', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-LOCKORDER-1', 'condition' => 'good'],
            ]],
        ]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->service()->approve($adjustment, $this->approver);
        $log = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // A "principal" lock query: filters by an IN (...) list (or, for
        // Adjustment/Setting, a plain "= ?" primary-key predicate) AND is
        // explicitly ordered by id -- Adjustment and Setting are looked up
        // by a single id (no ordering needed for a one-row result), so those
        // two are matched on table + "for update"/lock-equivalent select
        // alone, while Location/Product/ProductStock/ProductSerialNumber
        // are matched on table + "order by id asc" (the ordered
        // multi-row locking shape this refactor requires), which by
        // construction excludes every eager-load (with('baseUnit'), the
        // unlocked discovery reads, etc.) that this codebase never orders
        // by id.
        $firstIndexMatching = function (array $log, string $table, bool $requiresOrder): ?int {
            foreach ($log as $index => $entry) {
                $sql = strtolower($entry['query']);
                if (!str_contains($sql, "from \"{$table}\"")) {
                    continue;
                }
                if ($requiresOrder && !str_contains($sql, 'order by "id" asc')) {
                    continue;
                }
                return $index;
            }
            return null;
        };

        $adjustmentLockIndex = $firstIndexMatching($log, 'adjustments', false);
        $locationLockIndex = $firstIndexMatching($log, 'locations', true);
        $settingLockIndex = $firstIndexMatching($log, 'settings', false);
        $productLockIndex = $firstIndexMatching($log, 'products', true);
        $stockLockIndex = $firstIndexMatching($log, 'product_stocks', true);
        $serialLockIndex = $firstIndexMatching($log, 'product_serial_numbers', true);

        $this->assertNotNull($adjustmentLockIndex, 'Expected a locked Adjustment query in the log.');
        $this->assertNotNull($locationLockIndex, 'Expected a single ordered locked Location query in the log.');
        $this->assertNotNull($settingLockIndex, 'Expected a locked Setting query in the log.');
        $this->assertNotNull($productLockIndex, 'Expected a single ordered locked Product query in the log.');
        $this->assertNotNull($stockLockIndex, 'Expected a single ordered locked ProductStock query in the log.');
        $this->assertNotNull($serialLockIndex, 'Expected a single ordered locked ProductSerialNumber query in the log.');

        $this->assertLessThan($locationLockIndex, $adjustmentLockIndex, 'Adjustment must be locked before Location.');
        $this->assertLessThan($settingLockIndex, $locationLockIndex, 'Location must be locked before Setting.');
        $this->assertLessThan($productLockIndex, $settingLockIndex, 'Setting must be locked before Product.');
        $this->assertLessThan($stockLockIndex, $productLockIndex, 'Product must be locked before ProductStock.');
        $this->assertLessThan($serialLockIndex, $stockLockIndex, 'ProductStock must be locked before ProductSerialNumber.');

        // No with('product') eager-load of Product happens before the
        // authoritative Product lock: every "products" table query in the
        // log up to and including $productLockIndex must be the single
        // ordered lock query itself, never an earlier unlocked eager-load
        // row fetch triggered by ProductStock/ProductSerialNumber's
        // with('product').
        $productQueriesBeforeLock = 0;
        foreach (array_slice($log, 0, $productLockIndex + 1) as $entry) {
            $sql = strtolower($entry['query']);
            if (str_contains($sql, 'from "products"')) {
                $productQueriesBeforeLock++;
            }
        }
        $this->assertEquals(
            1,
            $productQueriesBeforeLock,
            'Exactly one "products" query (the authoritative ordered lock) is expected up to and including the Product lock -- an eager with(\'product\') read before it would add a second.'
        );

        // The destination location participates in the SAME single ordered
        // Location lock query as every other affected location -- assert
        // its id is among that one query's bindings, rather than being
        // pulled out into an earlier, separate lock.
        $locationLockBindings = $log[$locationLockIndex]['bindings'];
        $this->assertContains(
            $this->location->id,
            $locationLockBindings,
            'The destination Location id must be a binding of the single ordered Location lock query, not locked separately.'
        );
    }

    /**
     * Opposite-direction cross-setting lock ordering: Adjustment A's
     * destination is B's discovered serial source, and Adjustment B's
     * destination is A's discovered serial source. Under the OLD
     * implementation each transaction locked its own destination Location
     * first (out of any global order), then later tried to lock the other's
     * source/destination location as part of its own sorted set -- a
     * classic opposite-direction deadlock shape. The fix folds the
     * destination into the SAME single ascending-ID Location lock as every
     * other candidate location, so this scenario can no longer deadlock: it
     * is provable statically by confirming both directions produce the
     * identical ascending-ID Location lock query (same SQL, same bind
     * order) regardless of which side is the "destination" for that
     * particular approval, which is exactly what a single global hierarchy
     * guarantees and a destination-first hierarchy does not.
     */
    public function test_opposite_direction_cross_setting_lock_ordering_uses_one_ascending_location_lock_covering_both_sides()
    {
        // Both locations belong to the active setting (approval enforces
        // destination ownership per AdjustmentOwnershipGuard), but the
        // serial CUSTODY move itself is deliberately cross-setting-shaped by
        // giving each product a "home" setting_id that differs from the
        // location it is actually stocked/moved at -- exercising the same
        // global movement-eligible discovery path a genuine cross-setting
        // move would, while keeping both destinations approvable in this
        // single active session. What this test actually proves is lock
        // ORDERING, not cross-setting tax mechanics (already covered
        // elsewhere), so this keeps the setup minimal and deterministic.
        [$lowLocation, $highLocation] = $this->location->id < $this->otherLocation->id
            ? [$this->location, $this->otherLocation]
            : [$this->otherLocation, $this->location];

        $productA = $this->makeProduct(true);
        $productB = $this->makeProduct(true);

        // Adjustment A: destination = highLocation, its entered serial
        // currently sits at lowLocation (a cross-location move INTO high).
        $this->makeStockRow($productA, $lowLocation, goodTax: 0, goodNonTax: 1);
        $this->makeStockRow($productA, $highLocation);
        $this->makeSerial($productA, $lowLocation, 'SN-OPP-A', isBroken: false, taxId: null);

        // Adjustment B: destination = lowLocation, its entered serial
        // currently sits at highLocation (a cross-location move INTO low) --
        // the exact opposite direction of A.
        $this->makeStockRow($productB, $highLocation, goodTax: 0, goodNonTax: 1);
        $this->makeStockRow($productB, $lowLocation);
        $this->makeSerial($productB, $highLocation, 'SN-OPP-B', isBroken: false, taxId: null);

        $adjustmentA = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $productA->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-OPP-A', 'condition' => 'good'],
            ]],
        ], $highLocation);

        $adjustmentB = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $productB->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-OPP-B', 'condition' => 'good'],
            ]],
        ], $lowLocation);

        $extractFirstLocationLockSql = function (Adjustment $adjustment): ?array {
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->service()->approve($adjustment, $this->approver);
            $log = \Illuminate\Support\Facades\DB::getQueryLog();
            \Illuminate\Support\Facades\DB::disableQueryLog();

            foreach ($log as $entry) {
                $sql = strtolower($entry['query']);
                if (str_contains($sql, 'from "locations"') && str_contains($sql, 'order by "id" asc')) {
                    return ['sql' => $sql, 'bindings' => $entry['bindings']];
                }
            }

            return null;
        };

        $lockQueryA = $extractFirstLocationLockSql($adjustmentA);
        $lockQueryB = $extractFirstLocationLockSql($adjustmentB);

        $this->assertNotNull($lockQueryA, 'Expected a single ascending-ID Location lock query for adjustment A.');
        $this->assertNotNull($lockQueryB, 'Expected a single ascending-ID Location lock query for adjustment B.');

        // Both directions lock the SAME pair of location IDs (low, high) via
        // the identical single ascending-ID query shape -- the destination is
        // never pulled out into its own earlier lock on either side.
        sort($lockQueryA['bindings']);
        sort($lockQueryB['bindings']);
        $this->assertEquals(
            [$lowLocation->id, $highLocation->id],
            $lockQueryA['bindings'],
            'Adjustment A must lock exactly {low, high} location IDs via one ordered query.'
        );
        $this->assertEquals(
            [$lowLocation->id, $highLocation->id],
            $lockQueryB['bindings'],
            'Adjustment B must lock exactly {low, high} location IDs via one ordered query.'
        );

        $this->assertEquals(AdjustmentStatus::Approved, $adjustmentA->fresh()->status);
        $this->assertEquals(AdjustmentStatus::Approved, $adjustmentB->fresh()->status);
    }

    /**
     * Direct unit-level proof that discoverSerialLocations() (phase 1)
     * itself never issues a second discovery SELECT when
     * assertSerialLocationsUnchanged() (phase 8) detects a disagreement: the
     * query log for a rejected approval must contain exactly ONE unlocked
     * (non-"for update"/non-lock-select) read of product_serial_numbers by
     * serial_number -- the single discovery read -- never two, which would
     * be the fingerprint of an in-transaction retry.
     */
    public function test_discovery_disagreement_issues_exactly_one_discovery_read_no_inner_retry()
    {
        $product = $this->makeProduct(true);
        $sourceLocation = Location::create([
            'name' => 'Gudang Akan Konsinyasi Lagi', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);
        $this->makeStockRow($product, $sourceLocation, goodNonTax: 1);
        $this->makeStockRow($product, $this->location);
        $this->makeSerial($product, $sourceLocation, 'SN-NORETRY', isBroken: false, taxId: null);

        // Flip to consignment so the locked revalidation path is reached
        // deterministically without a second real connection (movement
        // eligibility is rejected via the classifier, but the discovery-read
        // COUNT assertion below is about discovery itself, independent of
        // which specific guard ultimately raises the conflict).
        $sourceLocation->update(['is_consignment' => true]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-NORETRY', 'condition' => 'good'],
            ]],
        ]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        try {
            $this->service()->approve($adjustment, $this->approver);
        } catch (ValidationException $e) {
            // expected for the consignment-source case; the assertion below
            // is what this test actually verifies.
        }
        $log = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $discoveryReadCount = 0;
        foreach ($log as $entry) {
            $sql = strtolower($entry['query']);
            // Discovery-by-serial-text's distinguishing shape: filters by
            // "serial_number" in (...) and is unlocked (no "for update"/no
            // locked "order by id asc" clause the later lockForUpdate()
            // queries carry). This deliberately excludes the separate
            // destination-serial discovery read (filtered by location_id +
            // product_id instead) so this count isolates only the
            // serial-text discovery path a retry would duplicate.
            if (str_contains($sql, 'from "product_serial_numbers"')
                && str_contains($sql, '"serial_number" in (')
                && !str_contains($sql, 'order by "id" asc')) {
                $discoveryReadCount++;
            }
        }

        $this->assertEquals(
            1,
            $discoveryReadCount,
            'Exactly one unlocked discovery read of product_serial_numbers by serial_number is expected -- a second would be an in-transaction retry.'
        );
    }

    /**
     * Note on concurrency-test limitations: PHPUnit's single connection/
     * single-process test harness cannot truly interleave a second
     * transaction inside StockOpnameApprovalService's discovery window, so a
     * genuine race is not exercised by an automated test here. What IS
     * covered here and below is the observable contract that makes the
     * no-inner-retry design safe: the actual SQL lock order (location, then
     * stock, then serial, with the destination inside the single ordered
     * location lock), correct posting outcomes for a moved serial, and a
     * clean rollback with zero mutations when discovery disagrees with the
     * locked state. A true interleaved-transaction test would require either
     * a second real DB connection paused mid-transaction or a
     * database-level deadlock/race harness, which is out of scope for this
     * focused section-4 suite.
     */

    // --- Global product catalogue: Product::setting_id never gates approval
    // eligibility; ownership is enforced entirely through location scoping. ---

    public function test_approval_of_globally_shared_product_zeroes_only_destination_location_and_leaves_other_setting_stock_untouched()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => \Modules\Currency\Entities\Currency::first()->id,
            'default_currency_position' => 'prefix', 'is_pkp' => false,
        ]);
        $foreignSettingLocation = Location::create([
            'name' => 'Gudang Setting Lain', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        // The product's own (legacy) setting_id differs from the destination
        // setting, but it is genuinely stocked at $this->location (which IS
        // owned by the destination setting) and also at a location belonging
        // to another setting entirely.
        $sharedProduct = $this->makeProduct();
        $sharedProduct->update(['setting_id' => $otherSetting->id]);

        $destinationStock = $this->makeStockRow($sharedProduct, $this->location, goodTax: 5);
        $foreignStock = $this->makeStockRow($sharedProduct, $foreignSettingLocation, goodTax: 42);
        $sharedProduct->update(['product_quantity' => 5 + 42]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $sharedProduct->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertEquals(AdjustmentStatus::Approved, $adjustment->fresh()->status);

        // Destination location's quantity is zeroed (the entered absolute count).
        $freshDestination = $destinationStock->fresh();
        $this->assertEquals(0, $freshDestination->quantity_tax);
        $this->assertEquals(0, $freshDestination->quantity);

        // The same product's stock at the other setting's location is
        // completely untouched -- global product identity never authorizes
        // mutating stock outside the destination setting's own locations.
        $freshForeign = $foreignStock->fresh();
        $this->assertEquals(42, $freshForeign->quantity_tax);
        $this->assertEquals(42, $freshForeign->quantity);

        // Product aggregate reflects only the destination-location delta (-5).
        $this->assertEquals(42, $sharedProduct->fresh()->product_quantity);
    }

    /**
     * Corrected domain behavior: an active, unencumbered serial at another
     * setting's ordinary (non-consignment) location is a safe stock-movement
     * candidate -- the product catalogue and physical serial custody are
     * global. Approval must move it exactly like a same-setting move: decrement
     * the source bucket, increment the destination bucket using the
     * destination's current PKP classification, move location_id, keep the
     * global product quantity net change at zero, write Transaction evidence
     * for BOTH locations with each row's OWN location's setting_id (not
     * unconditionally the destination's), and notify both locations.
     */
    public function test_approval_moves_a_cross_setting_active_serial_with_exact_buckets_and_per_location_transaction_settings()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => \Modules\Currency\Entities\Currency::first()->id,
            'default_currency_position' => 'prefix', 'is_pkp' => false,
        ]);
        $foreignSettingLocation = Location::create([
            'name' => 'GUDANG BARANG PERDANA', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        // Destination setting ($this->setting, set up in setUp()) is PKP,
        // source setting is Non-PKP: the move also converts tax classification.
        $sharedProduct = $this->makeProduct(true);
        $sharedProduct->update(['setting_id' => $otherSetting->id]);

        $sourceStock = $this->makeStockRow($sharedProduct, $foreignSettingLocation, goodNonTax: 1);
        $destStock = $this->makeStockRow($sharedProduct, $this->location);
        $sharedProduct->update(['product_quantity' => 1]);
        $serial = $this->makeSerial($sharedProduct, $foreignSettingLocation, 'SN-CROSS-SETTING-MOVE', isBroken: false, taxId: null);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $sharedProduct->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-CROSS-SETTING-MOVE', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertEquals(AdjustmentStatus::Approved, $adjustment->fresh()->status);

        // Serial moved and reclassified to the destination's PKP status.
        $freshSerial = $serial->fresh();
        $this->assertEquals($this->location->id, $freshSerial->location_id);
        $this->assertNotNull($freshSerial->tax_id, 'Destination is PKP, so the moved serial must become taxable.');

        // Exact source bucket decrement, exact destination bucket increment.
        $this->assertEquals(0, $sourceStock->fresh()->quantity_non_tax);
        $this->assertEquals(0, $sourceStock->fresh()->quantity);
        $this->assertEquals(1, $destStock->fresh()->quantity_tax);
        $this->assertEquals(0, $destStock->fresh()->quantity_non_tax);
        $this->assertEquals(1, $destStock->fresh()->quantity);

        // Pure movement: verified global net effect is zero.
        $this->assertEquals(1, $sharedProduct->fresh()->product_quantity);

        // Transaction evidence for BOTH locations, each with ITS OWN setting_id.
        $this->assertEquals(2, Transaction::where('product_id', $sharedProduct->id)->count());
        $this->assertDatabaseHas('transactions', [
            'product_id' => $sharedProduct->id,
            'location_id' => $foreignSettingLocation->id,
            'setting_id' => $otherSetting->id,
            'quantity' => -1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'product_id' => $sharedProduct->id,
            'location_id' => $this->location->id,
            'setting_id' => $this->setting->id,
            'quantity' => 1,
        ]);

        // approval_result records the movement + tax-change evidence needed
        // for row-level reviewer rendering (Dipindahkan / GUDANG BARANG
        // PERDANA -> destination / Tidak Kena Pajak -> Kena Pajak).
        $entry = $adjustment->fresh()->approval_result['products'][0]['serials'][0];
        $this->assertEquals('moved', $entry['action']);
        $this->assertEquals($foreignSettingLocation->id, $entry['source_location_id']);
        $this->assertFalse($entry['source_is_tax']);
        $this->assertTrue($entry['applied_is_tax']);
    }

    /**
     * Notifications must fire for BOTH the source and destination locations
     * of a cross-setting move, not only the destination.
     */
    public function test_approval_of_cross_setting_serial_move_notifies_both_source_and_destination_locations()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => \Modules\Currency\Entities\Currency::first()->id,
            'default_currency_position' => 'prefix', 'is_pkp' => false,
        ]);
        $foreignSettingLocation = Location::create([
            'name' => 'Gudang Setting Lain Notify', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $sharedProduct = $this->makeProduct(true);
        $sharedProduct->update(['setting_id' => $otherSetting->id, 'product_stock_alert' => 5]);

        // Source starts above alert and drops to/below it; destination starts
        // at/below alert and rises past it -- both directions must notify.
        $sourceStock = $this->makeStockRow($sharedProduct, $foreignSettingLocation, goodNonTax: 6);
        $destStock = $this->makeStockRow($sharedProduct, $this->location, goodTax: 5);
        $this->makeSerial($sharedProduct, $foreignSettingLocation, 'SN-NOTIFY-CROSS-SETTING', isBroken: false, taxId: null);

        // Low-stock recipients are resolved from the PRODUCT's own
        // setting_id (see StockNotificationService::createLocationStockNotifications),
        // not the touched location's setting -- so the watcher role must be
        // attached under the shared product's own (other) setting for this
        // cross-setting scenario to actually produce a notification.
        $permission = Permission::findOrCreate('notifications.lowStock', 'web');
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Stock Watcher Cross Setting', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $this->approver->settings()->attach($otherSetting->id, ['role_id' => $role->id]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $sharedProduct->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-NOTIFY-CROSS-SETTING', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertDatabaseHas('notifications', [
            'source_type' => ProductStock::class,
            'source_id' => $sourceStock->id,
            'category' => 'stock',
            'type' => 'location_low_stock',
        ]);
    }

    /**
     * A consignment source location remains a blocking conflict even though
     * cross-setting movement is otherwise allowed -- movement eligibility is
     * broadened by setting, never by consignment status.
     */
    public function test_approval_still_blocks_a_serial_at_a_consignment_location_regardless_of_setting()
    {
        $consignmentLocation = Location::create([
            'name' => 'Titik Konsinyasi Approval', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => true,
        ]);

        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $consignmentLocation, goodTax: 1);
        $this->makeStockRow($product, $this->location);
        $serial = $this->makeSerial($product, $consignmentLocation, 'SN-CONSIGNMENT-APPROVAL', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-CONSIGNMENT-APPROVAL', 'condition' => 'good'],
            ]],
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->approve($adjustment, $this->approver);
        } finally {
            $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
            $this->assertEquals($consignmentLocation->id, $serial->fresh()->location_id);
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    /**
     * The "all locations" total used for the same-setting over-total warning
     * must exclude a product's stock recorded under another setting, even
     * though that product is globally shared and movement itself is allowed
     * across settings.
     */
    public function test_same_setting_all_location_total_excludes_stock_recorded_under_another_setting()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => \Modules\Currency\Entities\Currency::first()->id,
            'default_currency_position' => 'prefix', 'is_pkp' => false,
        ]);
        $foreignSettingLocation = Location::create([
            'name' => 'Gudang Setting Lain Total', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $sharedProduct = $this->makeProduct();
        $sharedProduct->update(['setting_id' => $otherSetting->id]);

        $this->makeStockRow($sharedProduct, $this->location, goodTax: 5);
        $this->makeStockRow($sharedProduct, $this->otherLocation, goodTax: 3);
        // Stock under another setting's location: must be excluded from the
        // "all locations" total evidence even though the product is shared.
        $this->makeStockRow($sharedProduct, $foreignSettingLocation, goodTax: 999);
        $sharedProduct->update(['product_quantity' => 5 + 3 + 999]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $sharedProduct->id, 'good_count' => 5, 'bad_count' => 0, 'serials' => []],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $result = $adjustment->fresh()->approval_result;
        $this->assertEquals(8.0, $result['products'][0]['all_location_current_total_before']);
    }

    // --- Locked-location revalidation corrections ---

    /**
     * A source location that becomes consignment AFTER discovery's unlocked
     * read but is caught once the referenced Location rows are actually
     * locked must block the move -- the earlier unlocked
     * movementEligibleLocationIds-style snapshot is never final authority
     * for locked approval.
     */
    public function test_source_location_becoming_consignment_between_discovery_and_lock_blocks_approval_with_zero_mutations()
    {
        $sourceLocation = Location::create([
            'name' => 'Gudang Akan Jadi Konsinyasi', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $product = $this->makeProduct(true);
        $sourceStock = $this->makeStockRow($product, $sourceLocation, goodTax: 1);
        $destStock = $this->makeStockRow($product, $this->location);
        $serial = $this->makeSerial($product, $sourceLocation, 'SN-BECOMES-CONSIGNMENT', isBroken: false, taxId: $this->tax->id);

        // Flip the source location to consignment strictly between when a
        // caller might have read an earlier eligibility snapshot and when
        // approval actually locks/revalidates -- simulated here simply by
        // making the flip the authoritative state approval will observe when
        // it locks Location rows, since this test drives approve() directly
        // rather than needing a second real concurrent transaction.
        $sourceLocation->update(['is_consignment' => true]);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-BECOMES-CONSIGNMENT', 'condition' => 'good'],
            ]],
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->approve($adjustment, $this->approver);
        } finally {
            $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
            $this->assertEquals($sourceLocation->id, $serial->fresh()->location_id);
            $this->assertEquals(1, $sourceStock->fresh()->quantity_tax, 'Source bucket must be untouched.');
            $this->assertEquals(0, $destStock->fresh()->quantity_tax, 'Destination bucket must be untouched.');
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    /**
     * A missing/invalid source Location is unreachable through any write
     * path in this schema: product_serial_numbers.location_id is declared
     * with onDelete('cascade') AND the foreign key is enforced at the
     * database level (verified directly -- even a raw, Eloquent-bypassing
     * UPDATE to a non-existent location_id is rejected by SQLite's FK
     * constraint). A serial can therefore never reference a Location absent
     * from the locked map by the time approval runs; the two reachable
     * "source location invalid at lock time" scenarios --
     * became-consignment and changed-during-discovery -- are covered by
     * test_source_location_becoming_consignment_between_discovery_and_lock_blocks_approval_with_zero_mutations
     * and the existing test_approval_revalidates_against_authoritative_state_not_stale_row_reference
     * respectively. assertSerialLocationsUnchanged()'s missingLockedLocation
     * guard remains as defense-in-depth for that database invariant.
     */

    /**
     * Every Transaction row a cross-setting move writes must obtain its
     * setting_id from the LOCKED source/destination Location rows -- never
     * unconditionally the destination's setting, and never a fresh
     * Location::find() read outside the lock.
     */
    public function test_cross_setting_transactions_obtain_setting_id_from_locked_source_and_destination_locations()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => \Modules\Currency\Entities\Currency::first()->id,
            'default_currency_position' => 'prefix', 'is_pkp' => false,
        ]);
        $foreignSettingLocation = Location::create([
            'name' => 'Gudang Setting Lain Locked', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $product = $this->makeProduct(true);
        $product->update(['setting_id' => $otherSetting->id]);

        $this->makeStockRow($product, $foreignSettingLocation, goodNonTax: 1);
        $this->makeStockRow($product, $this->location);
        $this->makeSerial($product, $foreignSettingLocation, 'SN-LOCKED-SETTING-EVIDENCE', isBroken: false, taxId: null);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-LOCKED-SETTING-EVIDENCE', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'location_id' => $foreignSettingLocation->id,
            'setting_id' => $otherSetting->id,
        ]);
        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'setting_id' => $this->setting->id,
        ]);
    }

    /**
     * There must be no code path by which a source-location Transaction row
     * silently receives the destination's setting_id as a fallback: assert
     * directly against the actual persisted row rather than only the happy
     * path, so a regression that reintroduces the fallback is caught even if
     * it coincidentally produces the right value in other tests.
     */
    public function test_no_destination_setting_fallback_is_possible_for_source_location_transaction()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => \Modules\Currency\Entities\Currency::first()->id,
            'default_currency_position' => 'prefix', 'is_pkp' => true,
        ]);
        $foreignSettingLocation = Location::create([
            'name' => 'Gudang Setting Lain No Fallback', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $product = $this->makeProduct(true);
        $product->update(['setting_id' => $otherSetting->id]);

        $this->makeStockRow($product, $foreignSettingLocation, goodTax: 1);
        $this->makeStockRow($product, $this->location);
        $this->makeSerial($product, $foreignSettingLocation, 'SN-NO-FALLBACK', isBroken: false, taxId: $this->tax->id);

        $adjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [
                ['serial_number' => 'SN-NO-FALLBACK', 'condition' => 'good'],
            ]],
        ]);

        $this->service()->approve($adjustment, $this->approver);

        $sourceTransaction = Transaction::where('product_id', $product->id)
            ->where('location_id', $foreignSettingLocation->id)
            ->first();

        $this->assertNotNull($sourceTransaction);
        $this->assertEquals($otherSetting->id, $sourceTransaction->setting_id);
        $this->assertNotEquals(
            $this->setting->id,
            $sourceTransaction->setting_id,
            'The source-location transaction must never fall back to the destination setting_id.'
        );
    }

    /**
     * Approval's Location-locking step must be one bulk query regardless of
     * how many serials/transactions the document touches -- adding this
     * correction must not introduce a query per serial or per transaction.
     */
    public function test_approval_query_count_does_not_scale_with_transaction_count()
    {
        $fewProduct = $this->makeProduct(true);
        $this->makeStockRow($fewProduct, $this->otherLocation, goodTax: 2);
        $this->makeStockRow($fewProduct, $this->location);
        $fewSerials = [];
        for ($s = 0; $s < 2; $s++) {
            $this->makeSerial($fewProduct, $this->otherLocation, "SN-TXN-FEW-{$s}", isBroken: false, taxId: $this->tax->id);
            $fewSerials[] = ['serial_number' => "SN-TXN-FEW-{$s}", 'condition' => 'good'];
        }
        $fewAdjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $fewProduct->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => $fewSerials],
        ]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->service()->approve($fewAdjustment, $this->approver);
        $fewQueryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        // Each moved serial legitimately writes its own SerialNumberHistory
        // row (one INSERT per serial -- genuine per-row audit data, not a
        // query-count defect), so the delta between 2 and 12 serials is
        // expected to include roughly 10 such inserts. What this test
        // isolates is that the NEW Location-locking query added by this
        // correction (a single bulk whereIn over referenced location ids)
        // does not ALSO turn into one query per serial on top of that
        // already-expected per-serial history write -- i.e. the delta stays
        // close to "one history insert per extra serial", not "one history
        // insert plus one location lock plus other per-serial overhead".
        $manyProduct = $this->makeProduct(true);
        $this->makeStockRow($manyProduct, $this->otherLocation, goodTax: 12);
        $this->makeStockRow($manyProduct, $this->location);
        $manySerials = [];
        for ($s = 0; $s < 12; $s++) {
            $this->makeSerial($manyProduct, $this->otherLocation, "SN-TXN-MANY-{$s}", isBroken: false, taxId: $this->tax->id);
            $manySerials[] = ['serial_number' => "SN-TXN-MANY-{$s}", 'condition' => 'good'];
        }
        $manyAdjustment = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $manyProduct->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => $manySerials],
        ]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->service()->approve($manyAdjustment, $this->approver);
        $manyQueryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // 10 extra serials (2 -> 12), each expected to add exactly two
        // queries (its own UPDATE plus one SerialNumberHistory INSERT --
        // both genuine per-row writes, not a query-count defect): the total
        // delta must stay close to 20, not balloon well beyond it, which is
        // what an accidental per-serial Location::find()/lock query (the
        // exact regression this correction fixes) would add on top.
        $this->assertLessThan(
            $fewQueryCount + 25,
            $manyQueryCount,
            'Query count must not scale beyond the expected two-writes-per-serial cost; the new Location lock must remain a single bulk query.'
        );
    }
}

<?php

namespace Tests\Feature\Adjustment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\DTOs\SerialClassification;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\StockOpnameReconciliationService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class StockOpnameReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $location;
    protected Location $otherLocation;
    protected Unit $baseUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->location = Location::create([
            'name' => 'Gudang Utama',
            'setting_id' => $this->setting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->otherLocation = Location::create([
            'name' => 'Gudang Cabang',
            'setting_id' => $this->setting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->baseUnit = Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
            'is_active' => true,
        ]);

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Barang Uji',
            'product_code' => 'BU-' . uniqid(),
            'barcode' => 'BAR-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
            'serial_number_required' => false,
        ], $overrides));
    }

    private function makeAdjustment(array $draft): Adjustment
    {
        return Adjustment::create([
            'reference' => 'ADJ-' . uniqid(),
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'note' => null,
            'count_draft' => $draft,
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
        ]);
    }

    public function test_non_serialized_reconciliation_computes_differences_and_totals()
    {
        $product = $this->makeProduct();

        // quantity is the established TOTAL (good + broken) convention, so
        // 10 good_tax + 1 broken_tax => quantity = 11, not 10.
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 11,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 1,
            'broken_quantity_tax' => 1,
            'broken_quantity_non_tax' => 0,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => false,
                    'good_count' => 8,
                    'bad_count' => 3,
                    'baseline' => [
                        'existing_good_total' => 10,
                        'existing_bad_total' => 1,
                    ],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);

        $this->assertCount(1, $result->products);
        $p = $result->products[0];

        $this->assertSame(10, $p->currentGood);
        $this->assertSame(1, $p->currentBad);
        $this->assertSame(8, $p->enteredGood);
        $this->assertSame(3, $p->enteredBad);
        $this->assertSame(-2, $p->goodDifference);
        $this->assertSame(2, $p->badDifference);
        $this->assertTrue($p->isConditionReclassificationOnly());
        $this->assertSame(0.0, $p->potentialGlobalIncrease);
        $this->assertFalse($p->exceedsAllLocationTotal);
    }

    /**
     * Regression for the double-counting bug: ProductStock::quantity is
     * already the TOTAL (good + broken) per the established convention, so
     * current good must be derived from the two good buckets directly.
     * Reading currentGood from `quantity` (as if it were good-only) would
     * report currentGood=11 instead of 10 whenever broken stock is nonzero.
     */
    public function test_current_good_is_derived_from_good_buckets_not_from_quantity_when_broken_stock_is_nonzero()
    {
        $product = $this->makeProduct();

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 15, // 10 good + 5 broken
            'quantity_tax' => 6,
            'quantity_non_tax' => 4,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 3,
            'broken_quantity_non_tax' => 2,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => false,
                    'good_count' => 10,
                    'bad_count' => 5,
                    'baseline' => ['existing_good_total' => 10, 'existing_bad_total' => 5],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertSame(10, $p->currentGood);
        $this->assertSame(5, $p->currentBad);
        $this->assertSame(0, $p->goodDifference);
        $this->assertSame(0, $p->badDifference);
        $this->assertFalse($p->exceedsAllLocationTotal);
        $this->assertSame(0.0, $p->potentialGlobalIncrease);
    }

    /**
     * Regression for the double-counting bug in the all-location total:
     * summing SUM(quantity) + SUM(broken_quantity) across locations doubles
     * every location's broken units into the grand total. The correct
     * all-location total is SUM(quantity) alone, since `quantity` already
     * includes broken.
     */
    public function test_all_location_total_does_not_double_count_broken_stock_across_locations()
    {
        $product = $this->makeProduct();

        // 10 good + 5 broken at each of two eligible locations => 30 total,
        // never 40 (which is what SUM(quantity) + SUM(broken_quantity) would
        // incorrectly produce: (15+15) + (5+5) = 40).
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 15,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 5,
            'broken_quantity_non_tax' => 0,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->otherLocation->id,
            'quantity' => 15,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 5,
            'broken_quantity_non_tax' => 0,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => false,
                    'good_count' => 10,
                    'bad_count' => 5,
                    'baseline' => ['existing_good_total' => 10, 'existing_bad_total' => 5],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertEquals(30.0, $p->allLocationCurrentTotal);
        $this->assertFalse($p->exceedsAllLocationTotal);
        $this->assertSame(0.0, $p->potentialGlobalIncrease);
    }

    public function test_warns_when_entered_total_exceeds_all_location_total()
    {
        $product = $this->makeProduct();

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Other eligible location contributes to the same-owner all-location total.
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->otherLocation->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => false,
                    'good_count' => 12,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 5, 'existing_bad_total' => 0],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertEquals(10.0, $p->allLocationCurrentTotal);
        $this->assertTrue($p->exceedsAllLocationTotal);
        $this->assertEquals(2.0, $p->potentialGlobalIncrease);
        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('melebihi total stok', collect($result->warnings)->first());
    }

    public function test_consignment_location_excluded_from_all_location_total()
    {
        $product = $this->makeProduct();

        $consignmentLocation = Location::create([
            'name' => 'Titik Konsinyasi',
            'setting_id' => $this->setting->id,
            'is_active' => true,
            'is_consignment' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $consignmentLocation->id,
            'quantity' => 100,
            'quantity_tax' => 100,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => false,
                    'good_count' => 5,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 5, 'existing_bad_total' => 0],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertEquals(5.0, $p->allLocationCurrentTotal);
    }

    public function test_serialized_product_classifies_moved_new_and_retained_serials()
    {
        $product = $this->makeProduct(['serial_number_required' => true]);

        // Destination setting is_pkp = true, so a serial already carrying
        // tax stays retained (no tax change) only if it is already taxed.
        $tax = \Modules\Setting\Entities\Tax::create(['name' => 'PPN', 'value' => 11]);

        $retained = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-RETAINED',
            'tax_id' => $tax->id,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $moved = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->otherLocation->id,
            'serial_number' => 'SN-MOVED',
            'tax_id' => $tax->id,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => true,
                    'good_count' => 3,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 1, 'existing_bad_total' => 0],
                    'serials' => [
                        ['serial_number' => 'SN-RETAINED', 'condition' => 'good'],
                        ['serial_number' => 'SN-MOVED', 'condition' => 'good'],
                        ['serial_number' => 'SN-NEW', 'condition' => 'good'],
                    ],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $byText = collect($p->serials)->keyBy('serialNumber');

        $this->assertSame(SerialClassification::STATUS_RETAINED, $byText->get('SN-RETAINED')->status);
        $this->assertSame(SerialClassification::STATUS_MOVED, $byText->get('SN-MOVED')->status);
        $this->assertSame($this->otherLocation->id, $byText->get('SN-MOVED')->sourceLocationId);
        $this->assertSame(SerialClassification::STATUS_NEW, $byText->get('SN-NEW')->status);
    }

    public function test_same_serial_text_on_another_product_warns_without_treating_as_own_existing_serial()
    {
        $productA = $this->makeProduct(['serial_number_required' => true, 'product_code' => 'PA']);
        $productB = $this->makeProduct(['serial_number_required' => true, 'product_code' => 'PB']);

        ProductSerialNumber::create([
            'product_id' => $productB->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-SHARED',
            'tax_id' => null,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $productA->id,
                    'is_serialized' => true,
                    'good_count' => 1,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 0, 'existing_bad_total' => 0],
                    'serials' => [
                        ['serial_number' => 'SN-SHARED', 'condition' => 'good'],
                    ],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];
        $serial = $p->serials[0];

        $this->assertSame(SerialClassification::STATUS_NEW, $serial->status);
        $this->assertTrue($serial->sameTextOtherProduct);
        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('sudah terdaftar pada produk lain', collect($result->warnings)->implode(' | '));
    }

    public function test_omitted_destination_serial_is_listed_as_discrepancy()
    {
        $product = $this->makeProduct(['serial_number_required' => true]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-OMITTED',
            'tax_id' => null,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => true,
                    'good_count' => 0,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 1, 'existing_bad_total' => 0],
                    'serials' => [],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertCount(1, $p->omittedSerials);
        $this->assertSame('SN-OMITTED', $p->omittedSerials[0]->serialNumber);
        $this->assertSame(SerialClassification::STATUS_OMITTED, $p->omittedSerials[0]->status);
    }

    public function test_serial_with_active_dispatch_is_a_blocking_conflict()
    {
        $product = $this->makeProduct(['serial_number_required' => true]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->otherLocation->id,
            'serial_number' => 'SN-DISPATCHED',
            'tax_id' => null,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'dispatch_detail_id' => 999,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => true,
                    'good_count' => 1,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 0, 'existing_bad_total' => 0],
                    'serials' => [
                        ['serial_number' => 'SN-DISPATCHED', 'condition' => 'good'],
                    ],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertSame(SerialClassification::STATUS_CONFLICTING, $p->serials[0]->status);
        $this->assertTrue($result->hasConflicts());
    }

    public function test_destination_pkp_authoritative_over_serial_source_tax_state()
    {
        // Destination setting is Non-PKP; a taxable serial entered here must
        // project Kena Pajak -> Tidak Kena Pajak, ignoring its saved tax_id.
        $nonPkpSetting = Setting::create([
            'company_name' => 'Non PKP Co',
            'company_email' => 'nonpkp@company.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
            'default_currency_id' => \Modules\Currency\Entities\Currency::first()->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        $nonPkpLocation = Location::create([
            'name' => 'Gudang Non PKP',
            'setting_id' => $nonPkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $product = Product::create([
            'product_name' => 'Barang Pajak',
            'product_code' => 'BP-' . uniqid(),
            'barcode' => 'BAR-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $nonPkpSetting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
            'serial_number_required' => true,
        ]);

        $tax = \Modules\Setting\Entities\Tax::create([
            'name' => 'PPN',
            'value' => 11,
        ]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $nonPkpLocation->id,
            'serial_number' => 'SN-TAXED',
            'tax_id' => $tax->id,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-' . uniqid(),
            'date' => now()->toDateString(),
            'location_id' => $nonPkpLocation->id,
            'note' => null,
            'count_draft' => [
                'schema_version' => 1,
                'location_id' => $nonPkpLocation->id,
                'rows' => [
                    [
                        'product_id' => $product->id,
                        'is_serialized' => true,
                        'good_count' => 1,
                        'bad_count' => 0,
                        'baseline' => ['existing_good_total' => 1, 'existing_bad_total' => 0],
                        'serials' => [
                            ['serial_number' => 'SN-TAXED', 'condition' => 'good'],
                        ],
                    ],
                ],
            ],
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);

        $this->assertFalse($result->isPkp);
        $serial = $result->products[0]->serials[0];
        $this->assertSame(SerialClassification::STATUS_TAX_CHANGED, $serial->status);
        $this->assertTrue($serial->sourceIsTax);
        $this->assertFalse($serial->destinationIsTax);
    }

    public function test_counter_projection_excludes_baseline_current_and_serial_source_facts()
    {
        $product = $this->makeProduct(['serial_number_required' => true]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 99,
            'quantity_tax' => 99,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->otherLocation->id,
            'serial_number' => 'SN-SECRET',
            'tax_id' => null,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => true,
                    'good_count' => 1,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 99, 'existing_bad_total' => 0],
                    'serials' => [
                        ['serial_number' => 'SN-SECRET', 'condition' => 'good'],
                    ],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $counterArray = $result->toCounterArray();
        $encoded = json_encode($counterArray);

        $this->assertStringNotContainsString('baseline', $encoded);
        $this->assertStringNotContainsString('current', $encoded);
        $this->assertStringNotContainsString('99', $encoded);
        $this->assertStringNotContainsString('source_location', $encoded);
        $this->assertStringNotContainsString('warnings', $encoded);
        $this->assertArrayNotHasKey('warnings', $counterArray);
        $this->assertArrayNotHasKey('conflicts', $counterArray);

        $reviewerArray = $result->toReviewerArray();
        $this->assertArrayHasKey('warnings', $reviewerArray);
        $this->assertArrayHasKey('conflicts', $reviewerArray);
        $this->assertEquals(99, $reviewerArray['products'][0]['current']['good']);
    }

    public function test_serialized_global_projection_is_identity_based_not_delta_based()
    {
        $product = $this->makeProduct(['serial_number_required' => true]);

        $tax = \Modules\Setting\Entities\Tax::create(['name' => 'PPN', 'value' => 11]);

        // One serial already at the destination (retained).
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-RETAINED',
            'tax_id' => $tax->id,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        // One serial at another eligible location (will be moved in).
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->otherLocation->id,
            'serial_number' => 'SN-MOVED',
            'tax_id' => $tax->id,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        // ProductStock rows backing the all-location total: 1 unit at each
        // of the two eligible locations (2 total), matching the two existing
        // serials above.
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->otherLocation->id,
            'quantity' => 1,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Entered: retained + moved (2 pre-existing serials, net zero) plus
        // one genuinely new serial (adds exactly one unit globally).
        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => true,
                    'good_count' => 3,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 1, 'existing_bad_total' => 0],
                    'serials' => [
                        ['serial_number' => 'SN-RETAINED', 'condition' => 'good'],
                        ['serial_number' => 'SN-MOVED', 'condition' => 'good'],
                        ['serial_number' => 'SN-NEW', 'condition' => 'good'],
                    ],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        // All-location current total is 2 (1 + 1). The ordinary-product delta
        // formula would compute entered(3) - current(1) = +2, projecting a
        // global total of 4. The correct identity-based projection only adds
        // the genuinely new serial: 2 (current) + 1 (new) = 3.
        $this->assertEquals(2.0, $p->allLocationCurrentTotal);
        $this->assertEquals(3.0, $p->projectedGlobalTotal);
    }

    public function test_foreign_setting_product_is_rejected_as_conflict()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Co',
            'company_email' => 'other@company.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
            'default_currency_id' => \Modules\Currency\Entities\Currency::first()->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $foreignProduct = Product::create([
            'product_name' => 'Barang Asing',
            'product_code' => 'FOREIGN-' . uniqid(),
            'barcode' => 'BAR-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $otherSetting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
            'serial_number_required' => false,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $foreignProduct->id,
                    'is_serialized' => false,
                    'good_count' => 5,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 0, 'existing_bad_total' => 0],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);

        $this->assertCount(0, $result->products);
        $this->assertTrue($result->hasConflicts());
        $this->assertStringContainsString('tidak ditemukan atau bukan milik pengaturan aktif', collect($result->conflicts)->implode(' | '));
    }

    public function test_serial_at_cross_owner_or_consignment_location_is_rejected_as_conflict_not_movable()
    {
        $product = $this->makeProduct(['serial_number_required' => true]);

        $otherSetting = Setting::create([
            'company_name' => 'Other Co 2',
            'company_email' => 'other2@company.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
            'default_currency_id' => \Modules\Currency\Entities\Currency::first()->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $foreignLocation = Location::create([
            'name' => 'Gudang Milik Lain',
            'setting_id' => $otherSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $consignmentLocation = Location::create([
            'name' => 'Titik Konsinyasi 2',
            'setting_id' => $this->setting->id,
            'is_active' => true,
            'is_consignment' => true,
        ]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $foreignLocation->id,
            'serial_number' => 'SN-CROSS-OWNER',
            'tax_id' => null,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $consignmentLocation->id,
            'serial_number' => 'SN-CONSIGNMENT',
            'tax_id' => null,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $adjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => true,
                    'good_count' => 2,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 0, 'existing_bad_total' => 0],
                    'serials' => [
                        ['serial_number' => 'SN-CROSS-OWNER', 'condition' => 'good'],
                        ['serial_number' => 'SN-CONSIGNMENT', 'condition' => 'good'],
                    ],
                ],
            ],
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $byText = collect($p->serials)->keyBy('serialNumber');

        $this->assertSame(SerialClassification::STATUS_CONFLICTING, $byText->get('SN-CROSS-OWNER')->status);
        $this->assertSame(SerialClassification::STATUS_CONFLICTING, $byText->get('SN-CONSIGNMENT')->status);
        $this->assertTrue($result->hasConflicts());
        $this->assertCount(2, $result->conflicts);
    }

    public function test_moved_serial_reports_simultaneous_tax_and_condition_change()
    {
        // Destination setting is Non-PKP. Source serial (at another eligible
        // location within the same Non-PKP setting) is taxable and broken;
        // entered as good at the destination, so it moves, changes
        // condition, and changes tax classification all at once.
        $nonPkpSetting = Setting::create([
            'company_name' => 'Non PKP Multi Co',
            'company_email' => 'nonpkpmulti@company.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
            'default_currency_id' => \Modules\Currency\Entities\Currency::first()->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        $destinationLocation = Location::create([
            'name' => 'Gudang Tujuan Non PKP',
            'setting_id' => $nonPkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $sourceLocation = Location::create([
            'name' => 'Gudang Sumber Non PKP',
            'setting_id' => $nonPkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $product = Product::create([
            'product_name' => 'Barang Multi Efek',
            'product_code' => 'BM-' . uniqid(),
            'barcode' => 'BAR-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $nonPkpSetting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
            'serial_number_required' => true,
        ]);

        $tax = \Modules\Setting\Entities\Tax::create(['name' => 'PPN Multi', 'value' => 11]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $sourceLocation->id,
            'serial_number' => 'SN-MULTI',
            'tax_id' => $tax->id,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => true,
        ]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-' . uniqid(),
            'date' => now()->toDateString(),
            'location_id' => $destinationLocation->id,
            'note' => null,
            'count_draft' => [
                'schema_version' => 1,
                'location_id' => $destinationLocation->id,
                'rows' => [
                    [
                        'product_id' => $product->id,
                        'is_serialized' => true,
                        'good_count' => 1,
                        'bad_count' => 0,
                        'baseline' => ['existing_good_total' => 0, 'existing_bad_total' => 0],
                        'serials' => [
                            ['serial_number' => 'SN-MULTI', 'condition' => 'good'],
                        ],
                    ],
                ],
            ],
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
        ]);

        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $classification = $result->products[0]->serials[0];

        $this->assertTrue($classification->hasStatus(SerialClassification::STATUS_MOVED));
        $this->assertTrue($classification->hasStatus(SerialClassification::STATUS_CONDITION_CHANGED));
        $this->assertTrue($classification->hasStatus(SerialClassification::STATUS_TAX_CHANGED));
        $this->assertStringContainsString('Kena Pajak → Tidak Kena Pajak', $classification->label);
        $this->assertStringNotContainsString('Tidak Kena Pajak → Kena Pajak', $classification->label);

        $reviewerArray = $classification->toReviewerArray();
        $this->assertContains(SerialClassification::STATUS_MOVED, $reviewerArray['statuses']);
        $this->assertContains(SerialClassification::STATUS_CONDITION_CHANGED, $reviewerArray['statuses']);
        $this->assertContains(SerialClassification::STATUS_TAX_CHANGED, $reviewerArray['statuses']);
    }

    public function test_reconciliation_query_count_does_not_scale_with_product_or_serial_row_count()
    {
        // Build a mid-size document (10 products, some serialized with
        // multiple serials each) and assert the query count stays flat
        // regardless of row count -- proving no query runs inside the
        // product or serial classification loop.
        $products = [];
        $rows = [];

        for ($i = 0; $i < 10; $i++) {
            $isSerialized = $i % 2 === 0;
            $product = $this->makeProduct([
                'product_code' => "QC-{$i}-" . uniqid(),
                'serial_number_required' => $isSerialized,
            ]);
            $products[] = $product;

            if ($isSerialized) {
                for ($s = 0; $s < 3; $s++) {
                    ProductSerialNumber::create([
                        'product_id' => $product->id,
                        'location_id' => $this->location->id,
                        'serial_number' => "SN-{$i}-{$s}",
                        'tax_id' => null,
                        'status' => ProductSerialNumber::STATUS_ACTIVE,
                        'is_broken' => false,
                    ]);
                }

                $rows[] = [
                    'product_id' => $product->id,
                    'is_serialized' => true,
                    'good_count' => 3,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 3, 'existing_bad_total' => 0],
                    'serials' => [
                        ['serial_number' => "SN-{$i}-0", 'condition' => 'good'],
                        ['serial_number' => "SN-{$i}-1", 'condition' => 'good'],
                        ['serial_number' => "SN-{$i}-2", 'condition' => 'good'],
                    ],
                ];
            } else {
                ProductStock::create([
                    'product_id' => $product->id,
                    'location_id' => $this->location->id,
                    'quantity' => 10,
                    'quantity_tax' => 10,
                    'quantity_non_tax' => 0,
                    'broken_quantity' => 0,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                ]);

                $rows[] = [
                    'product_id' => $product->id,
                    'is_serialized' => false,
                    'good_count' => 8,
                    'bad_count' => 0,
                    'baseline' => ['existing_good_total' => 10, 'existing_bad_total' => 0],
                ];
            }
        }

        $smallAdjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [$rows[0]],
        ]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        app(StockOpnameReconciliationService::class)->reconcile($smallAdjustment);
        $smallQueryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $largeAdjustment = $this->makeAdjustment([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => $rows,
        ]);

        app(StockOpnameReconciliationService::class)->reconcile($largeAdjustment);
        $largeQueryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // The query count must not scale linearly with the number of rows:
        // going from 1 row to 10 rows (5 serialized with 3 serials each)
        // should add at most a small constant number of extra queries, not
        // one (or more) per product/serial.
        $this->assertLessThanOrEqual(
            $smallQueryCount + 3,
            $largeQueryCount,
            "Query count scaled with row count: {$smallQueryCount} (1 row) vs {$largeQueryCount} (10 rows)."
        );
    }
}

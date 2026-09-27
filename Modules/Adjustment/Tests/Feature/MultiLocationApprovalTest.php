<?php

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentLocation;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\StockOpnameApprovalService;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MultiLocationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $approver;
    protected Setting $settingA; // PKP
    protected Setting $settingB; // Non-PKP
    protected Location $locA1; // PKP
    protected Location $locA2; // PKP
    protected Location $locB1; // Non-PKP
    protected Location $locB2; // Non-PKP
    protected Location $outsideLoc;
    protected Tax $tax;
    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->settingA = Setting::create([
            'company_name' => 'PT Setting A',
            'company_email' => 'a@test.com',
            'company_phone' => '111',
            'notification_email' => 'a@test.com',
            'company_address' => 'Jakarta',
            'footer_text' => 'Footer A',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'UD Setting B',
            'company_email' => 'b@test.com',
            'company_phone' => '222',
            'notification_email' => 'b@test.com',
            'company_address' => 'Surabaya',
            'footer_text' => 'Footer B',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        $this->tax = Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true]);

        $this->locA1 = Location::create(['name' => 'Gudang A1', 'setting_id' => $this->settingA->id, 'is_active' => true, 'is_consignment' => false]);
        $this->locA2 = Location::create(['name' => 'Gudang A2', 'setting_id' => $this->settingA->id, 'is_active' => true, 'is_consignment' => false]);
        $this->locB1 = Location::create(['name' => 'Gudang B1', 'setting_id' => $this->settingB->id, 'is_active' => true, 'is_consignment' => false]);
        $this->locB2 = Location::create(['name' => 'Gudang B2', 'setting_id' => $this->settingB->id, 'is_active' => true, 'is_consignment' => false]);
        $this->outsideLoc = Location::create(['name' => 'Gudang Luar', 'setting_id' => $this->settingA->id, 'is_active' => true, 'is_consignment' => false]);

        $this->unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1, 'is_active' => true]);

        $this->approver = User::factory()->create(['is_active' => 1]);
        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        $this->approver->givePermissionTo(['adjustments.approval', 'adjustments.edit']);

        $this->actingAs($this->approver);
        session(['setting_id' => $this->settingA->id]);
    }

    private function createProduct(bool $serialized = false): Product
    {
        return Product::create([
            'product_name' => $serialized ? 'Produk Serial Test' : 'Produk Reguler Test',
            'product_code' => ($serialized ? 'PST-' : 'PRT-') . uniqid(),
            'product_cost' => 10000,
            'product_price' => 20000,
            'product_quantity' => 0,
            'setting_id' => $this->settingA->id,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'stock_managed' => true,
            'is_active' => true,
            'serial_number_required' => $serialized,
        ]);
    }

    private function setStock(Product $product, Location $loc, int $goodTax, int $goodNonTax, int $badTax = 0, int $badNonTax = 0): ProductStock
    {
        $stock = ProductStock::updateOrCreate(
            ['product_id' => $product->id, 'location_id' => $loc->id],
            [
                'quantity_tax' => $goodTax,
                'quantity_non_tax' => $goodNonTax,
                'broken_quantity_tax' => $badTax,
                'broken_quantity_non_tax' => $badNonTax,
                'broken_quantity' => $badTax + $badNonTax,
                'quantity' => $goodTax + $goodNonTax + $badTax + $badNonTax,
            ]
        );

        $this->syncGlobalProductStock($product);

        return $stock;
    }

    private function syncGlobalProductStock(Product $product): void
    {
        $totalGood = ProductStock::where('product_id', $product->id)->sum('quantity_tax')
            + ProductStock::where('product_id', $product->id)->sum('quantity_non_tax');
        $totalBroken = ProductStock::where('product_id', $product->id)->sum('broken_quantity');

        $product->update([
            'product_quantity' => $totalGood + $totalBroken,
            'broken_quantity' => $totalBroken,
        ]);
    }

    private function makeAdjustmentV2(array $locations, array $rows): Adjustment
    {
        $primary = $locations[0];
        $adj = Adjustment::create([
            'date' => now()->toDateString(),
            'reference' => 'SO-V2-' . uniqid(),
            'location_id' => $primary->id,
            'status' => AdjustmentStatus::WaitingApproval->value,
            'count_draft' => [
                'schema_version' => 2,
                'locations' => array_map(fn ($l) => (int) $l->id, $locations),
                'rows' => $rows,
            ],
        ]);

        foreach ($locations as $idx => $loc) {
            AdjustmentLocation::create([
                'adjustment_id' => $adj->id,
                'location_id' => $loc->id,
                'position' => $idx + 1,
            ]);
        }

        return $adj;
    }

    public function test_multi_location_non_serialized_shortage_waterfall_order(): void
    {
        $product = $this->createProduct(false);

        // locB1 (non-PKP): 10 good
        // locB2 (non-PKP): 20 good
        // locA1 (PKP): 15 good
        // locA2 (PKP): 5 good
        // Pool Total Good = 50.
        $this->setStock($product, $this->locB1, 0, 10);
        $this->setStock($product, $this->locB2, 0, 20);
        $this->setStock($product, $this->locA1, 15, 0);
        $this->setStock($product, $this->locA2, 5, 0);

        // Physical count = 22 good (Shortage of 28).
        // Shortage order: (is_pkp ASC, stock DESC, location_id ASC)
        // 1. locB2 (non-PKP, stock 20): deducts 20 -> remaining shortage 8, stock becomes 0
        // 2. locB1 (non-PKP, stock 10): deducts 8 -> remaining shortage 0, stock becomes 2
        // 3. locA1 (PKP, stock 15): deducts 0 -> stock remains 15
        // 4. locA2 (PKP, stock 5): deducts 0 -> stock remains 5
        $adj = $this->makeAdjustmentV2(
            [$this->locA1, $this->locA2, $this->locB1, $this->locB2],
            [
                ['product_id' => $product->id, 'good_count' => 22, 'bad_count' => 0],
            ]
        );

        $service = app(StockOpnameApprovalService::class);
        $service->approve($adj, $this->approver);

        $this->assertEquals(AdjustmentStatus::Approved, $adj->fresh()->status);

        // Verify per-location stocks
        $stockB2 = ProductStock::where('product_id', $product->id)->where('location_id', $this->locB2->id)->first();
        $stockB1 = ProductStock::where('product_id', $product->id)->where('location_id', $this->locB1->id)->first();
        $stockA1 = ProductStock::where('product_id', $product->id)->where('location_id', $this->locA1->id)->first();
        $stockA2 = ProductStock::where('product_id', $product->id)->where('location_id', $this->locA2->id)->first();

        $this->assertEquals(0, $stockB2->quantity);
        $this->assertEquals(2, $stockB1->quantity_non_tax);
        $this->assertEquals(15, $stockA1->quantity_tax);
        $this->assertEquals(5, $stockA2->quantity_tax);

        // Global product quantity
        $this->assertEquals(22, $product->fresh()->product_quantity);

        // Verify transaction attribution
        $txB2 = Transaction::where('product_id', $product->id)->where('location_id', $this->locB2->id)->first();
        $this->assertNotNull($txB2);
        $this->assertEquals(-20, $txB2->quantity);
        $this->assertEquals($this->settingB->id, $txB2->setting_id);

        $txB1 = Transaction::where('product_id', $product->id)->where('location_id', $this->locB1->id)->first();
        $this->assertNotNull($txB1);
        $this->assertEquals(-8, $txB1->quantity);
        $this->assertEquals($this->settingB->id, $txB1->setting_id);

        // approval_result checks
        $result = $adj->fresh()->approval_result;
        $this->assertIsArray($result);
        $this->assertCount(4, $result['selected_locations']);
        $this->assertEquals(22, $result['products'][0]['applied']['good']);
    }

    public function test_multi_location_non_serialized_surplus_assigned_to_first_target(): void
    {
        $product = $this->createProduct(false);

        // locA1 (PKP): 5 good
        // locB1 (non-PKP): 10 good
        // locB2 (non-PKP): 2 good
        // Pool Total Good = 17.
        $this->setStock($product, $this->locA1, 5, 0);
        $this->setStock($product, $this->locB1, 0, 10);
        $this->setStock($product, $this->locB2, 0, 2);

        // Physical count = 27 good (Surplus of 10).
        // Surplus order: (is_pkp ASC, stock ASC, location_id ASC)
        // Non-PKP lowest stock is locB2 (stock 2).
        // ENTIRE surplus (10) goes to locB2 -> stock becomes 12.
        // locB1 remains 10, locA1 remains 5.
        $adj = $this->makeAdjustmentV2(
            [$this->locA1, $this->locB1, $this->locB2],
            [
                ['product_id' => $product->id, 'good_count' => 27, 'bad_count' => 0],
            ]
        );

        $service = app(StockOpnameApprovalService::class);
        $service->approve($adj, $this->approver);

        $stockB2 = ProductStock::where('product_id', $product->id)->where('location_id', $this->locB2->id)->first();
        $stockB1 = ProductStock::where('product_id', $product->id)->where('location_id', $this->locB1->id)->first();
        $stockA1 = ProductStock::where('product_id', $product->id)->where('location_id', $this->locA1->id)->first();

        $this->assertEquals(12, $stockB2->quantity_non_tax);
        $this->assertEquals(10, $stockB1->quantity_non_tax);
        $this->assertEquals(5, $stockA1->quantity_tax);

        $this->assertEquals(27, $product->fresh()->product_quantity);
    }

    public function test_multi_location_serialized_reconciliation_approval(): void
    {
        $product = $this->createProduct(true);

        // locA1: holds SN-A1 (good, tax)
        // locB1: holds SN-B1 (good, non-tax) and SN-B2 (good, non-tax)
        // outsideLoc: holds SN-OUT (good, tax)
        $this->setStock($product, $this->locA1, 1, 0);
        $this->setStock($product, $this->locB1, 0, 2);
        $this->setStock($product, $this->outsideLoc, 1, 0);

        $snA1 = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->locA1->id,
            'serial_number' => 'SN-A1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'tax_id' => $this->tax->id,
        ]);
        $snB1 = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->locB1->id,
            'serial_number' => 'SN-B1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);
        $snB2 = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->locB1->id,
            'serial_number' => 'SN-B2',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);
        $snOut = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->outsideLoc->id,
            'serial_number' => 'SN-OUT',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'tax_id' => $this->tax->id,
        ]);

        // Pool selected: locA1 and locB1.
        // Entered serials:
        // - SN-A1: retained at locA1 (good)
        // - SN-B1: retained at locB1, but reclassified as BAD
        // - SN-OUT: moved from outsideLoc into pool surplus destination (locB1 has lower stock after debit or non-PKP)
        // - SN-NEW: brand new serial created in pool surplus destination
        // (SN-B2 is omitted from pool -> marked MISSING)
        $adj = $this->makeAdjustmentV2(
            [$this->locA1, $this->locB1],
            [
                [
                    'product_id' => $product->id,
                    'good_count' => 3,
                    'bad_count' => 1,
                    'serials' => [
                        ['serial_number' => 'SN-A1', 'condition' => 'good'],
                        ['serial_number' => 'SN-B1', 'condition' => 'bad'],
                        ['serial_number' => 'SN-OUT', 'condition' => 'good'],
                        ['serial_number' => 'SN-NEW', 'condition' => 'good'],
                    ],
                ],
            ]
        );

        $service = app(StockOpnameApprovalService::class);
        $service->approve($adj, $this->approver);

        $this->assertEquals(AdjustmentStatus::Approved, $adj->fresh()->status);

        // Check SN-A1: retained at locA1
        $this->assertEquals($this->locA1->id, $snA1->fresh()->location_id);
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $snA1->fresh()->status);
        $this->assertFalse((bool) $snA1->fresh()->is_broken);

        // Check SN-B1: retained at locB1, is_broken = true
        $this->assertEquals($this->locB1->id, $snB1->fresh()->location_id);
        $this->assertTrue((bool) $snB1->fresh()->is_broken);

        // Check SN-B2: marked missing at locB1
        $this->assertEquals(ProductSerialNumber::STATUS_MISSING, $snB2->fresh()->status);

        // Check SN-OUT: moved into pool (locB1 is non-PKP)
        $this->assertEquals($this->locB1->id, $snOut->fresh()->location_id);
        $this->assertNull($snOut->fresh()->tax_id); // moved to non-PKP setting

        // Check SN-NEW: created at locB1
        $snNew = ProductSerialNumber::where('serial_number', 'SN-NEW')->first();
        $this->assertNotNull($snNew);
        $this->assertEquals($this->locB1->id, $snNew->location_id);
        $this->assertNull($snNew->tax_id);

        // History events recorded
        $this->assertTrue(SerialNumberHistory::where('product_serial_number_id', $snOut->id)
            ->where('event_type', SerialNumberHistory::EVENT_LOCATION_TRANSFER)->exists());
        $this->assertTrue(SerialNumberHistory::where('product_serial_number_id', $snB2->id)
            ->where('event_type', SerialNumberHistory::EVENT_STOCK_OPNAME_MISSING)->exists());
        $this->assertTrue(SerialNumberHistory::where('product_serial_number_id', $snNew->id)
            ->where('event_type', SerialNumberHistory::EVENT_STATUS_CHANGED)->exists());
    }

    public function test_approval_rolls_back_entirely_on_insufficient_bucket_availability(): void
    {
        $product = $this->createProduct(true);

        // locA1 has serial record in database, but ProductStock is 0
        $snA1 = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->locA1->id,
            'serial_number' => 'SN-A1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'tax_id' => $this->tax->id,
        ]);
        $this->setStock($product, $this->locA1, 0, 0); // 0 stock in bucket

        $adj = $this->makeAdjustmentV2(
            [$this->locA1],
            [
                [
                    'product_id' => $product->id,
                    'good_count' => 1,
                    'bad_count' => 0,
                    'serials' => [
                        ['serial_number' => 'SN-A1', 'condition' => 'good'],
                    ],
                ],
            ]
        );

        $service = app(StockOpnameApprovalService::class);

        $this->expectException(ValidationException::class);
        $service->approve($adj, $this->approver);

        // Verify status remains WAITING_APPROVAL
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adj->fresh()->status);
        $this->assertNull($adj->fresh()->approval_result);
    }

    public function test_mixed_condition_redistribution_with_good_surplus_and_bad_shortage(): void
    {
        $product = $this->createProduct(false);

        // locA1 (PKP): good 10, bad 5
        // locB1 (non-PKP): good 10, bad 10
        $this->setStock($product, $this->locA1, 10, 0, 5, 0);
        $this->setStock($product, $this->locB1, 0, 10, 0, 10);

        // Count: good = 25 (surplus +5), bad = 8 (shortage -7)
        // Good surplus goes to locB1 (non-PKP, stock 10) -> good becomes 15
        // Bad shortage: locB1 has bad 10 (non-PKP, highest bad), deducts 7 -> locB1 bad becomes 3. locA1 bad remains 5.
        $adj = $this->makeAdjustmentV2(
            [$this->locA1, $this->locB1],
            [
                ['product_id' => $product->id, 'good_count' => 25, 'bad_count' => 8],
            ]
        );

        $service = app(StockOpnameApprovalService::class);
        $service->approve($adj, $this->approver);

        $this->assertEquals(AdjustmentStatus::Approved, $adj->fresh()->status);

        $stockA1 = ProductStock::where('product_id', $product->id)->where('location_id', $this->locA1->id)->first();
        $stockB1 = ProductStock::where('product_id', $product->id)->where('location_id', $this->locB1->id)->first();

        // locA1: good 10 (tax), bad 5 (tax)
        $this->assertEquals(10, $stockA1->quantity_tax);
        $this->assertEquals(5, $stockA1->broken_quantity_tax);

        // locB1: good 15 (non-tax), bad 3 (non-tax)
        $this->assertEquals(15, $stockB1->quantity_non_tax);
        $this->assertEquals(3, $stockB1->broken_quantity_non_tax);

        // Global product
        $this->assertEquals(33, $product->fresh()->product_quantity); // 25 good + 8 bad
        $this->assertEquals(8, $product->fresh()->broken_quantity);
    }

    public function test_immutable_approval_result_remains_stable_after_subsequent_stock_changes(): void
    {
        $product = $this->createProduct(false);
        $this->setStock($product, $this->locA1, 10, 0);
        $this->setStock($product, $this->locB1, 0, 10);

        $adj = $this->makeAdjustmentV2(
            [$this->locA1, $this->locB1],
            [
                ['product_id' => $product->id, 'good_count' => 25, 'bad_count' => 0],
            ]
        );

        $service = app(StockOpnameApprovalService::class);
        $service->approve($adj, $this->approver);

        $initialResult = $adj->fresh()->approval_result;
        $this->assertIsArray($initialResult);
        $this->assertEquals(25, $initialResult['products'][0]['applied']['good']);

        // Later stock change happens
        $this->setStock($product, $this->locA1, 999, 0);

        // Approval result is completely unchanged
        $laterResult = $adj->fresh()->approval_result;
        $this->assertEquals($initialResult, $laterResult);
    }
}

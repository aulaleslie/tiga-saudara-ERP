<?php

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\DTOs\ProductReconciliation;
use Modules\Adjustment\DTOs\ReconciliationResult;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentLocation;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\StockOpnameReconciliationService;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class MultiLocationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $pkpSetting;
    protected Setting $nonPkpSetting;
    protected Location $pkpLocation1;
    protected Location $pkpLocation2;
    protected Location $nonPkpLocation1;
    protected Location $nonPkpLocation2;
    protected Unit $baseUnit;

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

        $this->pkpSetting = Setting::create([
            'company_name' => 'PKP Company',
            'company_email' => 'pkp@company.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@pkp.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->nonPkpSetting = Setting::create([
            'company_name' => 'Non PKP Company',
            'company_email' => 'nonpkp@company.com',
            'company_phone' => '987654321',
            'notification_email' => 'notify@nonpkp.com',
            'footer_text' => 'Footer',
            'company_address' => 'Surabaya',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        $this->pkpLocation1 = Location::create([
            'name' => 'Gudang PKP 1',
            'setting_id' => $this->pkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->pkpLocation2 = Location::create([
            'name' => 'Gudang PKP 2',
            'setting_id' => $this->pkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->nonPkpLocation1 = Location::create([
            'name' => 'Gudang Non-PKP 1',
            'setting_id' => $this->nonPkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->nonPkpLocation2 = Location::create([
            'name' => 'Gudang Non-PKP 2',
            'setting_id' => $this->nonPkpSetting->id,
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
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Barang Uji Multi',
            'product_code' => 'BUM-' . uniqid(),
            'barcode' => 'BAR-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->pkpSetting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
            'serial_number_required' => false,
        ], $overrides));
    }

    private function makeStock(Product $product, Location $location, array $values): ProductStock
    {
        return ProductStock::create(array_merge([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ], $values));
    }

    private function makeMultiLocationAdjustment(array $locationIds, array $rows): Adjustment
    {
        $adjustment = Adjustment::create([
            'reference' => 'ADJ-ML-' . uniqid(),
            'date' => now()->toDateString(),
            'location_id' => null,
            'note' => 'Multi-location opname',
            'count_draft' => [
                'schema_version' => 2,
                'location_ids' => $locationIds,
                'rows' => $rows,
            ],
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
        ]);

        foreach (array_values($locationIds) as $pos => $locId) {
            AdjustmentLocation::create([
                'adjustment_id' => $adjustment->id,
                'location_id' => $locId,
                'position' => $pos,
            ]);
        }

        return $adjustment;
    }

    public function test_multi_location_reconciliation_sums_pool_and_plans_shortage_waterfall()
    {
        $product = $this->makeProduct();

        // Non-PKP 1 has 10 good, Non-PKP 2 has 20 good, PKP 1 has 30 good. Pool good total = 60.
        $this->makeStock($product, $this->nonPkpLocation1, [
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($product, $this->nonPkpLocation2, [
            'quantity' => 20,
            'quantity_tax' => 0,
            'quantity_non_tax' => 20,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($product, $this->pkpLocation1, [
            'quantity' => 30,
            'quantity_tax' => 30,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $locIds = [$this->pkpLocation1->id, $this->nonPkpLocation1->id, $this->nonPkpLocation2->id];

        // Physical count entered is 35 good. Shortage difference = 35 - 60 = -25.
        // Shortage waterfall order: (is_pkp ASC, stock DESC, location_id ASC)
        // 1. Non-PKP 2 (stock 20): deduct 20 -> remaining 5
        // 2. Non-PKP 1 (stock 10): deduct 5 -> remaining 0 (retains 5)
        // 3. PKP 1 (stock 30): deduct 0 (untouched, retains 30)
        $rows = [
            [
                'product_id' => $product->id,
                'is_serialized' => false,
                'good_count' => 35,
                'bad_count' => 0,
                'location_baselines' => [
                    $this->pkpLocation1->id => ['good' => 30, 'bad' => 0, 'total' => 30],
                    $this->nonPkpLocation1->id => ['good' => 10, 'bad' => 0, 'total' => 10],
                    $this->nonPkpLocation2->id => ['good' => 20, 'bad' => 0, 'total' => 20],
                ],
            ],
        ];

        $adjustment = $this->makeMultiLocationAdjustment($locIds, $rows);
        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);

        $this->assertCount(1, $result->products);
        $p = $result->products[0];

        $this->assertSame(60, $p->currentGood);
        $this->assertSame(0, $p->currentBad);
        $this->assertSame(35, $p->enteredGood);
        $this->assertSame(-25, $p->goodDifference);
        $this->assertSame(0, $p->badDifference);
        $this->assertEquals(0.0, $p->drift);

        // Verify allocation plan
        $plan = $p->goodAllocationPlan;
        $this->assertSame('shortage', $plan['type']);
        $this->assertSame(-25, $plan['difference']);

        $stepsByLocId = collect($plan['steps'])->keyBy('location_id');
        $this->assertSame(-20, $stepsByLocId->get($this->nonPkpLocation2->id)['delta']);
        $this->assertSame(0, $stepsByLocId->get($this->nonPkpLocation2->id)['after_stock']);

        $this->assertSame(-5, $stepsByLocId->get($this->nonPkpLocation1->id)['delta']);
        $this->assertSame(5, $stepsByLocId->get($this->nonPkpLocation1->id)['after_stock']);

        $this->assertSame(0, $stepsByLocId->get($this->pkpLocation1->id)['delta']);
        $this->assertSame(30, $stepsByLocId->get($this->pkpLocation1->id)['after_stock']);
    }

    public function test_multi_location_reconciliation_allocates_surplus_to_first_priority_destination()
    {
        $product = $this->makeProduct();

        // Non-PKP 1 has 15, Non-PKP 2 has 5, PKP 1 has 2. Pool good total = 22.
        $this->makeStock($product, $this->nonPkpLocation1, [
            'quantity' => 15,
            'quantity_tax' => 0,
            'quantity_non_tax' => 15,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($product, $this->nonPkpLocation2, [
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($product, $this->pkpLocation1, [
            'quantity' => 2,
            'quantity_tax' => 2,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $locIds = [$this->pkpLocation1->id, $this->nonPkpLocation1->id, $this->nonPkpLocation2->id];

        // Entered good is 30. Surplus difference = 30 - 22 = +8.
        // Surplus priority: (is_pkp ASC, stock ASC, location_id ASC)
        // 1. Non-PKP 2 (stock 5) is non-PKP with least stock -> receives all +8 (after: 13)
        // 2. Non-PKP 1 (stock 15) -> delta 0 (after: 15)
        // 3. PKP 1 (stock 2) -> delta 0 (after: 2)
        $rows = [
            [
                'product_id' => $product->id,
                'is_serialized' => false,
                'good_count' => 30,
                'bad_count' => 0,
                'location_baselines' => [
                    $this->pkpLocation1->id => ['good' => 2, 'bad' => 0, 'total' => 2],
                    $this->nonPkpLocation1->id => ['good' => 15, 'bad' => 0, 'total' => 15],
                    $this->nonPkpLocation2->id => ['good' => 5, 'bad' => 0, 'total' => 5],
                ],
            ],
        ];

        $adjustment = $this->makeMultiLocationAdjustment($locIds, $rows);
        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertSame(22, $p->currentGood);
        $this->assertSame(30, $p->enteredGood);
        $this->assertSame(8, $p->goodDifference);

        $plan = $p->goodAllocationPlan;
        $this->assertSame('surplus', $plan['type']);

        $stepsByLocId = collect($plan['steps'])->keyBy('location_id');
        $this->assertSame(8, $stepsByLocId->get($this->nonPkpLocation2->id)['delta']);
        $this->assertSame(13, $stepsByLocId->get($this->nonPkpLocation2->id)['after_stock']);
        $this->assertSame(0, $stepsByLocId->get($this->nonPkpLocation1->id)['delta']);
        $this->assertSame(0, $stepsByLocId->get($this->pkpLocation1->id)['delta']);
    }

    public function test_multi_location_condition_reclassification_produces_independent_plans()
    {
        $product = $this->makeProduct();

        $this->makeStock($product, $this->nonPkpLocation1, [
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($product, $this->pkpLocation1, [
            'quantity' => 15,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 5,
            'broken_quantity_non_tax' => 0,
        ]);

        $locIds = [$this->nonPkpLocation1->id, $this->pkpLocation1->id];

        // Pool current: good = 10 + 10 = 20, bad = 0 + 5 = 5. Total = 25.
        // Entered: good = 17 (-3 shortage), bad = 8 (+3 surplus). Total = 25 (reclassification only).
        $rows = [
            [
                'product_id' => $product->id,
                'is_serialized' => false,
                'good_count' => 17,
                'bad_count' => 8,
                'location_baselines' => [
                    $this->nonPkpLocation1->id => ['good' => 10, 'bad' => 0, 'total' => 10],
                    $this->pkpLocation1->id => ['good' => 10, 'bad' => 5, 'total' => 15],
                ],
            ],
        ];

        $adjustment = $this->makeMultiLocationAdjustment($locIds, $rows);
        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertTrue($p->isConditionReclassificationOnly());
        $this->assertSame(-3, $p->goodDifference);
        $this->assertSame(3, $p->badDifference);

        // Good shortage plan: Non-PKP 1 has 10, PKP 1 has 10 -> Non-PKP 1 deducted by 3
        $goodSteps = collect($p->goodAllocationPlan['steps'])->keyBy('location_id');
        $this->assertSame(-3, $goodSteps->get($this->nonPkpLocation1->id)['delta']);
        $this->assertSame(0, $goodSteps->get($this->pkpLocation1->id)['delta']);

        // Bad surplus plan: Non-PKP 1 has 0 bad, PKP 1 has 5 bad -> Non-PKP 1 gets +3 bad
        $badSteps = collect($p->badAllocationPlan['steps'])->keyBy('location_id');
        $this->assertSame(3, $badSteps->get($this->nonPkpLocation1->id)['delta']);
        $this->assertSame(0, $badSteps->get($this->pkpLocation1->id)['delta']);
    }

    public function test_multi_location_detects_drift_when_stock_changes_after_counting()
    {
        $product = $this->makeProduct();

        // Baseline captured when both locations had 10 (baseline total = 20)
        // Current stock: nonPkpLocation1 increased to 15 (e.g. from a sale return or purchase)
        $this->makeStock($product, $this->nonPkpLocation1, [
            'quantity' => 15,
            'quantity_tax' => 0,
            'quantity_non_tax' => 15,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($product, $this->pkpLocation1, [
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $locIds = [$this->nonPkpLocation1->id, $this->pkpLocation1->id];

        $rows = [
            [
                'product_id' => $product->id,
                'is_serialized' => false,
                'good_count' => 20,
                'bad_count' => 0,
                'location_baselines' => [
                    $this->nonPkpLocation1->id => ['good' => 10, 'bad' => 0, 'total' => 10],
                    $this->pkpLocation1->id => ['good' => 10, 'bad' => 0, 'total' => 10],
                ],
            ],
        ];

        $adjustment = $this->makeMultiLocationAdjustment($locIds, $rows);
        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertSame(20, $p->baselineGood);
        $this->assertSame(25, $p->currentGood);
        $this->assertEquals(5.0, $p->drift);
    }

    public function test_omitted_products_are_not_treated_as_zero_count_in_reconciliation()
    {
        $productEntered = $this->makeProduct(['product_name' => 'Produk Dihitung']);
        $productOmitted = $this->makeProduct(['product_name' => 'Produk Dilewati']);

        $this->makeStock($productEntered, $this->pkpLocation1, [
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($productOmitted, $this->pkpLocation1, [
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $locIds = [$this->pkpLocation1->id];

        // Only productEntered is in draft rows
        $rows = [
            [
                'product_id' => $productEntered->id,
                'is_serialized' => false,
                'good_count' => 10,
                'bad_count' => 0,
                'location_baselines' => [
                    $this->pkpLocation1->id => ['good' => 10, 'bad' => 0, 'total' => 10],
                ],
            ],
        ];

        $adjustment = $this->makeMultiLocationAdjustment($locIds, $rows);
        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);

        // Result only contains the entered product
        $this->assertCount(1, $result->products);
        $this->assertSame($productEntered->id, $result->products[0]->productId);
    }

    public function test_cross_setting_all_location_total_includes_all_selected_setting_standard_locations()
    {
        $product = $this->makeProduct();

        // PKP setting has pkpLocation1 and pkpLocation2.
        // Non-PKP setting has nonPkpLocation1 and nonPkpLocation2.
        // If draft selects pkpLocation1 and nonPkpLocation1:
        // Both PKP and Non-PKP settings are involved.
        // The allLocationCurrentTotal must include pkpLocation1 (10), pkpLocation2 (20), nonPkpLocation1 (30), nonPkpLocation2 (40) = 100.
        $this->makeStock($product, $this->pkpLocation1, [
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($product, $this->pkpLocation2, [
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($product, $this->nonPkpLocation1, [
            'quantity' => 30,
            'quantity_tax' => 0,
            'quantity_non_tax' => 30,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($product, $this->nonPkpLocation2, [
            'quantity' => 40,
            'quantity_tax' => 0,
            'quantity_non_tax' => 40,
            'broken_quantity' => 0,
        ]);

        $locIds = [$this->pkpLocation1->id, $this->nonPkpLocation1->id];

        $rows = [
            [
                'product_id' => $product->id,
                'is_serialized' => false,
                'good_count' => 40,
                'bad_count' => 0,
                'location_baselines' => [
                    $this->pkpLocation1->id => ['good' => 10, 'bad' => 0, 'total' => 10],
                    $this->nonPkpLocation1->id => ['good' => 30, 'bad' => 0, 'total' => 30],
                ],
            ],
        ];

        $adjustment = $this->makeMultiLocationAdjustment($locIds, $rows);
        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertSame(40, $p->currentGood); // 10 + 30
        $this->assertEquals(100.0, $p->allLocationCurrentTotal); // 10 + 20 + 30 + 40
        $this->assertFalse($p->exceedsAllLocationTotal);
    }

    public function test_multi_location_reconciliation_interprets_production_sequential_baselines_correctly()
    {
        $product = $this->makeProduct();

        $this->makeStock($product, $this->pkpLocation1, [
            'quantity' => 15,
            'quantity_tax' => 15,
            'quantity_non_tax' => 0,
            'broken_quantity' => 2,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 0,
        ]);
        $this->makeStock($product, $this->nonPkpLocation1, [
            'quantity' => 25,
            'quantity_tax' => 0,
            'quantity_non_tax' => 25,
            'broken_quantity' => 1,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 1,
        ]);

        $locIds = [$this->pkpLocation1->id, $this->nonPkpLocation1->id];

        // Production structure produced by CountDraftService is a sequential list of objects
        $rows = [
            [
                'product_id' => $product->id,
                'is_serialized' => false,
                'good_count' => 40,
                'bad_count' => 3,
                'location_baselines' => [
                    [
                        'location_id' => $this->pkpLocation1->id,
                        'existing_good_total' => 15,
                        'existing_good_tax' => 15,
                        'existing_good_non_tax' => 0,
                        'existing_bad_total' => 2,
                        'existing_bad_tax' => 2,
                        'existing_bad_non_tax' => 0,
                    ],
                    [
                        'location_id' => $this->nonPkpLocation1->id,
                        'existing_good_total' => 25,
                        'existing_good_tax' => 0,
                        'existing_good_non_tax' => 25,
                        'existing_bad_total' => 1,
                        'existing_bad_tax' => 0,
                        'existing_bad_non_tax' => 1,
                    ],
                ],
            ],
        ];

        $adjustment = $this->makeMultiLocationAdjustment($locIds, $rows);
        $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment);
        $p = $result->products[0];

        $this->assertSame(40, $p->baselineGood);
        $this->assertSame(3, $p->baselineBad);
        $this->assertSame(40, $p->currentGood);
        $this->assertSame(3, $p->currentBad);
        $this->assertEquals(0.0, $p->drift);

        // Verify per-location baselines map
        $this->assertArrayHasKey($this->pkpLocation1->id, $p->locationBaselines);
        $this->assertSame(15, $p->locationBaselines[$this->pkpLocation1->id]['good']);
        $this->assertSame(2, $p->locationBaselines[$this->pkpLocation1->id]['bad']);

        $this->assertArrayHasKey($this->nonPkpLocation1->id, $p->locationBaselines);
        $this->assertSame(25, $p->locationBaselines[$this->nonPkpLocation1->id]['good']);
        $this->assertSame(1, $p->locationBaselines[$this->nonPkpLocation1->id]['bad']);
    }
}

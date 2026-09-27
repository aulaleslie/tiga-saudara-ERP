<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService;
use Modules\Consignment\Services\ConsignmentReturnEligibilityService;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Bundle-associated dispatch details that represent authoritative stock-managed movements
 * from a consignment location are eligible sold sources; bundle_id is provenance, not a blocker.
 */
class ConsignmentBundleSoldSourceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Supplier $supplier;
    protected Location $consignmentLocation;
    protected Product $product;
    protected Product $componentProduct;
    protected Product $serviceProduct;

    protected ConsignmentSoldSourceDiscoveryService $discoveryService;
    protected ConsignmentReturnEligibilityService $eligibilityService;
    protected ConsignmentBillingConfirmationLifecycleService $lifecycleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Consignment Bundle Test',
            'company_email' => 'test@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
            'document_prefix' => 'CSG',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Alpha',
            'supplier_email' => 'alpha@example.com',
            'supplier_phone' => '081111111',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor St',
        ]);

        $this->consignmentLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Consignment Rack A',
            'is_consignment' => true,
        ]);

        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'CAT-001',
            'category_name' => 'General Category',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $makeProduct = function (string $name, string $code, bool $stockManaged) use ($unit, $category) {
            return Product::create([
                'product_name' => $name,
                'product_code' => $code,
                'product_price' => 150000,
                'product_cost' => 100000,
                'product_quantity' => 0,
                'product_unit' => $unit->id,
                'unit_id' => $unit->id,
                'category_id' => $category->id,
                'stock_managed' => $stockManaged,
                'setting_id' => $this->setting->id,
            ]);
        };

        $this->product = $makeProduct('Bundle Parent Widget', 'BPW-001', true);
        $this->componentProduct = $makeProduct('Bundle Component Widget', 'BCW-001', true);
        $this->serviceProduct = $makeProduct('Bundle Service Line', 'BSL-001', false);

        $this->discoveryService = app(ConsignmentSoldSourceDiscoveryService::class);
        $this->eligibilityService = app(ConsignmentReturnEligibilityService::class);
        $this->lifecycleService = app(ConsignmentBillingConfirmationLifecycleService::class);
    }

    protected function createTestSale(string $reference, float $grandTotal = 150000): Sale
    {
        return Sale::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'General Customer',
            'reference' => $reference,
            'date' => date('Y-m-d'),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $grandTotal,
            'paid_amount' => $grandTotal,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);
    }

    /**
     * Build an approved receipt pool for the given product.
     *
     * @return ConsignmentReceivingDetail
     */
    protected function createReceiptPool(string $ref, Product $product, float $qty = 10)
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => $ref,
            'receival_number' => $ref,
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);

        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $qty,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 100000 * $qty,
            'subtotal_dpp' => 100000 * $qty,
            'total_cost' => 100000 * $qty,
            'total_dpp' => 100000 * $qty,
        ]);

        $receiving = ConsignmentReceiving::create([
            'consignment_receival_id' => $receival->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->consignmentLocation->id,
            'receiving_number' => 'RCV-' . $ref,
            'date' => date('Y-m-d'),
            'status' => ConsignmentReceiving::STATUS_APPROVED,
        ]);

        return ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $receivalLine->id,
            'product_id' => $product->id,
            'quantity_received' => $qty,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
        ]);
    }

    // ---------------------------------------------------------------------
    // Task 2.1 / 2.2: bundle eligibility and evidence-based blockers
    // ---------------------------------------------------------------------

    /** @test */
    public function it_discovers_stock_managed_bundle_parent_and_component_dispatch_details()
    {
        $sale = $this->createTestSale('SL-BUNDLE-01');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);

        $parentDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 3,
            'bundle_id' => 77,
            'is_inventory_managed' => true,
        ]);

        $componentDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 6,
            'bundle_id' => 77,
            'is_inventory_managed' => true,
        ]);

        $result = $this->discoveryService->discoverForSetting($this->setting->id);

        $this->assertEquals(2, $result['created']);
        $this->assertEquals(0, $result['blocked']);

        $parentSource = ConsignmentSoldSource::where('dispatch_detail_id', $parentDetail->id)->firstOrFail();
        $componentSource = ConsignmentSoldSource::where('dispatch_detail_id', $componentDetail->id)->firstOrFail();

        $this->assertFalse((bool) $parentSource->has_reconstruction_blocker);
        $this->assertFalse((bool) $componentSource->has_reconstruction_blocker);

        // bundle_id is retained as provenance in the immutable snapshot
        $this->assertEquals(77, $parentSource->source_snapshot['bundle_id']);
        $this->assertEquals(77, $componentSource->source_snapshot['bundle_id']);
    }

    /** @test */
    public function it_keeps_explicit_non_inventory_bundle_content_blocked()
    {
        $sale = $this->createTestSale('SL-BUNDLE-SERVICE');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);

        $serviceDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->serviceProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 2,
            'bundle_id' => 88,
            'is_inventory_managed' => false,
        ]);

        $this->discoveryService->discoverForSetting($this->setting->id);

        $source = ConsignmentSoldSource::where('dispatch_detail_id', $serviceDetail->id)->firstOrFail();
        $this->assertTrue((bool) $source->has_reconstruction_blocker);
        $this->assertStringContainsStringIgnoringCase('Non-inventory', $source->blocker_reason);
    }

    /** @test */
    public function it_classifies_historical_nullable_rows_only_when_evidence_is_unambiguous()
    {
        $sale = $this->createTestSale('SL-BUNDLE-LEGACY');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);

        $legacySupported = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 4,
            'bundle_id' => 99,
        ]);
        $legacySupported->forceFill(['is_inventory_managed' => null])->saveQuietly();

        $legacyAmbiguous = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->serviceProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 2,
            'bundle_id' => 99,
        ]);
        $legacyAmbiguous->forceFill(['is_inventory_managed' => null])->saveQuietly();

        $this->discoveryService->discoverForSetting($this->setting->id);

        $supported = ConsignmentSoldSource::where('dispatch_detail_id', $legacySupported->id)->firstOrFail();
        $this->assertFalse((bool) $supported->has_reconstruction_blocker);
        $this->assertEquals('HISTORICAL_COMPATIBILITY', $supported->source_snapshot['inventory_classification']);
        $this->assertTrue((bool) $supported->source_snapshot['used_historical_compatibility_rule']);
        $this->assertNull($supported->source_snapshot['is_inventory_managed']);

        $ambiguous = ConsignmentSoldSource::where('dispatch_detail_id', $legacyAmbiguous->id)->firstOrFail();
        $this->assertTrue((bool) $ambiguous->has_reconstruction_blocker);
        $this->assertStringContainsStringIgnoringCase('not stock-managed', $ambiguous->blocker_reason);
    }

    /** @test */
    public function it_blocks_bundle_dispatch_details_with_missing_product_lineage()
    {
        $sale = $this->createTestSale('SL-BUNDLE-NOPROD');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);

        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 2,
            'bundle_id' => 55,
            'is_inventory_managed' => true,
        ]);

        // Unresolvable product lineage blocks even an explicitly inventory-managed bundle row.
        $classification = $this->discoveryService->classifyInventoryEvidence(
            $detail,
            null,
            $this->consignmentLocation
        );

        $this->assertFalse($classification['is_inventory_managed']);
        $this->assertEquals('MISSING_PRODUCT', $classification['classification']);
        $this->assertStringContainsStringIgnoringCase('Missing product', $classification['blocker_reason']);

        // A historical nullable row with no valid source location is blocked, never guessed.
        $detail->forceFill(['is_inventory_managed' => null])->saveQuietly();
        $noLocation = $this->discoveryService->classifyInventoryEvidence($detail, $this->product, null);
        $this->assertFalse($noLocation['is_inventory_managed']);
        $this->assertEquals('INVALID_LOCATION', $noLocation['classification']);
    }

    /**
     * @test
     *
     * Consignment physical receiving locks Product before ProductSerialNumber. Billing
     * lifecycle must use the same order, or approval and receiving can deadlock.
     */
    public function lifecycle_locks_products_before_serials_in_deterministic_id_order()
    {
        $crd = $this->createReceiptPool('CR-BUNDLE-LOCKORDER', $this->product);

        $sale = $this->createTestSale('SL-BUNDLE-LOCKORDER');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);

        // Two products, deliberately discovered in descending id order.
        $detailB = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 2,
            'is_inventory_managed' => true,
        ]);
        $detailA = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 2,
            'is_inventory_managed' => true,
        ]);

        $crdB = $this->createReceiptPool('CR-BUNDLE-LOCKORDER-B', $this->componentProduct);

        $this->discoveryService->discoverForSetting($this->setting->id);
        $sourceA = ConsignmentSoldSource::where('dispatch_detail_id', $detailA->id)->firstOrFail();
        $sourceB = ConsignmentSoldSource::where('dispatch_detail_id', $detailB->id)->firstOrFail();

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $sourceB->id,
                    'allocated_base_quantity' => 2,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crdB->id, 'allocated_base_quantity' => 2]],
                ],
                [
                    'consignment_sold_source_id' => $sourceA->id,
                    'allocated_base_quantity' => 2,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 2]],
                ],
            ]
        );

        // SQLite's grammar compiles lockForUpdate() away, so match the acquisition query
        // shape (whereIn ... order by id) that lockForUpdate() is attached to instead.
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            if (preg_match('/^select \* from "([a-z_]+)"/i', $query->sql, $m)) {
                $queries[] = ['table' => $m[1], 'sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        $this->lifecycleService->submitConfirmation($draft, $this->user->id);

        // Every product read in the transaction must be the single ordered acquisition:
        // a per-sold-source lookup would add unordered `where id = ?` queries here.
        $allProductQueries = array_values(array_filter($queries, fn ($q) => $q['table'] === 'products'));
        $productQueries = array_values(array_filter(
            $allProductQueries,
            fn ($q) => str_contains($q['sql'], 'order by "id" asc')
        ));

        $this->assertCount(
            1,
            $allProductQueries,
            'Products must be acquired once as an ordered set, not one-by-one during revalidation.'
        );
        $this->assertCount(1, $productQueries, 'The single product acquisition must be ordered by id.');

        // Both products are covered by that single ordered acquisition.
        $this->assertEqualsCanonicalizing(
            [$this->product->id, $this->componentProduct->id],
            array_map('intval', $productQueries[0]['bindings'])
        );

        $tables = array_column($queries, 'table');
        $productIndex = array_search('products', $tables, true);
        $serialIndex = array_search('product_serial_numbers', $tables, true);

        $this->assertNotFalse($productIndex, 'Products must be read under the lifecycle transaction.');

        if ($serialIndex !== false) {
            $this->assertLessThan(
                $serialIndex,
                $productIndex,
                'Products must be locked before ProductSerialNumber rows, matching receiving.'
            );
        }
    }

    /**
     * @test
     *
     * Locked rows must never be reachable from a later transaction. A rolled-back
     * submission followed by another lifecycle call on the SAME service instance must
     * re-acquire its own locks, not consume authority left over from the failed one.
     */
    public function locked_product_authority_does_not_leak_across_transactions()
    {
        $crd = $this->createReceiptPool('CR-BUNDLE-LEAK', $this->product);

        $sale = $this->createTestSale('SL-BUNDLE-LEAK');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'bundle_id' => 141,
        ]);
        $detail->forceFill(['is_inventory_managed' => null])->saveQuietly();

        $this->discoveryService->discoverForSetting($this->setting->id);
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();
        $this->assertEquals('HISTORICAL_COMPATIBILITY', $source->source_snapshot['inventory_classification']);

        $makeDraft = function (string $note) use ($source, $crd) {
            return $this->lifecycleService->createDraft(
                $this->setting->id,
                $this->supplier->id,
                date('Y-m-d'),
                [[
                    'consignment_sold_source_id' => $source->id,
                    'allocated_base_quantity' => 5,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 5]],
                ]],
                $note,
                $this->user->id
            );
        };

        // 1. A submission that acquires the locks and then fails, rolling back.
        $failing = $makeDraft('leak-probe-1');
        $this->product->forceFill(['stock_managed' => false])->saveQuietly();

        try {
            $this->lifecycleService->submitConfirmation($failing, $this->user->id);
            $this->fail('Submission should have failed on the changed classification.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('inventory classification changed since capture', $e->getMessage());
        }

        $this->assertSame(
            ConsignmentBillingConfirmation::STATUS_DRAFT,
            $failing->fresh()->status,
            'The failed submission must have rolled back.'
        );

        // 2. The product is restored, so a fresh submission on the SAME service instance
        //    must succeed by re-reading authority rather than reusing the stale row that
        //    the rolled-back transaction had locked.
        $this->product->forceFill(['stock_managed' => true])->saveQuietly();

        $submitted = $this->lifecycleService->submitConfirmation($makeDraft('leak-probe-2'), $this->user->id);
        $this->assertTrue($submitted->isWaitingApproval());
    }

    /** @test */
    public function approval_revalidation_blocks_when_product_classification_changed_after_submission()
    {
        $crd = $this->createReceiptPool('CR-BUNDLE-APPROVE', $this->product);

        $sale = $this->createTestSale('SL-BUNDLE-APPROVE');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'bundle_id' => 131,
        ]);
        $detail->forceFill(['is_inventory_managed' => null])->saveQuietly();

        $this->discoveryService->discoverForSetting($this->setting->id);
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [[
                'consignment_sold_source_id' => $source->id,
                'allocated_base_quantity' => 5,
                'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 5]],
            ]]
        );

        $submitted = $this->lifecycleService->submitConfirmation($draft, $this->user->id);
        $this->assertTrue($submitted->isWaitingApproval());

        // Drift after a clean submission must still block at approval.
        $this->product->forceFill(['stock_managed' => false])->saveQuietly();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('inventory classification changed since capture');
        $this->lifecycleService->approveConfirmation($submitted, $this->user->id);
    }

    /** @test */
    public function preview_and_persistence_classify_the_same_bundle_rows_identically()
    {
        $sale = $this->createTestSale('SL-BUNDLE-PARITY');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 3,
            'bundle_id' => 42,
            'is_inventory_managed' => true,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->serviceProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 1,
            'bundle_id' => 42,
            'is_inventory_managed' => false,
        ]);

        $preview = $this->discoveryService->discoverForSetting($this->setting->id, true);
        $this->assertEquals(0, ConsignmentSoldSource::count(), 'Preview must not persist sources.');

        $persisted = $this->discoveryService->discoverForSetting($this->setting->id);

        $this->assertEquals($preview['created'], $persisted['created']);
        $this->assertEquals($preview['blocked'], $persisted['blocked']);

        $previewBlockers = collect($preview['details'])->pluck('has_blocker', 'dispatch_detail_id')->all();
        $persistedBlockers = collect($persisted['details'])->pluck('has_blocker', 'dispatch_detail_id')->all();
        $this->assertEquals($previewBlockers, $persistedBlockers);
    }

    // ---------------------------------------------------------------------
    // Task 2.3 / 2.5: versioned evidence and revalidation
    // ---------------------------------------------------------------------

    /** @test */
    public function it_persists_the_current_snapshot_version_on_newly_discovered_sources()
    {
        $sale = $this->createTestSale('SL-BUNDLE-VER');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);

        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 2,
            'bundle_id' => 11,
            'is_inventory_managed' => true,
        ]);

        $this->discoveryService->discoverForSetting($this->setting->id);
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();

        $this->assertEquals(
            ConsignmentSoldSourceDiscoveryService::SNAPSHOT_VERSION,
            $source->source_snapshot['snapshot_version']
        );
        $this->assertEquals(11, $source->source_snapshot['bundle_id']);
        $this->assertTrue((bool) $source->source_snapshot['is_inventory_managed']);
        $this->assertEquals('EXPLICIT_INVENTORY', $source->source_snapshot['inventory_classification']);

        // The stored hash reproduces from the version-2 canonical payload.
        $this->assertSame(0, strcasecmp($source->source_hash, $this->lifecycleService->computeCanonicalLiveHash($source)));
    }

    /** @test */
    public function live_bundle_or_classification_mutation_blocks_version_two_submission()
    {
        $crd = $this->createReceiptPool('CR-BUNDLE-MUT', $this->product);

        $sale = $this->createTestSale('SL-BUNDLE-MUT');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'bundle_id' => 21,
            'is_inventory_managed' => true,
        ]);

        $this->discoveryService->discoverForSetting($this->setting->id);
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [[
                'consignment_sold_source_id' => $source->id,
                'allocated_base_quantity' => 5,
                'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 5]],
            ]]
        );

        // Mutating bundle identity alone must invalidate the version-2 canonical hash.
        $detail->forceFill(['bundle_id' => 22])->saveQuietly();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('hash mismatch');
        $this->lifecycleService->submitConfirmation($draft, $this->user->id);
    }

    /** @test */
    public function historical_unversioned_sources_still_submit_and_approve_without_false_mismatch()
    {
        $crd = $this->createReceiptPool('CR-BUNDLE-V1', $this->product);

        $sale = $this->createTestSale('SL-BUNDLE-V1');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'is_inventory_managed' => true,
        ]);

        $dispatchedAt = $dispatch->approved_at ?? $dispatch->created_at ?? $detail->created_at;
        $dispatchedAtStr = \Carbon\Carbon::parse($dispatchedAt)->format('Y-m-d H:i:s');

        // Reproduce a source created under the historical unversioned hash contract.
        $legacyPayload = ConsignmentSoldSourceDiscoveryService::buildCanonicalPayload(1, [
            'dispatch_detail_id' => $detail->id,
            'setting_id' => $this->setting->id,
            'location_id' => $detail->location_id,
            'product_id' => $detail->product_id,
            'original_base_quantity' => '5',
            'dispatched_at' => $dispatchedAtStr,
            'serials' => [],
            'dispatch_status' => Dispatch::STATUS_APPROVED,
            'is_consignment_location' => true,
            'pos_checkout_id' => null,
            'tax_id' => null,
        ]);
        $legacyHash = hash('sha256', json_encode($legacyPayload));

        $source = ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $detail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'original_base_quantity' => 5,
            'dispatched_at' => $dispatchedAt,
            'serial_identities' => [],
            'source_hash' => $legacyHash,
            // Historical snapshot: no snapshot_version key at all.
            'source_snapshot' => [
                'dispatch_detail_id' => $detail->id,
                'source_hash' => $legacyHash,
            ],
            'has_reconstruction_blocker' => false,
        ]);

        $this->assertSame(0, strcasecmp($legacyHash, $this->lifecycleService->computeCanonicalLiveHash($source)));

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [[
                'consignment_sold_source_id' => $source->id,
                'allocated_base_quantity' => 5,
                'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 5]],
            ]]
        );

        $submitted = $this->lifecycleService->submitConfirmation($draft, $this->user->id);
        $approved = $this->lifecycleService->approveConfirmation($submitted, $this->user->id);
        $this->assertTrue($approved->isApproved());
    }

    /**
     * @test
     *
     * @dataProvider unsupportedSnapshotVersionProvider
     */
    public function it_blocks_lifecycle_operations_for_unknown_snapshot_versions($storedVersion)
    {
        $sale = $this->createTestSale('SL-BUNDLE-UNKNOWN');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'is_inventory_managed' => true,
        ]);

        $source = ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $detail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'original_base_quantity' => 5,
            'dispatched_at' => now(),
            'serial_identities' => [],
            'source_hash' => str_repeat('a', 64),
            'source_snapshot' => ['snapshot_version' => $storedVersion],
            'has_reconstruction_blocker' => false,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('unsupported snapshot/hash version');
        $this->lifecycleService->computeCanonicalLiveHash($source);
    }

    /**
     * Corrupted or partially repaired versions must never fall back to a payload shape.
     */
    public static function unsupportedSnapshotVersionProvider(): array
    {
        return [
            'future version' => [9999],
            'zero' => [0],
            'negative' => [-1],
            'non-numeric string' => ['invalid'],
            'fractional' => [2.5],
            'boolean' => [true],
        ];
    }

    /** @test */
    public function it_resolves_only_exact_supported_snapshot_versions()
    {
        // Only an absent key means the historical unversioned contract.
        $this->assertSame(1, ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion([]));
        $this->assertSame(1, ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion(null));
        $this->assertSame(1, ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion(['snapshot_version' => 1]));
        $this->assertSame(2, ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion(['snapshot_version' => 2]));
        $this->assertSame(2, ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion(['snapshot_version' => '2']));

        // An explicitly stored null is corruption, not an absent key; "01" is not canonical.
        foreach ([null, 0, -1, 3, '01', ' 2', 'invalid', 2.5, true, []] as $bad) {
            $this->assertNull(
                ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion(['snapshot_version' => $bad]),
                'Unsupported version must not resolve: ' . var_export($bad, true)
            );
        }
    }

    /**
     * @test
     *
     * @dataProvider corruptedVersionTwoEvidenceProvider
     */
    public function it_blocks_version_two_sources_with_corrupted_classification_evidence(array $snapshotOverrides, string $expectedMessage)
    {
        $sale = $this->createTestSale('SL-BUNDLE-CORRUPT');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'bundle_id' => 111,
            'is_inventory_managed' => true,
        ]);

        $this->discoveryService->discoverForSetting($this->setting->id);
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();

        // Corrupt the stored evidence without touching the version marker.
        $snapshot = $source->source_snapshot;
        foreach ($snapshotOverrides as $key => $value) {
            if ($value === '__UNSET__') {
                unset($snapshot[$key]);
            } else {
                $snapshot[$key] = $value;
            }
        }
        $source->forceFill(['source_snapshot' => $snapshot])->saveQuietly();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($expectedMessage);
        $this->lifecycleService->computeCanonicalLiveHash($source->fresh());
    }

    /**
     * Version-2 evidence is mandatory: missing or invalid keys must never fail open by
     * skipping classification revalidation.
     */
    public static function corruptedVersionTwoEvidenceProvider(): array
    {
        return [
            'classification key removed' => [
                ['inventory_classification' => '__UNSET__'],
                'inventory_classification is missing',
            ],
            'classification set to null' => [
                ['inventory_classification' => null],
                'unknown inventory_classification',
            ],
            'unknown classification value' => [
                ['inventory_classification' => 'TOTALLY_MADE_UP'],
                'unknown inventory_classification',
            ],
            'compatibility flag removed' => [
                ['used_historical_compatibility_rule' => '__UNSET__'],
                'used_historical_compatibility_rule must be a boolean',
            ],
            'compatibility flag not boolean' => [
                ['used_historical_compatibility_rule' => 'yes'],
                'used_historical_compatibility_rule must be a boolean',
            ],
            'compatibility flag null' => [
                ['used_historical_compatibility_rule' => null],
                'used_historical_compatibility_rule must be a boolean',
            ],
        ];
    }

    /** @test */
    public function historical_compatibility_source_is_blocked_when_its_product_stops_being_stock_managed()
    {
        $crd = $this->createReceiptPool('CR-BUNDLE-DRIFT', $this->product);

        $sale = $this->createTestSale('SL-BUNDLE-DRIFT');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'bundle_id' => 91,
        ]);
        $detail->forceFill(['is_inventory_managed' => null])->saveQuietly();

        $this->discoveryService->discoverForSetting($this->setting->id);
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();
        $this->assertEquals('HISTORICAL_COMPATIBILITY', $source->source_snapshot['inventory_classification']);

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [[
                'consignment_sold_source_id' => $source->id,
                'allocated_base_quantity' => 5,
                'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 5]],
            ]]
        );

        // The persisted nullable flag is unchanged, so only re-running the classifier can
        // detect that this row would no longer be discovered as eligible.
        $this->product->forceFill(['stock_managed' => false])->saveQuietly();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('inventory classification changed since capture');
        $this->lifecycleService->submitConfirmation($draft, $this->user->id);
    }

    /**
     * @test
     *
     * Classification is shared, but the reporting outcome intentionally differs: preview
     * exposes the blocker, while persistence excludes the row rather than writing an
     * invalid sold source.
     */
    public function deactivated_location_is_blocked_in_preview_and_excluded_by_persistence()
    {
        $sale = $this->createTestSale('SL-BUNDLE-INACTIVE');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);

        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 3,
            'bundle_id' => 101,
            'is_inventory_managed' => true,
        ]);

        // An explicitly inventory-managed row whose location is no longer active must be
        // blocked in preview, matching what locked persistence does during revalidation.
        $this->consignmentLocation->forceFill(['is_active' => false])->saveQuietly();

        // Both paths classify the same evidence identically.
        $this->assertEquals(
            'INVALID_LOCATION',
            $this->discoveryService->classifyInventoryEvidence(
                $detail->fresh(),
                $this->product,
                null
            )['classification']
        );

        // Preview must not advertise the row as eligible: it reports it as blocked.
        $preview = $this->discoveryService->discoverForSetting($this->setting->id, true);
        $this->assertEquals(1, $preview['blocked'], 'Preview must block rows locked persistence will not capture.');
        $this->assertTrue($preview['details'][0]['has_blocker']);

        // Locked persistence excludes it outright rather than capturing an eligible source,
        // so neither path can produce a billable sold source for an inactive location.
        $persisted = $this->discoveryService->discoverForSetting($this->setting->id);
        $this->assertEquals(0, $persisted['created']);
        $this->assertEquals(1, $persisted['excluded']);
        $this->assertEquals(0, ConsignmentSoldSource::count());
    }

    /** @test */
    public function explicit_inventory_rows_require_a_valid_active_consignment_location()
    {
        $sale = $this->createTestSale('SL-BUNDLE-NOLOC');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 3,
            'bundle_id' => 102,
            'is_inventory_managed' => true,
        ]);

        $classification = $this->discoveryService->classifyInventoryEvidence($detail, $this->product, null);

        $this->assertFalse($classification['is_inventory_managed']);
        $this->assertEquals('INVALID_LOCATION', $classification['classification']);
        $this->assertStringContainsStringIgnoringCase('consignment source location', $classification['blocker_reason']);
    }

    // ---------------------------------------------------------------------
    // Task 3.1 / 3.2 / 3.3: quantity, lineage, and return safety
    // ---------------------------------------------------------------------

    /** @test */
    public function product_classification_changing_between_selection_and_persistence_is_seen_under_lock()
    {
        $sale = $this->createTestSale('SL-BUNDLE-RACE');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'bundle_id' => 121,
        ]);
        $detail->forceFill(['is_inventory_managed' => null])->saveQuietly();

        // Selection sees a stock-managed product; persistence must re-read it under lock.
        $this->discoveryService->beforePersistHook = function () {
            $this->product->forceFill(['stock_managed' => false])->saveQuietly();
        };

        $this->discoveryService->discoverForSetting($this->setting->id);
        $this->discoveryService->beforePersistHook = null;

        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();
        $this->assertTrue(
            (bool) $source->has_reconstruction_blocker,
            'Persistence must classify from the locked Product, not the stale eager-loaded relation.'
        );
        $this->assertEquals('AMBIGUOUS_HISTORICAL', $source->source_snapshot['inventory_classification']);
    }

    /** @test */
    public function bundle_sold_source_quantities_equal_their_authoritative_dispatch_details()
    {
        $sale = $this->createTestSale('SL-BUNDLE-QTY');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);

        $parentDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 2,
            'bundle_id' => 31,
            'is_inventory_managed' => true,
        ]);

        // Two physical chunks for the same component within one bundle.
        $chunkA = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 3,
            'bundle_id' => 31,
            'is_inventory_managed' => true,
        ]);
        $chunkB = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 1,
            'bundle_id' => 31,
            'is_inventory_managed' => true,
        ]);

        $this->discoveryService->discoverForSetting($this->setting->id);

        // Exactly one source per physical DispatchDetail, nothing synthesized from bundle rows.
        $this->assertEquals(3, ConsignmentSoldSource::count());

        $this->assertEquals(2.0, (float) ConsignmentSoldSource::where('dispatch_detail_id', $parentDetail->id)->firstOrFail()->original_base_quantity);
        $this->assertEquals(3.0, (float) ConsignmentSoldSource::where('dispatch_detail_id', $chunkA->id)->firstOrFail()->original_base_quantity);
        $this->assertEquals(1.0, (float) ConsignmentSoldSource::where('dispatch_detail_id', $chunkB->id)->firstOrFail()->original_base_quantity);

        // Rediscovery stays idempotent for bundle rows.
        $second = $this->discoveryService->discoverForSetting($this->setting->id);
        $this->assertEquals(0, $second['created']);
        $this->assertEquals(3, $second['existing']);
    }

    /** @test */
    public function serialized_bundle_component_retains_exact_serial_and_receipt_provenance()
    {
        $crd = $this->createReceiptPool('CR-BUNDLE-SER', $this->componentProduct, 5);

        $psn = ProductSerialNumber::create([
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SN-BUNDLE-001',
            'consignment_receiving_detail_id' => $crd->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $sale = $this->createTestSale('SL-BUNDLE-SER');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 1,
            'bundle_id' => 61,
            'serial_numbers' => json_encode(['SN-BUNDLE-001']),
            'is_inventory_managed' => true,
        ]);

        $this->discoveryService->discoverForSetting($this->setting->id);
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();

        // Provenance comes from the physical DispatchDetail, not from bundle membership.
        $this->assertEquals($this->componentProduct->id, $source->product_id);
        $this->assertEquals($this->consignmentLocation->id, $source->location_id);
        $this->assertEquals(['SN-BUNDLE-001'], $source->serial_identities);
        $this->assertDatabaseHas('consignment_sold_source_serials', [
            'consignment_sold_source_id' => $source->id,
            'product_serial_number_id' => $psn->id,
        ]);

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [[
                'consignment_sold_source_id' => $source->id,
                'allocated_base_quantity' => 1,
                'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 1]],
                'serialized_allocations' => [['product_serial_number_id' => $psn->id, 'consignment_receiving_detail_id' => $crd->id]],
            ]],
            'Serialized bundle component',
            $this->user->id
        );

        $submitted = $this->lifecycleService->submitConfirmation($draft, $this->user->id);
        $approved = $this->lifecycleService->approveConfirmation($submitted, $this->user->id);
        $this->assertTrue($approved->isApproved());
    }

    /**
     * Build an approved serialized bundle dispatch detail carrying the given serial texts.
     */
    protected function createSerializedBundleDetail(string $ref, array $serialTexts, int $qty = 1): DispatchDetail
    {
        $sale = $this->createTestSale($ref);
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);

        return DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => $qty,
            'bundle_id' => 201,
            'serial_numbers' => json_encode($serialTexts),
            'is_inventory_managed' => true,
        ]);
    }

    /** @test */
    public function serialized_bundle_with_missing_product_serial_record_is_blocked_without_partial_linkage()
    {
        $crd = $this->createReceiptPool('CR-SER-MISSING', $this->componentProduct, 5);

        $present = ProductSerialNumber::create([
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SN-PRESENT-1',
            'consignment_receiving_detail_id' => $crd->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        // 'SN-GONE-2' has no ProductSerialNumber row at all.
        $detail = $this->createSerializedBundleDetail('SL-SER-MISSING', ['SN-PRESENT-1', 'SN-GONE-2'], 2);

        $this->discoveryService->discoverForSetting($this->setting->id);

        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();
        $this->assertTrue((bool) $source->has_reconstruction_blocker);
        $this->assertStringContainsStringIgnoringCase('SN-GONE-2', $source->blocker_reason);

        // No partial provenance: the resolvable serial must NOT be linked on its own.
        $this->assertDatabaseMissing('consignment_sold_source_serials', [
            'consignment_sold_source_id' => $source->id,
        ]);
        $this->assertDatabaseMissing('consignment_sold_source_serials', [
            'product_serial_number_id' => $present->id,
        ]);
    }

    /** @test */
    public function serialized_bundle_with_foreign_product_serial_is_blocked_without_partial_linkage()
    {
        $crd = $this->createReceiptPool('CR-SER-FOREIGN', $this->componentProduct, 5);

        $own = ProductSerialNumber::create([
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SN-OWN-1',
            'consignment_receiving_detail_id' => $crd->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        // This serial exists, but belongs to a different product.
        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SN-FOREIGN-9',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $detail = $this->createSerializedBundleDetail('SL-SER-FOREIGN', ['SN-OWN-1', 'SN-FOREIGN-9'], 2);

        $this->discoveryService->discoverForSetting($this->setting->id);

        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();
        $this->assertTrue((bool) $source->has_reconstruction_blocker);
        $this->assertStringContainsStringIgnoringCase('no product serial record', $source->blocker_reason);
        $this->assertStringContainsStringIgnoringCase('SN-FOREIGN-9', $source->blocker_reason);

        $this->assertDatabaseMissing('consignment_sold_source_serials', [
            'consignment_sold_source_id' => $source->id,
        ]);
        $this->assertDatabaseMissing('consignment_sold_source_serials', [
            'product_serial_number_id' => $own->id,
        ]);
    }

    /** @test */
    public function duplicate_serial_text_in_dispatch_evidence_is_blocked()
    {
        $crd = $this->createReceiptPool('CR-SER-DUP', $this->componentProduct, 5);

        ProductSerialNumber::create([
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SN-DUP-1',
            'consignment_receiving_detail_id' => $crd->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        // One physical item cannot be sold twice on a single movement.
        $detail = $this->createSerializedBundleDetail('SL-SER-DUP', ['SN-DUP-1', 'SN-DUP-1'], 2);

        $this->discoveryService->discoverForSetting($this->setting->id);

        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();
        $this->assertTrue((bool) $source->has_reconstruction_blocker);
        $this->assertStringContainsStringIgnoringCase('Duplicate serial', $source->blocker_reason);
        $this->assertStringContainsStringIgnoringCase('SN-DUP-1', $source->blocker_reason);

        $this->assertDatabaseMissing('consignment_sold_source_serials', [
            'consignment_sold_source_id' => $source->id,
        ]);
    }

    /** @test */
    public function complete_serialized_bundle_provenance_links_every_serial_exactly_once()
    {
        $crd = $this->createReceiptPool('CR-SER-COMPLETE', $this->componentProduct, 5);

        $serials = collect(['SN-OK-1', 'SN-OK-2', 'SN-OK-3'])->map(fn ($text) => ProductSerialNumber::create([
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => $text,
            'consignment_receiving_detail_id' => $crd->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]));

        $detail = $this->createSerializedBundleDetail('SL-SER-COMPLETE', ['SN-OK-2', 'SN-OK-1', 'SN-OK-3'], 3);

        $this->discoveryService->discoverForSetting($this->setting->id);

        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();
        $this->assertFalse((bool) $source->has_reconstruction_blocker);

        // Identities are canonically sorted, and each links exactly once.
        $this->assertEquals(['SN-OK-1', 'SN-OK-2', 'SN-OK-3'], $source->serial_identities);
        $this->assertSame(3, \Modules\Consignment\Entities\ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $source->id)->count());

        foreach ($serials as $psn) {
            $this->assertSame(
                1,
                \Modules\Consignment\Entities\ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $source->id)
                    ->where('product_serial_number_id', $psn->id)
                    ->count()
            );
        }
    }

    /** @test */
    public function serial_provenance_drift_between_selection_and_persistence_is_seen_under_lock()
    {
        $crd = $this->createReceiptPool('CR-SER-DRIFT', $this->componentProduct, 5);

        $psn = ProductSerialNumber::create([
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SN-DRIFT-1',
            'consignment_receiving_detail_id' => $crd->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $detail = $this->createSerializedBundleDetail('SL-SER-DRIFT', ['SN-DRIFT-1'], 1);

        // Selection sees valid provenance; a concurrent correction reassigns the serial
        // to another product before persistence locks it.
        $this->discoveryService->beforePersistHook = function () use ($psn) {
            $psn->forceFill(['product_id' => $this->product->id])->saveQuietly();
        };

        $this->discoveryService->discoverForSetting($this->setting->id);
        $this->discoveryService->beforePersistHook = null;

        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();
        $this->assertTrue(
            (bool) $source->has_reconstruction_blocker,
            'Persistence must re-resolve serial authority under lock, not trust selection-time state.'
        );
        $this->assertStringContainsStringIgnoringCase('no product serial record', $source->blocker_reason);
        $this->assertDatabaseMissing('consignment_sold_source_serials', [
            'consignment_sold_source_id' => $source->id,
        ]);
    }

    /**
     * @test
     *
     * Serials are identified by the composite (product_id, serial_number), so the same
     * serial text under a different product is unrelated authority, not a conflict.
     */
    public function identical_serial_text_under_another_product_does_not_block_this_dispatch()
    {
        $crd = $this->createReceiptPool('CR-SER-SHARED', $this->componentProduct, 5);

        $mine = ProductSerialNumber::create([
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SERIAL-001',
            'consignment_receiving_detail_id' => $crd->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        // A different product legitimately carries the same serial text.
        $theirs = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SERIAL-001',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        $this->assertNotSame($mine->id, $theirs->id);

        $detail = $this->createSerializedBundleDetail('SL-SER-SHARED', ['SERIAL-001'], 1);

        $this->discoveryService->discoverForSetting($this->setting->id);

        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();
        $this->assertFalse(
            (bool) $source->has_reconstruction_blocker,
            'An identical serial text owned by another product must not block this dispatch.'
        );

        // Provenance resolves to THIS product's row only.
        $this->assertDatabaseHas('consignment_sold_source_serials', [
            'consignment_sold_source_id' => $source->id,
            'product_serial_number_id' => $mine->id,
        ]);
        $this->assertDatabaseMissing('consignment_sold_source_serials', [
            'product_serial_number_id' => $theirs->id,
        ]);
        $this->assertSame(
            1,
            \Modules\Consignment\Entities\ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $source->id)->count()
        );
    }

    /** @test */
    public function executed_bundle_returns_reduce_eligibility_once_and_pending_returns_do_not()
    {
        $sale = $this->createTestSale('SL-BUNDLE-RET');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 10,
            'bundle_id' => 71,
            'is_inventory_managed' => true,
        ]);

        $this->discoveryService->discoverForSetting($this->setting->id);
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->firstOrFail();
        $this->assertEquals(10.0, (float) $source->original_base_quantity);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Bundle Customer',
            'customer_email' => 'bundle@example.com',
            'customer_phone' => '0812345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Street 1',
        ]);

        $makeReturn = function (string $reference, string $status) use ($sale, $customer) {
            return \Modules\SalesReturn\Entities\SaleReturn::create([
                'setting_id' => $this->setting->id,
                'sale_id' => $sale->id,
                'date' => date('Y-m-d'),
                'reference' => $reference,
                'customer_id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'tax_percentage' => 0,
                'tax_amount' => 0,
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'total_amount' => 20000,
                'paid_amount' => 20000,
                'due_amount' => 0,
                'status' => $status,
                'payment_status' => 'Paid',
                'payment_method' => 'Cash',
            ]);
        };

        $executed = $makeReturn('SR-BUNDLE-DONE', 'Completed');
        \Modules\SalesReturn\Entities\SaleReturnDetail::create([
            'sale_return_id' => $executed->id,
            'dispatch_detail_id' => $detail->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 3,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 30000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $pending = $makeReturn('SR-BUNDLE-PENDING', 'Pending');
        \Modules\SalesReturn\Entities\SaleReturnDetail::create([
            'sale_return_id' => $pending->id,
            'dispatch_detail_id' => $detail->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 4,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 40000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Only the executed return reduces eligibility, and it reduces it exactly once.
        $eligibility = $this->eligibilityService->calculateSoldEligibility($source);
        $this->assertEquals(7.0, (float) $eligibility['remaining_quantity']);

        // Rediscovery must not rewrite the immutable original quantity.
        $this->discoveryService->discoverForSetting($this->setting->id);
        $source->refresh();
        $this->assertEquals(10.0, (float) $source->original_base_quantity);
    }
}

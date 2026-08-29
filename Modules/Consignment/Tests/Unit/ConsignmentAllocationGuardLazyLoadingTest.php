<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSerializedAllocation;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService;
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
 * Consignment Phase 2 immutability guards run inside model events, where the caller
 * controls which relations happen to be loaded.
 *
 * Laravel arms its lazy-loading guard per instance, and only when a query hydrates more
 * than one row (Builder::hydrate). A confirmation with two lines therefore triggers a
 * LazyLoadingViolationException inside a guard that dereferences an unloaded relation,
 * while a single-line confirmation silently succeeds. These tests reproduce the
 * multi-row shape so the guards stay independent of eager loading.
 */
class ConsignmentAllocationGuardLazyLoadingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Supplier $supplier;
    protected Location $consignmentLocation;
    protected Product $product;
    protected Product $componentProduct;

    protected ConsignmentSoldSourceDiscoveryService $discoveryService;
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
            'company_name' => 'Consignment Guard Test',
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

        $this->discoveryService = app(ConsignmentSoldSourceDiscoveryService::class);
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

    /**
     * Build a WAITING-eligible draft with two serialized lines, mirroring the real
     * confirmation shape that exposed the guard's lazy-loading dependency.
     *
     * @return array{0: \Modules\Consignment\Entities\ConsignmentBillingConfirmation, 1: array}
     */
    protected function createTwoLineSerializedDraft(string $ref, string $note): array
    {
        $crdA = $this->createReceiptPool("CR-{$ref}-A", $this->componentProduct, 5);
        $crdB = $this->createReceiptPool("CR-{$ref}-B", $this->product, 5);

        $psnA = ProductSerialNumber::create([
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => "SN-{$ref}-A",
            'consignment_receiving_detail_id' => $crdA->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);
        $psnB = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => "SN-{$ref}-B",
            'consignment_receiving_detail_id' => $crdB->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $saleA = $this->createTestSale("SL-{$ref}-A");
        $dispatchA = Dispatch::create(['sale_id' => $saleA->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detailA = DispatchDetail::create([
            'dispatch_id' => $dispatchA->id,
            'sale_id' => $saleA->id,
            'product_id' => $this->componentProduct->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 1,
            'serial_numbers' => json_encode(["SN-{$ref}-A"]),
            'is_inventory_managed' => true,
        ]);

        $saleB = $this->createTestSale("SL-{$ref}-B");
        $dispatchB = Dispatch::create(['sale_id' => $saleB->id, 'status' => Dispatch::STATUS_APPROVED]);
        $detailB = DispatchDetail::create([
            'dispatch_id' => $dispatchB->id,
            'sale_id' => $saleB->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 1,
            'serial_numbers' => json_encode(["SN-{$ref}-B"]),
            'is_inventory_managed' => true,
        ]);

        $this->discoveryService->discoverForSetting($this->setting->id);
        $sourceA = ConsignmentSoldSource::where('dispatch_detail_id', $detailA->id)->firstOrFail();
        $sourceB = ConsignmentSoldSource::where('dispatch_detail_id', $detailB->id)->firstOrFail();

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $sourceA->id,
                    'allocated_base_quantity' => 1,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crdA->id, 'allocated_base_quantity' => 1]],
                    'serialized_allocations' => [['product_serial_number_id' => $psnA->id, 'consignment_receiving_detail_id' => $crdA->id]],
                ],
                [
                    'consignment_sold_source_id' => $sourceB->id,
                    'allocated_base_quantity' => 1,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crdB->id, 'allocated_base_quantity' => 1]],
                    'serialized_allocations' => [['product_serial_number_id' => $psnB->id, 'consignment_receiving_detail_id' => $crdB->id]],
                ],
            ],
            $note,
            $this->user->id
        );

        return [$draft, compact('psnA', 'psnB', 'crdA', 'crdB')];
    }

    /**
     * @test
     *
     * Immutability guards run inside model events and must never depend on which relations
     * the caller happened to load. With lazy loading disabled, dereferencing an unloaded
     * relation throws before the guard can decide, turning a legitimate write into an
     * infrastructure failure.
     */
    public function immutability_guards_do_not_lazy_load_when_relations_are_absent()
    {
        // Two lines, each with one serial: the real shape of the failing confirmation.
        [$draft] = $this->createTwoLineSerializedDraft('GUARD-LAZY', 'Guard lazy-load probe');

        // Re-read each guarded model with NO relations loaded, as a multi-row collection.
        // Laravel only stamps the per-instance lazy-loading guard when a query hydrates
        // more than one row (Builder::hydrate), which is exactly why a two-line
        // confirmation reproduces this and a single-row fetch does not.
        $lines = \Modules\Consignment\Entities\ConsignmentBillingConfirmationLine::query()
            ->where('consignment_billing_confirmation_id', $draft->id)
            ->get();
        $this->assertGreaterThan(1, $lines->count(), 'Fixture must produce a multi-row hydration.');
        $line = $lines->first();
        $this->assertTrue($line->preventsLazyLoading, 'Lazy-load guard must be armed on this instance.');
        $this->assertFalse($line->relationLoaded('confirmation'));

        $allocations = \Modules\Consignment\Entities\ConsignmentSerializedAllocation::query()
            ->where('consignment_billing_confirmation_id', $draft->id)
            ->get();
        $this->assertGreaterThan(1, $allocations->count());
        $allocation = $allocations->first();
        $this->assertTrue($allocation->preventsLazyLoading);
        $this->assertFalse($allocation->relationLoaded('confirmation'));

        $receiptAllocations = \Modules\Consignment\Entities\ConsignmentReceiptAllocation::query()
            ->whereIn('consignment_billing_confirmation_line_id', $lines->pluck('id'))
            ->get();
        $this->assertGreaterThan(1, $receiptAllocations->count());
        $receiptAllocation = $receiptAllocations->first();
        $this->assertTrue($receiptAllocation->preventsLazyLoading);
        $this->assertFalse($receiptAllocation->relationLoaded('line'));

        // On a DRAFT these writes are permitted; the guards must reach that verdict
        // without lazy loading. Any LazyLoadingViolationException fails the test.
        $allocation->update(['status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED]);
        $receiptAllocation->update(['allocated_base_quantity' => 1]);
        $line->update(['allocated_base_quantity' => 1]);

        $this->assertSame(
            \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
            (string) $allocation->fresh()->status
        );
    }

    /**
     * @test
     *
     * The guards must still block on an approved confirmation when relations are unloaded:
     * being lazy-load safe must not mean being permissive.
     */
    public function immutability_guards_still_block_on_approved_confirmation_without_loaded_relations()
    {
        [$draft] = $this->createTwoLineSerializedDraft('GUARD-BLOCK', 'Guard block probe');

        // The real serialized approval path must succeed end to end.
        $submitted = $this->lifecycleService->submitConfirmation($draft, $this->user->id);
        $approved = $this->lifecycleService->approveConfirmation($submitted, $this->user->id);
        $this->assertTrue($approved->isApproved());
        $this->assertTrue($approved->is_ready_for_billing);

        $allocations = \Modules\Consignment\Entities\ConsignmentSerializedAllocation::query()
            ->where('consignment_billing_confirmation_id', $approved->id)
            ->get();
        $this->assertGreaterThan(1, $allocations->count());
        $allocation = $allocations->first();
        $this->assertTrue($allocation->preventsLazyLoading);
        $this->assertFalse($allocation->relationLoaded('confirmation'));
        $this->assertSame(
            \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
            (string) $allocation->status
        );

        try {
            $allocation->update(['status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_RELEASED]);
            $this->fail('Guard should have blocked modifying an approved serialized allocation.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('approved confirmation', $e->getMessage());
        }

        $receiptAllocations = \Modules\Consignment\Entities\ConsignmentReceiptAllocation::query()
            ->whereIn(
                'consignment_billing_confirmation_line_id',
                \Modules\Consignment\Entities\ConsignmentBillingConfirmationLine::query()
                    ->where('consignment_billing_confirmation_id', $approved->id)
                    ->pluck('id')
            )
            ->get();
        $this->assertGreaterThan(1, $receiptAllocations->count());
        $receiptAllocation = $receiptAllocations->first();
        $this->assertTrue($receiptAllocation->preventsLazyLoading);
        $this->assertFalse($receiptAllocation->relationLoaded('line'));

        try {
            $receiptAllocation->update(['allocated_base_quantity' => 99]);
            $this->fail('Guard should have blocked modifying an approved receipt allocation.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('approved confirmation', $e->getMessage());
        }
    }
}

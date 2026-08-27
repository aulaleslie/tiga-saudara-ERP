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
use Modules\Consignment\Services\ConsignmentReceiptAllocationService;
use Modules\Consignment\Services\ConsignmentReturnEligibilityService;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ConsignmentSalesAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Supplier $supplier;
    protected Location $consignmentLocation;
    protected Location $standardLocation;
    protected Product $product;

    protected ConsignmentSoldSourceDiscoveryService $discoveryService;
    protected ConsignmentReturnEligibilityService $eligibilityService;
    protected ConsignmentReceiptAllocationService $receiptAllocationService;
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
            'company_name' => 'Consignment ERP Unit Test',
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

        $this->standardLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
            'is_consignment' => false,
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

        $this->product = Product::create([
            'product_name' => 'Consignment Widget A',
            'product_code' => 'CWA-001',
            'product_price' => 150000,
            'product_cost' => 100000,
            'product_quantity' => 0,
            'product_unit' => $unit->id,
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'stock_managed' => true,
            'setting_id' => $this->setting->id,
        ]);

        $this->discoveryService = app(ConsignmentSoldSourceDiscoveryService::class);
        $this->eligibilityService = app(ConsignmentReturnEligibilityService::class);
        $this->receiptAllocationService = app(ConsignmentReceiptAllocationService::class);
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

    /** @test */
    public function it_discovers_approved_dispatches_at_consignment_locations_only()
    {
        $sale = $this->createTestSale('SL-001');

        $dispatchApproved = Dispatch::create([
            'sale_id' => $sale->id,
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $ddConsignment = DispatchDetail::create([
            'dispatch_id' => $dispatchApproved->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 10,
            'is_inventory_managed' => true,
        ]);

        $ddStandard = DispatchDetail::create([
            'dispatch_id' => $dispatchApproved->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->standardLocation->id,
            'dispatched_quantity' => 5,
            'is_inventory_managed' => true,
        ]);

        $result = $this->discoveryService->discoverForSetting($this->setting->id);

        $this->assertEquals(1, $result['created']);
        $this->assertDatabaseHas('consignment_sold_sources', [
            'dispatch_detail_id' => $ddConsignment->id,
            'original_base_quantity' => 10,
        ]);
        $this->assertDatabaseMissing('consignment_sold_sources', [
            'dispatch_detail_id' => $ddStandard->id,
        ]);
    }

    /** @test */
    public function it_enforces_idempotent_discovery_and_does_not_rewrite_existing_captures()
    {
        $sale = $this->createTestSale('SL-002', 300000);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 8,
            'is_inventory_managed' => true,
        ]);

        // Run first time
        $res1 = $this->discoveryService->discoverForSetting($this->setting->id);
        $this->assertEquals(1, $res1['created']);

        // Run second time
        $res2 = $this->discoveryService->discoverForSetting($this->setting->id);
        $this->assertEquals(0, $res2['created']);
        $this->assertEquals(1, $res2['existing']);
        $this->assertCount(1, ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->get());
    }

    /** @test */
    public function it_manages_confirmation_draft_lifecycle_and_reservations()
    {
        // 1. Create receiving detail for supplier
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-001',
            'receival_number' => 'CR-001',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 50,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 5000000,
            'subtotal_dpp' => 5000000,
            'total_cost' => 5000000,
            'total_dpp' => 5000000,
        ]);
        $receiving = ConsignmentReceiving::create([
            'consignment_receival_id' => $receival->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->consignmentLocation->id,
            'receiving_number' => 'RCV-001',
            'date' => date('Y-m-d'),
            'status' => ConsignmentReceiving::STATUS_APPROVED,
        ]);
        $crd = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $receivalLine->id,
            'product_id' => $this->product->id,
            'quantity_received' => 50,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
        ]);

        // 2. Create sold source
        $sale = $this->createTestSale('SL-003');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 15,
            'is_inventory_managed' => true,
        ]);
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        // 3. Create draft
        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $soldSource->id,
                    'product_id' => $this->product->id,
                    'location_id' => $this->consignmentLocation->id,
                    'allocated_base_quantity' => 15,
                    'receipt_allocations' => [
                        [
                            'consignment_receiving_detail_id' => $crd->id,
                            'allocated_base_quantity' => 15,
                        ],
                    ],
                ],
            ],
            'Test Draft',
            $this->user->id
        );

        $this->assertTrue($draft->isDraft());

        // 4. Submit confirmation -> WAITING_APPROVAL
        $submitted = $this->lifecycleService->submitConfirmation($draft, $this->user->id);
        $this->assertTrue($submitted->isWaitingApproval());

        // 5. Approve confirmation -> APPROVED (ready for billing)
        $approved = $this->lifecycleService->approveConfirmation($submitted, $this->user->id);
        $this->assertTrue($approved->isApproved());
        $this->assertTrue($approved->is_ready_for_billing);

        // 6. Prove financially inert: no purchases or payments created
        $this->assertEquals(0, Purchase::count());
    }

    /** @test */
    public function it_releases_reservations_atomically_upon_rejection()
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-002',
            'receival_number' => 'CR-002',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 20,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 2000000,
            'subtotal_dpp' => 2000000,
            'total_cost' => 2000000,
            'total_dpp' => 2000000,
        ]);
        $receiving = ConsignmentReceiving::create(['consignment_receival_id' => $receival->id, 'setting_id' => $this->setting->id, 'location_id' => $this->consignmentLocation->id, 'receiving_number' => 'RCV-002', 'date' => date('Y-m-d'), 'status' => ConsignmentReceiving::STATUS_APPROVED]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $receivalLine->id, 'product_id' => $this->product->id, 'quantity_received' => 20, 'unit_cost' => 100000, 'unit_dpp' => 100000]);

        $psn = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SN-1001',
            'consignment_receiving_detail_id' => $crd->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $sale = $this->createTestSale('SL-004');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 1,
            'serial_numbers' => json_encode(['SN-1001']),
            'is_inventory_managed' => true,
        ]);
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $soldSource->id,
                    'allocated_base_quantity' => 1,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 1]],
                    'serialized_allocations' => [['product_serial_number_id' => $psn->id, 'consignment_receiving_detail_id' => $crd->id]],
                ],
            ],
            'Serial Test',
            $this->user->id
        );

        $submitted = $this->lifecycleService->submitConfirmation($draft, $this->user->id);
        $this->assertDatabaseHas('consignment_active_serial_claims', ['product_serial_number_id' => $psn->id]);

        $rejected = $this->lifecycleService->rejectConfirmation($submitted, 'Data incorrect', $this->user->id);
        $this->assertTrue($rejected->isRejected());
        $this->assertDatabaseMissing('consignment_active_serial_claims', ['product_serial_number_id' => $psn->id]);
    }

    /** @test */
    public function it_prevents_duplicate_sold_source_over_allocation_in_single_payload()
    {
        $sale = $this->createTestSale('SL-DUP-01');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 10,
            'is_inventory_managed' => true,
        ]);
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-DUP',
            'receival_number' => 'CR-DUP',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 20,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 2000000,
            'subtotal_dpp' => 2000000,
            'total_cost' => 2000000,
            'total_dpp' => 2000000,
        ]);
        $receiving = ConsignmentReceiving::create(['consignment_receival_id' => $receival->id, 'setting_id' => $this->setting->id, 'location_id' => $this->consignmentLocation->id, 'receiving_number' => 'RCV-DUP', 'date' => date('Y-m-d'), 'status' => ConsignmentReceiving::STATUS_APPROVED]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $receivalLine->id, 'product_id' => $this->product->id, 'quantity_received' => 20, 'unit_cost' => 100000, 'unit_dpp' => 100000]);

        // Attempt forged payload with two lines of 8 units each against sold source of 10
        $this->expectException(\Throwable::class);

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $soldSource->id,
                    'allocated_base_quantity' => 8,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 8]],
                ],
                [
                    'consignment_sold_source_id' => $soldSource->id,
                    'allocated_base_quantity' => 8,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 8]],
                ],
            ],
            'Forged Payload Test',
            $this->user->id
        );

        $this->lifecycleService->submitConfirmation($draft, $this->user->id);
    }

    /** @test */
    public function it_prevents_duplicate_receipt_lot_over_allocation_in_single_payload()
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-POOL-01',
            'receival_number' => 'CR-POOL-01',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 1000000,
            'subtotal_dpp' => 1000000,
            'total_cost' => 1000000,
            'total_dpp' => 1000000,
        ]);
        $receiving = ConsignmentReceiving::create(['consignment_receival_id' => $receival->id, 'setting_id' => $this->setting->id, 'location_id' => $this->consignmentLocation->id, 'receiving_number' => 'RCV-POOL-01', 'date' => date('Y-m-d'), 'status' => ConsignmentReceiving::STATUS_APPROVED]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $receivalLine->id, 'product_id' => $this->product->id, 'quantity_received' => 10, 'unit_cost' => 100000, 'unit_dpp' => 100000]);

        $sale = $this->createTestSale('SL-POOL-01');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 14,
            'is_inventory_managed' => true,
        ]);
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        // Attempt forged receipt allocations: 2 allocations of 7 against pool capacity of 10
        $allocData = [
            ['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 7],
            ['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 7],
        ];

        $res = $this->receiptAllocationService->validateReceiptAllocations(
            $allocData,
            14,
            $this->setting->id,
            $this->supplier->id,
            $this->product->id,
            $this->consignmentLocation->id
        );

        $this->assertFalse($res['is_valid']);
        $this->assertStringContainsString('exceeds remaining pool capacity', implode(' ', $res['errors']));
    }

    /** @test */
    public function it_enforces_tenant_boundary_isolation_on_draft_creation()
    {
        $otherSetting = \Modules\Setting\Entities\Setting::create([
            'company_name' => 'Other Business',
            'company_email' => 'other@example.com',
            'company_phone' => '081299999',
            'notification_email' => 'other@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
        ]);

        $otherSale = Sale::create([
            'setting_id' => $otherSetting->id,
            'customer_name' => 'Other Customer',
            'reference' => 'SL-OTH-01',
            'date' => date('Y-m-d'),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        $otherDispatch = Dispatch::create(['sale_id' => $otherSale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $otherDd = DispatchDetail::create([
            'dispatch_id' => $otherDispatch->id,
            'sale_id' => $otherSale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 10,
            'is_inventory_managed' => true,
        ]);

        $foreignSoldSource = ConsignmentSoldSource::create([
            'setting_id' => $otherSetting->id,
            'dispatch_detail_id' => $otherDd->id,
            'sale_id' => $otherSale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'original_base_quantity' => 10,
            'source_hash' => md5('test-other'),
            'source_snapshot' => [],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to confirmation setting');

        $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $foreignSoldSource->id,
                    'allocated_base_quantity' => 5,
                ],
            ]
        );
    }

    /** @test */
    public function it_allows_editing_and_revising_rejected_confirmations()
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-REV-01',
            'receival_number' => 'CR-REV-01',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 50,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 5000000,
            'subtotal_dpp' => 5000000,
            'total_cost' => 5000000,
            'total_dpp' => 5000000,
        ]);
        $receiving = ConsignmentReceiving::create(['consignment_receival_id' => $receival->id, 'setting_id' => $this->setting->id, 'location_id' => $this->consignmentLocation->id, 'receiving_number' => 'RCV-REV-01', 'date' => date('Y-m-d'), 'status' => ConsignmentReceiving::STATUS_APPROVED]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $receivalLine->id, 'product_id' => $this->product->id, 'quantity_received' => 50, 'unit_cost' => 100000, 'unit_dpp' => 100000]);

        $sale = $this->createTestSale('SL-REV-01');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 10,
            'is_inventory_managed' => true,
        ]);
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $soldSource->id,
                    'allocated_base_quantity' => 10,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 10]],
                ],
            ]
        );

        $submitted = $this->lifecycleService->submitConfirmation($draft, $this->user->id);
        $rejected = $this->lifecycleService->rejectConfirmation($submitted, 'Incorrect lot allocation', $this->user->id);

        $this->assertTrue($rejected->isRejected());
        $this->assertTrue($rejected->canEdit());

        // Update rejected draft and resubmit
        $updated = $this->lifecycleService->updateDraft(
            $rejected,
            [
                [
                    'consignment_sold_source_id' => $soldSource->id,
                    'allocated_base_quantity' => 10,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 10]],
                ],
            ],
            'Revised notes'
        );

        $this->assertTrue($updated->isDraft());

        $resubmitted = $this->lifecycleService->submitConfirmation($updated, $this->user->id);
        $this->assertTrue($resubmitted->isWaitingApproval());
    }

    /** @test */
    public function it_guards_model_immutability_for_sold_sources()
    {
        $sale = $this->createTestSale('SL-IMM-01');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'is_inventory_managed' => true,
        ]);
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        $this->expectException(\DomainException::class);
        $soldSource->delete();
    }

    /** @test */
    public function it_guards_model_update_immutability_for_sold_sources()
    {
        $sale = $this->createTestSale('SL-IMM-02');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'is_inventory_managed' => true,
        ]);
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        $this->expectException(\DomainException::class);
        $soldSource->update(['original_base_quantity' => 999]);
    }

    /** @test */
    public function it_approves_serialized_confirmations_without_self_claim_blocking()
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-SER-APP',
            'receival_number' => 'CR-SER-APP',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 1000000,
            'subtotal_dpp' => 1000000,
            'total_cost' => 1000000,
            'total_dpp' => 1000000,
        ]);
        $receiving = ConsignmentReceiving::create(['consignment_receival_id' => $receival->id, 'setting_id' => $this->setting->id, 'location_id' => $this->consignmentLocation->id, 'receiving_number' => 'RCV-SER-APP', 'date' => date('Y-m-d'), 'status' => ConsignmentReceiving::STATUS_APPROVED]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $receivalLine->id, 'product_id' => $this->product->id, 'quantity_received' => 10, 'unit_cost' => 100000, 'unit_dpp' => 100000]);

        $psn = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'serial_number' => 'SN-APP-999',
            'consignment_receiving_detail_id' => $crd->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $sale = $this->createTestSale('SL-SER-APP');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 1,
            'serial_numbers' => json_encode(['SN-APP-999']),
            'is_inventory_managed' => true,
        ]);
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $soldSource->id,
                    'allocated_base_quantity' => 1,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 1]],
                    'serialized_allocations' => [['product_serial_number_id' => $psn->id, 'consignment_receiving_detail_id' => $crd->id]],
                ],
            ],
            'Serial Self Claim Approval Test',
            $this->user->id
        );

        $submitted = $this->lifecycleService->submitConfirmation($draft, $this->user->id);
        $this->assertTrue($submitted->isWaitingApproval());

        // Approval must succeed without treating its own active claim as a blocker
        $approved = $this->lifecycleService->approveConfirmation($submitted, $this->user->id);
        $this->assertTrue($approved->isApproved());
        $this->assertTrue($approved->is_ready_for_billing);
    }

    /** @test */
    public function it_enforces_cross_line_receipt_pool_capacity_aggregation()
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-CROSS-01',
            'receival_number' => 'CR-CROSS-01',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 1000000,
            'subtotal_dpp' => 1000000,
            'total_cost' => 1000000,
            'total_dpp' => 1000000,
        ]);
        $receiving = ConsignmentReceiving::create(['consignment_receival_id' => $receival->id, 'setting_id' => $this->setting->id, 'location_id' => $this->consignmentLocation->id, 'receiving_number' => 'RCV-CROSS-01', 'date' => date('Y-m-d'), 'status' => ConsignmentReceiving::STATUS_APPROVED]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $receivalLine->id, 'product_id' => $this->product->id, 'quantity_received' => 10, 'unit_cost' => 100000, 'unit_dpp' => 100000]);

        $sale1 = $this->createTestSale('SL-CROSS-01');
        $dispatch1 = Dispatch::create(['sale_id' => $sale1->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd1 = DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale1->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 7,
            'is_inventory_managed' => true,
        ]);

        $sale2 = $this->createTestSale('SL-CROSS-02');
        $dispatch2 = Dispatch::create(['sale_id' => $sale2->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd2 = DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale2->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 7,
            'is_inventory_managed' => true,
        ]);

        $this->discoveryService->discoverForSetting($this->setting->id);
        $src1 = ConsignmentSoldSource::where('dispatch_detail_id', $dd1->id)->firstOrFail();
        $src2 = ConsignmentSoldSource::where('dispatch_detail_id', $dd2->id)->firstOrFail();

        // 7 from Line 1 + 7 from Line 2 = 14 against pool capacity of 10
        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $src1->id,
                    'allocated_base_quantity' => 7,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 7]],
                ],
                [
                    'consignment_sold_source_id' => $src2->id,
                    'allocated_base_quantity' => 7,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 7]],
                ],
            ],
            'Cross Line Receipt Over-allocation',
            $this->user->id
        );

        $this->expectException(\Exception::class);
        $this->lifecycleService->submitConfirmation($draft, $this->user->id);
    }

    /** @test */
    public function it_reconstructs_original_quantity_with_pos_cash_returns_and_ordinary_sales_returns()
    {
        $sale = $this->createTestSale('SL-RECON-01');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 10,
            'is_inventory_managed' => true,
        ]);

        // 1. Discovery for original dispatch 10
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
        $this->assertEquals(10.0, (float) $soldSource->original_base_quantity);

        // 2. Ordinary Sales Return does NOT mutate dispatched_quantity (remains 10)
        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '0812345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Street 1',
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'setting_id' => $this->setting->id,
            'sale_id' => $sale->id,
            'date' => date('Y-m-d'),
            'reference' => 'SR-001',
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
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        \Modules\SalesReturn\Entities\SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'dispatch_detail_id' => $dd->id,
            'product_id' => $this->product->id,
            'product_name' => 'Product A',
            'product_code' => 'PROD-A',
            'quantity' => 2,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 20000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Re-discovery on unmodified dispatched_quantity 10 must keep original quantity 10
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource->refresh();
        $this->assertEquals(10.0, (float) $soldSource->original_base_quantity);

        // Eligibility calculation deducts ordinary return (10 - 2 = 8 eligible)
        $elig = $this->eligibilityService->calculateSoldEligibility($soldSource);
        $this->assertEquals(8.0, (float) $elig['remaining_quantity']);
    }

    /** @test */
    public function it_guards_immutability_on_child_confirmation_lines_receipt_allocations_and_audit_logs()
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-IMM-CHILD',
            'receival_number' => 'CR-IMM-CHILD',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 1000000,
            'subtotal_dpp' => 1000000,
            'total_cost' => 1000000,
            'total_dpp' => 1000000,
        ]);
        $receiving = ConsignmentReceiving::create(['consignment_receival_id' => $receival->id, 'setting_id' => $this->setting->id, 'location_id' => $this->consignmentLocation->id, 'receiving_number' => 'RCV-IMM-CHILD', 'date' => date('Y-m-d'), 'status' => ConsignmentReceiving::STATUS_APPROVED]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $receivalLine->id, 'product_id' => $this->product->id, 'quantity_received' => 10, 'unit_cost' => 100000, 'unit_dpp' => 100000]);

        $sale = $this->createTestSale('SL-IMM-CHILD');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'is_inventory_managed' => true,
        ]);
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $soldSource->id,
                    'allocated_base_quantity' => 5,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 5]],
                ],
            ]
        );

        $submitted = $this->lifecycleService->submitConfirmation($draft, $this->user->id);
        $approved = $this->lifecycleService->approveConfirmation($submitted, $this->user->id);

        $line = $approved->lines()->firstOrFail();
        $ra = $line->receiptAllocations()->firstOrFail();
        $auditLog = $approved->auditLogs()->firstOrFail();

        // 1. Audit log immutability
        try {
            $auditLog->update(['reason' => 'Tampered']);
            $this->fail('Audit log update should have thrown DomainException.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // 2. Approved line immutability
        try {
            $line->update(['allocated_base_quantity' => 99]);
            $this->fail('Approved line update should have thrown DomainException.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('approved', $e->getMessage());
        }

        // 3. Approved receipt allocation immutability
        try {
            $ra->update(['allocated_base_quantity' => 99]);
            $this->fail('Approved receipt allocation update should have thrown DomainException.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('approved', $e->getMessage());
        }
    }

    /** @test */
    public function it_blocks_submission_when_live_source_hash_is_stale_or_corrupted()
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-STALE-HASH',
            'receival_number' => 'CR-STALE-HASH',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 1000000,
            'subtotal_dpp' => 1000000,
            'total_cost' => 1000000,
            'total_dpp' => 1000000,
        ]);
        $receiving = ConsignmentReceiving::create(['consignment_receival_id' => $receival->id, 'setting_id' => $this->setting->id, 'location_id' => $this->consignmentLocation->id, 'receiving_number' => 'RCV-STALE-HASH', 'date' => date('Y-m-d'), 'status' => ConsignmentReceiving::STATUS_APPROVED]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $receivalLine->id, 'product_id' => $this->product->id, 'quantity_received' => 10, 'unit_cost' => 100000, 'unit_dpp' => 100000]);

        $sale = $this->createTestSale('SL-STALE-HASH');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'is_inventory_managed' => true,
        ]);
        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $soldSource->id,
                    'allocated_base_quantity' => 5,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 5]],
                ],
            ]
        );

        // Mutate live dispatch detail quantity behind the scenes so live hash changes
        $dd->update(['dispatched_quantity' => 99]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('hash mismatch');
        $this->lifecycleService->submitConfirmation($draft, $this->user->id);
    }

    /** @test */
    public function it_blocks_submission_of_serialized_sold_sources_without_serialized_allocations()
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-MISSING-SERIAL',
            'receival_number' => 'CR-MISSING-SERIAL',
            'date' => date('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $receivalLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 1000000,
            'subtotal_dpp' => 1000000,
            'total_cost' => 1000000,
            'total_dpp' => 1000000,
        ]);
        $receiving = ConsignmentReceiving::create(['consignment_receival_id' => $receival->id, 'setting_id' => $this->setting->id, 'location_id' => $this->consignmentLocation->id, 'receiving_number' => 'RCV-MISSING-SERIAL', 'date' => date('Y-m-d'), 'status' => ConsignmentReceiving::STATUS_APPROVED]);
        $crd = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $receivalLine->id, 'product_id' => $this->product->id, 'quantity_received' => 10, 'unit_cost' => 100000, 'unit_dpp' => 100000]);

        $sale = $this->createTestSale('SL-MISSING-SERIAL');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 1,
            'serial_numbers' => json_encode(['SN-MISSING-ALLOC-001']),
            'is_inventory_managed' => true,
        ]);

        $psn = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-MISSING-ALLOC-001',
            'status' => 'DISPATCHED',
            'location_id' => $this->consignmentLocation->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->discoveryService->discoverForSetting($this->setting->id);
        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();

        // Create draft WITHOUT serialized_allocations
        $draft = $this->lifecycleService->createDraft(
            $this->setting->id,
            $this->supplier->id,
            date('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $soldSource->id,
                    'allocated_base_quantity' => 1,
                    'receipt_allocations' => [['consignment_receiving_detail_id' => $crd->id, 'allocated_base_quantity' => 1]],
                ],
            ]
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('requires 1 serialized allocation');
        $this->lifecycleService->submitConfirmation($draft, $this->user->id);
    }

    /** @test */
    public function it_discovers_unsupported_product_type_as_blocker()
    {
        $sale = $this->createTestSale('SL-UNSUPPORTED');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 1,
            'is_inventory_managed' => false,
        ]);

        $result = $this->discoveryService->discoverForSetting($this->setting->id);

        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
        $this->assertTrue((bool)$soldSource->has_reconstruction_blocker);
        $this->assertStringContainsStringIgnoringCase('Non-inventory', $soldSource->blocker_reason);
    }

    /** @test */
    public function it_ignores_pending_pos_cash_returns_during_discovery()
    {
        if (!class_exists(\Modules\Pos\Entities\PosReturnLine::class)) {
            $this->markTestSkipped('POS module not installed.');
        }

        $sale = $this->createTestSale('SL-POS-PENDING');
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->consignmentLocation->id,
            'dispatched_quantity' => 5,
            'is_inventory_managed' => true,
        ]);

        $posSession = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $this->setting->id,
            'owner_user_id' => $this->user->id,
            'cashier_user_id' => $this->user->id,
            'pos_shift_id' => 1,
            'pos_location_id' => $this->consignmentLocation->id,
            'status' => 'OPEN',
        ]);

        $posTransaction = \Modules\Pos\Entities\PosTransaction::create([
            'setting_id' => $this->setting->id,
            'reference_no' => 'POS-001',
            'code' => 'POS-001-CODE',
            'status' => 'completed',
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $posSession->id,
        ]);

        $posTerminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $this->setting->id,
            'name' => 'Terminal 1',
            'code' => 'TERM-01',
            'location_id' => $this->consignmentLocation->id,
            'status' => 'active',
        ]);

        $posCheckout = \Modules\Pos\Entities\PosCheckout::create([
            'setting_id' => $this->setting->id,
            'reference_no' => 'CHK-001',
            'pos_session_id' => $posSession->id,
            'owner_user_id' => $this->user->id,
            'cashier_user_id' => $this->user->id,
            'terminal_id' => $posTerminal->id,
            'amount' => 100,
            'idempotency_key' => 'chk-001',
            'payload_hash' => 'hash',
        ]);

        $posReturn = \Modules\Pos\Entities\PosReturn::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $posTransaction->id,
            'pos_checkout_id' => $posCheckout->id,
            'location_id' => $this->consignmentLocation->id,
            'status' => 'pending',
            'return_number' => 'RET-POS-PENDING',
            'reference' => 'RET-POS-PENDING',
            'transaction_code' => 'RET-CODE-001',
            'receipt_number' => 'REC-001',
            'return_option' => 'cash',
            'approval_status' => 'approved',
            'source_snapshot' => '{}',
            'source_snapshot_hash' => 'hash',
            'total_amount' => 0,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
        ]);

        $posCheckoutSale = \Modules\Pos\Entities\PosCheckoutSale::create([
            'pos_checkout_id' => $posCheckout->id,
            'sale_id' => $dispatch->sale_id ?? 1,
            'split_key' => 'split-key',
            'tax_bucket' => 'none',
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->consignmentLocation->id,
        ]);

        \Modules\Pos\Entities\PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $posCheckoutSale->id,
            'dispatch_detail_id' => $dd->id,
            'product_id' => $this->product->id,
            'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
            'quantity' => 1,
            'sale_id' => $dispatch->sale_id ?? 1,
            'sale_detail_id' => 1,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->consignmentLocation->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'unit_price' => 100,
            'line_total' => 100,
            'stock_behavior' => 'stock_managed',
        ]);

        $result = $this->discoveryService->discoverForSetting($this->setting->id);

        $soldSource = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
        $this->assertFalse((bool)$soldSource->has_reconstruction_blocker);
    }
}

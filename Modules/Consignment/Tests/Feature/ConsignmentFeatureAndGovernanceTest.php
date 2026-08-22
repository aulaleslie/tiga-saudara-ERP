<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentReferenceService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConsignmentFeatureAndGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $consignmentLocation;
    protected Location $standardLocation;
    protected Supplier $supplier;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Consignment Business',
            'company_email' => 'biz@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'biz@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
            'document_prefix' => 'CSG',
        ]);

        $this->user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $permissions = [
            'consignments.access',
            'consignments.create',
            'consignments.edit',
            'consignments.delete',
            'consignments.submit',
            'consignments.approve',
            'consignments.reject',
            'consignments.receive',
            'consignments.receive.approve',
            'consignments.receive.reject',
            'consignments.reverse',
            'purchases.access',
            'purchases.create',
            'purchases.receive',
            'purchases.receive.access',
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.edit',
            'adjustments.access',
            'adjustments.create',
            'adjustments.breakage.create',
        ];

        foreach ($permissions as $perm) {
            $p = Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            $role->givePermissionTo($p);
            $this->user->givePermissionTo($p);
        }

        $this->consignmentLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Rak Konsinyasi A',
            'is_consignment' => true,
        ]);

        $this->standardLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Gudang Utama',
            'is_consignment' => false,
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Alpha',
            'supplier_email' => 'alpha@example.com',
            'supplier_phone' => '0899999999',
            'city' => 'Surabaya',
            'country' => 'Indonesia',
            'address' => 'Alpha Street 10',
        ]);

        $this->product = Product::create([
            'product_name' => 'Mouse Wireless Gaming',
            'product_code' => 'MS-WL-01',
            'product_quantity' => 0,
            'product_cost' => 150000,
            'product_price' => 200000,
            'stock_managed' => true,
            'serial_number_required' => false,
            'setting_id' => $this->setting->id,
        ]);

    }

    public function test_consignment_approval_never_creates_purchase_or_payables()
    {
        $receival = ConsignmentReferenceService::createReceivalWithReference([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_APPROVED,
        ]);

        ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'unit_cost' => 150000,
            'unit_dpp' => 150000,
            'subtotal_cost' => 750000,
            'total_cost' => 750000,
            'is_serialized' => false,
        ]);

        $receivingService = app(\Modules\Consignment\Services\ConsignmentReceivingService::class);

        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $receival->lines->first()->id => [
                    'quantity_received' => 5,
                ]
            ]
        ], $this->user->id);

        $receivingService->approveReceiving($receiving, $this->user->id);

        // Explicit assertion: No Purchase, PurchaseDetail, ReceivedNote or Payable records were created
        $this->assertEquals(0, Purchase::count());
        $this->assertEquals(0, ReceivedNote::count());
        $this->assertDatabaseMissing('purchase_payments', ['setting_id' => $this->setting->id]);
    }

    public function test_receival_create_uses_canonical_product_eligibility_schema()
    {
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.receivals.create'));

        $response->assertOk();
        $response->assertSee('consignment-product-select', false);
        $response->assertSee('consignment-supplier-select', false);
        $response->assertSee("placeholder: '-- Cari Produk --'", false);
        $response->assertSee("placeholder: '-- Cari Supplier --'", false);
    }

    public function test_consignment_product_search_fetches_eligible_products_server_side()
    {
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('consignments.receival-products.search', [
                'q' => $this->product->product_name,
            ]));

        $response->assertOk()
            ->assertJsonPath('results.0.id', $this->product->id)
            ->assertJsonPath('pagination.more', false);

        $this->assertStringContainsString(
            $this->product->product_name,
            $response->json('results.0.text')
        );
    }

    public function test_consignment_supplier_search_fetches_current_setting_suppliers_server_side()
    {
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('consignments.receival-suppliers.search', [
                'q' => $this->supplier->supplier_name,
            ]));

        $response->assertOk()
            ->assertJsonPath('results.0.id', $this->supplier->id)
            ->assertJsonPath('pagination.more', false);

        $this->assertStringContainsString(
            $this->supplier->supplier_name,
            $response->json('results.0.text')
        );
    }

    public function test_purchase_receiving_rejects_consignment_location()
    {
        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Purchase::STATUS_APPROVED,
            'reference' => 'PR-2026-08-00001',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $purchaseDetail = \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'price' => 150000,
            'unit_price' => 150000,
            'sub_total' => 300000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('purchases.storeReceive', $purchase->id), [
                'location_id' => $this->consignmentLocation->id, // Consignment location is prohibited!
                'date' => now()->toDateString(),
                'details' => [
                    $purchaseDetail->id => [
                        'quantity' => 2,
                    ]
                ]
            ]);

        // Expect validation failure / error redirect
        $response->assertSessionHasErrors('location_id');
        $this->assertEquals(0, ReceivedNote::count());
    }

    public function test_valuation_reports_exclude_consignment_stock_value_from_owned_totals()
    {
        $receival = ConsignmentReferenceService::createReceivalWithReference([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_APPROVED,
        ]);

        ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'unit_cost' => 150000,
            'unit_dpp' => 150000,
            'subtotal_cost' => 1500000,
            'total_cost' => 1500000,
            'is_serialized' => false,
        ]);

        $receivingService = app(\Modules\Consignment\Services\ConsignmentReceivingService::class);
        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $receival->lines->first()->id => [
                    'quantity_received' => 10,
                ]
            ]
        ], $this->user->id);

        $receivingService->approveReceiving($receiving, $this->user->id);

        // 1. Check Inventory Valuation Report Query Service
        $invValuationService = app(\App\Services\Reports\InventoryValuationReportQueryService::class);
        $filterData = new \App\Services\Reports\InventoryValuationReportFilterData();
        $filterData->tanggalAwal = now()->subDays(1);
        $filterData->tanggalAkhir = now()->addDays(1);
        $filterData->productIds = [$this->product->id];

        $invResult = $invValuationService->getReport($filterData, $this->setting->id, 10, 1);
        // Company owned inventory total value must be 0 because all stock is non-owned consignment custody
        $this->assertEquals(0, $invResult['totalValue']);

        // 2. Check Warehouse Stock Valuation Report Query Service
        $whService = app(\App\Services\Reports\WarehouseStockValuationReportQueryService::class);
        $whFilter = new \App\Services\Reports\WarehouseStockValuationReportFilterData();
        $whFilter->scopeSettingId = $this->setting->id;
        $whFilter->asOfDate = now()->toDateString();
        $whFilter->warehouseIds = [$this->consignmentLocation->id];

        $whRows = $whService->build($whFilter);
        $this->assertCount(1, $whRows);
        $firstRow = $whRows->first();
        $this->assertTrue($firstRow->is_consignment);
        $this->assertStringContainsString('(Konsinyasi)', $firstRow->warehouse_name);
        $this->assertEquals(10, (float) $firstRow->qty);
        $this->assertEquals(0, (float) $firstRow->stock_value); // Excluded from company inventory value
    }

    public function test_consignment_lifecycle_triggers_document_notifications()
    {
        $receival = ConsignmentReferenceService::createReceivalWithReference([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_DRAFT,
        ]);

        ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'unit_cost' => 150000,
            'unit_dpp' => 150000,
            'subtotal_cost' => 750000,
            'total_cost' => 750000,
            'is_serialized' => false,
        ]);

        $lifecycle = app(\Modules\Consignment\Services\ConsignmentReceivalLifecycleService::class);

        // Submit triggers notification
        $submitted = $lifecycle->submit($receival, $this->user->id);
        $this->assertTrue($submitted->isWaitingApproval());
        $this->assertDatabaseHas('notifications', [
            'source_type' => ConsignmentReceival::class,
            'source_id' => $receival->id,
            'type' => 'document_approval',
        ]);

        // Reject triggers revision notification and resolves approval
        $rejected = $lifecycle->reject($submitted, $this->user->id, 'Harga tidak cocok');
        $this->assertTrue($rejected->isRejected());
        $this->assertDatabaseHas('notifications', [
            'source_type' => ConsignmentReceival::class,
            'source_id' => $receival->id,
            'type' => 'document_revision',
        ]);

        // Re-submit and approve resolves notifications
        $resubmitted = $lifecycle->submit($rejected, $this->user->id);
        $approved = $lifecycle->approve($resubmitted, $this->user->id);
        $this->assertTrue($approved->isApproved());

        // Test receiving notifications
        $receivingService = app(\Modules\Consignment\Services\ConsignmentReceivingService::class);
        $receiving = $receivingService->createPendingReceiving($approved, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $approved->lines->first()->id => [
                    'quantity_received' => 5,
                ]
            ]
        ], $this->user->id);

        $this->assertDatabaseHas('notifications', [
            'source_type' => ConsignmentReceiving::class,
            'source_id' => $receiving->id,
            'type' => 'document_approval',
        ]);

        $receivingService->approveReceiving($receiving, $this->user->id);
        $unresolvedCount = \App\Models\Notification::where('source_type', ConsignmentReceiving::class)
            ->where('source_id', $receiving->id)
            ->whereNull('resolved_at')
            ->count();
        $this->assertEquals(0, $unresolvedCount);
    }

    public function test_sales_from_consignment_location_do_not_reduce_company_owned_inventory()
    {
        $receival = ConsignmentReferenceService::createReceivalWithReference([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'status' => ConsignmentReceival::STATUS_APPROVED,
        ]);

        ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'unit_cost' => 150000,
            'unit_dpp' => 150000,
            'subtotal_cost' => 1500000,
            'total_cost' => 1500000,
            'is_serialized' => false,
        ]);

        $receivingService = app(\Modules\Consignment\Services\ConsignmentReceivingService::class);
        $receiving = $receivingService->createPendingReceiving($receival, [
            'location_id' => $this->consignmentLocation->id,
            'date' => now()->toDateString(),
            'details' => [
                $receival->lines->first()->id => [
                    'quantity_received' => 10,
                ]
            ]
        ], $this->user->id);

        $receivingService->approveReceiving($receiving, $this->user->id);

        // Simulate subsequent sale of 2 units from consignment location
        \Modules\Product\Entities\Transaction::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'type' => 'SELL',
            'quantity' => -2,
            'current_quantity' => 8,
            'location_id' => $this->consignmentLocation->id,
            'user_id' => $this->user->id,
            'reason' => 'Penjualan POS Konsinyasi #INV-001',
            'previous_quantity' => 10,
            'after_quantity' => 8,
            'previous_quantity_at_location' => 10,
            'after_quantity_at_location' => 8,
            'quantity_non_tax' => -2,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $invValuationService = app(\App\Services\Reports\InventoryValuationReportQueryService::class);
        $filterData = new \App\Services\Reports\InventoryValuationReportFilterData();
        $filterData->tanggalAwal = now()->subDays(1);
        $filterData->tanggalAkhir = now()->addDays(1);
        $filterData->productIds = [$this->product->id];

        $invResult = $invValuationService->getReport($filterData, $this->setting->id, 10, 1);
        
        // Ending stock and value for company-owned inventory must remain exactly 0 (not -2)
        $firstProductRow = $invResult['allRows']->first();
        $this->assertEquals(0, $firstProductRow['ending_stock']);
        $this->assertEquals(0, $firstProductRow['ending_value']);
        $this->assertEquals(0, $invResult['totalValue']);
    }

    public function test_transfers_between_standard_and_consignment_locations_are_rejected()
    {
        // 1. Standard -> Consignment
        $response1 = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('transfers.store'), [
                'origin_location' => $this->standardLocation->id,
                'destination_location' => $this->consignmentLocation->id,
                'product_ids' => [$this->product->id],
                'quantities' => [1],
            ]);

        $response1->assertSessionHasErrors('destination_location');

        // 2. Consignment -> Standard
        $response2 = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('transfers.store'), [
                'origin_location' => $this->consignmentLocation->id,
                'destination_location' => $this->standardLocation->id,
                'product_ids' => [$this->product->id],
                'quantities' => [1],
            ]);

        $response2->assertSessionHasErrors('destination_location');
    }

    public function test_stock_adjustments_to_consignment_location_are_rejected()
    {
        // 1. Standard adjustment store
        $response1 = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('adjustments.store'), [
                'reference' => 'ADJ-001',
                'date' => now()->toDateString(),
                'location_id' => $this->consignmentLocation->id,
                'product_ids' => [$this->product->id],
                'quantities_tax' => [0],
                'quantities_non_tax' => [1],
            ]);

        $response1->assertSessionHasErrors('location_id');

        // 2. Breakage adjustment store
        $response2 = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('adjustments.storeBreakage'), [
                'reference' => 'BRK-001',
                'date' => now()->toDateString(),
                'location_id' => $this->consignmentLocation->id,
                'product_ids' => [$this->product->id],
                'quantities_tax' => [0],
                'quantities_non_tax' => [1],
            ]);

        $response2->assertSessionHasErrors('location_id');
    }
}

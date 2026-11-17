<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\SoldSerialNumberReportFilterData;
use App\Services\Reports\SoldSerialNumberReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class SoldSerialNumberReportQueryServiceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Customer $customer;
    private Category $category;
    private Unit $unit;
    private Location $location;
    private SoldSerialNumberReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->customer = $this->makeCustomer($this->setting);
        $this->category = $this->makeCategory($this->setting);
        $this->unit = $this->makeUnit($this->setting);
        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
        ]);
        $this->service = new SoldSerialNumberReportQueryService();

        session(['setting_id' => $this->setting->id]);
    }

    private function makeDispatch(Sale $sale, string $status = Dispatch::STATUS_APPROVED, ?string $approvedAt = null): Dispatch
    {
        return Dispatch::create([
            'sale_id' => $sale->id,
            'status' => $status,
            'approved_at' => $approvedAt ?? ($status === Dispatch::STATUS_APPROVED ? now() : null),
        ]);
    }

    private function makeDispatchDetail(
        Dispatch $dispatch,
        Sale $sale,
        SaleDetails $saleDetail,
        Product $product,
        array $serials,
        array $extra = []
    ): DispatchDetail {
        return DispatchDetail::create(array_merge([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'product_id' => $product->id,
            'dispatched_quantity' => count($serials),
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode($serials),
        ], $extra));
    }

    public function test_includes_regular_and_pos_approved_dispatches_and_excludes_pending_rejected_and_blank_sns(): void
    {
        $product = $this->makeProduct($this->setting, $this->category, $this->unit, [
            'product_name' => 'Alpha Monitor',
        ]);

        // Regular sale with partial approved dispatch + pending + rejected
        $regularSale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, [
            'date' => '2026-10-15',
        ]);
        $regDetail = $this->makeSaleDetail($regularSale, $product, ['quantity' => 3]);

        $approvedDispatch = $this->makeDispatch($regularSale, Dispatch::STATUS_APPROVED);
        $this->makeDispatchDetail($approvedDispatch, $regularSale, $regDetail, $product, ['SN-REG-1', '', '   ']);

        $pendingDispatch = $this->makeDispatch($regularSale, Dispatch::STATUS_PENDING);
        $this->makeDispatchDetail($pendingDispatch, $regularSale, $regDetail, $product, ['SN-PENDING']);

        $rejectedDispatch = $this->makeDispatch($regularSale, Dispatch::STATUS_REJECTED);
        $this->makeDispatchDetail($rejectedDispatch, $regularSale, $regDetail, $product, ['SN-REJECTED']);

        // POS sale
        $posSale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, [
            'date' => '2026-10-16',
        ]);
        $posDetail = $this->makeSaleDetail($posSale, $product, ['quantity' => 1]);
        $posDispatch = $this->makeDispatch($posSale, Dispatch::STATUS_APPROVED);
        $this->makeDispatchDetail($posDispatch, $posSale, $posDetail, $product, ['000123']);

        $rows = $this->service->project(new SoldSerialNumberReportFilterData(
            startDate: '2026-10-01',
            endDate: '2026-10-31',
            productIds: [$product->id],
            scopeSettingIds: [$this->setting->id],
        ));

        $this->assertCount(2, $rows);
        $this->assertSame([
            'product_name' => 'Alpha Monitor',
            'sn' => 'SN-REG-1',
            'day' => 15,
            'month' => 10,
            'year' => 2026,
        ], $rows[0]);
        $this->assertSame([
            'product_name' => 'Alpha Monitor',
            'sn' => '000123',
            'day' => 16,
            'month' => 10,
            'year' => 2026,
        ], $rows[1]);
    }

    public function test_handles_bundle_overlap_renamed_product_duplicate_sn_across_products_and_ignores_reporting_date(): void
    {
        $bundle = $this->makeProduct($this->setting, $this->category, $this->unit, [
            'product_name' => 'Office Bundle',
        ]);
        $compA = $this->makeProduct($this->setting, $this->category, $this->unit, [
            'product_name' => 'Old Name A',
        ]);
        $compB = $this->makeProduct($this->setting, $this->category, $this->unit, [
            'product_name' => 'Keyboard B',
        ]);

        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, [
            'date' => '2026-10-15',
            'reporting_date' => '2026-11-05',
        ]);
        $sdBundle = $this->makeSaleDetail($sale, $bundle, ['quantity' => 1]);
        $dispatch = $this->makeDispatch($sale, Dispatch::STATUS_APPROVED);

        // Both components share the same SN text across different products
        $this->makeDispatchDetail($dispatch, $sale, $sdBundle, $compA, ['SHARED-01'], ['bundle_id' => $bundle->id]);
        $this->makeDispatchDetail($dispatch, $sale, $sdBundle, $compB, ['SHARED-01'], ['bundle_id' => $bundle->id]);

        // Rename compA after sale
        $compA->update(['product_name' => 'New Name A']);

        // Select both bundle AND compA -> should deduplicate compA occurrence
        $rows = $this->service->project(new SoldSerialNumberReportFilterData(
            startDate: '2026-10-01',
            endDate: '2026-10-31',
            productIds: [$bundle->id, $compA->id],
            scopeSettingIds: [$this->setting->id],
        ));

        $this->assertCount(2, $rows);
        $this->assertSame('Keyboard B', $rows[0]['product_name']);
        $this->assertSame('New Name A', $rows[1]['product_name']);
        $this->assertSame(15, $rows[0]['day']);
        $this->assertSame(10, $rows[0]['month']);
        $this->assertSame(2026, $rows[0]['year']);
    }

    public function test_retains_unreceived_return_and_removes_only_physically_received_partial_return(): void
    {
        $product = $this->makeProduct($this->setting, $this->category, $this->unit, [
            'product_name' => 'Router X',
        ]);

        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, ['date' => '2026-10-10']);
        $sd = $this->makeSaleDetail($sale, $product, ['quantity' => 2]);
        $dispatch = $this->makeDispatch($sale, Dispatch::STATUS_APPROVED, '2026-10-10 10:00:00');
        $this->makeDispatchDetail($dispatch, $sale, $sd, $product, ['SN-A', 'SN-B']);

        // Unreceived return for SN-B (approved, but received_at is null)
        $unreceivedReturn = SaleReturn::create([
            'sale_id' => $sale->id,
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => '2026-10-12',
            'reference' => 'SR-UNREC',
            'status' => 'APPROVED',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'received_at' => null,
        ]);
        $unrecDetail = SaleReturnDetail::create([
            'sale_return_id' => $unreceivedReturn->id,
            'sale_detail_id' => $sd->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        ReturnSerialRegistration::create([
            'claim_uuid' => 'claim-unrec',
            'source_key' => 'src-unrec',
            'source_classification' => 'serial',
            'source_basis' => 'serial',
            'source_fingerprint' => 'fp-unrec',
            'unit_attribution_key' => 'ua-1',
            'workflow' => ReturnSerialRegistration::WORKFLOW_STANDARD,
            'sale_return_id' => $unreceivedReturn->id,
            'sale_return_detail_id' => $unrecDetail->id,
            'product_id' => $product->id,
            'proposed_serial' => 'SN-B',
        ]);

        // Physically received return for SN-A
        $receivedReturn = SaleReturn::create([
            'sale_id' => $sale->id,
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => '2026-10-14',
            'reference' => 'SR-REC',
            'status' => 'APPROVED',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'received_at' => '2026-10-14 12:00:00',
        ]);
        $recDetail = SaleReturnDetail::create([
            'sale_return_id' => $receivedReturn->id,
            'sale_detail_id' => $sd->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        ReturnSerialRegistration::create([
            'claim_uuid' => 'claim-rec',
            'source_key' => 'src-rec',
            'source_classification' => 'serial',
            'source_basis' => 'serial',
            'source_fingerprint' => 'fp-rec',
            'unit_attribution_key' => 'ua-rec',
            'workflow' => ReturnSerialRegistration::WORKFLOW_STANDARD,
            'sale_return_id' => $receivedReturn->id,
            'sale_return_detail_id' => $recDetail->id,
            'product_id' => $product->id,
            'proposed_serial' => 'SN-A',
        ]);

        $rows = $this->service->project(new SoldSerialNumberReportFilterData(
            startDate: '2026-10-01',
            endDate: '2026-10-31',
            productIds: [$product->id],
            scopeSettingIds: [$this->setting->id],
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('SN-B', $rows[0]['sn']);
    }

    public function test_pos_replacement_chain_and_cross_owner_suppression(): void
    {
        $product = $this->makeProduct($this->setting, $this->category, $this->unit, [
            'product_name' => 'Printer Z',
        ]);

        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, ['date' => '2026-10-05']);
        $sd = $this->makeSaleDetail($sale, $product, ['quantity' => 1]);
        $d1 = $this->makeDispatch($sale, Dispatch::STATUS_APPROVED, '2026-10-05 09:00:00');
        $dd1 = $this->makeDispatchDetail($d1, $sale, $sd, $product, ['SN-A']);

        $snA = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-A',
            'status' => 'AVAILABLE',
        ]);
        $snB = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-B',
            'status' => 'AVAILABLE',
        ]);

        // SN-A received back
        SerialNumberHistory::create([
            'product_serial_number_id' => $snA->id,
            'user_id' => 1,
            'event_type' => SerialNumberHistory::EVENT_SALE_RETURNED,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'created_at' => '2026-10-06 10:00:00',
        ]);

        // Replacement B dispatched in November (outside Oct filter) on a separate replacement-owner sale
        $repSale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, ['date' => '2026-10-05']);
        $repSd = $this->makeSaleDetail($repSale, $product, ['quantity' => 1]);
        $d2 = $this->makeDispatch($repSale, Dispatch::STATUS_APPROVED, '2026-11-02 10:00:00');
        $dd2 = $this->makeDispatchDetail($d2, $repSale, $repSd, $product, ['SN-B'], [
            'replacement_of_dispatch_detail_id' => $dd1->id,
            'replacement_returned_serial_id' => $snA->id,
        ]);

        // SN-B also received back and replaced by SN-C
        SerialNumberHistory::create([
            'product_serial_number_id' => $snB->id,
            'user_id' => 1,
            'event_type' => SerialNumberHistory::EVENT_SALE_RETURNED,
            'reference_type' => Sale::class,
            'reference_id' => $repSale->id,
            'created_at' => '2026-11-05 10:00:00',
        ]);
        $d3 = $this->makeDispatch($repSale, Dispatch::STATUS_APPROVED, '2026-11-06 10:00:00');
        $this->makeDispatchDetail($d3, $repSale, $repSd, $product, ['SN-C'], [
            'replacement_of_dispatch_detail_id' => $dd2->id,
            'replacement_returned_serial_id' => $snB->id,
        ]);

        $rows = $this->service->project(new SoldSerialNumberReportFilterData(
            startDate: '2026-10-01',
            endDate: '2026-10-31',
            productIds: [$product->id],
            scopeSettingIds: [$this->setting->id],
        ));

        // Only 1 row (no duplicate root from repSale), showing SN-C with original Oct 5 date
        $this->assertCount(1, $rows);
        $this->assertSame('SN-C', $rows[0]['sn']);
        $this->assertSame(5, $rows[0]['day']);
        $this->assertSame(10, $rows[0]['month']);
        $this->assertSame(2026, $rows[0]['year']);
    }

    public function test_standard_settlement_replacement_and_ordinary_resale(): void
    {
        $product = $this->makeProduct($this->setting, $this->category, $this->unit, [
            'product_name' => 'Scanner S',
        ]);

        // Sale 1: SN-100 sold Oct 1, returned Oct 2, replaced by SN-200 via standard settlement
        $sale1 = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, ['date' => '2026-10-01']);
        $sd1 = $this->makeSaleDetail($sale1, $product, ['quantity' => 1]);
        $d1 = $this->makeDispatch($sale1, Dispatch::STATUS_APPROVED, '2026-10-01 09:00:00');
        $this->makeDispatchDetail($d1, $sale1, $sd1, $product, ['SN-100']);

        $sn100 = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-100',
            'status' => 'AVAILABLE',
        ]);

        $sr1 = SaleReturn::create([
            'sale_id' => $sale1->id,
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => '2026-10-02',
            'reference' => 'SR-1',
            'status' => 'APPROVED',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'received_at' => '2026-10-02 10:00:00',
        ]);
        $srd1 = SaleReturnDetail::create([
            'sale_return_id' => $sr1->id,
            'sale_detail_id' => $sd1->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        ReturnSerialRegistration::create([
            'claim_uuid' => 'claim-100',
            'source_key' => 'src-100',
            'source_classification' => 'serial',
            'source_basis' => 'serial',
            'source_fingerprint' => 'fp-unrec',
            'unit_attribution_key' => 'ua-1',
            'workflow' => ReturnSerialRegistration::WORKFLOW_STANDARD,
            'sale_return_id' => $sr1->id,
            'sale_return_detail_id' => $srd1->id,
            'product_id' => $product->id,
            'registered_serial_id' => $sn100->id,
            'proposed_serial' => 'SN-100',
        ]);
        SaleReturnItemSettlement::create([
            'sale_return_id' => $sr1->id,
            'sale_return_detail_id' => $srd1->id,
            'product_serial_number_id' => $sn100->id,
            'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
            'dispatch_kind' => 'REPLACEMENT',
            'dispatched_serial_number' => 'SN-200',
        ]);

        // Sale 2: SN-100 resold on Oct 20 (new ordinary sale)
        $sale2 = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, ['date' => '2026-10-20']);
        $sd2 = $this->makeSaleDetail($sale2, $product, ['quantity' => 1]);
        $d2 = $this->makeDispatch($sale2, Dispatch::STATUS_APPROVED, '2026-10-20 09:00:00');
        $this->makeDispatchDetail($d2, $sale2, $sd2, $product, ['SN-100']);

        $rows = $this->service->project(new SoldSerialNumberReportFilterData(
            startDate: '2026-10-01',
            endDate: '2026-10-31',
            productIds: [$product->id],
            scopeSettingIds: [$this->setting->id],
        ));

        $this->assertCount(2, $rows);
        $this->assertSame('SN-200', $rows[0]['sn']);
        $this->assertSame(1, $rows[0]['day']);
        $this->assertSame('SN-100', $rows[1]['sn']);
        $this->assertSame(20, $rows[1]['day']);
    }
}

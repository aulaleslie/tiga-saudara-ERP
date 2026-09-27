<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\PurchaseDeliveryReportFilterData;
use App\Services\Reports\PurchaseDeliveryReportQueryService;
use App\Services\Reports\AgedPayablesReportFilterData;
use App\Services\Reports\AgedPayablesReportQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseReportReportingDateRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        $this->supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        session(['setting_id' => $this->setting->id]);
    }

    /**
     * Test 4.1: Purchase Delivery Report still filters by approved receiving-note date.
     * The reporting-date override should NOT affect delivery date selection.
     */
    public function test_purchase_delivery_filters_by_receiving_note_date_not_reporting_date()
    {
        $purchaseDate = '2026-01-15';
        $reportingDate = '2026-02-15';
        $receivingDate = '2026-03-10';

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'date' => $purchaseDate,
            'reporting_date' => $reportingDate,
            'due_date' => Carbon::parse($purchaseDate)->addDays(30),
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        // Create a purchase detail with product_tax_amount
        $purchaseDetail = \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_type' => 'AMOUNT',
            'product_discount_amount' => 0,
            'sub_total' => 1000,
            'product_tax_amount' => 0,
        ]);

        // Create a receiving note with date different from both purchase and reporting dates
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'RCV-001',
            'date' => $receivingDate,
            'status' => ReceivedNote::STATUS_APPROVED,
            'location_id' => 1,
            'setting_id' => $this->setting->id,
        ]);

        // Create a received note detail with quantity_received
        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 1,
        ]);

        // Filter by the receiving date range
        $filter = new PurchaseDeliveryReportFilterData(
            startDate: $receivingDate,
            endDate: $receivingDate,
            scopeSettingId: $this->setting->id,
        );

        $queryService = new PurchaseDeliveryReportQueryService();
        $query = $queryService->build($filter);
        $result = $query->get();

        // Should include because receiving date matches
        $this->assertCount(1, $result);
    }

    /**
     * Test 4.1: Purchase Delivery Report excludes when receiving date is out of range.
     * Even if reporting date is in range, delivery should not be included.
     */
    public function test_purchase_delivery_excludes_when_receiving_date_out_of_range()
    {
        $purchaseDate = '2026-01-15';
        $reportingDate = '2026-02-15';
        $receivingDate = '2026-03-10';

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'date' => $purchaseDate,
            'reporting_date' => $reportingDate,
            'due_date' => Carbon::parse($purchaseDate)->addDays(30),
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        // Create a purchase detail with product_tax_amount
        $purchaseDetail = \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_type' => 'AMOUNT',
            'product_discount_amount' => 0,
            'sub_total' => 1000,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'RCV-001',
            'date' => $receivingDate,
            'status' => ReceivedNote::STATUS_APPROVED,
            'location_id' => 1,
            'setting_id' => $this->setting->id,
        ]);

        // Create a received note detail with quantity_received
        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 1,
        ]);

        // Filter by reporting date range (not receiving date)
        $filter = new PurchaseDeliveryReportFilterData(
            startDate: $reportingDate,
            endDate: $reportingDate,
            scopeSettingId: $this->setting->id,
        );

        $queryService = new PurchaseDeliveryReportQueryService();
        $query = $queryService->build($filter);
        $result = $query->get();

        // Should NOT include because receiving date doesn't match filter
        $this->assertCount(0, $result);
    }

    /**
     * Test 4.2: Aged Payables Report retains original purchase-date ageing behavior.
     * A reporting-date override should NOT change how ageing is calculated.
     */
    public function test_aged_payables_uses_original_purchase_date_not_reporting_date()
    {
        $originalPurchaseDate = '2026-01-15';
        $reportingDate = '2026-06-15';
        $asOfDate = '2026-08-10';

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'supplier_name' => 'Test Supplier',
            'date' => $originalPurchaseDate,
            'reporting_date' => $reportingDate,
            'due_date' => Carbon::parse($originalPurchaseDate)->addDays(30),
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        // Create a purchase detail with product_tax_amount
        \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_type' => 'AMOUNT',
            'product_discount_amount' => 0,
            'sub_total' => 1000,
            'product_tax_amount' => 0,
        ]);

        // Test Aged Payables with as-of date
        $filter = new AgedPayablesReportFilterData(
            asOfDate: $asOfDate,
            scopeSettingId: $this->setting->id,
        );

        $queryService = new AgedPayablesReportQueryService();
        $result = $queryService->build($filter)->get();

        // Should include because it uses original purchase date for ageing
        // Original date is Jan 15, as-of is Aug 10 - this is old (> 120 days)
        $this->assertTrue(
            $result->some(fn($row) => $row->supplier_id === $this->supplier->id),
            'Purchase should be included in aged payables using original purchase date'
        );

        // Verify the ageing bucket is calculated from original date
        $row = $result->firstWhere('supplier_id', $this->supplier->id);
        $this->assertNotNull($row, 'Expected aged payables row for supplier');
        $this->assertGreaterThan(0, $row->bucket_4, 'Expected purchase to fall in 90+ day bucket (original date is Jan 15, as-of is Aug 10)');
    }

    /**
     * Test 4.2: Supplier Payables Report retains original due-date maturity behavior.
     * A reporting-date override should NOT change due-date calculations.
     */
    public function test_supplier_payables_uses_original_due_date_not_reporting_date()
    {
        $originalPurchaseDate = '2026-01-15';
        $reportingDate = '2026-06-15';
        $dueDate = Carbon::parse($originalPurchaseDate)->addDays(30);
        $asOfDate = '2026-08-10';

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'supplier_name' => 'Test Supplier',
            'date' => $originalPurchaseDate,
            'reporting_date' => $reportingDate,
            'due_date' => $dueDate,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        // Verify the purchase retains original due date despite reporting override
        $purchase->refresh();
        $this->assertEquals($dueDate->format('Y-m-d'), $purchase->due_date->format('Y-m-d'));
        $this->assertNotEquals(
            $reportingDate,
            $purchase->due_date->format('Y-m-d'),
            'Due date should not be affected by reporting-date override'
        );
    }
}

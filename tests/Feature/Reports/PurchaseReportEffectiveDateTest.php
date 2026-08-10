<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\PurchaseReportFilterData;
use App\Services\Reports\PurchaseReportQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseReportEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;
    private PurchaseReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        $this->supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        $this->service = new PurchaseReportQueryService();
        session(['setting_id' => $this->setting->id]);
    }

    public function test_includes_purchase_by_active_reporting_date_when_not_original_date()
    {
        $originalDate = '2026-01-15';
        $reportingDate = '2026-02-15';

        Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'date' => $originalDate,
            'reporting_date' => $reportingDate,
            'due_date' => Carbon::parse($originalDate)->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $filter = new PurchaseReportFilterData(
            startDate: $reportingDate,
            endDate: $reportingDate,
            scopeSettingId: $this->setting->id,
            reportMode: 'header',
        );

        $query = $this->service->build($filter);
        $result = $query->get();

        $this->assertCount(1, $result);
        $this->assertEquals($reportingDate, $result[0]->effective_date->format('Y-m-d'));
    }

    public function test_excludes_purchase_when_date_range_contains_original_but_not_reporting_date()
    {
        $originalDate = '2026-01-15';
        $reportingDate = '2026-02-15';

        Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'date' => $originalDate,
            'reporting_date' => $reportingDate,
            'due_date' => Carbon::parse($originalDate)->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $filter = new PurchaseReportFilterData(
            startDate: $originalDate,
            endDate: $originalDate,
            reportMode: 'header',
            scopeSettingId: $this->setting->id,
        );

        $query = $this->service->build($filter);
        $result = $query->get();

        $this->assertCount(0, $result);
    }

    public function test_displays_effective_date_in_header_mode()
    {
        $originalDate = '2026-01-15';
        $reportingDate = '2026-02-15';

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'date' => $originalDate,
            'reporting_date' => $reportingDate,
            'due_date' => Carbon::parse($originalDate)->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $mapped = PurchaseReportQueryService::mapRow($purchase, 'header');

        $this->assertEquals('15/02/2026', $mapped['Tanggal']);
    }

    public function test_displays_original_date_when_no_override_in_header_mode()
    {
        $originalDate = '2026-01-15';

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'date' => $originalDate,
            'due_date' => Carbon::parse($originalDate)->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $mapped = PurchaseReportQueryService::mapRow($purchase, 'header');

        $this->assertEquals('15/01/2026', $mapped['Tanggal']);
    }

    public function test_cleared_override_uses_original_date_for_filtering()
    {
        $originalDate = '2026-01-15';
        $reportingDate = '2026-02-15';

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'date' => $originalDate,
            'reporting_date' => $reportingDate,
            'due_date' => Carbon::parse($originalDate)->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $purchase->update(['reporting_date' => null]);

        $filter = new PurchaseReportFilterData(
            startDate: $originalDate,
            endDate: $originalDate,
            reportMode: 'header',
            scopeSettingId: $this->setting->id,
        );

        $query = $this->service->build($filter);
        $result = $query->get();

        $this->assertCount(1, $result);
        $this->assertEquals($originalDate, $result[0]->effective_date->format('Y-m-d'));
    }

    public function test_replacement_override_updates_report_date()
    {
        $originalDate = '2026-01-15';
        $firstReportingDate = '2026-02-15';
        $secondReportingDate = '2026-03-15';

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'date' => $originalDate,
            'reporting_date' => $firstReportingDate,
            'due_date' => Carbon::parse($originalDate)->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        // Filter matches first reporting date
        $filter1 = new PurchaseReportFilterData(
            startDate: $firstReportingDate,
            endDate: $firstReportingDate,
            reportMode: 'header',
            scopeSettingId: $this->setting->id,
        );

        $query1 = $this->service->build($filter1);
        $this->assertCount(1, $query1->get());

        // Replace the override
        $purchase->update(['reporting_date' => $secondReportingDate]);

        // First date range no longer matches
        $query1 = $this->service->build($filter1);
        $this->assertCount(0, $query1->get());

        // Second date range matches
        $filter2 = new PurchaseReportFilterData(
            startDate: $secondReportingDate,
            endDate: $secondReportingDate,
            reportMode: 'header',
            scopeSettingId: $this->setting->id,
        );

        $query2 = $this->service->build($filter2);
        $this->assertCount(1, $query2->get());
    }

    public function test_sorts_by_effective_date()
    {
        $date1 = '2026-01-15';
        $date2 = '2026-01-10';
        $reportingDate2 = '2026-02-20';

        $purchase1 = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'date' => $date1,
            'due_date' => Carbon::parse($date1)->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $purchase2 = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-002',
            'date' => $date2,
            'reporting_date' => $reportingDate2,
            'due_date' => Carbon::parse($date2)->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $filter = new PurchaseReportFilterData(
            startDate: '2026-01-01',
            endDate: '2026-02-28',
            reportMode: 'header',
            scopeSettingId: $this->setting->id,
        );

        $query = $this->service->build($filter);
        $this->service->applySort($query, 'date', 'desc', 'header');

        $result = $query->get();

        $this->assertCount(2, $result);
        $this->assertEquals($reportingDate2, $result[0]->effective_date->format('Y-m-d'));
        $this->assertEquals($date1, $result[1]->effective_date->format('Y-m-d'));
    }

    public function test_due_date_filtering_not_affected_by_reporting_date()
    {
        $originalDate = '2026-01-15';
        $reportingDate = '2026-02-15';
        $dueDate = '2026-03-15';

        Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'date' => $originalDate,
            'reporting_date' => $reportingDate,
            'due_date' => $dueDate,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        // Filter by due_date
        $filter = new PurchaseReportFilterData(
            startDate: $dueDate,
            endDate: $dueDate,
            dateBasis: 'due_date',
            reportMode: 'header',
            scopeSettingId: $this->setting->id,
        );

        $query = $this->service->build($filter);
        $result = $query->get();

        $this->assertCount(1, $result);
        $this->assertEquals($dueDate, $result[0]->due_date->format('Y-m-d'));
    }
}

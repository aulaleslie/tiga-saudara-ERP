<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\Concerns\EffectivePurchaseReportingDate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class EffectivePurchaseReportingDateTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        $this->supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
    }

    public function test_sql_expression_returns_coalesce_formula()
    {
        $expression = EffectivePurchaseReportingDate::sqlExpression('purchases');

        $this->assertStringContainsString('DATE', $expression);
        $this->assertStringContainsString('COALESCE', $expression);
        $this->assertStringContainsString('purchases.reporting_date', $expression);
        $this->assertStringContainsString('purchases.date', $expression);
    }

    public function test_sql_expression_with_custom_alias()
    {
        $expression = EffectivePurchaseReportingDate::sqlExpression('p');

        $this->assertStringContainsString('p.reporting_date', $expression);
        $this->assertStringContainsString('p.date', $expression);
    }

    public function test_absent_override_uses_original_date()
    {
        $originalDate = Carbon::parse('2026-01-15');
        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-001',
            'supplier_name' => 'Test Supplier',
            'date' => $originalDate,
            'due_date' => $originalDate->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $this->assertNull($purchase->reporting_date);
        $this->assertEquals($originalDate, $purchase->effective_date);
    }

    public function test_active_override_uses_reporting_date()
    {
        $originalDate = Carbon::parse('2026-01-15');
        $reportingDate = Carbon::parse('2026-02-15');

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-002',
            'supplier_name' => 'Test Supplier',
            'date' => $originalDate,
            'reporting_date' => $reportingDate,
            'due_date' => $originalDate->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $this->assertEquals($reportingDate, $purchase->reporting_date);
        $this->assertEquals($reportingDate, $purchase->effective_date);
    }

    public function test_cleared_override_restores_original_date()
    {
        $originalDate = Carbon::parse('2026-01-15');
        $reportingDate = Carbon::parse('2026-02-15');

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-003',
            'supplier_name' => 'Test Supplier',
            'date' => $originalDate,
            'reporting_date' => $reportingDate,
            'due_date' => $originalDate->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        // Clear the override
        $purchase->update(['reporting_date' => null]);

        $this->assertNull($purchase->reporting_date);
        $this->assertEquals($originalDate, $purchase->effective_date);
    }

    public function test_replacement_override_updates_effective_date()
    {
        $originalDate = Carbon::parse('2026-01-15');
        $firstReportingDate = Carbon::parse('2026-02-15');
        $secondReportingDate = Carbon::parse('2026-03-15');

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'TEST-004',
            'supplier_name' => 'Test Supplier',
            'date' => $originalDate,
            'reporting_date' => $firstReportingDate,
            'due_date' => $originalDate->addDays(30),
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $this->assertEquals($firstReportingDate, $purchase->effective_date);

        // Replace the override
        $purchase->update(['reporting_date' => $secondReportingDate]);

        $this->assertEquals($secondReportingDate, $purchase->effective_date);
    }
}

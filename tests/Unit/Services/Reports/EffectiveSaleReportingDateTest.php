<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\Concerns\EffectiveSaleReportingDate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class EffectiveSaleReportingDateTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
    }

    public function test_sql_expression_returns_coalesce_formula()
    {
        $expression = EffectiveSaleReportingDate::sqlExpression('sales');

        $this->assertStringContainsString('DATE', $expression);
        $this->assertStringContainsString('COALESCE', $expression);
        $this->assertStringContainsString('sales.reporting_date', $expression);
        $this->assertStringContainsString('sales.date', $expression);
    }

    public function test_sql_expression_with_custom_alias()
    {
        $expression = EffectiveSaleReportingDate::sqlExpression('s');

        $this->assertStringContainsString('s.reporting_date', $expression);
        $this->assertStringContainsString('s.date', $expression);
    }

    public function test_absent_override_uses_original_date()
    {
        $originalDate = Carbon::parse('2026-01-15');
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'reference' => 'TEST-001',
            'customer_name' => 'Test Customer',
            'date' => $originalDate,
            'due_date' => $originalDate->copy()->addDays(30),
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $this->assertNull($sale->reporting_date);
        $this->assertEquals($originalDate, $sale->effective_date);
    }

    public function test_active_override_uses_reporting_date()
    {
        $originalDate = Carbon::parse('2026-01-15');
        $reportingDate = Carbon::parse('2026-02-15');

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'reference' => 'TEST-002',
            'customer_name' => 'Test Customer',
            'date' => $originalDate,
            'reporting_date' => $reportingDate,
            'due_date' => $originalDate->copy()->addDays(30),
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $this->assertEquals($reportingDate, $sale->reporting_date);
        $this->assertEquals($reportingDate, $sale->effective_date);
    }

    public function test_cleared_override_restores_original_date()
    {
        $originalDate = Carbon::parse('2026-01-15');
        $reportingDate = Carbon::parse('2026-02-15');

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'reference' => 'TEST-003',
            'customer_name' => 'Test Customer',
            'date' => $originalDate,
            'reporting_date' => $reportingDate,
            'due_date' => $originalDate->copy()->addDays(30),
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        // Clear the override
        $sale->update(['reporting_date' => null]);

        $this->assertNull($sale->reporting_date);
        $this->assertEquals($originalDate, $sale->effective_date);
    }

    public function test_replacement_override_updates_effective_date()
    {
        $originalDate = Carbon::parse('2026-01-15');
        $firstReportingDate = Carbon::parse('2026-02-15');
        $secondReportingDate = Carbon::parse('2026-03-15');

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'reference' => 'TEST-004',
            'customer_name' => 'Test Customer',
            'date' => $originalDate,
            'reporting_date' => $firstReportingDate,
            'due_date' => $originalDate->copy()->addDays(30),
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        $this->assertEquals($firstReportingDate, $sale->effective_date);

        // Replace the override
        $sale->update(['reporting_date' => $secondReportingDate]);

        $this->assertEquals($secondReportingDate, $sale->effective_date);
    }
}

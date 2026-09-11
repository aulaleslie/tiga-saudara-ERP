<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\CustomerReceivablesReportFilterData;
use App\Services\Reports\CustomerReceivablesReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class CustomerReceivablesReportQueryServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Customer $customer;
    private CustomerReceivablesReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->customer = $this->makeCustomer($this->setting);
        $this->service = new CustomerReceivablesReportQueryService();

        session(['setting_id' => $this->setting->id]);
    }

    private function baseFilter(array $overrides = []): CustomerReceivablesReportFilterData
    {
        return CustomerReceivablesReportFilterData::fromArray(array_merge([
            'endDate' => '2026-01-31',
            'scopeSettingId' => $this->setting->id,
        ], $overrides));
    }

    public function test_eligible_sale_with_unpaid_balance_appears()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, [
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);

        $ids = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertContains($sale->id, $ids);
    }

    public function test_ineligible_partial_fulfillment_sale_is_excluded()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED_PARTIALLY, null, [
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);

        $ids = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertNotContains($sale->id, $ids);
    }

    public function test_sisa_piutang_reflects_active_payments_as_of_end_date()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, [
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 400,
            'date' => '2026-01-10',
            'reference' => 'PAY-1',
            'payment_method' => 'CASH',
            'status' => 'ACTIVE',
        ]);

        $row = $this->service->build($this->baseFilter())->get()->firstWhere('id', $sale->id);

        $this->assertNotNull($row);
        $this->assertEquals(600.0, (float) $row->sisa_piutang);
    }

    public function test_fully_paid_sale_is_excluded_by_balance_filter()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, [
            'total_amount' => 1000,
            'due_amount' => 0,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 1000,
            'date' => '2026-01-10',
            'reference' => 'PAY-2',
            'payment_method' => 'CASH',
            'status' => 'ACTIVE',
        ]);

        $ids = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertNotContains($sale->id, $ids);
    }
}

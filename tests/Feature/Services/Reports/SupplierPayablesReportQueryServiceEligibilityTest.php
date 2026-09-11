<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\SupplierPayablesReportFilterData;
use App\Services\Reports\SupplierPayablesReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class SupplierPayablesReportQueryServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Supplier $supplier;
    private SupplierPayablesReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->supplier = $this->makeSupplier($this->setting);
        $this->service = new SupplierPayablesReportQueryService();

        session(['setting_id' => $this->setting->id]);
    }

    private function baseFilter(array $overrides = []): SupplierPayablesReportFilterData
    {
        return SupplierPayablesReportFilterData::fromArray(array_merge([
            'endDate' => '2026-01-31',
            'scopeSettingId' => $this->setting->id,
        ], $overrides));
    }

    public function test_eligible_purchase_with_unpaid_balance_appears()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED, null, [
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);

        $ids = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertContains($purchase->id, $ids);
    }

    public function test_ineligible_partial_fulfillment_purchase_is_excluded()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED_PARTIALLY, null, [
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);

        $ids = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertNotContains($purchase->id, $ids);
    }

    public function test_saldo_reflects_active_payments_as_of_end_date()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED, null, [
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 400,
            'date' => '2026-01-10',
            'reference' => 'PAY-1',
            'payment_method' => 'CASH',
            'status' => 'ACTIVE',
        ]);

        $row = $this->service->build($this->baseFilter())->get()->firstWhere('id', $purchase->id);

        $this->assertNotNull($row);
        $this->assertEquals(600.0, (float) $row->saldo);
    }

    public function test_fully_paid_purchase_is_excluded_by_balance_filter()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED, null, [
            'total_amount' => 1000,
            'due_amount' => 0,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 1000,
            'date' => '2026-01-10',
            'reference' => 'PAY-2',
            'payment_method' => 'CASH',
            'status' => 'ACTIVE',
        ]);

        $ids = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertNotContains($purchase->id, $ids);
    }
}

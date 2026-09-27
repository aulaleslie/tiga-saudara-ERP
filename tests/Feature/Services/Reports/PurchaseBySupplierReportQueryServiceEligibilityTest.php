<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\PurchaseBySupplierReportFilterData;
use App\Services\Reports\PurchaseBySupplierReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class PurchaseBySupplierReportQueryServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Supplier $supplier;
    private Category $category;
    private Unit $unit;
    private PurchaseBySupplierReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->supplier = $this->makeSupplier($this->setting);
        $this->category = $this->makeCategory($this->setting);
        $this->unit = $this->makeUnit($this->setting);
        $this->service = new PurchaseBySupplierReportQueryService();

        session(['setting_id' => $this->setting->id]);
    }

    private function baseFilter(array $overrides = []): PurchaseBySupplierReportFilterData
    {
        return PurchaseBySupplierReportFilterData::fromArray(array_merge([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'scopeSettingId' => $this->setting->id,
        ], $overrides));
    }

    public function test_eligible_purchase_detail_is_included()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $detail = $this->makePurchaseDetail($purchase, $product);

        $ids = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertContains($detail->id, $ids);
    }

    public function test_partial_fulfillment_purchase_is_excluded()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED_PARTIALLY);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $detail = $this->makePurchaseDetail($purchase, $product);

        $ids = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertNotContains($detail->id, $ids);
    }
}

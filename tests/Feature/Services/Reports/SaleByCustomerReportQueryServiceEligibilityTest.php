<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\SaleByCustomerReportFilterData;
use App\Services\Reports\SaleByCustomerReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class SaleByCustomerReportQueryServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Customer $customer;
    private Category $category;
    private Unit $unit;
    private SaleByCustomerReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->customer = $this->makeCustomer($this->setting);
        $this->category = $this->makeCategory($this->setting);
        $this->unit = $this->makeUnit($this->setting);
        $this->service = new SaleByCustomerReportQueryService();

        session(['setting_id' => $this->setting->id]);
    }

    private function baseFilter(array $overrides = []): SaleByCustomerReportFilterData
    {
        return SaleByCustomerReportFilterData::fromArray(array_merge([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'scopeSettingId' => $this->setting->id,
        ], $overrides));
    }

    public function test_eligible_sale_detail_is_included()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $detail = $this->makeSaleDetail($sale, $product);

        $ids = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertContains($detail->id, $ids);
    }

    public function test_partial_fulfillment_sale_is_excluded()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED_PARTIALLY);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $detail = $this->makeSaleDetail($sale, $product);

        $ids = $this->service->build($this->baseFilter())->pluck('id')->all();

        $this->assertNotContains($detail->id, $ids);
    }
}

<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\SaleByProductReportFilterData;
use App\Services\Reports\SaleByProductReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class SaleByProductReportQueryServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Customer $customer;
    private Category $category;
    private Unit $unit;
    private SaleByProductReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->customer = $this->makeCustomer($this->setting);
        $this->category = $this->makeCategory($this->setting);
        $this->unit = $this->makeUnit($this->setting);
        $this->service = new SaleByProductReportQueryService();

        session(['setting_id' => $this->setting->id]);
    }

    private function baseFilter(array $overrides = []): SaleByProductReportFilterData
    {
        return SaleByProductReportFilterData::fromArray(array_merge([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'scopeSettingIds' => [$this->setting->id],
        ], $overrides));
    }

    public function test_eligible_sale_is_included_in_product_aggregate()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $this->makeSaleDetail($sale, $product, ['sub_total' => 250, 'quantity' => 5]);

        $rows = $this->service->build($this->baseFilter())->get();

        $row = $rows->firstWhere('product_id', $product->id);
        $this->assertNotNull($row);
        $this->assertEquals(5, (int) $row->sold_quantity);
    }

    public function test_partial_fulfillment_sale_is_excluded_from_product_aggregate()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED_PARTIALLY);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $this->makeSaleDetail($sale, $product, ['sub_total' => 250, 'quantity' => 5]);

        $rows = $this->service->build($this->baseFilter())->get();

        $row = $rows->firstWhere('product_id', $product->id);
        $this->assertNull($row);
    }

    public function test_persisted_sub_total_is_used_directly_without_return_deduction()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_RETURNED_PARTIALLY);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        // sub_total already reflects the post-settlement (reduced) persisted value.
        $this->makeSaleDetail($sale, $product, ['sub_total' => 175, 'quantity' => 3, 'product_tax_amount' => 0]);

        $rows = $this->service->build($this->baseFilter())->get();

        $row = $rows->firstWhere('product_id', $product->id);
        $this->assertNotNull($row);
        $this->assertEquals(175.0, (float) $row->sold_value);
    }
}

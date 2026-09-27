<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\PurchaseByProductReportFilterData;
use App\Services\Reports\PurchaseByProductReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class PurchaseByProductReportQueryServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Supplier $supplier;
    private Category $category;
    private Unit $unit;
    private PurchaseByProductReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->supplier = $this->makeSupplier($this->setting);
        $this->category = $this->makeCategory($this->setting);
        $this->unit = $this->makeUnit($this->setting);
        $this->service = new PurchaseByProductReportQueryService();

        session(['setting_id' => $this->setting->id]);
    }

    private function baseFilter(array $overrides = []): PurchaseByProductReportFilterData
    {
        return PurchaseByProductReportFilterData::fromArray(array_merge([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'scopeSettingId' => $this->setting->id,
        ], $overrides));
    }

    public function test_eligible_purchase_is_included_in_product_aggregate()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $this->makePurchaseDetail($purchase, $product, ['sub_total' => 250, 'quantity' => 5]);

        $rows = $this->service->build($this->baseFilter())->get();

        $row = $rows->firstWhere('product_id', $product->id);
        $this->assertNotNull($row);
        $this->assertEquals(5, (int) $row->purchase_quantity);
    }

    public function test_partial_fulfillment_purchase_is_excluded_from_product_aggregate()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED_PARTIALLY);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $this->makePurchaseDetail($purchase, $product, ['sub_total' => 250, 'quantity' => 5]);

        $rows = $this->service->build($this->baseFilter())->get();

        $row = $rows->firstWhere('product_id', $product->id);
        $this->assertNull($row);
    }

    public function test_persisted_sub_total_is_used_directly_without_return_deduction()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RETURNED_PARTIALLY);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        // sub_total already reflects the post-settlement (reduced) persisted value.
        $this->makePurchaseDetail($purchase, $product, ['sub_total' => 175, 'quantity' => 3, 'product_tax_amount' => 0]);

        $rows = $this->service->build($this->baseFilter())->get();

        $row = $rows->firstWhere('product_id', $product->id);
        $this->assertNotNull($row);
        $this->assertEquals(175.0, (float) $row->purchase_value);
    }
}

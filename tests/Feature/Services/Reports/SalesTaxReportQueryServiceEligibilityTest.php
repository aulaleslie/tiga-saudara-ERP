<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\SalesTaxReportFilterData;
use App\Services\Reports\SalesTaxReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class SalesTaxReportQueryServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Customer $customer;
    private Supplier $supplier;
    private Category $category;
    private Unit $unit;
    private Tax $tax;
    private SalesTaxReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->customer = $this->makeCustomer($this->setting);
        $this->supplier = $this->makeSupplier($this->setting);
        $this->category = $this->makeCategory($this->setting);
        $this->unit = $this->makeUnit($this->setting);
        $this->tax = $this->makeTax(10);
        $this->service = new SalesTaxReportQueryService();

        session(['setting_id' => $this->setting->id]);
    }

    private function baseFilter(array $overrides = []): SalesTaxReportFilterData
    {
        return SalesTaxReportFilterData::fromArray(array_merge([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
            'scopeSettingId' => $this->setting->id,
        ], $overrides));
    }

    public function test_dispatched_sale_taxable_detail_appears_under_penjualan()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $this->makeSaleDetail($sale, $product, [
            'tax_id' => $this->tax->id,
            'product_tax_amount' => 10,
            'sub_total' => 110,
        ]);

        $rows = $this->service->build($this->baseFilter());

        $penjualanRow = $rows->firstWhere('transaction_type', 'Penjualan');
        $this->assertNotNull($penjualanRow);
        $this->assertEquals(10.0, (float) $penjualanRow->total_tax);
    }

    public function test_received_purchase_taxable_detail_appears_under_pembelian()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $this->makePurchaseDetail($purchase, $product, [
            'tax_id' => $this->tax->id,
            'product_tax_amount' => 10,
            'sub_total' => 110,
        ]);

        $rows = $this->service->build($this->baseFilter());

        $pembelianRow = $rows->firstWhere('transaction_type', 'Pembelian');
        $this->assertNotNull($pembelianRow);
        $this->assertEquals(10.0, (float) $pembelianRow->total_tax);
    }

    public function test_dispatched_partially_sale_is_excluded()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED_PARTIALLY);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $this->makeSaleDetail($sale, $product, [
            'tax_id' => $this->tax->id,
            'product_tax_amount' => 10,
            'sub_total' => 110,
        ]);

        $rows = $this->service->build($this->baseFilter());

        $penjualanRow = $rows->firstWhere('transaction_type', 'Penjualan');
        $this->assertNull($penjualanRow);
    }

    public function test_received_partially_purchase_is_excluded()
    {
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED_PARTIALLY);
        $product = $this->makeProduct($this->setting, $this->category, $this->unit);
        $this->makePurchaseDetail($purchase, $product, [
            'tax_id' => $this->tax->id,
            'product_tax_amount' => 10,
            'sub_total' => 110,
        ]);

        $rows = $this->service->build($this->baseFilter());

        $pembelianRow = $rows->firstWhere('transaction_type', 'Pembelian');
        $this->assertNull($pembelianRow);
    }

    public function test_setting_id_scoping_excludes_detail_from_other_setting()
    {
        $otherSetting = $this->makeSetting();
        $otherCustomer = $this->makeCustomer($otherSetting);

        $sale = $this->makeSale($otherSetting, $otherCustomer, Sale::STATUS_DISPATCHED);
        $product = $this->makeProduct($otherSetting, $this->category, $this->unit);
        $this->makeSaleDetail($sale, $product, [
            'tax_id' => $this->tax->id,
            'product_tax_amount' => 10,
            'sub_total' => 110,
        ]);

        $rows = $this->service->build($this->baseFilter());

        $penjualanRow = $rows->firstWhere('transaction_type', 'Penjualan');
        $this->assertNull($penjualanRow);
    }
}

<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\SaleHppAggregateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SalesCostSnapshotService;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Covers the reporting gap flagged after Section 6: same-owner replacement
 * dispatches persist their outgoing HPP only on dispatch_details, so the
 * shared aggregate must fold it in explicitly, restricted to effective
 * (replacement_cost_snapshot_at IS NOT NULL) replacement rows.
 */
class SaleHppAggregateReplacementTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Setting $otherSetting;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $this->setting = Setting::factory()->create(['default_currency_id' => $currency->id]);
        $this->otherSetting = Setting::factory()->create(['default_currency_id' => $currency->id]);
        session(['setting_id' => $this->setting->id]);

        $category = Category::forceCreate([
            'category_name' => 'Cat',
            'category_code' => 'C1',
            'setting_id' => $this->setting->id,
            'created_by' => 1,
        ]);

        $this->product = Product::forceCreate([
            'product_name' => 'Prod',
            'product_code' => 'P1',
            'product_price' => 10000,
            'product_cost' => 5000,
            'category_id' => $category->id,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'product_stock_alert' => 1,
            'setting_id' => $this->setting->id,
        ]);

        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
    }

    protected function createSale(int $totalAmount = 1000): Sale
    {
        return Sale::forceCreate([
            'setting_id' => $this->setting->id,
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => $totalAmount,
            'paid_amount' => $totalAmount,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2023-05-15',
            'reference' => 'S' . uniqid(),
            'customer_name' => 'C',
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ]);
    }

    protected function createSaleDetail(Sale $sale, float $costUnit, int $qty = 1, float $subTotal = 1000): SaleDetails
    {
        return SaleDetails::forceCreate([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => 'Prod',
            'product_code' => 'P1',
            'quantity' => $qty,
            'price' => $subTotal / $qty,
            'unit_price' => $subTotal / $qty,
            'sub_total' => $subTotal,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => $costUnit,
            'cost_total_snapshot' => $costUnit * $qty,
        ]);
    }

    protected function createOriginalDispatchDetail(Sale $sale): DispatchDetail
    {
        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        return DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
        ]);
    }

    protected function createReplacementDispatchDetail(
        Sale $sale,
        DispatchDetail $originalDetail,
        float $replacementUnitCost,
        ?int $snapshotSettingId,
        bool $effective = true
    ): DispatchDetail {
        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        return DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
            'replacement_of_dispatch_detail_id' => $originalDetail->id,
            'replacement_cost_unit_snapshot' => $effective ? $replacementUnitCost : null,
            'replacement_cost_total_snapshot' => $effective ? $replacementUnitCost : null,
            'replacement_cost_snapshot_source' => $effective ? SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE : null,
            'replacement_cost_snapshot_setting_id' => $snapshotSettingId,
            'replacement_cost_snapshot_at' => $effective ? now() : null,
        ]);
    }

    protected function createEffectiveReturnReversal(Sale $sale, SaleDetails $saleDetail, float $unitCost): SaleReturnDetail
    {
        $saleReturn = SaleReturn::forceCreate([
            'date' => now()->toDateString(),
            'reference' => 'SR' . uniqid(),
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'customer_name' => 'C',
            'setting_id' => $this->setting->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
        ]);

        return SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'sale_detail_id' => $saleDetail->id,
            'product_id' => $this->product->id,
            'product_name' => 'Prod',
            'product_code' => 'P1',
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_SALE_DETAIL,
            'cost_unit_snapshot' => $unitCost,
            'cost_quantity' => 1,
            'cost_total_snapshot' => $unitCost,
            'cost_effective_at' => now(),
        ]);
    }

    public function test_net_hpp_equals_original_minus_returned_plus_replacement(): void
    {
        $sale = $this->createSale();
        $saleDetail = $this->createSaleDetail($sale, costUnit: 300); // original HPP 300
        $originalDispatchDetail = $this->createOriginalDispatchDetail($sale);

        $this->createEffectiveReturnReversal($sale, $saleDetail, unitCost: 300); // returned HPP 300
        $this->createReplacementDispatchDetail($sale, $originalDispatchDetail, replacementUnitCost: 450, snapshotSettingId: $this->setting->id); // replacement HPP 450

        $aggregate = new SaleHppAggregateService();
        $totals = $aggregate->totals([$this->setting->id], '2023-05-01', '2023-05-31');

        // net = 300 (original) - 300 (returned) + 450 (replacement) = 450
        $this->assertEquals(450, $totals->hpp);
    }

    public function test_same_owner_replacement_contributes_dispatch_hpp_exactly_once(): void
    {
        $sale = $this->createSale();
        $this->createSaleDetail($sale, costUnit: 0, subTotal: 0); // no parent HPP contribution for this scenario
        $originalDispatchDetail = $this->createOriginalDispatchDetail($sale);

        $this->createReplacementDispatchDetail($sale, $originalDispatchDetail, replacementUnitCost: 500, snapshotSettingId: $this->setting->id);

        $aggregate = new SaleHppAggregateService();
        $totals = $aggregate->totals([$this->setting->id], '2023-05-01', '2023-05-31');

        // Exactly one replacement row exists; must contribute exactly once, not doubled.
        $this->assertEquals(500, $totals->hpp);
    }

    public function test_cross_owner_replacement_sale_details_hpp_is_not_additionally_counted_through_dispatch(): void
    {
        // Cross-owner replacement creates a brand-new Sale/SaleDetails under the
        // replacement owner setting; its DispatchDetail never gets
        // replacement_cost_snapshot_at stamped (only same-owner dispatches do).
        $replacementSale = Sale::forceCreate([
            'setting_id' => $this->otherSetting->id,
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2023-05-15',
            'reference' => 'RS' . uniqid(),
            'customer_name' => 'C',
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ]);

        $replacementDetail = SaleDetails::forceCreate([
            'sale_id' => $replacementSale->id,
            'product_id' => $this->product->id,
            'product_name' => 'Prod',
            'product_code' => 'P1',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 700, // cross-owner replacement's own snapshot
            'cost_total_snapshot' => 700,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $replacementSale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        // Cross-owner DispatchDetail: replacement_of_dispatch_detail_id points at the
        // ORIGINAL (different-Sale) dispatch detail, but replacement_cost_snapshot_at
        // is never stamped for this path.
        $originalSale = $this->createSale();
        $originalDispatchDetail = $this->createOriginalDispatchDetail($originalSale);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $replacementSale->id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
            'replacement_of_dispatch_detail_id' => $originalDispatchDetail->id,
            'replacement_cost_unit_snapshot' => null,
            'replacement_cost_total_snapshot' => null,
            'replacement_cost_snapshot_source' => null,
            'replacement_cost_snapshot_setting_id' => null,
            'replacement_cost_snapshot_at' => null,
        ]);

        $aggregate = new SaleHppAggregateService();
        $totals = $aggregate->totals([$this->otherSetting->id], '2023-05-01', '2023-05-31');

        // Only the replacement Sale's own parent-detail HPP (700) counts; the
        // ineffective (null snapshot) DispatchDetail row contributes nothing extra.
        $this->assertEquals(700, $totals->hpp);
    }

    public function test_replacement_rows_add_no_new_revenue(): void
    {
        $sale = $this->createSale(totalAmount: 1000);
        $saleDetail = $this->createSaleDetail($sale, costUnit: 300, subTotal: 1000);
        $originalDispatchDetail = $this->createOriginalDispatchDetail($sale);

        $this->createReplacementDispatchDetail($sale, $originalDispatchDetail, replacementUnitCost: 400, snapshotSettingId: $this->setting->id);

        $aggregate = new SaleHppAggregateService();
        $totals = $aggregate->totals([$this->setting->id], '2023-05-01', '2023-05-31');

        // DPP still comes solely from sale_details.sub_total; the replacement
        // dispatch never becomes a second revenue source.
        $this->assertEquals(1000, $totals->dpp);
        $this->assertEquals(700, $totals->hpp); // 300 original + 400 replacement
    }

    public function test_ineffective_replacement_row_without_snapshot_timestamp_contributes_zero(): void
    {
        $sale = $this->createSale();
        $this->createSaleDetail($sale, costUnit: 0, subTotal: 0);
        $originalDispatchDetail = $this->createOriginalDispatchDetail($sale);

        // Simulates a retry-in-progress or not-yet-effective row: no snapshot timestamp.
        $this->createReplacementDispatchDetail(
            $sale,
            $originalDispatchDetail,
            replacementUnitCost: 999,
            snapshotSettingId: $this->setting->id,
            effective: false
        );

        $aggregate = new SaleHppAggregateService();
        $totals = $aggregate->totals([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(0, $totals->hpp);
    }

    public function test_retry_does_not_duplicate_outgoing_replacement_hpp(): void
    {
        $sale = $this->createSale();
        $this->createSaleDetail($sale, costUnit: 0, subTotal: 0);
        $originalDispatchDetail = $this->createOriginalDispatchDetail($sale);

        $replacementDetail = $this->createReplacementDispatchDetail(
            $sale,
            $originalDispatchDetail,
            replacementUnitCost: 500,
            snapshotSettingId: $this->setting->id
        );

        $aggregate = new SaleHppAggregateService();
        $firstRun = $aggregate->totals([$this->setting->id], '2023-05-01', '2023-05-31');
        $this->assertEquals(500, $firstRun->hpp);

        // A retried finalize/approval writes to the SAME row idempotently (no new
        // DispatchDetail row is created); simulate by re-saving unchanged values.
        $replacementDetail->forceFill([
            'replacement_cost_unit_snapshot' => 500,
            'replacement_cost_total_snapshot' => 500,
            'replacement_cost_snapshot_at' => now(),
        ])->save();

        $secondRun = $aggregate->totals([$this->setting->id], '2023-05-01', '2023-05-31');
        $this->assertEquals(500, $secondRun->hpp);
    }
}

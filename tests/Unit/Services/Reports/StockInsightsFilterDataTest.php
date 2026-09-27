<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\ProductStockInsightsRow;
use App\Services\Reports\StockBucketData;
use App\Services\Reports\StockInsightsFilterData;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StockInsightsFilterDataTest extends TestCase
{
    public function test_default_filter_data_uses_inclusive_seven_days_and_defaults()
    {
        $fixedToday = Carbon::parse('2026-09-26');
        $filter = new StockInsightsFilterData(today: $fixedToday);

        $this->assertEquals('', $filter->search);
        $this->assertSame([], $filter->statuses);
        $this->assertSame([], $filter->categoryIds);
        $this->assertSame([], $filter->brandIds);
        $this->assertEquals('2026-09-20', $filter->startDate);
        $this->assertEquals('2026-09-26', $filter->endDate);
        $this->assertEquals(7, $filter->durationDays);
        $this->assertEquals('7', $filter->preset);
        $this->assertEquals('7 Hari Terakhir', $filter->getPeriodLabel());
        $this->assertEquals('default', $filter->sortColumn);
        $this->assertEquals('asc', $filter->sortDirection);
        $this->assertEquals(25, $filter->perPage);
        $this->assertEquals(1, $filter->page);
    }

    public function test_presets_30_and_90_days_derive_duration_and_preset_matching()
    {
        $fixedToday = Carbon::parse('2026-09-26');

        // 30 days: startDate = 2026-09-26 - 29 days = 2026-08-28
        $filter30 = new StockInsightsFilterData(startDate: '2026-08-28', today: $fixedToday);
        $this->assertEquals(30, $filter30->durationDays);
        $this->assertEquals('30', $filter30->preset);
        $this->assertEquals('30 Hari Terakhir', $filter30->getPeriodLabel());

        // 90 days: startDate = 2026-09-26 - 89 days = 2026-06-29
        $filter90 = new StockInsightsFilterData(startDate: '2026-06-29', today: $fixedToday);
        $this->assertEquals(90, $filter90->durationDays);
        $this->assertEquals('90', $filter90->preset);
        $this->assertEquals('90 Hari Terakhir', $filter90->getPeriodLabel());
    }

    public function test_custom_start_date_generates_custom_period_label_with_null_preset()
    {
        $fixedToday = Carbon::parse('2026-09-26');

        // 18 days: startDate = 2026-09-26 - 17 days = 2026-09-09
        $filter18 = new StockInsightsFilterData(startDate: '2026-09-09', today: $fixedToday);
        $this->assertEquals(18, $filter18->durationDays);
        $this->assertNull($filter18->preset);
        $this->assertEquals('18 Hari Terakhir', $filter18->getPeriodLabel());

        // Single day (today only: 2026-09-26 to 2026-09-26 = 1 day)
        $filterTodayOnly = new StockInsightsFilterData(startDate: '2026-09-26', today: $fixedToday);
        $this->assertEquals(1, $filterTodayOnly->durationDays);
        $this->assertNull($filterTodayOnly->preset);
        $this->assertEquals('1 Hari Terakhir', $filterTodayOnly->getPeriodLabel());
    }

    public function test_future_start_date_throws_exception()
    {
        $fixedToday = Carbon::parse('2026-09-26');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tanggal mulai tidak boleh lebih dari hari ini.');

        new StockInsightsFilterData(startDate: '2026-09-27', today: $fixedToday);
    }

    public function test_statuses_and_sorting_are_normalized()
    {
        $filter = new StockInsightsFilterData(
            statuses: ['Stok Habis', 'InvalidStatus', 'Perlu Dibeli Lagi', 'Stok Habis'],
            sortColumn: 'UNKNOWN_COLUMN',
            sortDirection: 'INVALID_DIRECTION'
        );

        $this->assertSame(['Stok Habis', 'Perlu Dibeli Lagi'], $filter->statuses);
        $this->assertEquals('default', $filter->sortColumn);
        $this->assertEquals('asc', $filter->sortDirection);
    }

    public function test_stock_bucket_data_and_product_row_aggregation()
    {
        $bucket1 = new StockBucketData(5.0, 3.0, 1.0, 2.0);
        $this->assertEquals(8.0, $bucket1->totalGood);
        $this->assertEquals(3.0, $bucket1->totalBroken);

        $bucket2 = new StockBucketData(2.0, 4.0, 0.0, 1.0);
        $combined = $bucket1->add($bucket2);

        $this->assertEquals(7.0, $combined->taxGood);
        $this->assertEquals(7.0, $combined->nonTaxGood);
        $this->assertEquals(14.0, $combined->totalGood);
        $this->assertEquals(1.0, $combined->taxBroken);
        $this->assertEquals(3.0, $combined->nonTaxBroken);
        $this->assertEquals(4.0, $combined->totalBroken);

        $row = new ProductStockInsightsRow(
            productId: 1,
            productCode: 'PRD-001',
            productName: 'Produk A',
            stockAlert: 5,
            globalStock: $combined,
            isOutOfStock: false,
            isReorderRequired: false,
            isMinimumUnset: false,
            isSlowMoving: true,
            soldQuantity: 12.0,
            salesValue: 120000.0,
            soldCost: 80000.0,
            grossProfit: 40000.0,
            isCostIncomplete: false
        );

        $this->assertSame(['Lama Tidak Terjual'], $row->getActiveStatuses());
        $this->assertEquals(40000.0, $row->grossProfit);
    }
}

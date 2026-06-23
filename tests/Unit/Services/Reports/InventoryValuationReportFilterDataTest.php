<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\InventoryValuationReportFilterData;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

class InventoryValuationReportFilterDataTest extends TestCase
{
    public function test_it_applies_sane_defaults()
    {
        Carbon::setTestNow(Carbon::parse('2023-10-15 14:30:00'));

        $filter = new InventoryValuationReportFilterData();

        $this->assertEquals('2023-10-01 00:00:00', $filter->tanggalAwal->format('Y-m-d H:i:s'));
        $this->assertEquals('2023-10-31 23:59:59', $filter->tanggalAkhir->format('Y-m-d H:i:s'));
        $this->assertEquals([], $filter->categoryIds);
        $this->assertEquals('any', $filter->categoryMatchMode);
        $this->assertEquals([], $filter->productIds);
        $this->assertEquals('product_name', $filter->sortColumn);
        $this->assertEquals('asc', $filter->sortDirection);

        Carbon::setTestNow();
    }

    public function test_it_normalizes_dates_to_start_and_end_of_day()
    {
        $filter = new InventoryValuationReportFilterData(
            Carbon::parse('2023-10-10 12:00:00'),
            Carbon::parse('2023-10-20 12:00:00')
        );

        $this->assertEquals('2023-10-10 00:00:00', $filter->tanggalAwal->format('Y-m-d H:i:s'));
        $this->assertEquals('2023-10-20 23:59:59', $filter->tanggalAkhir->format('Y-m-d H:i:s'));
    }

    public function test_it_validates_category_match_mode()
    {
        $filterAll = new InventoryValuationReportFilterData(null, null, [], 'all');
        $this->assertEquals('all', $filterAll->categoryMatchMode);

        $filterInvalid = new InventoryValuationReportFilterData(null, null, [], 'invalid_mode');
        $this->assertEquals('any', $filterInvalid->categoryMatchMode);
    }

    public function test_it_builds_from_request()
    {
        Carbon::setTestNow(Carbon::parse('2023-10-15 14:30:00'));

        $request = new Request([
            'tanggalAwal' => '2023-10-05',
            'tanggalAkhir' => '2023-10-25',
            'categoryIds' => [1, 2],
            'categoryMatchMode' => 'all',
            'productIds' => [3, 4],
            'sortColumn' => 'stock',
            'sortDirection' => 'desc',
        ]);

        $filter = InventoryValuationReportFilterData::fromRequest($request);

        $this->assertEquals('2023-10-05 00:00:00', $filter->tanggalAwal->format('Y-m-d H:i:s'));
        $this->assertEquals('2023-10-25 23:59:59', $filter->tanggalAkhir->format('Y-m-d H:i:s'));
        $this->assertEquals([1, 2], $filter->categoryIds);
        $this->assertEquals('all', $filter->categoryMatchMode);
        $this->assertEquals([3, 4], $filter->productIds);
        $this->assertEquals('stock', $filter->sortColumn);
        $this->assertEquals('desc', $filter->sortDirection);

        Carbon::setTestNow();
    }

    public function test_it_builds_from_array()
    {
        Carbon::setTestNow(Carbon::parse('2023-10-15 14:30:00'));

        $data = [
            'tanggalAwal' => '2023-10-05',
            'tanggalAkhir' => '2023-10-25',
            'categoryIds' => [1, 2],
            'categoryMatchMode' => 'all',
            'productIds' => [3, 4],
            'sortColumn' => 'stock',
            'sortDirection' => 'desc',
        ];

        $filter = InventoryValuationReportFilterData::fromArray($data);

        $this->assertEquals('2023-10-05 00:00:00', $filter->tanggalAwal->format('Y-m-d H:i:s'));
        $this->assertEquals('2023-10-25 23:59:59', $filter->tanggalAkhir->format('Y-m-d H:i:s'));
        $this->assertEquals([1, 2], $filter->categoryIds);
        $this->assertEquals('all', $filter->categoryMatchMode);
        $this->assertEquals([3, 4], $filter->productIds);
        $this->assertEquals('stock', $filter->sortColumn);
        $this->assertEquals('desc', $filter->sortDirection);

        Carbon::setTestNow();
    }
}

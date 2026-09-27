<?php

namespace Modules\Sale\Tests\Unit;

use Modules\Sale\Jobs\StageSalesImportRows;
use Tests\TestCase;

class StageSalesImportRowsTest extends TestCase
{
    /**
     * Regression test for JL2915: missing harga_satuan should fallback to line_total / quantity.
     */
    public function test_jl2915_fallback_harga_satuan_from_line_total(): void
    {
        $headers = [
            'tanggal' => 'Tanggal',
            'no_faktur' => 'No. Faktur',
            'produk' => 'Produk',
            'kuantitas' => 'Kuantitas',
            'satuan' => 'Satuan',
            'harga_satuan' => 'Harga per Unit',
            'line_total' => 'Jumlah Per Baris',
        ];

        $service = app(\Modules\Sale\Services\SalesImportService::class);

        // Row missing harga_satuan but has line_total and quantity
        $record = [
            'Tanggal' => '2020-10-01',
            'No. Faktur' => 'JL2915',
            'Produk' => 'STIKER NAMA UNDANGAN POLOS (103)',
            'Kuantitas' => '25',
            'Satuan' => 'PCS',
            'Harga per Unit' => '',
            'Jumlah Per Baris' => '60000',
        ];

        $mapped = $service->mapCsvRow($record, $headers);

        $this->assertEquals('2400', $mapped['harga_satuan'], 'Should fallback to 60000 / 25');
    }

}

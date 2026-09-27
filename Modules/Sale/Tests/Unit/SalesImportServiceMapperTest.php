<?php

namespace Modules\Sale\Tests\Unit;

use Modules\Sale\Services\SalesImportService;
use Tests\TestCase;

class SalesImportServiceMapperTest extends TestCase
{
    protected SalesImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SalesImportService::class);
    }

    public function test_normalizes_jumlah_per_baris_to_line_total()
    {
        $rawHeaders = ['Tanggal', 'Customer', 'Jumlah Per Baris'];
        $normalized = $this->service->normalizeHeaders($rawHeaders);

        $this->assertEquals('Tanggal', $normalized['tanggal']);
        $this->assertEquals('Customer', $normalized['customer']);
        $this->assertEquals('Jumlah Per Baris', $normalized['line_total']);
        // The last one overwrites line_total in the mapper if both exist, which is fine, we just test one
        
        $rawHeaders2 = ['Jumlah Kena Pajak per Baris'];
        $normalized2 = $this->service->normalizeHeaders($rawHeaders2);
        $this->assertEquals('Jumlah Kena Pajak per Baris', $normalized2['line_total']);
    }

    public function test_map_csv_row_derives_harga_satuan_from_line_total_when_missing_or_zero()
    {
        $headers = [
            'kuantitas' => 'Qty',
            'harga_satuan' => 'Harga',
            'line_total' => 'Total',
        ];

        // 1. Missing harga_satuan
        $record1 = ['Qty' => '2', 'Harga' => '', 'Total' => '4800'];
        $mapped1 = $this->service->mapCsvRow($record1, $headers);
        $this->assertEquals('2400', $mapped1['harga_satuan']);

        // 2. Zero harga_satuan
        $record2 = ['Qty' => '1.5', 'Harga' => '0', 'Total' => '3000'];
        $mapped2 = $this->service->mapCsvRow($record2, $headers);
        $this->assertEquals('2000', $mapped2['harga_satuan']);

        // 3. Invalid harga_satuan (parses as 0)
        $record3 = ['Qty' => '3', 'Harga' => '-', 'Total' => '9000'];
        $mapped3 = $this->service->mapCsvRow($record3, $headers);
        $this->assertEquals('3000', $mapped3['harga_satuan']);
    }

    public function test_map_csv_row_does_not_replace_existing_harga_satuan()
    {
        $headers = [
            'kuantitas' => 'Qty',
            'harga_satuan' => 'Harga',
            'line_total' => 'Total',
        ];

        // Valid harga_satuan, even if it contradicts line total, remains authoritative
        $record = ['Qty' => '2', 'Harga' => '2000', 'Total' => '5000'];
        $mapped = $this->service->mapCsvRow($record, $headers);
        $this->assertEquals('2000', $mapped['harga_satuan']);
    }

    public function test_parse_numeric_fallback(): void
    {
        $this->assertEquals(60000.0, $this->service->parseNumericFallback('60000'));
        $this->assertEquals(60000.0, $this->service->parseNumericFallback('60.000'));
        $this->assertEquals(60000.0, $this->service->parseNumericFallback('60,000'));
        $this->assertEquals(60000.5, $this->service->parseNumericFallback('60,000.50'));
        $this->assertEquals(60000.5, $this->service->parseNumericFallback('60.000,50'));
        $this->assertEquals(1000.5, $this->service->parseNumericFallback('1000,50'));
    }
}

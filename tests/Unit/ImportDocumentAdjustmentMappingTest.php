<?php

namespace Tests\Unit;

use Modules\Purchase\Http\Controllers\PurchaseUploadController;
use Modules\Purchase\Jobs\StagePurchaseImportRows;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Sale\Http\Controllers\SalesUploadController;
use Modules\Sale\Jobs\StageSalesImportRows;
use Modules\Sale\Services\SalesImportService;
use Tests\TestCase;

class ImportDocumentAdjustmentMappingTest extends TestCase
{
    /** @test */
    public function purchase_upload_mapping_keeps_document_discount_separate_from_line_discount_percent(): void
    {
        $controller = new PurchaseUploadController(new PurchaseImportService());

        $headers = ['Diskon', 'Diskon %', 'Diskon Per Baris %', 'Biaya Pengiriman'];
        $normalized = $this->invokeMethod($controller, 'normalizeHeaders', [$headers]);
        $mapped = $this->invokeMethod($controller, 'mapCsvRow', [[
            'Diskon' => '15000',
            'Diskon %' => '7.26',
            'Diskon Per Baris %' => '2.50',
            'Biaya Pengiriman' => '5000',
        ], $normalized, $headers]);

        $this->assertSame('Diskon', $normalized['diskon']);
        $this->assertSame('Diskon %', $normalized['diskon_document_persen']);
        $this->assertSame('Diskon Per Baris %', $normalized['diskon_persen']);
        $this->assertSame('15000', $mapped['diskon']);
        $this->assertSame('7.26', $mapped['diskon_document_persen']);
        $this->assertSame('2.50', $mapped['diskon_persen']);
        $this->assertSame('5000', $mapped['biaya_pengiriman']);
    }

    /** @test */
    public function sales_upload_mapping_keeps_document_discount_separate_from_line_discount_percent(): void
    {
        $controller = new SalesUploadController(new SalesImportService());

        $headers = ['Diskon', 'Diskon %', 'Diskon Per Baris %', 'Biaya Pengiriman'];
        $normalized = $this->invokeMethod($controller, 'normalizeHeaders', [$headers]);
        $mapped = $this->invokeMethod($controller, 'mapCsvRow', [[
            'Diskon' => '15000',
            'Diskon %' => '7.26',
            'Diskon Per Baris %' => '2.50',
            'Biaya Pengiriman' => '5000',
        ], $normalized, $headers]);

        $this->assertSame('Diskon', $normalized['diskon']);
        $this->assertSame('Diskon %', $normalized['diskon_document_persen']);
        $this->assertSame('Diskon Per Baris %', $normalized['diskon_persen']);
        $this->assertSame('15000', $mapped['diskon']);
        $this->assertSame('7.26', $mapped['diskon_document_persen']);
        $this->assertSame('2.50', $mapped['diskon_persen']);
        $this->assertSame('5000', $mapped['biaya_pengiriman']);
    }

    /** @test */
    public function purchase_stage_mapping_preserves_document_discount_percent_for_diagnostics_only(): void
    {
        $job = new StagePurchaseImportRows(1, [
            'diskon' => 'Diskon',
            'diskon_document_persen' => 'Diskon %',
            'diskon_persen' => 'Diskon Per Baris %',
            'biaya_pengiriman' => 'Biaya Pengiriman',
        ], [], ',');

        $mapped = $this->invokeMethod($job, 'mapCsvRow', [[
            'Diskon' => '15000',
            'Diskon %' => '7.26',
            'Diskon Per Baris %' => '2.50',
            'Biaya Pengiriman' => '5000',
        ]]);

        $this->assertSame('15000', $mapped['diskon']);
        $this->assertSame('7.26', $mapped['diskon_document_persen']);
        $this->assertSame('2.50', $mapped['diskon_persen']);
        $this->assertSame('5000', $mapped['biaya_pengiriman']);
    }

    /** @test */
    public function sales_stage_mapping_preserves_document_discount_percent_for_diagnostics_only(): void
    {
        $job = new StageSalesImportRows(1, [
            'diskon' => 'Diskon',
            'diskon_document_persen' => 'Diskon %',
            'diskon_persen' => 'Diskon Per Baris %',
            'biaya_pengiriman' => 'Biaya Pengiriman',
        ], [], ',');

        $mapped = $this->invokeMethod($job, 'mapCsvRow', [[
            'Diskon' => '15000',
            'Diskon %' => '7.26',
            'Diskon Per Baris %' => '2.50',
            'Biaya Pengiriman' => '5000',
        ]]);

        $this->assertSame('15000', $mapped['diskon']);
        $this->assertSame('7.26', $mapped['diskon_document_persen']);
        $this->assertSame('2.50', $mapped['diskon_persen']);
        $this->assertSame('5000', $mapped['biaya_pengiriman']);
    }

    private function invokeMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
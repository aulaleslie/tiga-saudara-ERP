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

    /** @test */
    public function upload_and_stage_mappings_preserve_blank_document_adjustments_instead_of_coercing_them_to_zero(): void
    {
        $purchaseController = new PurchaseUploadController(new PurchaseImportService());
        $salesController = new SalesUploadController(new SalesImportService());

        $headers = ['Diskon', 'Biaya Pengiriman'];

        $purchaseNormalized = $this->invokeMethod($purchaseController, 'normalizeHeaders', [$headers]);
        $salesNormalized = $this->invokeMethod($salesController, 'normalizeHeaders', [$headers]);

        $purchaseMapped = $this->invokeMethod($purchaseController, 'mapCsvRow', [[
            'Diskon' => '',
            'Biaya Pengiriman' => '',
        ], $purchaseNormalized, $headers]);
        $salesMapped = $this->invokeMethod($salesController, 'mapCsvRow', [[
            'Diskon' => '',
            'Biaya Pengiriman' => '',
        ], $salesNormalized, $headers]);

        $purchaseStageJob = new StagePurchaseImportRows(1, [
            'diskon' => 'Diskon',
            'biaya_pengiriman' => 'Biaya Pengiriman',
        ], [], ',');
        $salesStageJob = new StageSalesImportRows(1, [
            'diskon' => 'Diskon',
            'biaya_pengiriman' => 'Biaya Pengiriman',
        ], [], ',');

        $purchaseStaged = $this->invokeMethod($purchaseStageJob, 'mapCsvRow', [[
            'Diskon' => '',
            'Biaya Pengiriman' => '',
        ]]);
        $salesStaged = $this->invokeMethod($salesStageJob, 'mapCsvRow', [[
            'Diskon' => '',
            'Biaya Pengiriman' => '',
        ]]);

        $this->assertSame('', $purchaseMapped['diskon']);
        $this->assertSame('', $purchaseMapped['biaya_pengiriman']);
        $this->assertSame('', $salesMapped['diskon']);
        $this->assertSame('', $salesMapped['biaya_pengiriman']);
        $this->assertSame('', $purchaseStaged['diskon']);
        $this->assertSame('', $purchaseStaged['biaya_pengiriman']);
        $this->assertSame('', $salesStaged['diskon']);
        $this->assertSame('', $salesStaged['biaya_pengiriman']);
    }

    private function invokeMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
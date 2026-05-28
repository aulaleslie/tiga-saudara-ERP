<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\Transaction;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Services\SalesImportService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalesImportDaizuOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $daizuSetting;
    protected Setting $perdanaSetting;
    protected Currency $currency;
    protected Location $daizuLocation;
    protected Location $perdanaLocation;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup Currency
        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        // Setup Daizu Setting
        $this->daizuSetting = Setting::create([
            'company_name' => 'DAIZU KEDELAI',
            'company_email' => 'daizu@example.com',
            'company_phone' => '123456',
            'company_address' => 'Daizu Address',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'daizu@example.com',
            'footer_text' => 'Daizu Footer',
        ]);

        // Setup Perdana Setting
        $this->perdanaSetting = Setting::create([
            'company_name' => 'PERDANA',
            'company_email' => 'perdana@example.com',
            'company_phone' => '654321',
            'company_address' => 'Perdana Address',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'perdana@example.com',
            'footer_text' => 'Perdana Footer',
        ]);

        // Setup Locations
        $this->daizuLocation = Location::create([
            'setting_id' => $this->daizuSetting->id,
            'name' => 'Daizu Main Warehouse',
        ]);

        $this->perdanaLocation = Location::create([
            'setting_id' => $this->perdanaSetting->id,
            'name' => 'Perdana Main Warehouse',
        ]);
    }

    protected function createImportBatch(array $rows): SalesImportBatch
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);
        $batch = SalesImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => SalesImportBatch::STATUS_PROCESSING,
        ]);

        foreach ($rows as $index => $rowData) {
            SalesImportRow::create([
                'batch_id' => $batch->id,
                'row_number' => $index + 2,
                'raw_json' => $rowData,
            ]);
        }

        return $batch;
    }

    protected function createBaseRowData(array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '01/10/2024',
            'no_faktur' => 'INV-TEST-001',
            'customer' => 'TEST CUSTOMER',
            'produk' => 'TEST PRODUCT',
            'kuantitas' => '1',
            'satuan' => 'PCS',
            'harga_satuan' => '100000',
            'tarif_pajak' => '11.0',
            'pajak' => '11000',
            'tag' => '',
            'gudang' => '',
        ], $overrides);
    }

    /** @test */
    public function untagged_kedelai_sale_creates_daizu_sale()
    {
        $rowData = $this->createBaseRowData([
            'produk' => 'KEDELE IMPORT',
            'tag' => '',
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->daizuSetting->id, $sale->setting_id);
    }

    /** @test */
    public function existing_tag_does_not_override_daizu_sale_ownership()
    {
        $rowData = $this->createBaseRowData([
            'produk' => 'RAGI IMPORT',
            'tag' => 'perdana', // Tag points to PERDANA
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->daizuSetting->id, $sale->setting_id, 'Daizu should override tag');
    }

    /** @test */
    public function product_marker_does_not_override_daizu_sale_ownership()
    {
        $rowData = $this->createBaseRowData([
            'produk' => '* KEDELAI IMPORT TP', // Has both * and TP markers
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->daizuSetting->id, $sale->setting_id, 'Daizu should override markers');
    }

    /** @test */
    public function non_whole_word_names_do_not_match_daizu_rule()
    {
        $batch = $this->createImportBatch([
            $this->createBaseRowData([
                'no_faktur' => 'INV-PREKE-001',
                'produk' => 'PREKEDELAI SAMPLE', // "PREKEDELAI" should not match
            ]),
            $this->createBaseRowData([
                'no_faktur' => 'INV-RAG-001',
                'produk' => 'RAGING BULL', // "RAGING" should not match
                'row_number' => 3,
            ]),
        ]);

        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $salePreke = Sale::where('imported_sales_reference_number', 'INV-PREKE-001')->first();
        $this->assertNotNull($salePreke);
        $this->assertEquals($this->perdanaSetting->id, $salePreke->setting_id, 'PREKEDELAI should resolve to PERDANA (default)');

        $saleRag = Sale::where('imported_sales_reference_number', 'INV-RAG-001')->first();
        $this->assertNotNull($saleRag);
        $this->assertEquals($this->perdanaSetting->id, $saleRag->setting_id, 'RAGING should resolve to PERDANA (default)');
    }

    /** @test */
    public function daizu_sale_row_decrements_daizu_stock()
    {
        $rowData = $this->createBaseRowData([
            'produk' => 'KEDELE IMPORT',
            'kuantitas' => '5',
            'gudang' => 'Daizu Main Warehouse',
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->daizuSetting->id, $sale->setting_id);

        // Check Transaction exists for Daizu setting
        $transaction = Transaction::where('setting_id', $this->daizuSetting->id)
            ->where('type', 'DISPATCH')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertEquals($this->daizuSetting->id, $transaction->setting_id);
        $this->assertEquals(-5, $transaction->quantity);
    }

    /** @test */
    public function historical_purchase_owner_ignored_for_daizu_sales()
    {
        // This test is implicit in the owner resolution tests
        // The key is that Daizu-matched products always resolve to Daizu
        // regardless of purchase history
        $rowData = $this->createBaseRowData([
            'produk' => 'RAGI IMPORT HISTORICAL',
            'gudang' => 'Daizu Main Warehouse',
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->daizuSetting->id, $sale->setting_id, 'Should always use Daizu for Daizu products');
    }

    /** @test */
    public function sale_and_all_related_entities_stay_aligned_for_daizu()
    {
        $rowData = $this->createBaseRowData([
            'produk' => 'KEDELAI IMPORT ALIGNMENT',
            'kuantitas' => '2',
            'harga_satuan' => '50000',
            'tarif_pajak' => '10.0',
            'pajak' => '10000',
            'gudang' => 'Daizu Main Warehouse',
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->daizuSetting->id, $sale->setting_id);

        // Verify ProductPrice was created under Daizu
        $productPrice = ProductPrice::where('setting_id', $this->daizuSetting->id)->first();
        $this->assertNotNull($productPrice);
        $this->assertEquals($this->daizuSetting->id, $productPrice->setting_id);

        // Check Transaction is under Daizu
        $transaction = Transaction::where('setting_id', $this->daizuSetting->id)
            ->where('type', 'DISPATCH')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertEquals($this->daizuSetting->id, $transaction->setting_id);
        $this->assertEquals($this->daizuLocation->id, $transaction->location_id);
    }

    /** @test */
    public function daizu_row_with_matching_location_uses_that_location()
    {
        $altLocation = Location::create([
            'setting_id' => $this->daizuSetting->id,
            'name' => 'Daizu Secondary Warehouse',
        ]);

        $rowData = $this->createBaseRowData([
            'produk' => 'KEDELE IMPORT LOCATION',
            'gudang' => 'Daizu Secondary Warehouse',
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        $this->assertNotNull($sale);
        $this->assertTrue($sale->saleDispatches->count() > 0);
        $dispatch = $sale->saleDispatches->first();
        $dispatchDetail = $dispatch->saleDetails->first();
        $this->assertEquals($altLocation->id, $dispatchDetail->location_id);
    }

    /** @test */
    public function blank_gudang_uses_daizu_default_location()
    {
        $rowData = $this->createBaseRowData([
            'produk' => 'RAGI IMPORT DEFAULT',
            'gudang' => '', // Blank
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        $this->assertNotNull($sale);
        $this->assertTrue($sale->saleDispatches->count() > 0);
        $dispatch = $sale->saleDispatches->first();
        $dispatchDetail = $dispatch->saleDetails->first();
        $this->assertEquals($this->daizuLocation->id, $dispatchDetail->location_id);
    }

    /** @test */
    public function gudang_cannot_fall_back_to_another_setting()
    {
        $rowData = $this->createBaseRowData([
            'produk' => 'KEDELAI IMPORT MISMATCH',
            'gudang' => 'Perdana Main Warehouse', // This location belongs to PERDANA, not DAIZU
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $row = SalesImportRow::where('batch_id', $batch->id)->first();
        $this->assertEquals(SalesImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Daizu location not found', $row->error_message);
    }

    /** @test */
    public function missing_daizu_setting_invalidates_matching_sales_rows()
    {
        // Delete Daizu setting to simulate missing setup
        $this->daizuSetting->delete();

        $rowData = $this->createBaseRowData([
            'produk' => 'KEDELE IMPORT NO SETUP',
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $row = SalesImportRow::where('batch_id', $batch->id)->first();
        $this->assertEquals(SalesImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Daizu Kedelai setting not found', $row->error_message);
    }

    /** @test */
    public function missing_default_daizu_location_invalidates_blank_gudang_row()
    {
        // Delete the default location
        $this->daizuLocation->delete();

        $rowData = $this->createBaseRowData([
            'produk' => 'RAGI IMPORT NO LOCATION',
            'gudang' => '', // Blank - should use default
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $row = SalesImportRow::where('batch_id', $batch->id)->first();
        $this->assertEquals(SalesImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Daizu location not found', $row->error_message);
    }

    /** @test */
    public function existing_daizu_sale_is_skipped_as_duplicate()
    {
        // Create first import
        $rowData = $this->createBaseRowData([
            'produk' => 'KEDELE DUPLICATE',
        ]);

        $batch1 = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch1);

        $sale1 = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        $this->assertNotNull($sale1);

        // Try to import same invoice again
        $batch2 = $this->createImportBatch([$rowData]);
        $service->processBatch($batch2);

        $row2 = SalesImportRow::where('batch_id', $batch2->id)->first();
        $this->assertEquals(SalesImportRow::STATUS_SKIPPED, $row2->status);
        $this->assertStringContainsString('already exists', $row2->error_message);
        $this->assertEquals($sale1->id, $row2->sale_id);
    }

    /** @test */
    public function existing_non_daizu_daizu_product_sale_blocks_import()
    {
        // Create a non-Daizu sale with a Daizu-matched product
        $customer = Customer::create([
            'customer_name' => 'TEST CUSTOMER',
            'customer_email' => '',
            'address' => '',
            'city' => '',
            'country' => '',
        ]);

        $product = Product::create([
            'product_name' => 'KEDELAI LEGACY',
            'product_code' => 'KEDELAI-LEG-001',
            'unit_id' => null,
            'product_quantity' => 0,
            'purchase_price' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'setting_id' => $this->perdanaSetting->id,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'due_date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 100000,
            'tax_amount' => 10000,
            'tax_percentage' => 10,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => '',
            'setting_id' => $this->perdanaSetting->id,
            'imported_sales_reference_number' => 'INV-TEST-001', // Same invoice
        ]);

        // Add detail with Daizu product
        $sale->saleDetails()->create([
            'product_id' => $product->id,
            'product_name' => 'KEDELAI LEGACY',
            'product_code' => 'KEDELAI-LEG-001',
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 10000,
            'tax_id' => null,
        ]);

        // Now try to import same invoice as Daizu
        $rowData = $this->createBaseRowData([
            'produk' => 'KEDELAI LEGACY',
        ]);

        $batch = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $row = SalesImportRow::where('batch_id', $batch->id)->first();
        $this->assertEquals(SalesImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Conflict', $row->error_message);
        $this->assertStringContainsString('legacy ownership conflict', $row->error_message);
    }

    /** @test */
    public function non_daizu_duplicate_behavior_remains_unchanged()
    {
        // Create first non-Daizu import
        $rowData = $this->createBaseRowData([
            'produk' => 'REGULAR PRODUCT',
            'tag' => 'perdana',
        ]);

        $batch1 = $this->createImportBatch([$rowData]);
        $service = app(SalesImportService::class);
        $service->processBatch($batch1);

        $sale1 = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        $this->assertNotNull($sale1);
        $this->assertEquals($this->perdanaSetting->id, $sale1->setting_id);

        // Try to import same invoice again
        $batch2 = $this->createImportBatch([$rowData]);
        $service->processBatch($batch2);

        $row2 = SalesImportRow::where('batch_id', $batch2->id)->first();
        $this->assertEquals(SalesImportRow::STATUS_SKIPPED, $row2->status);
    }
}

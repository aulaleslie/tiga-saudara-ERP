<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Services\SalesImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalesImportTagIgnoredOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $daizuSetting;
    protected Setting $perdanaSetting;
    protected Setting $tigaNusaSetting;
    protected Setting $topItSetting;
    protected Currency $currency;
    protected Location $daizuLocation;
    protected Location $perdanaLocation;
    protected Location $tigaNusaLocation;
    protected Location $topItLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->daizuSetting = Setting::create([
            'company_name' => 'DAIZU KEDELAI',
            'company_email' => 'daizu@example.com',
            'company_phone' => '111',
            'company_address' => 'Daizu Address',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'daizu@example.com',
            'footer_text' => '',
        ]);

        $this->perdanaSetting = Setting::create([
            'company_name' => 'PERDANA',
            'company_email' => 'perdana@example.com',
            'company_phone' => '222',
            'company_address' => 'Perdana Address',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'perdana@example.com',
            'footer_text' => '',
        ]);

        $this->tigaNusaSetting = Setting::create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'company_email' => 'tiga@example.com',
            'company_phone' => '333',
            'company_address' => 'Tiga Nusa Address',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'tiga@example.com',
            'footer_text' => '',
        ]);

        $this->topItSetting = Setting::create([
            'company_name' => 'CV TOP IT INTERNUSA',
            'company_email' => 'topit@example.com',
            'company_phone' => '444',
            'company_address' => 'Top IT Address',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'topit@example.com',
            'footer_text' => '',
        ]);

        $this->daizuLocation = Location::create([
            'setting_id' => $this->daizuSetting->id,
            'name' => 'Daizu Warehouse',
        ]);

        $this->perdanaLocation = Location::create([
            'setting_id' => $this->perdanaSetting->id,
            'name' => 'Perdana Warehouse',
        ]);

        $this->tigaNusaLocation = Location::create([
            'setting_id' => $this->tigaNusaSetting->id,
            'name' => 'Tiga Nusa Warehouse',
        ]);

        $this->topItLocation = Location::create([
            'setting_id' => $this->topItSetting->id,
            'name' => 'Top IT Warehouse',
        ]);

        $cashCoa = ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $this->perdanaSetting->id,
        ]);

        PaymentMethod::create([
            'name' => 'CASH',
            'coa_id' => $cashCoa->id,
            'is_cash' => true,
            'requires_reference' => false,
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

    protected function createPurchaseBatch(array $rows): PurchaseImportBatch
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);
        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy-purchase',
            'status' => PurchaseImportBatch::STATUS_PROCESSING,
        ]);

        foreach ($rows as $index => $rowData) {
            PurchaseImportRow::create([
                'batch_id' => $batch->id,
                'row_number' => $index + 2,
                'raw_json' => $rowData,
            ]);
        }

        return $batch;
    }

    protected function baseRow(array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '01/10/2024',
            'no_faktur' => 'INV-TAG-001',
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
    public function daizu_row_routes_to_daizu_when_tag_conflicts()
    {
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => 'RAGI IMPORT', 'tag' => 'perdana']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TAG-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->daizuSetting->id, $sale->setting_id);
    }

    /** @test */
    public function asterisk_row_routes_to_mapped_tag_owner()
    {
        // Mapped tag now takes priority over the product marker.
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => '* MONITOR SAMPLE', 'tag' => 'perdana']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TAG-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->perdanaSetting->id, $sale->setting_id);
    }

    /** @test */
    public function tp_suffix_row_routes_to_mapped_tag_owner()
    {
        // Mapped tag now takes priority over the product marker.
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => 'MONITOR SAMPLE TP', 'tag' => 'cv tiga nusa']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TAG-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->tigaNusaSetting->id, $sale->setting_id);
    }

    /** @test */
    public function unmarked_row_routes_to_perdana_despite_tag()
    {
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => 'MONITOR SAMPLE', 'tag' => 'rahmat']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TAG-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->perdanaSetting->id, $sale->setting_id);
    }

    /** @test */
    public function mapped_tag_row_preserves_csv_tag_as_metadata()
    {
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => '* MONITOR SAMPLE', 'tag' => 'perdana']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TAG-001')->first();
        $this->assertNotNull($sale);
        // Mapped tag wins for ownership.
        $this->assertEquals($this->perdanaSetting->id, $sale->setting_id);
        // Tag is synced as metadata — sale should have the tag attached
        $tagNames = $sale->tags->pluck('name')->toArray();
        $this->assertContains('perdana', $tagNames);
    }

    /** @test */
    public function mapped_tag_differences_split_same_invoice_into_distinct_owners()
    {
        // Mapped tags resolving to different owners now split the invoice.
        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'INV-GROUP-001', 'produk' => 'MONITOR SAMPLE', 'tag' => 'perdana']),
            $this->baseRow(['no_faktur' => 'INV-GROUP-001', 'produk' => 'MONITOR SAMPLE B', 'tag' => 'cv tiga nusa']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'INV-GROUP-001')->get();
        $this->assertCount(2, $sales);
        $this->assertEqualsCanonicalizing(
            [$this->perdanaSetting->id, $this->tigaNusaSetting->id],
            $sales->pluck('setting_id')->all()
        );
    }

    /** @test */
    public function blank_tag_rows_with_same_marker_owner_stay_in_one_document()
    {
        // Blank tags falling back to the same marker owner must not split.
        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'INV-GROUP-002', 'produk' => 'MONITOR SAMPLE', 'tag' => '']),
            $this->baseRow(['no_faktur' => 'INV-GROUP-002', 'produk' => 'MONITOR SAMPLE B', 'tag' => '']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'INV-GROUP-002')->get();
        $this->assertCount(1, $sales);
        $this->assertEquals($this->perdanaSetting->id, $sales->first()->setting_id);
    }

    /** @test */
    public function historical_purchase_owner_is_ignored_for_unmarked_sales()
    {
        // Import an asterisk purchase under Tiga Nusa to create BUY history
        $purchaseBatch = $this->createPurchaseBatch([
            [
                'tanggal' => '01/09/2024',
                'no_faktur' => 'PO-HIST-001',
                'supplier' => 'HIST SUPPLIER',
                'produk' => '* PLAIN PRODUCT HIST',
                'kuantitas' => '10',
                'satuan' => 'PCS',
                'harga_satuan' => '50000',
                'tarif_pajak' => '0',
                'pajak' => '0',
                'tag' => '',
                'gudang' => '',
            ],
        ]);
        app(\Modules\Purchase\Services\PurchaseImportService::class)->processBatch($purchaseBatch);

        // The product should now exist with a BUY transaction under Tiga Nusa
        $product = Product::where('product_name', 'PLAIN PRODUCT HIST')->first();
        $this->assertNotNull($product);
        $this->assertTrue(
            Transaction::where('product_id', $product->id)->where('type', 'BUY')
                ->where('setting_id', $this->tigaNusaSetting->id)->exists()
        );

        // Now import a sale for the same product WITHOUT a marker — should go to PERDANA
        $saleBatch = $this->createImportBatch([
            $this->baseRow(['produk' => 'PLAIN PRODUCT HIST', 'tag' => '']),
        ]);
        app(SalesImportService::class)->processBatch($saleBatch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TAG-001')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->perdanaSetting->id, $sale->setting_id);

        $dispatchTx = Transaction::where('type', 'DISPATCH')
            ->where('product_id', $product->id)
            ->first();
        $this->assertNotNull($dispatchTx);
        $this->assertEquals($this->perdanaSetting->id, $dispatchTx->setting_id);
    }

    /** @test */
    public function duplicate_lookup_uses_product_name_ownership_ignores_changed_tag()
    {
        // First import under PERDANA (unmarked product, no tag)
        $batch1 = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'INV-DUP-001', 'produk' => 'PLAIN PRODUCT', 'tag' => '']),
        ]);
        app(SalesImportService::class)->processBatch($batch1);

        $sale1 = Sale::where('imported_sales_reference_number', 'INV-DUP-001')->first();
        $this->assertNotNull($sale1);
        $this->assertEquals($this->perdanaSetting->id, $sale1->setting_id);

        // Re-import with a different tag value — must still detect as duplicate
        $batch2 = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'INV-DUP-001', 'produk' => 'PLAIN PRODUCT', 'tag' => 'rahmat']),
        ]);
        app(SalesImportService::class)->processBatch($batch2);

        $row2 = SalesImportRow::where('batch_id', $batch2->id)->first();
        $this->assertEquals(SalesImportRow::STATUS_SKIPPED, $row2->status);
        $this->assertEquals($sale1->id, $row2->sale_id);
    }
}

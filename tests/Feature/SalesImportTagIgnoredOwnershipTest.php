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
    public function asterisk_row_routes_to_product_marker_owner_ignoring_tag()
    {
        // Product marker (asterisk) determines ownership, not tag. Tag is metadata only.
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => '* MONITOR SAMPLE', 'tag' => 'perdana']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TAG-001')->first();
        $this->assertNotNull($sale);
        // Asterisk marker routes to Tiga Nusa, not perdana tag
        $this->assertEquals($this->tigaNusaSetting->id, $sale->setting_id);
    }

    /** @test */
    public function tp_suffix_row_routes_to_product_marker_owner_ignoring_tag()
    {
        // Product marker (TP suffix) determines ownership, not tag. Tag is metadata only.
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => 'MONITOR SAMPLE TP', 'tag' => 'cv tiga nusa']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TAG-001')->first();
        $this->assertNotNull($sale);
        // TP suffix routes to CV Top IT, not the tag. Tags are metadata only.
        $this->assertEquals($this->topItSetting->id, $sale->setting_id);
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
    public function product_marker_determines_ownership_while_tag_is_synced_as_metadata()
    {
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => '* MONITOR SAMPLE', 'tag' => 'perdana']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TAG-001')->first();
        $this->assertNotNull($sale);
        // Product marker (asterisk) determines ownership, not tag
        $this->assertEquals($this->tigaNusaSetting->id, $sale->setting_id);
        // Tag is synced as metadata — sale should have the tag attached
        $tagNames = $sale->tags->pluck('name')->toArray();
        $this->assertContains('perdana', $tagNames);
    }

    /** @test */
    public function product_marker_differences_split_same_invoice_into_distinct_owners()
    {
        // Product markers determine ownership and split the invoice, not tags.
        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'INV-GROUP-001', 'produk' => 'MONITOR SAMPLE', 'tag' => 'cv tiga nusa']),
            $this->baseRow(['no_faktur' => 'INV-GROUP-001', 'produk' => '* MONITOR SAMPLE B', 'tag' => 'perdana']),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'INV-GROUP-001')->get();
        $this->assertCount(2, $sales);
        // Unmarked product routes to PERDANA, asterisk product routes to Tiga Nusa (product markers, not tags)
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
        // Pre-create a product registered under Tiga Nusa, simulating a product that
        // was historically purchased/associated there. The sale import must NOT use
        // this product ownership — it uses only product name marker and tag mapping.
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'PCS']);
        Product::create([
            'product_name' => 'PLAIN PRODUCT HIST',
            'product_code' => 'SKU-HIST-001',
            'unit_id' => $unit->id,
            'setting_id' => $this->tigaNusaSetting->id,
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_quantity' => 10,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
        ]);

        // Import a sale for the same product WITHOUT a marker or mapped tag.
        // Should route to PERDANA, ignoring the product's registered Tiga Nusa ownership.
        $saleBatch = $this->createImportBatch([
            $this->baseRow(['produk' => 'PLAIN PRODUCT HIST', 'tag' => '']),
        ]);
        app(SalesImportService::class)->processBatch($saleBatch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-TAG-001')->first();
        $this->assertNotNull($sale);
        // Without a product marker or mapped tag, routing falls back to PERDANA
        $this->assertEquals($this->perdanaSetting->id, $sale->setting_id);
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

    /** @test */
    public function owner_split_sales_import_allocates_precision_drift_before_settlement_allocation_so_owners_balance_independently()
    {
        $batch = $this->createImportBatch([
            $this->baseRow([
                'no_faktur' => 'INV-SPLIT-DRIFT',
                'produk' => 'MONITOR SAMPLE A', // PERDANA (no marker)
                'harga_satuan' => '222000.01',
                'tarif_pajak' => '0',
                'pajak' => '0',
                // Raw total for PERDANA = 222000.01

                'pembayaran' => '444000.00',
                'source_total' => '444000.00',
                'status_hari_ini' => 'Lunas',
                'sisa_tagihan' => '0',
            ]),
            $this->baseRow([
                'no_faktur' => 'INV-SPLIT-DRIFT',
                'produk' => '* MONITOR SAMPLE B', // TIGA NUSA (* marker)
                'harga_satuan' => '222000.01',
                'tarif_pajak' => '0',
                'pajak' => '0',
                // Raw total for TIGA NUSA = 222000.01

                'pembayaran' => '444000.00',
                'source_total' => '444000.00',
                'status_hari_ini' => 'Lunas',
                'sisa_tagihan' => '0',
            ]),
        ]);

        app(SalesImportService::class)->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'INV-SPLIT-DRIFT')->get();
        $this->assertCount(2, $sales);

        $perdanaSale = $sales->where('setting_id', $this->perdanaSetting->id)->first();
        $tigaNusaSale = $sales->where('setting_id', $this->tigaNusaSetting->id)->first();

        $this->assertNotNull($perdanaSale);
        $this->assertNotNull($tigaNusaSale);

        // Sum of both should be exactly 444000.00
        $this->assertEquals(444000.00, $perdanaSale->total_amount + $tigaNusaSale->total_amount);

        // Each should be PAID
        $this->assertEquals('PAID', $perdanaSale->payment_status);
        $this->assertEquals('PAID', $tigaNusaSale->payment_status);

        // Due amounts should be 0
        $this->assertEquals(0.0, (float) $perdanaSale->due_amount);
        $this->assertEquals(0.0, (float) $tigaNusaSale->due_amount);
    }
}

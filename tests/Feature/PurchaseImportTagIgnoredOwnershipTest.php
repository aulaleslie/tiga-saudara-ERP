<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseImportTagIgnoredOwnershipTest extends TestCase
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

    protected function createImportBatch(array $rows): PurchaseImportBatch
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);
        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
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
            'no_faktur' => 'PO-TAG-001',
            'supplier' => 'TEST SUPPLIER',
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
    public function daizu_purchase_row_routes_to_daizu_when_tag_conflicts()
    {
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => 'RAGI IMPORT', 'tag' => 'perdana']),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-TAG-001')->first();
        $this->assertNotNull($purchase);
        $this->assertEquals($this->daizuSetting->id, $purchase->setting_id);
    }

    /** @test */
    public function asterisk_purchase_row_with_unmapped_tag_routes_to_perdana()
    {
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => '* MONITOR SAMPLE', 'tag' => '']),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-TAG-001')->first();
        $this->assertNotNull($purchase);
        $this->assertEquals($this->perdanaSetting->id, $purchase->setting_id);
    }

    /** @test */
    public function tp_suffix_purchase_row_with_unmapped_tag_routes_to_perdana()
    {
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => 'MONITOR SAMPLE TP', 'tag' => 'unknown']),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-TAG-001')->first();
        $this->assertNotNull($purchase);
        $this->assertEquals($this->perdanaSetting->id, $purchase->setting_id);
    }

    /** @test */
    public function unmarked_purchase_row_routes_to_perdana_despite_tag()
    {
        $batch = $this->createImportBatch([
            $this->baseRow(['produk' => 'MONITOR SAMPLE', 'tag' => 'rahmat']),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'PO-TAG-001')->first();
        $this->assertNotNull($purchase);
        $this->assertEquals($this->perdanaSetting->id, $purchase->setting_id);
    }

    /** @test */
    public function mapped_tag_differences_split_same_invoice_into_distinct_owners()
    {
        // Mapped tags resolving to different owners now split the invoice.
        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'PO-GROUP-001', 'produk' => 'MONITOR A', 'tag' => 'perdana']),
            $this->baseRow(['no_faktur' => 'PO-GROUP-001', 'produk' => 'MONITOR B', 'tag' => 'cv tiga nusa']),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchases = Purchase::where('supplier_purchase_number', 'PO-GROUP-001')->get();
        $this->assertCount(2, $purchases);
        $this->assertEqualsCanonicalizing(
            [$this->perdanaSetting->id, $this->tigaNusaSetting->id],
            $purchases->pluck('setting_id')->all()
        );
    }

    /** @test */
    public function blank_tag_rows_with_same_marker_owner_stay_in_one_document()
    {
        // Blank tags falling back to PERDANA must not split.
        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'PO-GROUP-002', 'produk' => 'MONITOR A', 'tag' => '']),
            $this->baseRow(['no_faktur' => 'PO-GROUP-002', 'produk' => 'MONITOR B', 'tag' => '']),
        ]);

        app(PurchaseImportService::class)->processBatch($batch);

        $purchases = Purchase::where('supplier_purchase_number', 'PO-GROUP-002')->get();
        $this->assertCount(1, $purchases);
        $this->assertEquals($this->perdanaSetting->id, $purchases->first()->setting_id);
    }



    /** @test */
    public function purchase_duplicate_lookup_uses_product_name_ownership_ignores_changed_tag()
    {
        // First import under PERDANA (unmarked, no tag)
        $batch1 = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'PO-DUP-001', 'produk' => 'PLAIN PRODUCT', 'tag' => '']),
        ]);
        app(PurchaseImportService::class)->processBatch($batch1);

        $purchase1 = Purchase::where('supplier_purchase_number', 'PO-DUP-001')->first();
        $this->assertNotNull($purchase1);
        $this->assertEquals($this->perdanaSetting->id, $purchase1->setting_id);

        // Re-import same invoice with different tag — must still detect as duplicate
        $batch2 = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'PO-DUP-001', 'produk' => 'PLAIN PRODUCT', 'tag' => 'rahmat']),
        ]);
        app(PurchaseImportService::class)->processBatch($batch2);

        $row2 = PurchaseImportRow::where('batch_id', $batch2->id)->first();
        $this->assertEquals(PurchaseImportRow::STATUS_SKIPPED, $row2->status);
        $this->assertEquals($purchase1->id, $row2->purchase_id);
    }
}

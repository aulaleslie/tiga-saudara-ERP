<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Services\SalesImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class SalesImportPriceSyncTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $settingA;
    private Setting $settingB;
    private SalesImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name'      => 'Rupiah',
            'code'               => 'IDR',
            'symbol'             => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator'  => ',',
            'exchange_rate'      => 1,
        ]);

        $this->settingA = $this->createSetting('PERDANA', 'a@example.com');
        $this->settingB = $this->createSetting('CV TIGA NUSA COMPUTER', 'b@example.com');

        Location::create(['setting_id' => $this->settingA->id, 'name' => 'Gudang A']);
        Location::create(['setting_id' => $this->settingB->id, 'name' => 'Gudang B']);

        $cashCoa = ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $this->settingA->id,
        ]);

        PaymentMethod::create([
            'name' => 'CASH',
            'coa_id' => $cashCoa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        $this->service = new SalesImportService();

        $this->actingAs(User::factory()->create());
    }

    private function createSetting(string $companyName, string $email): Setting
    {
        return Setting::create([
            'company_name'              => $companyName,
            'company_email'             => $email,
            'company_phone'             => '000',
            'notification_email'        => $email,
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'prefix',
            'footer_text'               => '',
            'company_address'           => '',
        ]);
    }

    private function makeBatch(): SalesImportBatch
    {
        return SalesImportBatch::create([
            'user_id'         => auth()->id(),
            'source_csv_path' => 'test.csv',
            'file_sha256'     => md5(uniqid()),
            'status'          => SalesImportBatch::STATUS_QUEUED,
            'total_rows'      => 0,
            'processed_rows'  => 0,
            'success_count'   => 0,
            'error_count'     => 0,
        ]);
    }

    private function makeRow(SalesImportBatch $batch, array $overrides = [], int $rowNumber = 1): SalesImportRow
    {
        $defaults = [
            'tanggal'          => '01/01/2024',
            'no_faktur'        => 'SINV-001',
            'customer'         => 'Customer A',
            'produk'           => 'Gadget',
            'satuan'           => 'PCS',
            'kuantitas'        => '5',
            'harga_satuan'     => '10000',
            'tarif_pajak'      => '0',
            'pajak'            => '0',
            'sisa_tagihan'     => '0',
            'biaya_pengiriman' => '0',
            'tag'              => '',
        ];

        return SalesImportRow::create([
            'batch_id'   => $batch->id,
            'row_number' => $rowNumber,
            'status'     => SalesImportRow::STATUS_PENDING,
            'raw_json'   => array_merge($defaults, $overrides),
        ]);
    }

    // Task 1.3 — positive-price sales import creates/updates sale_price, tier_1_price, tier_2_price across every setting
    public function test_positive_price_sales_import_upserts_all_tier_prices_for_every_setting(): void
    {
        $batch = $this->makeBatch();
        $this->makeRow($batch, ['harga_satuan' => '10000']);

        $this->service->processBatch($batch);

        $product = Product::whereRaw('LOWER(product_name) = ?', ['gadget'])->firstOrFail();
        $prices = ProductPrice::where('product_id', $product->id)->get()->keyBy('setting_id');

        $this->assertCount(2, $prices, 'Must have product_prices rows for both settings');

        foreach ([$this->settingA->id, $this->settingB->id] as $settingId) {
            $price = $prices->get($settingId);
            $this->assertNotNull($price, "Missing product_prices row for setting {$settingId}");
            $this->assertEquals(10000.0, (float) $price->sale_price,   "sale_price mismatch for setting {$settingId}");
            $this->assertEquals(10000.0, (float) $price->tier_1_price, "tier_1_price mismatch for setting {$settingId}");
            $this->assertEquals(10000.0, (float) $price->tier_2_price, "tier_2_price mismatch for setting {$settingId}");
        }
    }

    // Task 1.4 — latest processed positive sales row wins for repeated products
    public function test_last_processed_sales_row_wins_for_repeated_product(): void
    {
        // Two invoices for the same product with different prices — processed in order
        $batch = $this->makeBatch();
        $this->makeRow($batch, ['no_faktur' => 'SINV-A', 'harga_satuan' => '8000'], rowNumber: 1);
        $this->makeRow($batch, ['no_faktur' => 'SINV-B', 'harga_satuan' => '12000'], rowNumber: 2);

        $this->service->processBatch($batch);

        $product = Product::whereRaw('LOWER(product_name) = ?', ['gadget'])->firstOrFail();
        $prices = ProductPrice::where('product_id', $product->id)->get();

        foreach ($prices as $price) {
            $this->assertEquals(12000.0, (float) $price->sale_price,   "sale_price should be 12000 (last row) for setting {$price->setting_id}");
            $this->assertEquals(12000.0, (float) $price->tier_1_price, "tier_1_price should be 12000 (last row) for setting {$price->setting_id}");
            $this->assertEquals(12000.0, (float) $price->tier_2_price, "tier_2_price should be 12000 (last row) for setting {$price->setting_id}");
        }
    }

    // Task 1.5 — zero or blank final unit price does not overwrite existing catalog prices
    public function test_zero_sales_price_does_not_overwrite_existing_catalog_prices(): void
    {
        $unit = Unit::create(['name' => 'Piece', 'short_name' => 'PCS']);
        $product = Product::create([
            'product_name'     => 'Gadget',
            'product_code'     => 'GDG-001',
            'unit_id'          => $unit->id,
            'setting_id'       => $this->settingA->id,
            'product_cost'     => 0,
            'product_price'    => 0,
            'product_quantity' => 0,
        ]);

        foreach ([$this->settingA->id, $this->settingB->id] as $settingId) {
            ProductPrice::create([
                'product_id'             => $product->id,
                'setting_id'             => $settingId,
                'sale_price'             => 20000,
                'tier_1_price'           => 18000,
                'tier_2_price'           => 16000,
                'last_purchase_price'    => 0,
                'average_purchase_price' => 0,
            ]);
        }

        $batch = $this->makeBatch();
        $this->makeRow($batch, ['harga_satuan' => '0']);

        $this->service->processBatch($batch);

        // The sale detail row should still have been created for this product
        $saleDetail = SaleDetails::first();
        $this->assertNotNull($saleDetail, 'Sale detail must be created even for zero price');

        // Catalog prices on the pre-seeded product must remain unchanged
        foreach ([$this->settingA->id, $this->settingB->id] as $settingId) {
            $price = ProductPrice::where('product_id', $product->id)
                ->where('setting_id', $settingId)
                ->firstOrFail();

            $this->assertEquals(20000.0, (float) $price->sale_price,   "sale_price overwritten for setting {$settingId}");
            $this->assertEquals(18000.0, (float) $price->tier_1_price, "tier_1_price overwritten for setting {$settingId}");
            $this->assertEquals(16000.0, (float) $price->tier_2_price, "tier_2_price overwritten for setting {$settingId}");
        }
    }

    // Task 1.6 — duplicate sales invoice does not backfill product prices
    public function test_duplicate_sales_invoice_does_not_update_product_prices(): void
    {
        // First import — establishes the sale and prices
        $batch1 = $this->makeBatch();
        $this->makeRow($batch1, ['no_faktur' => 'SINV-DUP', 'harga_satuan' => '10000']);
        $this->service->processBatch($batch1);

        $product = Product::whereRaw('LOWER(product_name) = ?', ['gadget'])->firstOrFail();

        $pricesAfterFirst = ProductPrice::where('product_id', $product->id)
            ->get()
            ->mapWithKeys(fn ($p) => [$p->setting_id => (float) $p->sale_price])
            ->toArray();

        // Second import with same invoice number but different price
        $batch2 = $this->makeBatch();
        $this->makeRow($batch2, ['no_faktur' => 'SINV-DUP', 'harga_satuan' => '99999']);
        $this->service->processBatch($batch2);

        foreach ($pricesAfterFirst as $settingId => $originalPrice) {
            $price = ProductPrice::where('product_id', $product->id)
                ->where('setting_id', $settingId)
                ->firstOrFail();

            $this->assertEquals($originalPrice, (float) $price->sale_price, "Duplicate sale changed sale_price for setting {$settingId}");
        }
    }
}

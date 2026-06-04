<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseImportPriceSyncTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $settingA;
    private Setting $settingB;
    private PurchaseImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code'          => 'IDR',
            'symbol'        => 'Rp',
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

        $this->service = new PurchaseImportService();

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

    private function makeBatch(): PurchaseImportBatch
    {
        return PurchaseImportBatch::create([
            'user_id'         => auth()->id(),
            'source_csv_path' => 'test.csv',
            'file_sha256'     => md5(uniqid()),
            'status'          => PurchaseImportBatch::STATUS_QUEUED,
            'total_rows'      => 0,
            'processed_rows'  => 0,
            'success_count'   => 0,
            'error_count'     => 0,
        ]);
    }

    private function makeRow(PurchaseImportBatch $batch, array $overrides = [], int $rowNumber = 1): PurchaseImportRow
    {
        $defaults = [
            'tanggal'          => '01/01/2024',
            'no_faktur'        => 'INV-001',
            'supplier'         => 'Supplier A',
            'produk'           => 'Widget',
            'satuan'           => 'PCS',
            'kuantitas'        => '10',
            'harga_satuan'     => '5000',
            'tarif_pajak'      => '0',
            'diskon_persen'    => '0',
            'pajak'            => '0',
            'sisa_tagihan'     => '0',
            'biaya_pengiriman' => '0',
            'tag'              => '',
        ];

        return PurchaseImportRow::create([
            'batch_id'   => $batch->id,
            'row_number' => $rowNumber,
            'status'     => PurchaseImportRow::STATUS_PENDING,
            'raw_json'   => array_merge($defaults, $overrides),
        ]);
    }

    // Task 1.1 — purchase import creates/updates product_prices for every setting
    public function test_successful_purchase_import_upserts_product_prices_for_every_setting(): void
    {
        $batch = $this->makeBatch();
        $this->makeRow($batch, [
            'produk'       => 'Widget',
            'kuantitas'    => '10',
            'harga_satuan' => '5000',
            'tarif_pajak'  => '0',
        ]);

        $this->service->processBatch($batch);

        $product = Product::whereRaw('LOWER(product_name) = ?', ['widget'])->firstOrFail();
        $prices = ProductPrice::where('product_id', $product->id)->get()->keyBy('setting_id');

        $this->assertCount(2, $prices, 'Must have product_prices for both settings');

        foreach ([$this->settingA->id, $this->settingB->id] as $settingId) {
            $price = $prices->get($settingId);
            $this->assertNotNull($price, "Missing product_prices row for setting {$settingId}");
            $this->assertEquals(5000.0, (float) $price->last_purchase_price, "last_purchase_price mismatch for setting {$settingId}");
            $this->assertEquals(0.0, (float) $price->average_purchase_price, "average_purchase_price should be 0 for new rows for setting {$settingId}");
        }
    }

    // Task 1.2 — purchase import does NOT overwrite sale_price, tier_1_price, tier_2_price
    public function test_purchase_import_does_not_overwrite_existing_sale_prices(): void
    {
        $product = Product::create([
            'product_name'     => 'Widget',
            'product_code'     => 'WGT-001',
            'unit_id'          => \Modules\Setting\Entities\Unit::create(['name' => 'Piece', 'short_name' => 'PCS'])->id,
            'setting_id'       => $this->settingA->id,
            'product_cost'     => 0,
            'product_price'    => 0,
            'product_quantity' => 0,
        ]);

        // Seed pre-existing sale prices on both settings
        foreach ([$this->settingA->id, $this->settingB->id] as $settingId) {
            ProductPrice::create([
                'product_id'           => $product->id,
                'setting_id'           => $settingId,
                'sale_price'           => 15000,
                'tier_1_price'         => 13000,
                'tier_2_price'         => 11000,
                'last_purchase_price'  => 0,
                'average_purchase_price' => 0,
            ]);
        }

        $batch = $this->makeBatch();
        $this->makeRow($batch, ['produk' => 'Widget', 'harga_satuan' => '6000']);

        $this->service->processBatch($batch);

        foreach ([$this->settingA->id, $this->settingB->id] as $settingId) {
            $price = ProductPrice::where('product_id', $product->id)
                ->where('setting_id', $settingId)
                ->firstOrFail();

            $this->assertEquals(15000.0, (float) $price->sale_price,   "sale_price overwritten for setting {$settingId}");
            $this->assertEquals(13000.0, (float) $price->tier_1_price, "tier_1_price overwritten for setting {$settingId}");
            $this->assertEquals(11000.0, (float) $price->tier_2_price, "tier_2_price overwritten for setting {$settingId}");
        }
    }

    // Task 4.1, 4.2 — average_purchase_price remains unchanged
    public function test_purchase_import_preserves_existing_average_purchase_price(): void
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Piece', 'short_name' => 'PCS']);

        $product = Product::create([
            'product_name'     => 'Widget',
            'product_code'     => 'WGT-001',
            'unit_id'          => $unit->id,
            'setting_id'       => $this->settingA->id,
            'product_cost'     => 0,
            'product_price'    => 0,
            'product_quantity' => 20,
        ]);

        // Existing row with specific average price
        ProductPrice::create([
            'product_id'             => $product->id,
            'setting_id'             => $this->settingA->id,
            'sale_price'             => 0,
            'last_purchase_price'    => 4000,
            'average_purchase_price' => 4500,
        ]);

        ProductPrice::create([
            'product_id'             => $product->id,
            'setting_id'             => $this->settingB->id,
            'sale_price'             => 0,
            'last_purchase_price'    => 4000,
            'average_purchase_price' => 4600,
        ]);

        $batch = $this->makeBatch();
        // Import 10 more units at 6000
        $this->makeRow($batch, ['produk' => 'Widget', 'kuantitas' => '10', 'harga_satuan' => '6000']);

        $this->service->processBatch($batch);

        $priceA = ProductPrice::where('product_id', $product->id)->where('setting_id', $this->settingA->id)->firstOrFail();
        $priceB = ProductPrice::where('product_id', $product->id)->where('setting_id', $this->settingB->id)->firstOrFail();

        $this->assertEquals(6000.0, (float) $priceA->last_purchase_price);
        $this->assertEquals(6000.0, (float) $priceB->last_purchase_price);

        // average_purchase_price is unchanged
        $this->assertEquals(4500.0, (float) $priceA->average_purchase_price);
        $this->assertEquals(4600.0, (float) $priceB->average_purchase_price);
    }

    // Task 1.6 — duplicate purchase invoice skips product price sync
    public function test_duplicate_purchase_invoice_does_not_update_product_prices(): void
    {
        // First import — establishes the purchase and prices
        $batch1 = $this->makeBatch();
        $this->makeRow($batch1, ['no_faktur' => 'INV-DUP', 'harga_satuan' => '5000']);
        $this->service->processBatch($batch1);

        $product = Product::whereRaw('LOWER(product_name) = ?', ['widget'])->firstOrFail();

        // Record prices after first import
        $pricesAfterFirst = ProductPrice::where('product_id', $product->id)
            ->get()
            ->mapWithKeys(fn ($p) => [$p->setting_id => (float) $p->last_purchase_price])
            ->toArray();

        // Second import with same invoice number but different price
        $batch2 = $this->makeBatch();
        $this->makeRow($batch2, ['no_faktur' => 'INV-DUP', 'harga_satuan' => '9999']);
        $this->service->processBatch($batch2);

        // Prices must not have changed
        foreach ($pricesAfterFirst as $settingId => $originalPrice) {
            $price = ProductPrice::where('product_id', $product->id)
                ->where('setting_id', $settingId)
                ->firstOrFail();
            $this->assertEquals($originalPrice, (float) $price->last_purchase_price, "Duplicate purchase changed last_purchase_price for setting {$settingId}");
        }
    }
}

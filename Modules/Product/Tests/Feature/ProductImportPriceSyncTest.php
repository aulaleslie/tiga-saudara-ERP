<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Jobs\ProcessProductImportBatch;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductImportPriceSyncTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $primarySetting;
    private Setting $secondarySetting;
    private Location $location;

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

        $this->primarySetting = $this->createSetting('primary');
        $this->secondarySetting = $this->createSetting('secondary');

        $this->location = Location::create([
            'setting_id' => $this->primarySetting->id,
            'name' => 'Main Warehouse',
        ]);
    }

    public function test_import_seeds_prices_for_all_settings_when_product_is_new(): void
    {
        $this->dispatchImportJob([
            'product_name' => 'New Product',
            'product_code' => 'NEW-001',
            'sale_price' => '50000',
            'tier_1_price' => '55000',
            'tier_2_price' => '60000',
            'purchase_price' => '20000',
        ]);

        $product = Product::where('product_code', 'NEW-001')->firstOrFail();
        $prices = ProductPrice::forProduct($product->id)->get()->keyBy('setting_id');

        $this->assertCount(2, $prices);

        foreach ([$this->primarySetting->id, $this->secondarySetting->id] as $settingId) {
            $price = $prices->get($settingId);
            $this->assertNotNull($price, "Missing price for setting {$settingId}");
            $this->assertSame('50000.00', $price->sale_price);
            $this->assertSame('55000.00', $price->tier_1_price);
            $this->assertSame('60000.00', $price->tier_2_price);
            $this->assertSame('20000.00', $price->last_purchase_price);
            $this->assertSame('20000.00', $price->average_purchase_price);
            $this->assertNull($price->purchase_tax_id);
            $this->assertNull($price->sale_tax_id);
        }
    }

    public function test_import_updates_only_default_setting_price_when_product_exists(): void
    {
        $product = Product::create([
            'product_name' => 'Existing Product',
            'product_code' => 'EX-001',
            'setting_id' => $this->primarySetting->id,
            'product_quantity' => 0,
            'serial_number_required' => false,
            'stock_managed' => true,
            'product_stock_alert' => 0,
            'product_cost' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'purchase_tax_id' => null,
            'is_sold' => 1,
            'sale_price' => 0,
            'sale_tax_id' => null,
            'product_price' => 0,
            'last_purchase_price' => 0,
            'average_purchase_price' => 0,
        ]);

        ProductPrice::upsertFor([
            'product_id' => $product->id,
            'setting_id' => $this->primarySetting->id,
            'sale_price' => 10,
            'tier_1_price' => 11,
            'tier_2_price' => 12,
            'last_purchase_price' => 5,
            'average_purchase_price' => 5,
            'purchase_tax_id' => null,
            'sale_tax_id' => null,
        ]);

        ProductPrice::upsertFor([
            'product_id' => $product->id,
            'setting_id' => $this->secondarySetting->id,
            'sale_price' => 99,
            'tier_1_price' => 101,
            'tier_2_price' => 103,
            'last_purchase_price' => 77,
            'average_purchase_price' => 77,
            'purchase_tax_id' => null,
            'sale_tax_id' => null,
        ]);

        $secondaryPriceBefore = ProductPrice::forProduct($product->id)
            ->forSetting($this->secondarySetting->id)
            ->firstOrFail();

        $this->dispatchImportJob([
            'product_name' => 'Existing Product',
            'product_code' => 'EX-001',
            'sale_price' => '150000',
            'tier_1_price' => '151000',
            'tier_2_price' => '152000',
            'purchase_price' => '50000',
        ]);

        $primaryPrice = ProductPrice::forProduct($product->id)
            ->forSetting($this->primarySetting->id)
            ->firstOrFail();
        $secondaryPrice = ProductPrice::forProduct($product->id)
            ->forSetting($this->secondarySetting->id)
            ->firstOrFail();

        $this->assertSame('150000.00', $primaryPrice->sale_price);
        $this->assertSame('151000.00', $primaryPrice->tier_1_price);
        $this->assertSame('152000.00', $primaryPrice->tier_2_price);
        $this->assertSame('50000.00', $primaryPrice->last_purchase_price);
        $this->assertSame('50000.00', $primaryPrice->average_purchase_price);

        $this->assertSame($secondaryPriceBefore->sale_price, $secondaryPrice->sale_price);
        $this->assertSame($secondaryPriceBefore->tier_1_price, $secondaryPrice->tier_1_price);
        $this->assertSame($secondaryPriceBefore->tier_2_price, $secondaryPrice->tier_2_price);
        $this->assertSame($secondaryPriceBefore->last_purchase_price, $secondaryPrice->last_purchase_price);
        $this->assertSame($secondaryPriceBefore->average_purchase_price, $secondaryPrice->average_purchase_price);

        $this->assertCount(2, ProductPrice::forProduct($product->id)->get());
    }

    private function createSetting(string $suffix): Setting
    {
        return Setting::create([
            'company_name' => 'Company ' . $suffix,
            'company_email' => $suffix . '@company.example',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'left',
            'notification_email' => $suffix . '@notify.example',
            'footer_text' => 'Footer',
            'company_address' => 'Some Address',
        ]);
    }

    private function dispatchImportJob(array $payload): void
    {
        $batch = ProductImportBatch::create([
            'location_id' => $this->location->id,
            'source_csv_path' => 'storage/import.csv',
            'status' => 'queued',
            'total_rows' => 1,
            'processed_rows' => 0,
            'success_rows' => 0,
            'error_rows' => 0,
        ]);

        ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => $payload,
        ]);

        (new ProcessProductImportBatch($batch->id))->handle();
    }
}

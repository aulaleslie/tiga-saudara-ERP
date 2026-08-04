<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\BarcodeIdentity;
use Modules\Setting\Entities\Setting;
use Modules\Product\Services\Ean13Service;
use Tests\TestCase;

class GenerateEan13BarcodesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Ean13Service $ean13Service;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->delete();

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->ean13Service = new Ean13Service();
    }

    private function createProduct(array $attributes = [])
    {
        $name = $attributes['product_name'] ?? 'Test Product ' . uniqid();
        $product = Product::create(array_merge([
            'product_name' => $name,
            'product_code' => 'TEST-' . uniqid(),
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
            'product_quantity' => 0,
            'serial_number_required' => false,
            'product_stock_alert' => 0,
            'product_cost' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'is_sold' => 1,
            'sale_price' => 0,
            'product_price' => 0,
            'last_purchase_price' => 0,
            'average_purchase_price' => 0,
        ], $attributes));

        DB::table('products')->where('id', $product->id)->update(['product_name' => $name]);
        return $product->fresh();
    }

    public function test_complete_catalog_is_processed()
    {
        $this->createProduct(['product_name' => 'Product 1', 'barcode' => null]);
        $this->createProduct(['product_name' => 'Product 2', 'barcode' => '']);
        $this->createProduct(['product_name' => 'Product 3', 'barcode' => 'INVALID']);

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->expectsOutputToContain('Processing 3 products')
            ->assertExitCode(0);
    }

    public function test_valid_ean13_is_preserved()
    {
        $validEan = '5901234123457';
        $product = $this->createProduct(['product_name' => 'Valid EAN Product', 'barcode' => $validEan, 'product_barcode_symbology' => null]);

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();
        $this->assertEquals($validEan, $updated->barcode);
        $this->assertEquals('EAN13', $updated->product_barcode_symbology);
    }

    public function test_invalid_barcode_is_replaced()
    {
        $product = $this->createProduct(['product_name' => 'Invalid Barcode', 'barcode' => 'INVALID-123']);

        $originalBarcode = $product->barcode;

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();
        $this->assertNotEquals($originalBarcode, $updated->barcode);
        $this->assertTrue($this->ean13Service->isValidEan13($updated->barcode));
        $this->assertEquals('EAN13', $updated->product_barcode_symbology);
    }

    public function test_blank_barcode_is_initialized()
    {
        $product = $this->createProduct(['product_name' => 'Blank Barcode', 'barcode' => null]);

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();
        $this->assertNotNull($updated->barcode);
        $this->assertTrue($this->ean13Service->isValidEan13($updated->barcode));
        $this->assertEquals('EAN13', $updated->product_barcode_symbology);
    }

    public function test_symbology_is_set_to_ean13()
    {
        $product = $this->createProduct(['product_name' => 'Symbol Test', 'barcode' => 'INVALID', 'product_barcode_symbology' => 'CODE128']);

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();
        $this->assertEquals('EAN13', $updated->product_barcode_symbology);
    }

    public function test_dry_run_makes_no_changes()
    {
        $product1 = $this->createProduct(['product_name' => 'Product 1', 'barcode' => null]);
        $product2 = $this->createProduct(['product_name' => 'Product 2', 'barcode' => 'INVALID']);

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertNull($product1->fresh()->barcode);
        $this->assertEquals('INVALID', $product2->fresh()->barcode);
    }

    public function test_dry_run_reports_correct_counts()
    {
        $this->createProduct(['product_name' => 'Blank', 'barcode' => null]);
        $this->createProduct(['product_name' => 'Invalid', 'barcode' => 'INVALID']);
        $this->createProduct(['product_name' => 'Valid', 'barcode' => '5901234123457']);

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->expectsOutputToContain('Generated replacements: 2')
            ->expectsOutputToContain('Preserved valid EAN-13s:')
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);
    }

    public function test_idempotent_rerun_preserves_generated_values()
    {
        $product = $this->createProduct(['product_name' => 'Idempotent Test', 'barcode' => null]);

        $this->artisan('product:generate-ean13-barcodes')->assertExitCode(0);
        $firstRun = $product->fresh()->barcode;

        $this->artisan('product:generate-ean13-barcodes')->assertExitCode(0);
        $secondRun = $product->fresh()->barcode;

        $this->assertEquals($firstRun, $secondRun);
    }

    public function test_thirteen_digit_invalid_checksum_is_replaced()
    {
        $invalidChecksum = '5901234123456';
        $product = $this->createProduct(['product_name' => 'Invalid Checksum', 'barcode' => $invalidChecksum]);

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();
        $this->assertNotEquals($invalidChecksum, $updated->barcode);
        $this->assertTrue($this->ean13Service->isValidEan13($updated->barcode));
    }

    public function test_multiple_settings_processed()
    {
        $setting2 = Setting::create([
            'company_name' => 'Test Company 2',
            'company_email' => 'test2@example.com',
            'company_phone' => '0987654321',
            'default_currency_id' => 1,
            'default_currency_position' => 'left',
            'notification_email' => 'notify2@example.com',
            'footer_text' => 'Footer 2',
            'company_address' => 'Address 2',
        ]);

        $product1 = $this->createProduct(['product_name' => 'Setting 1 Product', 'barcode' => null, 'setting_id' => $this->setting->id]);
        $product2 = $this->createProduct(['product_name' => 'Setting 2 Product', 'barcode' => null, 'setting_id' => $setting2->id]);

        $this->artisan('product:generate-ean13-barcodes')
            ->expectsOutputToContain('Processing 2 products')
            ->assertExitCode(0);

        $this->assertNotNull($product1->fresh()->barcode);
        $this->assertNotNull($product2->fresh()->barcode);
    }
}

<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\BarcodeIdentity;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class GenerateEan13DryRunTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;

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

    public function test_dry_run_does_not_modify_product_barcode()
    {
        $originalBarcode = 'INVALID-123';
        $product = $this->createProduct(['product_name' => 'Test', 'barcode' => $originalBarcode]);

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertEquals($originalBarcode, $product->fresh()->barcode);
    }

    public function test_dry_run_does_not_modify_symbology()
    {
        $product = $this->createProduct(['product_name' => 'Test', 'barcode' => 'INVALID', 'product_barcode_symbology' => 'CODE128']);

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertEquals('CODE128', $product->fresh()->product_barcode_symbology);
    }

    public function test_dry_run_does_not_create_identities()
    {
        $product = $this->createProduct(['product_name' => 'Test', 'barcode' => 'INVALID']);

        BarcodeIdentity::query()->delete();

        $countBefore = BarcodeIdentity::query()->count();

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->assertExitCode(0);

        $countAfter = BarcodeIdentity::query()->count();
        $this->assertEquals($countBefore, $countAfter);
    }

    public function test_dry_run_does_not_replace_identities()
    {
        $product = $this->createProduct(['product_name' => 'Test', 'barcode' => 'OLD']);

        $originalIdentity = BarcodeIdentity::create([
            'canonical_key' => 'old',
            'value' => 'OLD',
            'product_id' => $product->id,
        ]);

        $identitiesBefore = BarcodeIdentity::query()->count();

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->assertExitCode(0);

        $identitiesAfter = BarcodeIdentity::query()->count();
        $this->assertEquals($identitiesBefore, $identitiesAfter);

        $stillExists = BarcodeIdentity::find($originalIdentity->id);
        $this->assertNotNull($stillExists);
    }

    public function test_dry_run_reports_outcomes_without_persisting()
    {
        $product1 = $this->createProduct(['product_name' => 'Blank', 'barcode' => null, 'product_barcode_symbology' => null]);
        $product2 = $this->createProduct(['product_name' => 'Valid', 'barcode' => '5901234123457', 'product_barcode_symbology' => null]);
        $product3 = $this->createProduct(['product_name' => 'Invalid', 'barcode' => 'INVALID', 'product_barcode_symbology' => 'CODE128']);

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->expectsOutputToContain('Generated replacements: 2')
            ->expectsOutputToContain('Preserved valid EAN-13s:')
            ->expectsOutputToContain('Symbology updates:')
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertNull($product1->fresh()->barcode);
        $this->assertEquals('5901234123457', $product2->fresh()->barcode);
        $this->assertEquals('CODE128', $product3->fresh()->product_barcode_symbology);
    }

    public function test_dry_run_then_real_run_produces_consistent_results()
    {
        $product1 = $this->createProduct(['product_name' => 'Product 1', 'barcode' => null]);
        $product2 = $this->createProduct(['product_name' => 'Product 2', 'barcode' => 'INVALID']);

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNull($product1->fresh()->barcode);

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $this->assertNotNull($product1->fresh()->barcode);
        $this->assertNotNull($product2->fresh()->barcode);
    }
}

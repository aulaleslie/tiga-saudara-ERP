<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TigaNusaPriceExportCommandTest extends TestCase
{
    use RefreshDatabase;

    private Setting $tigaNusaSetting;
    private Setting $topItSetting;
    private Setting $otherSetting;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->delete();

        $this->tigaNusaSetting = $this->createSetting('CV TIGA NUSA COMPUTER', 'tiga@example.com');
        $this->topItSetting = $this->createSetting('CV TOP IT INTERNUSA', 'topit@example.com');
        $this->otherSetting = $this->createSetting('Other Company', 'other@example.com');
    }

    private function createSetting(string $companyName, string $email): Setting
    {
        return Setting::create([
            'company_name' => $companyName,
            'company_email' => $email,
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
    }

    private function createProduct(array $attributes = []): Product
    {
        $name = $attributes['product_name'] ?? 'Test Product ' . uniqid();
        $product = Product::create(array_merge([
            'product_name' => $name,
            'product_code' => 'TEST-' . uniqid(),
            'setting_id' => $this->tigaNusaSetting->id,
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

    private function setProductPrice(Product $product, Setting $setting, array $prices)
    {
        ProductPrice::updateOrCreate(
            [
                'product_id' => $product->id,
                'setting_id' => $setting->id,
            ],
            [
                'sale_price' => $prices['sale_price'] ?? null,
                'tier_1_price' => $prices['tier_1_price'] ?? null,
                'tier_2_price' => $prices['tier_2_price'] ?? null,
                'last_purchase_price' => $prices['last_purchase_price'] ?? null,
                'average_purchase_price' => $prices['average_purchase_price'] ?? null,
            ]
        );
    }

    private function exportTo(string $path)
    {
        if (file_exists($path)) {
            unlink($path);
        }

        $this->artisan('product:export-tiga-nusa-prices', ['--path' => $path, '--force' => true])
            ->assertExitCode(0);

        return IOFactory::load($path);
    }

    public function test_export_to_default_path()
    {
        $product = $this->createProduct(['product_name' => 'Test Product']);
        $this->setProductPrice($product, $this->tigaNusaSetting, [
            'sale_price' => 100000,
            'tier_1_price' => 90000,
            'tier_2_price' => 80000,
        ]);

        $path = storage_path('app/product_prices_tiga_nusa_export.xlsx');
        if (file_exists($path)) {
            unlink($path);
        }

        $this->artisan('product:export-tiga-nusa-prices')
            ->expectsOutputToContain('CV TIGA NUSA COMPUTER: 1 products exported successfully')
            ->expectsOutputToContain('CV TOP IT INTERNUSA: 1 products exported successfully')
            ->assertExitCode(0);

        $this->assertFileExists($path);
        unlink($path);
    }

    public function test_export_to_custom_path()
    {
        $product = $this->createProduct(['product_name' => 'Test Product']);
        $this->setProductPrice($product, $this->tigaNusaSetting, [
            'sale_price' => 100000,
        ]);

        $path = storage_path('app/custom_export.xlsx');
        if (file_exists($path)) {
            unlink($path);
        }

        $this->artisan('product:export-tiga-nusa-prices', ['--path' => $path])
            ->expectsOutputToContain("exported successfully to {$path}")
            ->assertExitCode(0);

        $this->assertFileExists($path);
        unlink($path);
    }

    public function test_workbook_has_both_company_sheets_in_order_with_six_headers()
    {
        $product = $this->createProduct(['product_name' => 'Test Product']);
        $this->setProductPrice($product, $this->tigaNusaSetting, ['sale_price' => 100000]);

        $path = storage_path('app/test_export.xlsx');
        $spreadsheet = $this->exportTo($path);

        $this->assertSame(2, $spreadsheet->getSheetCount());

        $expectedHeaders = [
            'A4' => 'Nama Produk',
            'B4' => 'Harga Jual',
            'C4' => 'Harga Tier 1',
            'D4' => 'Harga Tier 2',
            'E4' => 'Harga Beli Terakhir',
            'F4' => 'Harga Beli Rata-rata',
        ];

        foreach (['CV TIGA NUSA COMPUTER', 'CV TOP IT INTERNUSA'] as $index => $companyName) {
            $sheet = $spreadsheet->getSheet($index);
            $this->assertEquals($companyName, $sheet->getCell('A1')->getValue());
            $this->assertEquals('Daftar Harga Produk', $sheet->getCell('A2')->getValue());

            foreach ($expectedHeaders as $cell => $header) {
                $this->assertEquals($header, $sheet->getCell($cell)->getValue());
            }
        }

        unlink($path);
    }

    public function test_export_products_ordered_by_name()
    {
        $productA = $this->createProduct(['product_name' => 'Zebra Product']);
        $productB = $this->createProduct(['product_name' => 'Alpha Product']);
        $productC = $this->createProduct(['product_name' => 'Beta Product']);

        $this->setProductPrice($productA, $this->tigaNusaSetting, ['sale_price' => 100000]);
        $this->setProductPrice($productB, $this->tigaNusaSetting, ['sale_price' => 200000]);
        $this->setProductPrice($productC, $this->tigaNusaSetting, ['sale_price' => 300000]);

        $path = storage_path('app/test_export.xlsx');
        $sheet = $this->exportTo($path)->getSheet(0);

        $this->assertEquals('Alpha Product', $sheet->getCell('A5')->getValue());
        $this->assertEquals('Beta Product', $sheet->getCell('A6')->getValue());
        $this->assertEquals('Zebra Product', $sheet->getCell('A7')->getValue());

        unlink($path);
    }

    public function test_export_reports_correct_row_count_per_company()
    {
        $this->createProduct(['product_name' => 'Product A']);
        $this->createProduct(['product_name' => 'Product B']);
        $this->createProduct(['product_name' => 'Product C']);

        $path = storage_path('app/test_export.xlsx');

        $this->artisan('product:export-tiga-nusa-prices', ['--path' => $path, '--force' => true])
            ->expectsOutputToContain('CV TIGA NUSA COMPUTER: 3 products exported successfully')
            ->expectsOutputToContain('CV TOP IT INTERNUSA: 3 products exported successfully')
            ->assertExitCode(0);

        unlink($path);
    }

    public function test_each_sheet_shows_only_its_company_selling_and_tier_prices()
    {
        $product = $this->createProduct(['product_name' => 'Test Product']);

        $this->setProductPrice($product, $this->tigaNusaSetting, [
            'sale_price' => 100000,
            'tier_1_price' => 90000,
            'tier_2_price' => 80000,
        ]);

        $this->setProductPrice($product, $this->topItSetting, [
            'sale_price' => 555555,
            'tier_1_price' => 444444,
            'tier_2_price' => 333333,
        ]);

        $this->setProductPrice($product, $this->otherSetting, [
            'sale_price' => 999999,
            'tier_1_price' => 888888,
            'tier_2_price' => 777777,
        ]);

        $path = storage_path('app/test_export.xlsx');
        $spreadsheet = $this->exportTo($path);

        $tigaNusa = $spreadsheet->getSheet(0);
        $this->assertEquals(100000, $tigaNusa->getCell('B5')->getValue());
        $this->assertEquals(90000, $tigaNusa->getCell('C5')->getValue());
        $this->assertEquals(80000, $tigaNusa->getCell('D5')->getValue());

        $topIt = $spreadsheet->getSheet(1);
        $this->assertEquals(555555, $topIt->getCell('B5')->getValue());
        $this->assertEquals(444444, $topIt->getCell('C5')->getValue());
        $this->assertEquals(333333, $topIt->getCell('D5')->getValue());

        unlink($path);
    }

    public function test_products_without_company_price_included_with_blank_cells()
    {
        $productWithPrice = $this->createProduct(['product_name' => 'Zebra With Price']);
        $this->createProduct(['product_name' => 'Alpha Without Price']);

        $this->setProductPrice($productWithPrice, $this->tigaNusaSetting, [
            'sale_price' => 100000,
            'tier_1_price' => 90000,
            'tier_2_price' => 80000,
        ]);

        $path = storage_path('app/test_export.xlsx');
        $sheet = $this->exportTo($path)->getSheet(0);

        $this->assertEquals('Alpha Without Price', $sheet->getCell('A5')->getValue());
        $this->assertEquals('Zebra With Price', $sheet->getCell('A6')->getValue());

        $this->assertNull($sheet->getCell('B5')->getValue());
        $this->assertNull($sheet->getCell('C5')->getValue());
        $this->assertNull($sheet->getCell('D5')->getValue());

        unlink($path);
    }

    public function test_average_purchase_price_falls_back_to_last_purchase_price()
    {
        $product = $this->createProduct(['product_name' => 'Fallback Product', 'purchase_price' => 10000]);

        $this->setProductPrice($product, $this->tigaNusaSetting, [
            'last_purchase_price' => 75000,
            'average_purchase_price' => 0,
        ]);

        $path = storage_path('app/test_export.xlsx');
        $sheet = $this->exportTo($path)->getSheet(0);

        $this->assertEquals(75000, $sheet->getCell('E5')->getValue());
        $this->assertEquals(75000, $sheet->getCell('F5')->getValue());

        unlink($path);
    }

    public function test_null_average_purchase_price_falls_back_to_last_purchase_price()
    {
        $product = $this->createProduct(['product_name' => 'Null Average Product', 'purchase_price' => 10000]);

        $this->setProductPrice($product, $this->tigaNusaSetting, [
            'last_purchase_price' => 62000,
            'average_purchase_price' => null,
        ]);

        $path = storage_path('app/test_export.xlsx');
        $sheet = $this->exportTo($path)->getSheet(0);

        $this->assertEquals(62000, $sheet->getCell('F5')->getValue());

        unlink($path);
    }

    public function test_missing_last_purchase_price_falls_back_to_product_purchase_price()
    {
        $product = $this->createProduct(['product_name' => 'Product Fallback', 'purchase_price' => 43000]);

        $this->setProductPrice($product, $this->tigaNusaSetting, [
            'last_purchase_price' => 0,
            'average_purchase_price' => null,
        ]);

        $path = storage_path('app/test_export.xlsx');
        $sheet = $this->exportTo($path)->getSheet(0);

        $this->assertEquals(43000, $sheet->getCell('E5')->getValue());
        $this->assertEquals(43000, $sheet->getCell('F5')->getValue());

        unlink($path);
    }

    public function test_purchase_cost_cells_blank_when_no_positive_value_available()
    {
        $product = $this->createProduct(['product_name' => 'No Cost Product', 'purchase_price' => 0]);

        $this->setProductPrice($product, $this->tigaNusaSetting, [
            'sale_price' => 100000,
            'last_purchase_price' => 0,
            'average_purchase_price' => 0,
        ]);

        $path = storage_path('app/test_export.xlsx');
        $sheet = $this->exportTo($path)->getSheet(0);

        $this->assertNull($sheet->getCell('E5')->getValue());
        $this->assertNull($sheet->getCell('F5')->getValue());

        unlink($path);
    }

    public function test_company_purchase_costs_are_isolated_per_sheet()
    {
        $product = $this->createProduct(['product_name' => 'Isolated Cost Product', 'purchase_price' => 1000]);

        $this->setProductPrice($product, $this->tigaNusaSetting, [
            'last_purchase_price' => 50000,
            'average_purchase_price' => 48000,
        ]);
        $this->setProductPrice($product, $this->topItSetting, [
            'last_purchase_price' => 70000,
            'average_purchase_price' => 0,
        ]);

        $path = storage_path('app/test_export.xlsx');
        $spreadsheet = $this->exportTo($path);

        $this->assertEquals(50000, $spreadsheet->getSheet(0)->getCell('E5')->getValue());
        $this->assertEquals(48000, $spreadsheet->getSheet(0)->getCell('F5')->getValue());

        $this->assertEquals(70000, $spreadsheet->getSheet(1)->getCell('E5')->getValue());
        $this->assertEquals(70000, $spreadsheet->getSheet(1)->getCell('F5')->getValue());

        unlink($path);
    }

    public function test_fails_when_target_setting_not_found()
    {
        Setting::where('company_name', 'CV TIGA NUSA COMPUTER')->delete();

        $path = storage_path('app/test_export_not_found.xlsx');
        if (file_exists($path)) {
            unlink($path);
        }

        $this->artisan('product:export-tiga-nusa-prices', ['--path' => $path])
            ->expectsOutputToContain('No setting found with company name "CV TIGA NUSA COMPUTER"')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($path);
    }

    public function test_fails_when_top_it_setting_not_found()
    {
        Setting::where('company_name', 'CV TOP IT INTERNUSA')->delete();

        $path = storage_path('app/test_export_top_it_missing.xlsx');
        if (file_exists($path)) {
            unlink($path);
        }

        $this->artisan('product:export-tiga-nusa-prices', ['--path' => $path])
            ->expectsOutputToContain('No setting found with company name "CV TOP IT INTERNUSA"')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($path);
    }

    public function test_unresolved_top_it_setting_leaves_existing_file_untouched()
    {
        Setting::where('company_name', 'CV TOP IT INTERNUSA')->delete();

        $path = storage_path('app/test_export_untouched.xlsx');
        file_put_contents($path, 'original content');

        $this->artisan('product:export-tiga-nusa-prices', ['--path' => $path, '--force' => true])
            ->expectsOutputToContain('No setting found with company name "CV TOP IT INTERNUSA"')
            ->assertExitCode(1);

        $this->assertEquals('original content', file_get_contents($path));

        unlink($path);
    }

    public function test_cancels_export_when_file_exists_and_user_declines()
    {
        $product = $this->createProduct(['product_name' => 'Test Product']);
        $this->setProductPrice($product, $this->tigaNusaSetting, [
            'sale_price' => 100000,
        ]);

        $path = storage_path('app/test_export.xlsx');

        file_put_contents($path, 'original content');

        $this->artisan('product:export-tiga-nusa-prices', ['--path' => $path])
            ->expectsQuestion("File {$path} already exists. Overwrite?", false)
            ->expectsOutputToContain('Export cancelled')
            ->assertExitCode(1);

        $this->assertEquals('original content', file_get_contents($path));

        unlink($path);
    }

    public function test_overwrites_file_with_force_flag()
    {
        $product = $this->createProduct(['product_name' => 'Test Product']);
        $this->setProductPrice($product, $this->tigaNusaSetting, [
            'sale_price' => 100000,
        ]);

        $path = storage_path('app/test_export.xlsx');

        file_put_contents($path, 'original content');

        $this->artisan('product:export-tiga-nusa-prices', ['--path' => $path, '--force' => true])
            ->assertExitCode(0);

        $this->assertNotEquals('original content', file_get_contents($path));

        unlink($path);
    }
}

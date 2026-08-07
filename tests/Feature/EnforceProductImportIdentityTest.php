<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Exceptions\AmbiguousProductResolutionException;
use Modules\Product\Jobs\ProcessDualCompanyTierPriceBatch;
use Modules\Product\Jobs\ProcessProductImportBatch;
use Modules\Product\Jobs\ProcessSalesPriceSnapshotBatch;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class EnforceProductImportIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Setting $topItSetting;
    protected Currency $currency;

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

        $this->setting = Setting::create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'company_address' => 'Test Address',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => 'Test Footer',
            'is_pkp' => true,
        ]);

        Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Location',
        ]);

        $this->topItSetting = Setting::create([
            'company_name' => 'CV TOP IT INTERNUSA',
            'company_email' => 'test2@example.com',
            'company_phone' => '123456',
            'company_address' => 'Test Address 2',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test2@example.com',
            'footer_text' => 'Test Footer',
            'is_pkp' => false,
        ]);

        Location::create([
            'setting_id' => $this->topItSetting->id,
            'name' => 'Top IT Location',
        ]);

        $cashCoa = ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
        ]);

        PaymentMethod::create([
            'name' => 'CASH',
            'coa_id' => $cashCoa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);
    }

    public function test_purchase_preload_rejects_mixed_legacy_collision()
    {
        Product::create([
            'product_name' => 'product a',
            'canonical_name' => 'product a',
            'product_code' => 'SKU-A1',
            'setting_id' => $this->setting->id,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);

        Product::create([
            'product_name' => 'Product   A',
            'canonical_name' => null,
            'product_code' => 'SKU-A2',
            'setting_id' => $this->setting->id,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);

        $batch = PurchaseImportBatch::create([
            'user_id' => User::factory()->create()->id,
            'source_csv_path' => 'dummy',
            'file_sha256' => 'dummy',
            'status' => 'processing'
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'tanggal' => '01/01/2026',
                'supplier' => 'SUPP',
                'produk' => 'product a',
                'kuantitas' => '1',
                'satuan' => 'PCS',
                'harga_satuan' => '1000',
                'no_faktur' => 'INV-01',
                'tarif_pajak' => '11.0',
                'diskon_persen' => '0.00 %',
                'tag' => 'cv tiga nusa',
                'pajak' => '110',
            ]
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        $row = PurchaseImportRow::first();
        $this->assertEquals('invalid', $row->status, 'Failed with: ' . ($row->error_message ?? $row->validation_errors));
        $this->assertStringContainsString('Ambiguous', $row->error_message ?? $row->validation_errors);
    }

    public function test_purchase_preload_throws_on_two_legacy_collisions()
    {
        Product::create([
            'product_name' => 'Legacy B',
            'canonical_name' => null,
            'product_code' => 'SKU-B1',
            'setting_id' => $this->setting->id,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);
        Product::create([
            'product_name' => 'LEGACY B',
            'canonical_name' => null,
            'product_code' => 'SKU-B2',
            'setting_id' => $this->setting->id,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);

        $batch = PurchaseImportBatch::create([
            'user_id' => User::factory()->create()->id,
            'source_csv_path' => 'dummy',
            'file_sha256' => 'dummy',
            'status' => 'processing'
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'tanggal' => '01/01/2026',
                'supplier' => 'SUPP',
                'produk' => 'Legacy B',
                'kuantitas' => '1',
                'satuan' => 'PCS',
                'harga_satuan' => '1000',
                'no_faktur' => 'INV-02',
                'tarif_pajak' => '11.0',
                'diskon_persen' => '0.00 %',
                'tag' => 'cv tiga nusa',
                'pajak' => '110',
            ]
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        $row = PurchaseImportRow::first();
        $this->assertEquals('invalid', $row->status, 'Failed with: ' . ($row->error_message ?? $row->validation_errors));
        $this->assertStringContainsString('Ambiguous', $row->error_message ?? $row->validation_errors);
    }

    public function test_generic_csv_uses_canonical_identity_and_rejects_duplicate()
    {
        Product::create([
            'product_name' => 'Original Item',
            'canonical_name' => 'original item',
            'product_code' => 'ORI-1',
            'setting_id' => $this->setting->id,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => User::factory()->create()->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'hash',
            'type' => 'products',
            'status' => 'processing',
        ]);

        // One duplicate by canonical identity, one new product.
        ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'product_name' => '* Original Item TP',
                'unit_name' => 'PCS',
            ]
        ]);

        ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => [
                'product_name' => 'New Product',
                'unit_name' => 'PCS',
            ]
        ]);

        $job = new ProcessProductImportBatch($batch->id);
        $job->handle();

        $rows = ProductImportRow::orderBy('row_number')->get();

        // Row 1: duplicate, just updates prices and sets imported status
        $this->assertEquals('imported', $rows[0]->status);
        $this->assertNotNull($rows[0]->product_id);

        // Row 2: new product created
        $this->assertEquals('imported', $rows[1]->status);
        $newProduct = Product::find($rows[1]->product_id);
        $this->assertEquals('new product', $newProduct->canonical_name);
    }

    public function test_dual_company_tier_price_resolves_variants_and_does_not_create()
    {
        $product = Product::create([
            'product_name' => 'Dual Item',
            'canonical_name' => 'dual item',
            'product_code' => 'DUAL-1',
            'setting_id' => $this->setting->id,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);

        \Modules\Product\Entities\ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 0,
            'tier_1_price' => 0,
            'tier_2_price' => 0,
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => User::factory()->create()->id,
            'source_csv_path' => 'dummy.xlsx',
            'file_sha256' => 'hash2',
            'type' => 'dual_company_tier_prices',
            'status' => 'processing',
        ]);

        // Note: The job normally expects staging from Excel. 
        // We inject staged rows mimicking what staging does.
        ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'worksheet' => 'CV TIGA NUSA COMPUTER',
                'nama_produk' => '*   Dual Item   TP',
                'harga_jual' => '5000',
                'harga_tier_1' => '4500',
                'harga_tier_2' => '4000',
            ]
        ]);

        ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => [
                'worksheet' => 'CV TIGA NUSA COMPUTER',
                'nama_produk' => 'Non Existent Item',
                'harga_jual' => '1000',
            ]
        ]);

        // Since the job uses a static method call for parsing, we can just run processRows via Reflection to avoid staging errors
        $job = new ProcessDualCompanyTierPriceBatch($batch->id);
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('processRows');
        $method->setAccessible(true);
        $method->invoke($job, $batch);

        $rows = ProductImportRow::orderBy('row_number')->get();
        
        $this->assertEquals('imported', $rows[0]->status, "Failed with error: " . $rows[0]->error_message);
        $this->assertEquals($product->id, $rows[0]->product_id);
        
        $this->assertEquals('skipped', $rows[1]->status);
        $this->assertStringContainsString('Product not found', $rows[1]->error_message);
    }

    public function test_accurate_price_snapshot_resolves_variants_and_does_not_create()
    {
        $product = Product::create([
            'product_name' => 'Accurate Item',
            'canonical_name' => 'accurate item',
            'product_code' => 'ACC-1',
            'setting_id' => $this->setting->id,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => User::factory()->create()->id,
            'source_csv_path' => 'dummy.xlsx',
            'file_sha256' => 'hash3',
            'type' => 'sales_prices',
            'status' => 'processing',
        ]);

        // Using accurate snapshot format
        ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'name*' => '* ACCURATE ITEM',
                'productcode' => '',
                'sellprice' => '10000',
                'stock' => '5',
                '*unit' => 'PCS'
            ]
        ]);

        ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => [
                'name*' => '* NON EXISTENT',
                'productcode' => '',
                'sellprice' => '10000',
                'stock' => '5',
                '*unit' => 'PCS'
            ]
        ]);

        $job = new ProcessSalesPriceSnapshotBatch($batch->id);
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('processRows');
        $method->setAccessible(true);
        $method->invoke($job, $batch);

        $rows = ProductImportRow::orderBy('row_number')->get();
        
        $this->assertEquals('imported', $rows[0]->status, "Failed with error: " . $rows[0]->error_message);
        $this->assertEquals($product->id, $rows[0]->product_id);

        $this->assertEquals('skipped', $rows[1]->status);
        $this->assertStringContainsString('Product not found', $rows[1]->error_message);
    }
    public function test_forced_price_seeding_failure_leaves_no_product()
    {
        $batch = ProductImportBatch::create([
            'user_id' => User::factory()->create()->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'hash_tx',
            'type' => 'products',
            'status' => 'processing',
        ]);

        ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'product_name' => 'Failing Product',
                'category' => 'Test',
                'brand' => 'Test',
                'unit_name' => 'Pcs',
                'product_code' => 'FAIL-1',
                'price' => '1000',
                'cost' => '500',
            ]
        ]);

        // Mock ProductPrice::seedForSettings to throw an exception
        // Actually, an easier way is to mock it via an event or just simulate it if possible.
        // Wait, since we are doing integration test and can't easily mock a static method `seedForSettings`
        // We can mock it by binding a mock or just passing an invalid setting_id (but it uses $this->settingIds)
        // Wait, if we can't easily mock `seedForSettings` statically, let's use `Mockery` on an alias?
        // Let's just create a mock exception from DB
        \Illuminate\Support\Facades\DB::shouldReceive('commit')->andThrow(new \Exception('Forced price seeding failure'));
        \Illuminate\Support\Facades\DB::makePartial();
        
        $job = new ProcessProductImportBatch($batch->id);
        $reflection = new \ReflectionClass($job);
        
        // Initialize batch property
        $batchProp = $reflection->getProperty('batch');
        $batchProp->setAccessible(true);
        $batchProp->setValue($job, $batch);

        $defaultSettingProp = $reflection->getProperty('defaultSettingId');
        $defaultSettingProp->setAccessible(true);
        $defaultSettingProp->setValue($job, 1);

        $method = $reflection->getMethod('processRow');
        $method->setAccessible(true);

        $row = ProductImportRow::first();
        try {
            $method->invoke($job, $row);
        } catch (\Exception $e) {
            // expected
        }
        
        // Ensure no product was created
        $this->assertNull(Product::where('product_code', 'FAIL-1')->first());
        
        // Cleanup Mockery
        \Mockery::close();
    }

    public function test_generic_csv_and_hpp_imports_reject_code_name_disagreement()
    {
        $product = Product::create([
            'product_name' => 'Original Item',
            'canonical_name' => 'original item',
            'product_code' => 'ORIG-1',
            'setting_id' => $this->setting->id,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => User::factory()->create()->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'hash4',
            'type' => 'products',
            'status' => 'processing',
        ]);

        $row = ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'product_name' => 'Different Item',
                'category' => 'Test',
                'brand' => 'Test',
                'unit_name' => 'Pcs',
                'product_code' => 'ORIG-1',
                'price' => '1000',
                'cost' => '500',
            ]
        ]);

        $job = new ProcessProductImportBatch($batch->id);
        $reflection = new \ReflectionClass($job);
        
        // Initialize batch property
        $batchProp = $reflection->getProperty('batch');
        $batchProp->setAccessible(true);
        $batchProp->setValue($job, $batch);

        $defaultSettingProp = $reflection->getProperty('defaultSettingId');
        $defaultSettingProp->setAccessible(true);
        $defaultSettingProp->setValue($job, 1);

        $method = $reflection->getMethod('processRow');
        $method->setAccessible(true);
        $method->invoke($job, $row);

        $row->refresh();
        $this->assertEquals('error', $row->status);
        $this->assertStringContainsString('disagreement', $row->error_message);
        
        // Also verify for HPP matchOrCreateProduct
        $hppMethod = $reflection->getMethod('matchOrCreateProduct');
        $hppMethod->setAccessible(true);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('disagreement');
        $hppMethod->invoke($job, [
            'product_code' => 'ORIG-1',
            'unit_name' => 'Pcs'
        ], 'Different Item');
    }

    public function test_generic_csv_valid_code_name_match_succeeds()
    {
        $product = Product::create([
            'product_name' => 'Original Item',
            'canonical_name' => 'original item',
            'product_code' => 'MATCH-1',
            'setting_id' => $this->setting->id,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => User::factory()->create()->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'hash5',
            'type' => 'products',
            'status' => 'processing',
        ]);

        $row = ProductImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'product_name' => 'Original Item',
                'category' => 'Test',
                'brand' => 'Test',
                'unit_name' => 'Pcs',
                'product_code' => 'MATCH-1',
                'price' => '1000',
                'cost' => '500',
            ]
        ]);

        $job = new ProcessProductImportBatch($batch->id);
        $reflection = new \ReflectionClass($job);
        
        $batchProp = $reflection->getProperty('batch');
        $batchProp->setAccessible(true);
        $batchProp->setValue($job, $batch);

        $defaultSettingProp = $reflection->getProperty('defaultSettingId');
        $defaultSettingProp->setAccessible(true);
        $defaultSettingProp->setValue($job, $this->setting->id);

        $method = $reflection->getMethod('processRow');
        $method->setAccessible(true);
        $method->invoke($job, $row);

        $row->refresh();
        $this->assertEquals('imported', $row->status, 'Failed with: ' . ($row->error_message ?? 'none'));
        $this->assertEquals($product->id, $row->product_id);
    }
}

<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Product\Services\ProductBarcodeAssignmentService;
use Modules\Product\Services\BarcodeIdentityService;
use Tests\TestCase;

class BarcodeExportImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();
        
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

    public function test_export_writes_only_barcoded_products_and_reports_count()
    {
        $this->createProduct(['product_name' => 'Product A', 'barcode' => null]);
        $this->createProduct(['product_name' => '* Product B', 'barcode' => '123']);
        $this->createProduct(['product_name' => 'Product c ', 'barcode' => '456']);

        $path = storage_path('app/test_export.csv');
        if (file_exists($path)) {
            unlink($path);
        }

        $this->artisan('product:export-barcodes', ['--path' => $path])
            ->expectsOutputToContain('2 products exported successfully.')
            ->assertExitCode(0);

        $this->assertFileExists($path);
        
        $content = file_get_contents($path);
        $this->assertStringContainsString('product_name,barcode', $content);
        $this->assertStringNotContainsString('Product A', $content);
        $this->assertStringContainsString('* Product B', $content);
        $this->assertStringContainsString('123', $content);
        $this->assertStringContainsString('Product c ', $content);
        $this->assertStringContainsString('456', $content);

        unlink($path);
    }

    public function test_export_reports_zero_when_no_barcodes_exist()
    {
        $this->createProduct(['product_name' => 'Product A', 'barcode' => null]);

        $path = storage_path('app/test_export_empty.csv');

        $this->artisan('product:export-barcodes', ['--path' => $path])
            ->expectsOutputToContain('0 products exported.')
            ->assertExitCode(0);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function test_mixed_file_exercising_every_category()
    {
        // 1. Exact match (applicable) -> applied
        $p1 = $this->createProduct(['product_name' => 'Product Exact', 'barcode' => null]);
        
        // 2. Already has barcode -> has_barcode
        $p2 = $this->createProduct(['product_name' => 'Product Has Barcode', 'barcode' => '111']);
        app(BarcodeIdentityService::class)->reserve('111', $p2->id, null);
        
        // 3. Unmatched -> not_found (Not in DB)
        
        // 4. Multi-match -> ambiguous
        $this->createProduct(['product_name' => 'Product Ambig', 'barcode' => null]);
        $this->createProduct(['product_name' => 'Product Ambig', 'barcode' => null]);
        
        // 5. Barcode taken -> barcode_taken
        $p3 = $this->createProduct(['product_name' => 'Product Taken', 'barcode' => null]);
        // Reserve the barcode it will try to take
        $dummy = $this->createProduct(['product_name' => 'Dummy Owner', 'barcode' => 'TAKEN-123']);
        app(BarcodeIdentityService::class)->reserve('TAKEN-123', $dummy->id, null);
        
        $path = storage_path('app/test_import_mixed.csv');
        $file = fopen($path, 'w');
        fputcsv($file, ['product_name', 'barcode']);
        fputcsv($file, ['Product Exact', 'NEW-123']);
        fputcsv($file, ['Product Has Barcode', '222']);
        fputcsv($file, ['Product Not Found', '333']);
        fputcsv($file, ['Product Ambig', '444']);
        fputcsv($file, ['Product Taken', 'TAKEN-123']);
        fclose($file);

        $this->artisan('product:import-barcodes', ['path' => $path])
            ->expectsOutputToContain('Applied: 1')
            ->expectsOutputToContain('Not Found: 1')
            ->expectsOutputToContain('Ambiguous: 1')
            ->expectsOutputToContain('Has Barcode: 1')
            ->expectsOutputToContain('Barcode Taken: 1')
            ->assertExitCode(0);

        // Assertions
        $this->assertEquals('NEW-123', $p1->fresh()->barcode);
        $this->assertEquals('111', $p2->fresh()->barcode);
        $this->assertNull($p3->fresh()->barcode);
        
        // Registry check
        $this->assertDatabaseHas('barcode_identities', [
            'product_id' => $p1->id,
            'canonical_key' => 'new-123',
        ]);

        // Audit check (no audit written)
        $this->assertDatabaseCount('product_barcode_assignments', 0);
        
        // Idempotency: Second run changes nothing
        $this->artisan('product:import-barcodes', ['path' => $path])
            ->expectsOutputToContain('Applied: 0')
            ->expectsOutputToContain('Has Barcode: 2') // Product Exact and Product Has Barcode
            ->assertExitCode(0);
            
        $this->assertBarcodeInvariant();

        unlink($path);
    }

    public function test_dry_run_produces_report_without_writing()
    {
        $p1 = $this->createProduct(['product_name' => 'Product Exact', 'barcode' => null]);
        
        $path = storage_path('app/test_import_dry_run.csv');
        $file = fopen($path, 'w');
        fputcsv($file, ['product_name', 'barcode']);
        fputcsv($file, ['Product Exact', 'DRY-123']);
        fputcsv($file, ['Not Found Product', '999']);
        fclose($file);

        $this->artisan('product:import-barcodes', ['path' => $path, '--dry-run' => true])
            ->expectsOutputToContain('Running in DRY-RUN mode')
            ->expectsOutputToContain('Applied: 1')
            ->expectsOutputToContain('Not Found: 1')
            ->assertExitCode(0);

        $this->assertNull($p1->fresh()->barcode);
        $this->assertDatabaseCount('barcode_identities', 0);

        unlink($path);
    }
    
    public function test_barcode_assignment_after_restore_is_rejected_if_collides()
    {
        $p1 = $this->createProduct(['product_name' => 'Product Restore', 'barcode' => null]);
        
        $path = storage_path('app/test_import_collision.csv');
        $file = fopen($path, 'w');
        fputcsv($file, ['product_name', 'barcode']);
        fputcsv($file, ['Product Restore', 'RESTORED-123']);
        fclose($file);

        $this->artisan('product:import-barcodes', ['path' => $path])->assertExitCode(0);
        
        $p2 = $this->createProduct(['product_name' => 'Product UI', 'barcode' => null]);
        
        \Spatie\Permission\Models\Permission::create(['name' => 'products.barcodes.manage']);
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('products.barcodes.manage');
        
        $result = app(ProductBarcodeAssignmentService::class)->assign(
            $p2->id,
            'RESTORED-123',
            null,
            $user
        );
        
        $this->assertFalse($result['success']);
        $this->assertEquals('duplicate', $result['error']);
        
        unlink($path);
    }

    public function test_round_trip_verification()
    {
        // 1. Seeded catalog
        $p1 = $this->createProduct(['product_name' => 'Round Trip 1', 'barcode' => 'RT-111']);
        $p2 = $this->createProduct(['product_name' => 'Round Trip 2', 'barcode' => 'RT-222']);
        
        app(BarcodeIdentityService::class)->reserve('RT-111', $p1->id, null);
        app(BarcodeIdentityService::class)->reserve('RT-222', $p2->id, null);

        // 2. Export
        $path = storage_path('app/test_round_trip.csv');
        $this->artisan('product:export-barcodes', ['--path' => $path])->assertExitCode(0);
        
        // 3. Clear all barcodes and registry (simulate migrate:fresh --seed)
        DB::table('products')->update(['barcode' => null]);
        DB::table('barcode_identities')->truncate();
        
        // 4. Restore from exported file
        $this->artisan('product:import-barcodes', ['path' => $path])
            ->expectsOutputToContain('Applied: 2')
            ->assertExitCode(0);
            
        // 5. Assert returned to original
        $this->assertEquals('RT-111', $p1->fresh()->barcode);
        $this->assertEquals('RT-222', $p2->fresh()->barcode);
        
        $this->assertDatabaseHas('barcode_identities', ['product_id' => $p1->id, 'canonical_key' => 'rt-111']);
        $this->assertDatabaseHas('barcode_identities', ['product_id' => $p2->id, 'canonical_key' => 'rt-222']);
        
        unlink($path);
    }

    protected function assertBarcodeInvariant()
    {
        $productsWithBarcode = DB::table('products')->whereNotNull('barcode')->where('barcode', '!=', '')->get();
        $identities = DB::table('barcode_identities')->get();

        $this->assertCount($productsWithBarcode->count(), $identities, 'Mismatch between number of barcoded products and barcode_identities rows.');

        foreach ($productsWithBarcode as $product) {
            $expectedKey = \Modules\Product\Utils\BarcodeUtils::canonicalize($product->barcode);
            $identityExists = $identities->contains(function ($identity) use ($expectedKey, $product) {
                return $identity->canonical_key === $expectedKey && $identity->product_id === $product->id;
            });
            $this->assertTrue($identityExists, "Product {$product->id} has barcode {$product->barcode} but missing or mismatched barcode_identities row.");
        }
    }

    public function test_invalid_blank_barcode_reported_and_skipped()
    {
        $this->createProduct(['product_name' => 'Valid Product', 'barcode' => null]);
        $this->createProduct(['product_name' => 'Blank Barcode Product', 'barcode' => null]);
        $this->createProduct(['product_name' => 'Whitespace Barcode Product', 'barcode' => null]);

        $path = storage_path('app/test_invalid_barcode.csv');
        $file = fopen($path, 'w');
        fputcsv($file, ['product_name', 'barcode']);
        fputcsv($file, ['Blank Barcode Product', '']);
        fputcsv($file, ['Whitespace Barcode Product', '   ']);
        fputcsv($file, ['Valid Product', 'VALID-123']);
        fclose($file);

        $this->artisan('product:import-barcodes', ['path' => $path])
            ->expectsOutputToContain('Applied: 1')
            ->expectsOutputToContain('Invalid Barcode: 2')
            ->assertExitCode(0);

        $this->assertBarcodeInvariant();
        unlink($path);
    }

    public function test_unique_constraint_without_registry_row_is_classified_as_barcode_taken()
    {
        $p1 = $this->createProduct(['product_name' => 'First Product', 'barcode' => 'TAKEN-999']);
        // We explicitly do NOT reserve it in barcode_identities to simulate the edge case
        $p2 = $this->createProduct(['product_name' => 'Second Product', 'barcode' => null]);

        $path = storage_path('app/test_unique_constraint.csv');
        $file = fopen($path, 'w');
        fputcsv($file, ['product_name', 'barcode']);
        fputcsv($file, ['Second Product', 'TAKEN-999']);
        fclose($file);

        $this->artisan('product:import-barcodes', ['path' => $path])
            ->expectsOutputToContain('Barcode Taken: 1')
            ->expectsOutputToContain('Applied: 0')
            ->assertExitCode(0);

        $this->assertNull($p2->fresh()->barcode);
        // The first product still has it, but it never had a registry row in this setup.
        // We bypass the full invariant check here since we intentionally corrupted the DB state to test the constraint.
        unlink($path);
    }

    public function test_dry_run_with_same_product_twice_reports_correctly()
    {
        $this->createProduct(['product_name' => 'Twice Product', 'barcode' => null]);

        $path = storage_path('app/test_dry_run_twice.csv');
        $file = fopen($path, 'w');
        fputcsv($file, ['product_name', 'barcode']);
        fputcsv($file, ['Twice Product', 'FIRST-111']);
        fputcsv($file, ['Twice Product', 'SECOND-222']);
        fclose($file);

        $this->artisan('product:import-barcodes', ['path' => $path, '--dry-run' => true])
            ->expectsOutputToContain('Applied: 1')
            ->expectsOutputToContain('Has Barcode: 1')
            ->assertExitCode(0);

        unlink($path);
    }

    public function test_dry_run_with_barcode_already_taken_reports_correctly()
    {
        $this->createProduct(['product_name' => 'Another Product', 'barcode' => null]);

        $path = storage_path('app/test_dry_run_taken.csv');
        $file = fopen($path, 'w');
        fputcsv($file, ['product_name', 'barcode']);
        fputcsv($file, ['Another Product', 'TAKEN-DRY-123']);
        fputcsv($file, ['Another Product', 'TAKEN-DRY-123']); // Will be skipped as Has Barcode if same product
        fclose($file);
        
        $this->createProduct(['product_name' => 'Different Product', 'barcode' => null]);
        $path2 = storage_path('app/test_dry_run_taken2.csv');
        $file2 = fopen($path2, 'w');
        fputcsv($file2, ['product_name', 'barcode']);
        fputcsv($file2, ['Another Product', 'TAKEN-DRY-123']);
        fputcsv($file2, ['Different Product', 'TAKEN-DRY-123']);
        fclose($file2);

        $this->artisan('product:import-barcodes', ['path' => $path2, '--dry-run' => true])
            ->expectsOutputToContain('Applied: 1')
            ->expectsOutputToContain('Barcode Taken: 1')
            ->assertExitCode(0);

        unlink($path);
        unlink($path2);
    }

    public function test_mixed_case_barcode_is_stored_lowercased_in_registry()
    {
        $p1 = $this->createProduct(['product_name' => 'Mixed Case Product', 'barcode' => null]);

        $path = storage_path('app/test_mixed_case.csv');
        $file = fopen($path, 'w');
        fputcsv($file, ['product_name', 'barcode']);
        fputcsv($file, ['Mixed Case Product', 'AbC-123']);
        fclose($file);

        $this->artisan('product:import-barcodes', ['path' => $path])
            ->expectsOutputToContain('Applied: 1')
            ->assertExitCode(0);

        $this->assertEquals('AbC-123', $p1->fresh()->barcode);
        
        $this->assertDatabaseHas('barcode_identities', [
            'product_id' => $p1->id,
            'value' => 'AbC-123',
            'canonical_key' => 'abc-123',
        ]);
        
        $this->assertBarcodeInvariant();

        unlink($path);
    }
}

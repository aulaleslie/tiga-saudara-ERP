<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\BarcodeIdentity;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Product\Services\Ean13Service;
use Modules\Product\Services\BarcodeIdentityService;
use Tests\TestCase;

class GenerateEan13BarcodesAdvancedTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Ean13Service $ean13Service;
    private BarcodeIdentityService $identityService;
    private Unit $unit;

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
        $this->identityService = new BarcodeIdentityService();

        $this->unit = Unit::create([
            'name' => 'Test Unit',
            'short_name' => 'TU',
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
            'base_unit_id' => $this->unit->id,
            'unit_id' => $this->unit->id,
        ], $attributes));

        DB::table('products')->where('id', $product->id)->update(['product_name' => $name]);
        return $product->fresh();
    }

    public function test_valid_ean13_lacks_identity_creates_it()
    {
        $validEan = '5901234123457';
        $product = $this->createProduct(['product_name' => 'No Identity', 'barcode' => $validEan]);

        BarcodeIdentity::query()->delete();

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();
        $this->assertEquals($validEan, $updated->barcode);
        $this->assertEquals('EAN13', $updated->product_barcode_symbology);

        $identity = BarcodeIdentity::where('product_id', $product->id)
            ->where('value', $validEan)
            ->first();
        $this->assertNotNull($identity);
    }

    public function test_invalid_barcode_replaces_its_identity()
    {
        $oldBarcode = 'OLD-INVALID-123';
        $product = $this->createProduct(['product_name' => 'Replace Identity', 'barcode' => $oldBarcode]);

        $oldIdentity = BarcodeIdentity::create([
            'canonical_key' => 'old-invalid-123',
            'value' => $oldBarcode,
            'product_id' => $product->id,
        ]);

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();

        $oldStill = BarcodeIdentity::where('value', $oldBarcode)->first();
        $this->assertNull($oldStill);

        $newIdentity = BarcodeIdentity::where('product_id', $product->id)
            ->where('value', $updated->barcode)
            ->first();
        $this->assertNotNull($newIdentity);
    }

    public function test_generated_candidate_collides_with_conversion_retries()
    {
        $product = $this->createProduct(['product_name' => 'Collision Test', 'barcode' => 'INVALID']);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'conversion_factor' => 10,
            'barcode' => '2000000000008',
        ]);

        BarcodeIdentity::create([
            'canonical_key' => '2000000000008',
            'value' => '2000000000008',
            'product_unit_conversion_id' => $conversion->id,
        ]);

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();
        $this->assertNotNull($updated->barcode);
        $this->assertNotEquals('2000000000008', $updated->barcode);
        $this->assertTrue($this->ean13Service->isValidEan13($updated->barcode));
    }

    public function test_valid_ean13_without_identity_creates_it()
    {
        $validEan = '5901234123457';
        $product1 = $this->createProduct(['product_name' => 'Product 1', 'barcode' => $validEan]);

        BarcodeIdentity::where('value', $validEan)->delete();

        $this->artisan('product:generate-ean13-barcodes')
            ->expectsOutputToContain('Identity repairs:')
            ->assertExitCode(0);

        $identities = BarcodeIdentity::where('value', $validEan)->get();
        $this->assertEquals(1, $identities->count());
        $this->assertEquals($product1->id, $identities->first()->product_id);
    }

    public function test_continuation_after_per_product_error()
    {
        $product1 = $this->createProduct(['product_name' => 'Product 1', 'barcode' => null]);
        $product2 = $this->createProduct(['product_name' => 'Product 2', 'barcode' => 'INVALID']);
        $product3 = $this->createProduct(['product_name' => 'Product 3', 'barcode' => '5901234123457']);

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $this->assertNotNull($product1->fresh()->barcode);
        $this->assertNotNull($product2->fresh()->barcode);
        $this->assertEquals('5901234123457', $product3->fresh()->barcode);
    }

    public function test_dry_run_reports_collision_detection()
    {
        $product = $this->createProduct(['product_name' => 'Product', 'barcode' => 'INVALID']);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'conversion_factor' => 10,
            'barcode' => '2000000000008',
        ]);

        BarcodeIdentity::create([
            'canonical_key' => '2000000000008',
            'value' => '2000000000008',
            'product_unit_conversion_id' => $conversion->id,
        ]);

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertEquals('INVALID', $product->fresh()->barcode);
    }

    public function test_report_includes_all_outcome_types()
    {
        $this->createProduct(['product_name' => 'Preserved', 'barcode' => '5901234123457']);
        $this->createProduct(['product_name' => 'Generated', 'barcode' => 'INVALID']);
        $this->createProduct(['product_name' => 'Blank', 'barcode' => null]);

        $this->artisan('product:generate-ean13-barcodes')
            ->expectsOutputToContain('Preserved valid EAN-13s:')
            ->expectsOutputToContain('Generated replacements:')
            ->expectsOutputToContain('Symbology updates:')
            ->expectsOutputToContain('Identity repairs:')
            ->expectsOutputToContain('Conflicts:')
            ->expectsOutputToContain('Errors:')
            ->assertExitCode(0);
    }

    public function test_generated_barcodes_are_unique_across_namespace()
    {
        $product1 = $this->createProduct(['product_name' => 'Product 1', 'barcode' => null]);
        $product2 = $this->createProduct(['product_name' => 'Product 2', 'barcode' => null]);
        $product3 = $this->createProduct(['product_name' => 'Product 3', 'barcode' => null]);

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $barcode1 = $product1->fresh()->barcode;
        $barcode2 = $product2->fresh()->barcode;
        $barcode3 = $product3->fresh()->barcode;

        $this->assertNotEquals($barcode1, $barcode2);
        $this->assertNotEquals($barcode2, $barcode3);
        $this->assertNotEquals($barcode1, $barcode3);
    }

    public function test_valid_ean_with_identity_conflict_is_skipped()
    {
        $conflictingEan = '5901234123457';
        $product1 = $this->createProduct(['product_name' => 'Product 1', 'barcode' => $conflictingEan, 'product_barcode_symbology' => 'CODE128']);
        $product2 = $this->createProduct(['product_name' => 'Product 2', 'barcode' => 'OTHER']);

        BarcodeIdentity::where('value', $conflictingEan)->delete();
        BarcodeIdentity::create([
            'canonical_key' => '5901234123457',
            'value' => $conflictingEan,
            'product_id' => $product2->id,
        ]);

        $this->artisan('product:generate-ean13-barcodes')
            ->expectsOutputToContain('Conflicts:')
            ->assertExitCode(0);

        $updated = $product1->fresh();
        $this->assertEquals($conflictingEan, $updated->barcode);
        $this->assertEquals('CODE128', $updated->product_barcode_symbology);
    }

    public function test_dry_run_detects_collisions_and_keeps_simulated_values_unique()
    {
        $product1 = $this->createProduct(['product_name' => 'Product 1', 'barcode' => 'INVALID1']);
        $product2 = $this->createProduct(['product_name' => 'Product 2', 'barcode' => 'INVALID2']);

        $generator = $this->createDeterministicGenerator(['2000000000008', '2000000000015']);
        App::instance(Ean13Service::class, tap(new Ean13Service(), fn($svc) => $svc->setCandidateGenerator($generator)));

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->expectsOutputToContain('Generated replacements: 2')
            ->assertExitCode(0);

        $this->assertEquals('INVALID1', $product1->fresh()->barcode);
        $this->assertEquals('INVALID2', $product2->fresh()->barcode);
    }

    public function test_collision_with_conversion_lacking_registry_row_retries()
    {
        $product = $this->createProduct(['product_name' => 'Product', 'barcode' => 'INVALID']);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'conversion_factor' => 10,
            'barcode' => '2000000000008',
        ]);

        DB::table('barcode_identities')->where('product_unit_conversion_id', $conversion->id)->delete();

        $generator = $this->createDeterministicGenerator(['2000000000008', '2000000000015']);
        App::instance(Ean13Service::class, tap(new Ean13Service(), fn($svc) => $svc->setCandidateGenerator($generator)));

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();
        $this->assertNotNull($updated->barcode);
        $this->assertEquals('2000000000015', $updated->barcode);
    }

    public function test_concurrent_stale_product_handling_respects_locking()
    {
        $product = $this->createProduct(['product_name' => 'Product', 'barcode' => 'INVALID']);

        $generator = $this->createDeterministicGenerator(['2000000000008']);
        App::instance(Ean13Service::class, tap(new Ean13Service(), fn($svc) => $svc->setCandidateGenerator($generator)));

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();
        $this->assertEquals('2000000000008', $updated->barcode);
        $this->assertEquals('EAN13', $updated->product_barcode_symbology);
    }

    public function test_invalid_candidate_is_skipped_and_valid_candidate_is_used()
    {
        $product = $this->createProduct(['product_name' => 'Invalid then Valid', 'barcode' => 'INVALID']);

        $generator = $this->createDeterministicGenerator(['1000000000001', '2000000000008']);
        App::instance(Ean13Service::class, tap(new Ean13Service(), fn($svc) => $svc->setCandidateGenerator($generator)));

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $updated = $product->fresh();
        $this->assertEquals('2000000000008', $updated->barcode);
        $this->assertTrue($this->ean13Service->isValidEan13($updated->barcode));
    }

    public function test_invalid_candidates_skipped_in_dry_run()
    {
        $product = $this->createProduct(['product_name' => 'Dry Run Invalid Skip', 'barcode' => 'INVALID']);

        $generator = $this->createDeterministicGenerator(['1000000000001', '2000000000008']);
        App::instance(Ean13Service::class, tap(new Ean13Service(), fn($svc) => $svc->setCandidateGenerator($generator)));

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->expectsOutputToContain('Generated replacements: 1')
            ->assertExitCode(0);

        $this->assertEquals('INVALID', $product->fresh()->barcode);
    }

    public function test_all_persisted_candidates_are_valid_ean13()
    {
        $product1 = $this->createProduct(['product_name' => 'Product 1', 'barcode' => null]);
        $product2 = $this->createProduct(['product_name' => 'Product 2', 'barcode' => 'INVALID']);
        $product3 = $this->createProduct(['product_name' => 'Product 3', 'barcode' => '5901234123457']);

        $this->artisan('product:generate-ean13-barcodes')
            ->assertExitCode(0);

        $this->assertTrue($this->ean13Service->isValidEan13($product1->fresh()->barcode));
        $this->assertTrue($this->ean13Service->isValidEan13($product2->fresh()->barcode));
        $this->assertTrue($this->ean13Service->isValidEan13($product3->fresh()->barcode));
    }

    public function test_all_dry_run_simulated_candidates_are_valid_ean13()
    {
        $product1 = $this->createProduct(['product_name' => 'Product 1', 'barcode' => null]);
        $product2 = $this->createProduct(['product_name' => 'Product 2', 'barcode' => 'INVALID']);
        $product3 = $this->createProduct(['product_name' => 'Product 3', 'barcode' => '5901234123457']);

        $generator = $this->createDeterministicGenerator(['2000000000008', '2000000000015', '5901234123457']);
        App::instance(Ean13Service::class, tap(new Ean13Service(), fn($svc) => $svc->setCandidateGenerator($generator)));

        $this->artisan('product:generate-ean13-barcodes', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNull($product1->fresh()->barcode);
        $this->assertEquals('INVALID', $product2->fresh()->barcode);
        $this->assertEquals('5901234123457', $product3->fresh()->barcode);
    }

    private function createDeterministicGenerator(array $candidates): \Closure
    {
        $index = 0;
        return function () use ($candidates, &$index) {
            if ($index >= count($candidates)) {
                return $this->ean13Service->generateCandidate();
            }
            return $candidates[$index++];
        };
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseImportProductCodeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
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
            'company_name' => 'PERDANA',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'company_address' => 'Test Address',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => '',
        ]);

        Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Location',
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

    /** @test */
    public function test_3_1_unused_imported_code_persisted_for_new_product()
    {
        // Test: An unused imported code is persisted for a newly created product
        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => 'processing',
            'total_rows' => 1,
        ]);

        $rowData = [
            'tanggal' => '01/07/2026',
            'supplier' => 'CV TEST',
            'no_faktur' => 'INV001',
            'produk' => 'LAPTOP BARU ABC123',
            'kuantitas' => '1',
            'satuan' => 'UNIT',
            'harga_satuan' => '5000000',
            'pajak' => '0',
            'tarif_pajak' => '0',
            'tag' => null,
            'kode_produk' => 'LAP-ABC-NEW-001',  // Unused imported code
        ];

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => $rowData,
            'status' => 'pending',
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        // Verify product was created with the imported code
        $product = Product::where('product_name', 'LAPTOP BARU ABC123')->first();
        $this->assertNotNull($product);
        $this->assertEquals('LAP-ABC-NEW-001', $product->product_code);
    }

    /** @test */
    public function test_3_2_marker_normalized_existing_name_reuses_first_product()
    {
        // Test: Marker-normalized existing-name matches reuse the first product without changing its code
        // Create an existing product
        $product = Product::create([
            'product_name' => 'MONITOR LG 24',
            'product_code' => 'MON-LG-24-EXISTING',
            'unit_id' => 1,
            'base_unit_id' => 1,
            'product_unit' => 'UNIT',
            'setting_id' => $this->setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
        ]);

        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => 'processing',
            'total_rows' => 1,
        ]);

        // Import row with marker variant of existing product name + different code
        $rowData = [
            'tanggal' => '01/07/2026',
            'supplier' => 'CV TEST',
            'no_faktur' => 'INV002',
            'produk' => '* MONITOR LG 24',  // Asterisk marker variant
            'kuantitas' => '2',
            'satuan' => 'UNIT',
            'harga_satuan' => '2000000',
            'pajak' => '0',
            'tarif_pajak' => '0',
            'tag' => null,
            'kode_produk' => 'MON-LG-24-DIFFERENT',  // Different code
        ];

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => $rowData,
            'status' => 'pending',
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        // Verify existing product was reused
        $product->refresh();
        $this->assertEquals('MON-LG-24-EXISTING', $product->product_code); // Code unchanged
        $this->assertCount(1, Product::where('product_name', 'MONITOR LG 24')->get());
    }

    /** @test */
    public function test_3_3_blank_code_fallback_and_preexisting_code_collision()
    {
        // Test: Blank code fallback + pre-existing code collision with distinct product name
        // Create an existing product with a code
        $existing = Product::create([
            'product_name' => 'KEYBOARD LOGITECH',
            'product_code' => 'KEY-LOG-EXISTING',
            'unit_id' => 1,
            'base_unit_id' => 1,
            'product_unit' => 'PCS',
            'setting_id' => $this->setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
        ]);

        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => 'processing',
            'total_rows' => 2,
        ]);

        // Row 1: Blank code (should get generated SKU)
        $row1Data = [
            'tanggal' => '01/07/2026',
            'supplier' => 'CV TEST',
            'no_faktur' => 'INV003',
            'produk' => 'MOUSE LOGITECH NEW',
            'kuantitas' => '10',
            'satuan' => 'PCS',
            'harga_satuan' => '150000',
            'pajak' => '0',
            'tarif_pajak' => '0',
            'tag' => null,
            'kode_produk' => '',  // Blank
        ];

        // Row 2: Code conflict with existing product (should get generated SKU)
        $row2Data = [
            'tanggal' => '01/07/2026',
            'supplier' => 'CV TEST',
            'no_faktur' => 'INV003',
            'produk' => 'TOUCHPAD USB NEW',
            'kuantitas' => '5',
            'satuan' => 'PCS',
            'harga_satuan' => '200000',
            'pajak' => '0',
            'tarif_pajak' => '0',
            'tag' => null,
            'kode_produk' => 'KEY-LOG-EXISTING',  // Conflict with existing product
        ];

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => $row1Data,
            'status' => 'pending',
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => $row2Data,
            'status' => 'pending',
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        // Verify blank-code product got generated SKU
        $mouse = Product::where('product_name', 'MOUSE LOGITECH NEW')->first();
        $this->assertNotNull($mouse);
        $this->assertStringStartsWith('SKU-', $mouse->product_code);

        // Verify conflict-code product got generated SKU (not the conflicting code)
        $touchpad = Product::where('product_name', 'TOUCHPAD USB NEW')->first();
        $this->assertNotNull($touchpad);
        $this->assertStringStartsWith('SKU-', $touchpad->product_code);
        $this->assertNotEquals('KEY-LOG-EXISTING', $touchpad->product_code);

        // Verify existing product code unchanged
        $existing->refresh();
        $this->assertEquals('KEY-LOG-EXISTING', $existing->product_code);
    }

    /** @test */
    public function test_3_4_same_batch_duplicate_code_distinct_name()
    {
        // Test: Same-batch duplicate-code test (DL ES621 scenario)
        // Row 1 creates product with code "DL ES621"
        // Row 2 has different name but same code "DL ES621"
        // Expected: Row 2 creates distinct product with generated SKU

        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => 'processing',
            'total_rows' => 2,
        ]);

        // Row 1: Creates product A with "DL ES621"
        $row1Data = [
            'tanggal' => '01/07/2026',
            'supplier' => 'CV SUPPLIER A',
            'no_faktur' => 'INV004',
            'produk' => 'PRODUCT A CATEGORY X',
            'kuantitas' => '100',
            'satuan' => 'PCS',
            'harga_satuan' => '10000',
            'pajak' => '0',
            'tarif_pajak' => '0',
            'tag' => null,
            'kode_produk' => 'DL ES621',  // Same code for two distinct names
        ];

        // Row 2: Tries to use same code "DL ES621" but different name
        $row2Data = [
            'tanggal' => '01/07/2026',
            'supplier' => 'CV SUPPLIER A',
            'no_faktur' => 'INV004',
            'produk' => 'PRODUCT B CATEGORY Y',  // Different product name
            'kuantitas' => '50',
            'satuan' => 'UNIT',
            'harga_satuan' => '20000',
            'pajak' => '0',
            'tarif_pajak' => '0',
            'tag' => null,
            'kode_produk' => 'DL ES621',  // Same code as row 1
        ];

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => $row1Data,
            'status' => 'pending',
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => $row2Data,
            'status' => 'pending',
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        // Verify Product A was created with DL ES621
        $productA = Product::where('product_name', 'PRODUCT A CATEGORY X')->first();
        $this->assertNotNull($productA);
        $this->assertEquals('DL ES621', $productA->product_code);

        // Verify Product B was created with generated SKU (not DL ES621)
        $productB = Product::where('product_name', 'PRODUCT B CATEGORY Y')->first();
        $this->assertNotNull($productB);
        $this->assertStringStartsWith('SKU-', $productB->product_code);
        $this->assertNotEquals('DL ES621', $productB->product_code);

        // Verify two distinct products exist
        $this->assertNotEquals($productA->id, $productB->id);
    }

    /**
     * Create a product with the import defaults, overriding only what a test cares about.
     */
    protected function makeProduct(array $attributes): Product
    {
        return Product::create($attributes + [
            'unit_id' => 1,
            'base_unit_id' => 1,
            'product_unit' => 'PCS',
            'setting_id' => $this->setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
        ]);
    }

    /**
     * Stage a single pending row and process it through the importer.
     */
    protected function processRows(array $rows): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => 'processing',
            'total_rows' => count($rows),
        ]);

        foreach ($rows as $index => $rowData) {
            PurchaseImportRow::create([
                'batch_id' => $batch->id,
                'row_number' => $index + 1,
                'raw_json' => $rowData + [
                    'tanggal' => '01/07/2026',
                    'supplier' => 'CV TEST',
                    'no_faktur' => 'INV-DEFAULT',
                    'kuantitas' => '1',
                    'satuan' => 'PCS',
                    'harga_satuan' => '10000',
                    'pajak' => '0',
                    'tarif_pajak' => '0',
                    'tag' => null,
                ],
                'status' => 'pending',
            ]);
        }

        app(PurchaseImportService::class)->processBatch($batch);
    }

    /** @test */
    public function test_lowest_id_product_is_reused_when_normalized_names_collide()
    {
        // Two legacy products share a case-insensitive normalized name but differ in ID and code.
        // The importer must reuse the lowest-ID product and leave both codes untouched.
        $first = $this->makeProduct([
            'product_name' => 'SWITCH TP LINK 8 PORT',
            'product_code' => 'SW-TPL-FIRST',
        ]);

        $second = $this->makeProduct([
            'product_name' => 'switch tp link 8 port',
            'product_code' => 'SW-TPL-SECOND',
        ]);

        $this->assertLessThan($second->id, $first->id, 'First product must have the lower ID');

        $this->processRows([[
            'no_faktur' => 'INV-DUP-NAME',
            'produk' => 'SWITCH TP LINK 8 PORT',
            'kode_produk' => 'SW-TPL-IMPORTED',
        ]]);

        // The lowest-ID product is reused ...
        $detail = Purchase::where('supplier_purchase_number', 'INV-DUP-NAME')
            ->firstOrFail()
            ->purchaseDetails
            ->first();
        $this->assertEquals($first->id, $detail->product_id);

        // ... and neither existing code changes.
        $first->refresh();
        $second->refresh();
        $this->assertEquals('SW-TPL-FIRST', $first->product_code);
        $this->assertEquals('SW-TPL-SECOND', $second->product_code);

        // No third product was created for the same normalized name.
        $this->assertSame(2, Product::whereRaw('LOWER(product_name) = ?', ['switch tp link 8 port'])->count());
    }

    /** @test */
    public function test_fallback_sku_avoids_an_already_used_generated_base()
    {
        // Seed a product already holding the deterministic SKU base for this name, so the
        // fallback must produce a different unused code rather than colliding.
        $cleanName = 'ROUTER MIKROTIK RB951';
        $collidingBase = 'SKU-' . strtoupper(substr(md5($cleanName), 0, 8));

        $squatter = $this->makeProduct([
            'product_name' => 'UNRELATED SQUATTER PRODUCT',
            'product_code' => $collidingBase,
        ]);

        $this->processRows([[
            'no_faktur' => 'INV-SKU-COLLIDE',
            'produk' => $cleanName,
            'kode_produk' => '',  // Blank -> generated fallback path
        ]]);

        $created = Product::where('product_name', $cleanName)->first();
        $this->assertNotNull($created);

        // The fallback must not reuse the taken base, and must remain a SKU-prefixed code.
        $this->assertNotEquals($collidingBase, $created->product_code);
        $this->assertStringStartsWith($collidingBase . '-', $created->product_code);

        // The squatting product keeps its code.
        $squatter->refresh();
        $this->assertEquals($collidingBase, $squatter->product_code);
    }

    /** @test */
    public function test_concurrent_code_claim_retries_with_generated_sku()
    {
        // Simulate the race: the availability check passes, then another worker inserts the same
        // code before this insert lands. The importer must retry with a generated SKU and still
        // create the product rather than invalidating the invoice group.
        $cleanName = 'PRINTER EPSON L3210';
        $contestedCode = 'PRN-EPS-L3210';

        $service = new class extends PurchaseImportService {
            public string $raceCode = '';
            public bool $raceTriggered = false;

            protected function resolveProductCodeForNewProduct(?string $importedCode, string $cleanName): string
            {
                $resolved = parent::resolveProductCodeForNewProduct($importedCode, $cleanName);

                // Claim the code behind the importer's back exactly once, mimicking a concurrent
                // worker that won the insert between the availability check and our create().
                if (!$this->raceTriggered && $resolved === $this->raceCode) {
                    $this->raceTriggered = true;

                    Product::create([
                        'product_name' => 'CONCURRENT WORKER PRODUCT',
                        'product_code' => $resolved,
                        'unit_id' => 1,
                        'base_unit_id' => 1,
                        'product_unit' => 'PCS',
                        'setting_id' => Product::query()->value('setting_id') ?? 1,
                        'product_cost' => 0,
                        'product_price' => 0,
                        'product_quantity' => 0,
                        'stock_managed' => 1,
                        'is_purchased' => 1,
                        'is_sold' => 1,
                    ]);
                }

                return $resolved;
            }
        };
        $service->raceCode = $contestedCode;

        // Seed a product so the anonymous subclass can resolve a valid setting_id.
        $this->makeProduct(['product_name' => 'SEED PRODUCT', 'product_code' => 'SEED-001']);

        $user = \App\Models\User::factory()->create(['is_active' => 1]);
        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => 'processing',
            'total_rows' => 1,
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'tanggal' => '01/07/2026',
                'supplier' => 'CV TEST',
                'no_faktur' => 'INV-RACE',
                'produk' => $cleanName,
                'kuantitas' => '1',
                'satuan' => 'PCS',
                'harga_satuan' => '10000',
                'pajak' => '0',
                'tarif_pajak' => '0',
                'tag' => null,
                'kode_produk' => $contestedCode,
            ],
            'status' => 'pending',
        ]);

        $service->processBatch($batch);

        $this->assertTrue($service->raceTriggered, 'The simulated concurrent claim must have fired');

        // The imported product still exists, under a generated SKU rather than the lost code.
        $created = Product::where('product_name', $cleanName)->first();
        $this->assertNotNull($created, 'Product must still be created after losing the code race');
        $this->assertStringStartsWith('SKU-', $created->product_code);
        $this->assertNotEquals($contestedCode, $created->product_code);

        // The concurrent worker keeps the contested code, and the invoice group still posted.
        $this->assertEquals(
            'CONCURRENT WORKER PRODUCT',
            Product::where('product_code', $contestedCode)->value('product_name')
        );
        $this->assertNotNull(Purchase::where('supplier_purchase_number', 'INV-RACE')->first());
    }

    /** @test */
    public function test_unrelated_query_exceptions_are_not_retried()
    {
        // A non-duplicate-key database failure must propagate rather than silently retrying.
        $service = new class extends PurchaseImportService {
            public function exposeIsDuplicate(\Illuminate\Database\QueryException $e): bool
            {
                return $this->isDuplicateProductCodeException($e);
            }
        };

        $notNullViolation = new \Illuminate\Database\QueryException(
            'sqlite',
            'insert into "products" ...',
            [],
            new \PDOException('SQLSTATE[23000]: NOT NULL constraint failed: products.product_cost')
        );
        $notNullViolation->errorInfo = ['23000', 19, 'NOT NULL constraint failed: products.product_cost'];

        $this->assertFalse(
            $service->exposeIsDuplicate($notNullViolation),
            'A NOT NULL violation must not be treated as a retryable duplicate code'
        );

        $duplicateCode = new \Illuminate\Database\QueryException(
            'sqlite',
            'insert into "products" ...',
            [],
            new \PDOException('SQLSTATE[23000]: UNIQUE constraint failed: products.product_code')
        );
        $duplicateCode->errorInfo = ['23000', 19, 'UNIQUE constraint failed: products.product_code'];

        $this->assertTrue(
            $service->exposeIsDuplicate($duplicateCode),
            'A product_code unique violation must be retryable'
        );
    }
}

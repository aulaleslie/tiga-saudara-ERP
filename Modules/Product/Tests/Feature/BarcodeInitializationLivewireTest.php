<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBarcodeAssignment;
use Modules\Product\Livewire\BarcodeInitialization;
use Modules\Product\Services\BarcodeIdentityService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;

class BarcodeInitializationLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $identityService;
    protected $setting;
    protected $unit;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        Permission::firstOrCreate(['name' => 'products.barcodes.manage']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('products.barcodes.manage');
        
        $this->identityService = app(BarcodeIdentityService::class);
        
        $currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'              => 'Alpha Co',
            'company_email'             => 'alpha@example.com',
            'company_phone'             => '11111',
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'left',
            'notification_email'        => 'notify-alpha@example.com',
            'footer_text'               => 'Alpha',
            'company_address'           => 'Alpha Street',
        ]);

        $this->unit = Unit::create([
            'name'       => 'Piece',
            'short_name' => 'Pcs',
            'setting_id' => $this->setting->id,
        ]);
        
        $this->category = Category::create([
            'category_name' => 'Test Category',
            'category_code' => 'TC',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);
        
        session(['setting_id' => $this->setting->id]);
    }

    private function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Default Name',
            'product_code' => 'DEF-1',
            'barcode' => null,
            'product_price' => 100,
            'product_cost' => 80,
            'product_quantity' => 0,
            'product_stock_alert' => 0,
            'category_id' => $this->category->id,
            'stock_managed' => true,
            'is_purchased' => false,
            'is_sold' => false,
            'setting_id' => $this->setting->id,
            'base_unit_id' => $this->unit->id,
        ], $attributes));
    }

    public function test_can_search_products_and_filter_uninitialized()
    {
        $this->createProduct(['product_name' => 'Produk A', 'product_code' => 'PA', 'barcode' => null]);
        $this->createProduct(['product_name' => 'Produk B', 'product_code' => 'PB', 'barcode' => '12345']);
        $this->identityService->reserve('12345', Product::where('barcode', '12345')->first()->id);

        Livewire::actingAs($this->user)
            ->test(BarcodeInitialization::class)
            ->assertSee('PRODUK A')
            ->assertDontSee('PRODUK B') // Because filterUninitializedOnly is true by default
            ->set('filterUninitializedOnly', false)
            ->assertSee('PRODUK A')
            ->assertSee('PRODUK B')
            ->set('searchQuery', 'PA')
            ->assertSee('PRODUK A')
            ->assertDontSee('PRODUK B');
    }

    public function test_can_select_product_and_transition_state()
    {
        $product = $this->createProduct(['product_name' => 'Scanner Test', 'barcode' => null]);

        Livewire::actingAs($this->user)
            ->test(BarcodeInitialization::class)
            ->assertSet('currentState', 'SEARCHING')
            ->call('selectProduct', $product->id)
            ->assertSet('selectedProductId', $product->id)
            ->assertSet('currentState', 'READY_TO_SCAN')
            ->assertDispatched('product-selected');
    }

    public function test_can_capture_scanner_input_and_transition_to_review()
    {
        $product = $this->createProduct(['barcode' => null]);

        Livewire::actingAs($this->user)
            ->test(BarcodeInitialization::class)
            ->call('selectProduct', $product->id)
            ->call('handleScan', '987654321  ') // trailing whitespace typical from scanner
            ->assertSet('candidateBarcode', '987654321')
            ->assertSet('currentState', 'REVIEW')
            ->assertDispatched('review-ready');
    }

    public function test_can_initialize_new_barcode()
    {
        $product = $this->createProduct(['barcode' => null]);

        Livewire::actingAs($this->user)
            ->test(BarcodeInitialization::class)
            ->call('selectProduct', $product->id)
            ->call('handleScan', '987654321')
            ->call('save')
            ->assertSet('currentState', 'SEARCHING') // Returns to search on success
            ->assertDispatched('save-success');

        $this->assertEquals('987654321', $product->fresh()->barcode);
        $this->assertDatabaseHas('product_barcode_assignments', [
            'product_id' => $product->id,
            'new_barcode' => '987654321',
            'action' => 'initialize',
        ]);
        $this->assertDatabaseHas('barcode_identities', [
            'canonical_key' => '987654321',
            'product_id' => $product->id,
        ]);
    }

    public function test_can_replace_existing_barcode()
    {
        $product = $this->createProduct(['barcode' => 'OLD123']);
        $this->identityService->reserve('OLD123', $product->id);

        Livewire::actingAs($this->user)
            ->test(BarcodeInitialization::class)
            ->set('filterUninitializedOnly', false)
            ->call('selectProduct', $product->id)
            ->call('handleScan', 'NEW456')
            ->call('save');

        $this->assertEquals('NEW456', $product->fresh()->barcode);
        
        // Old identity should be released
        $this->assertDatabaseMissing('barcode_identities', [
            'canonical_key' => 'old123',
        ]);
        
        // New identity reserved
        $this->assertDatabaseHas('barcode_identities', [
            'canonical_key' => 'new456',
            'product_id' => $product->id,
        ]);

        $this->assertDatabaseHas('product_barcode_assignments', [
            'product_id' => $product->id,
            'old_barcode' => 'OLD123',
            'new_barcode' => 'NEW456',
            'action' => 'replace',
        ]);
    }

    public function test_prevents_duplicate_barcode_assignment()
    {
        $existingProduct = $this->createProduct(['barcode' => 'DUP999', 'product_code' => 'EXISTING']);
        $this->identityService->reserve('DUP999', $existingProduct->id);

        $newProduct = $this->createProduct(['barcode' => null, 'product_code' => 'NEWPROD']);

        Livewire::actingAs($this->user)
            ->test(BarcodeInitialization::class)
            ->call('selectProduct', $newProduct->id)
            ->call('handleScan', 'DUP999')
            ->call('save')
            ->assertSet('currentState', 'READY_TO_SCAN')
            ->assertSet('candidateError', "Barcode sudah digunakan oleh Produk Utama (ID Produk: {$existingProduct->id}).");

        $this->assertNull($newProduct->fresh()->barcode);
    }

    public function test_scanner_cancellation()
    {
        $product = $this->createProduct(['barcode' => null]);
        \Livewire\Livewire::actingAs($this->user)
            ->test(\Modules\Product\Livewire\BarcodeInitialization::class)
            ->call('selectProduct', $product->id)
            ->call('handleScan', '987654321')
            ->call('cancelSelection')
            ->assertSet('currentState', 'SEARCHING')
            ->assertSet('selectedProductId', null)
            ->assertSet('candidateBarcode', null)
            ->assertSet('candidateError', null);
    }

    public function test_no_op()
    {
        $product = $this->createProduct(['barcode' => 'EXISTING123']);
        $this->identityService->reserve('EXISTING123', $product->id);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\Modules\Product\Livewire\BarcodeInitialization::class)
            ->set('filterUninitializedOnly', false)
            ->call('selectProduct', $product->id)
            ->call('handleScan', 'EXISTING123')
            ->call('save')
            ->assertSet('currentState', 'READY_TO_SCAN')
            ->assertSet('candidateError', 'Barcode baru sama dengan barcode lama (No-op).');

        $this->assertEquals('EXISTING123', $product->fresh()->barcode);
    }

    public function test_conversion_conflicts()
    {
        $conversionProduct = $this->createProduct(['product_code' => 'P123']);
        $mockService = \Mockery::mock(\Modules\Product\Services\ProductBarcodeAssignmentService::class);
        $mockService->shouldReceive('assign')->andReturn([
            'success' => false,
            'error' => 'duplicate',
            'conflict' => [
                'type' => 'conversion',
                'product_id' => $conversionProduct->id,
                'conversion_id' => 999,
                'product_code' => 'P123',
                'product_name' => 'Produk 123',
                'unit_name' => 'Box',
                'multiplier' => 10
            ]
        ]);
        app()->instance(\Modules\Product\Services\ProductBarcodeAssignmentService::class, $mockService);

        $product = $this->createProduct(['barcode' => null]);
        \Livewire\Livewire::actingAs($this->user)
            ->test(\Modules\Product\Livewire\BarcodeInitialization::class)
            ->call('selectProduct', $product->id)
            ->call('handleScan', 'CONFLICT123')
            ->call('save')
            ->assertSet('currentState', 'READY_TO_SCAN');
    }

    public function test_stale_updates()
    {
        $product = $this->createProduct(['barcode' => 'OLD_VAL']);
        $test = \Livewire\Livewire::actingAs($this->user)
            ->test(\Modules\Product\Livewire\BarcodeInitialization::class)
            ->set('filterUninitializedOnly', false)
            ->call('selectProduct', $product->id)
            ->call('handleScan', 'SCAN123'); // Ensure it transitions to REVIEW state

        // Make the DB stale
        $product->update(['barcode' => 'NEW_VAL']);

        $test->call('save')
            ->assertSet('currentState', 'READY_TO_SCAN')
            ->assertSet('candidateError', 'Status barcode produk telah berubah oleh pengguna lain. Silakan pilih kembali.');
    }

    public function test_recent_activity_and_counters()
    {
        $product1 = $this->createProduct(['product_code' => 'A1', 'product_name' => 'P1', 'barcode' => null]);
        $product2 = $this->createProduct(['product_code' => 'A2', 'product_name' => 'P2', 'barcode' => null]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\Modules\Product\Livewire\BarcodeInitialization::class)
            ->call('selectProduct', $product1->id)
            ->call('handleScan', 'B1')
            ->call('save')
            ->assertSet('sessionSavedCount', 1)
            ->call('selectProduct', $product2->id)
            ->call('handleScan', 'B2')
            ->call('save')
            ->assertSet('sessionSavedCount', 2);
    }

    public function test_cross_setting_product_search_and_assignment()
    {
        $otherSetting = Setting::create([
            'company_name'              => 'Beta Co',
            'company_email'             => 'beta@example.com',
            'company_phone'             => '22222',
            'default_currency_id'       => $this->setting->default_currency_id,
            'default_currency_position' => 'left',
            'notification_email'        => 'notify-beta@example.com',
            'footer_text'               => 'Beta',
            'company_address'           => 'Beta Street',
        ]);

        $otherProduct = $this->createProduct([
            'product_name' => 'Produk Lintas Cabang',
            'product_code' => 'X-1',
            'barcode' => null,
            'setting_id' => $otherSetting->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(BarcodeInitialization::class)
            ->set('searchQuery', 'Lintas Cabang')
            ->assertSee('PRODUK LINTAS CABANG')
            ->call('selectProduct', $otherProduct->id)
            ->assertSet('selectedProductId', $otherProduct->id)
            ->call('handleScan', 'X-BARCODE')
            ->call('save')
            ->assertDispatched('save-success');

        $this->assertEquals('X-BARCODE', $otherProduct->fresh()->barcode);
    }

    public function test_tokenized_product_search()
    {
        $this->createProduct([
            'product_name' => 'LAPTOP GAMING ACER',
            'product_code' => 'L-ACER',
            'barcode' => null,
        ]);

        Livewire::actingAs($this->user)
            ->test(BarcodeInitialization::class)
            ->set('searchQuery', 'acer laptop')
            ->assertSee('LAPTOP GAMING ACER');
    }

    public function test_valid_barcode_renders_svg_without_error()
    {
        $product = $this->createProduct(['barcode' => null]);

        Livewire::actingAs($this->user)
            ->test(BarcodeInitialization::class)
            ->call('selectProduct', $product->id)
            ->call('handleScan', '089686180657')
            ->assertSee('<svg', false)
            ->assertDontSee('Tidak dapat merender preview barcode.');
    }
}

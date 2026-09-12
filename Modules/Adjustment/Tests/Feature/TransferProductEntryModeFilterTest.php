<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Transfer\SearchProduct;
use App\Livewire\Transfer\TransferProductTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferProductEntryModeFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Location $origin;
    private Product $goodOnlyProduct;
    private Product $brokenOnlyProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // TransferProductTable enforces stockTransfers.create/edit surface
        // authorization on its own (it is an independently callable
        // Livewire endpoint), so a user driving it directly in tests needs
        // this permission regardless of route-level gating.
        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name' => 'stockTransfers.create',
            'guard_name' => 'web',
        ]);
        $this->user->givePermissionTo('stockTransfers.create');

        $currency = Currency::create([
            'currency_name' => 'Rupiah Test',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '08001234567',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => 'Test Address',
        ]);

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Origin Warehouse',
        ]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'created_by' => $this->user->id,
        ]);

        $this->goodOnlyProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Good Only Product',
            'product_code' => 'GOOD-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->goodOnlyProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 4,
            'quantity_non_tax' => 6,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $this->brokenOnlyProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Broken Only Product',
            'product_code' => 'BROK-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->brokenOnlyProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 3,
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    /** @test */
    public function good_mode_search_excludes_products_with_only_broken_stock()
    {
        Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->set('query', 'Product')
            ->assertSee('Good Only Product')
            ->assertDontSee('Broken Only Product');
    }

    /** @test */
    public function breakage_mode_search_excludes_products_with_only_good_stock()
    {
        Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_BREAKAGE,
            ])
            ->set('query', 'Product')
            ->assertSee('Broken Only Product')
            ->assertDontSee('Good Only Product');
    }

    /** @test */
    public function duplicate_product_scan_increments_existing_row_instead_of_duplicating()
    {
        $table = Livewire::actingAs($this->user)
            ->test(TransferProductTable::class, ['originLocationId' => $this->origin->id, 'stockCondition' => Transfer::CONDITION_GOOD])
            ->call('productSelected', array_merge($this->goodOnlyProduct->toArray(), ['is_broken_mode' => false]))
            ->call('productSelected', array_merge($this->goodOnlyProduct->toArray(), ['is_broken_mode' => false]));

        $products = $table->get('products');
        $this->assertCount(1, $products, 'Scanning the same product twice in the same mode must not create a duplicate row');
        $this->assertEquals(2, $products[0]['requested_quantity']);
    }

    /** @test */
    public function serial_selection_rejects_a_serial_whose_condition_does_not_match_the_row_mode()
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->goodOnlyProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-BROKEN-1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => 1,
            'tax_id' => null,
            'dispatch_detail_id' => null,
            'is_in_return_process' => 0,
        ]);

        $this->goodOnlyProduct->update(['serial_number_required' => true]);

        $table = Livewire::actingAs($this->user)
            ->test(TransferProductTable::class, ['originLocationId' => $this->origin->id, 'stockCondition' => Transfer::CONDITION_GOOD])
            ->call('productSelected', [
                'id' => $this->goodOnlyProduct->id,
                'is_broken_mode' => false,
            ]);

        $rowKey = 0;

        $table->call('serialNumberSelected', [
            'productCompositeKey' => $rowKey,
            'serialNumber' => [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'tax_id' => $serial->tax_id,
                'is_broken' => true,
            ],
        ]);

        // TransferProductTable authoritatively reloads and rejects condition-mismatched serials
        $products = $table->get('products');
        $serials = collect($products[$rowKey]['serial_numbers'] ?? []);

        $this->assertCount(0, $serials);
        $this->assertNotNull($table->get('serialNumberErrors')[$rowKey] ?? null);
    }

    /** @test */
    public function duplicate_serial_selection_across_rows_is_rejected()
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->goodOnlyProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-DUP-1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => 0,
            'tax_id' => null,
            'dispatch_detail_id' => null,
            'is_in_return_process' => 0,
        ]);

        $this->goodOnlyProduct->update(['serial_number_required' => true]);

        $table = Livewire::actingAs($this->user)
            ->test(TransferProductTable::class, ['originLocationId' => $this->origin->id, 'stockCondition' => Transfer::CONDITION_GOOD])
            ->call('productSelected', [
                'id' => $this->goodOnlyProduct->id,
                'is_broken_mode' => false,
            ]);

        $payload = [
            'productCompositeKey' => 0,
            'serialNumber' => [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'tax_id' => $serial->tax_id,
                'is_broken' => false,
            ],
        ];

        $table->call('serialNumberSelected', $payload);
        $table->call('serialNumberSelected', $payload);

        $products = $table->get('products');
        $this->assertCount(1, $products[0]['serial_numbers']);
        $this->assertEquals('Nomor seri sudah dipilih.', $table->get('serialNumberErrors')[0]);
    }

    /** @test */
    public function tokenized_search_matches_category_brand_barcode_and_code()
    {
        $brand = \Modules\Product\Entities\Brand::create([
            'setting_id' => $this->setting->id,
            'name' => 'AcmeBrand',
            'created_by' => $this->user->id,
        ]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'ELECTRONICS',
            'category_name' => 'Gadgets',
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'product_name' => 'Super Widget Pro',
            'product_code' => 'WIDGET-XYZ',
            'barcode' => 'BC-WIDGET-999',
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 5,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Search by category token
        Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->set('query', 'Gadgets')
            ->assertSee('Super Widget Pro');

        // Search by brand token
        Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->set('query', 'AcmeBrand')
            ->assertSee('Super Widget Pro');

        // Search by barcode token
        Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->set('query', 'BC-WIDGET-999')
            ->assertSee('Super Widget Pro');

        // Multi-token: name + code
        Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->set('query', 'Widget XYZ')
            ->assertSee('Super Widget Pro');
    }

    /** @test */
    public function ambiguous_search_results_are_presented_without_automatic_selection()
    {
        $category = Category::first();

        $item1 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Delta Alpha Widget',
            'product_code' => 'DAW-001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'stock_managed' => true,
        ]);
        ProductStock::create([
            'product_id' => $item1->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 5,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $item2 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Delta Beta Widget',
            'product_code' => 'DBW-002',
            'product_cost' => 1000,
            'product_price' => 2000,
            'stock_managed' => true,
        ]);
        ProductStock::create([
            'product_id' => $item2->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 5,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $test = Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->set('query', 'Delta Widget')
            ->assertSee('Delta Alpha Widget')
            ->assertSee('Delta Beta Widget')
            ->assertNotDispatched('productSelected');

        $this->assertCount(2, $test->get('search_results'));
    }

    /** @test */
    public function scan_barcode_passes_operation_token_to_product_selected_event()
    {
        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-SCAN-' . uniqid(),
            'category_name' => 'Category Scan',
            'created_by' => 1,
        ]);

        $item = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Scan Test Product',
            'product_code' => 'STP-001',
            'barcode' => 'BARCODE-STP-001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'stock_managed' => true,
        ]);
        ProductStock::create([
            'product_id' => $item->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 5,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $token = 'txscan-token-livewire-test';

        Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('scanBarcode', 'BARCODE-STP-001', $token)
            ->assertDispatched('productSelected', function ($event, $params) use ($item, $token) {
                $payload = $params[0] ?? [];
                return ($payload['id'] ?? null) === $item->id
                    && ($payload['operation_token'] ?? null) === $token
                    && ($payload['is_broken_mode'] ?? null) === false;
            })
            ->assertDispatched('restore-scanner-focus');
    }

    /** @test */
    public function scan_barcode_passes_operation_token_to_serial_scanned_event()
    {
        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-SCAN-SN-' . uniqid(),
            'category_name' => 'Category Scan SN',
            'created_by' => 1,
        ]);

        $serialItem = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Serial Scan Test Product',
            'product_code' => 'SSTP-001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => true,
            'stock_managed' => true,
        ]);
        ProductStock::create([
            'product_id' => $serialItem->id,
            'location_id' => $this->origin->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $serialItem->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-EXACT-001',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $token = 'txscan-token-serial-test';

        Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('scanBarcode', 'SN-EXACT-001', $token)
            ->assertDispatched('productSelected', function ($event, $params) use ($serialItem) {
                $payload = $params[0] ?? [];
                return ($payload['id'] ?? null) === $serialItem->id;
            })
            ->assertDispatched('serialScanned', function ($event, $params) use ($serial, $token) {
                $payload = $params[0] ?? [];
                return ($payload['id'] ?? null) === $serial->id
                    && ($payload['operation_token'] ?? null) === $token;
            })
            ->assertDispatched('restore-scanner-focus');
    }
}

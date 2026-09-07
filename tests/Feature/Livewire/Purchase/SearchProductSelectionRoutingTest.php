<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Purchase;

use App\Livewire\Adjustment\AdjustmentProductTable;
use App\Livewire\Adjustment\BreakageProductTable;
use App\Livewire\Purchase\ProductCart;
use App\Livewire\Purchase\SearchProduct;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\AdjustedProduct;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SearchProductSelectionRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $location;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'adjustments.access',
            'adjustments.create',
            'adjustments.edit',
            'adjustments.breakage.create',
            'adjustments.breakage.edit',
            'products.create',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Location',
            'is_consignment' => false,
        ]);

        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->assignRole($role);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Laptop',
            'product_code' => 'SKU-LAPTOP-1',
            'product_quantity' => 10,
            'serial_number_required' => true,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);
    }

    /**
     * Task 2.1: Focused Livewire checks for:
     * - Default purchase recipient
     * - Explicit table recipients
     * - Payload preservation including serial requirements
     * - Recipient persistence across requests
     * - Rejection of client recipient updates
     */
    public function test_search_product_defaults_to_product_cart_recipient(): void
    {
        $this->actingAs($this->user);

        $payload = [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'serial_number_required' => true,
        ];

        Livewire::test(SearchProduct::class)
            ->assertSet('selectionTarget', ProductCart::class)
            ->call('selectProduct', $payload)
            ->assertDispatchedTo(ProductCart::class, 'productSelected', function ($eventName, $params) use ($payload) {
                return $params === [$payload];
            });
    }

    public function test_search_product_routes_to_explicit_adjustment_table_target(): void
    {
        $this->actingAs($this->user);

        $payload = [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'serial_number_required' => true,
        ];

        Livewire::test(SearchProduct::class, [
            'selectionTarget' => AdjustmentProductTable::class,
        ])
            ->assertSet('selectionTarget', AdjustmentProductTable::class)
            ->call('selectProduct', $payload)
            ->assertDispatchedTo(AdjustmentProductTable::class, 'productSelected', function ($eventName, $params) use ($payload) {
                return $params === [$payload];
            });
    }

    public function test_search_product_routes_to_explicit_breakage_table_target(): void
    {
        $this->actingAs($this->user);

        $payload = [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'serial_number_required' => true,
        ];

        Livewire::test(SearchProduct::class, [
            'selectionTarget' => BreakageProductTable::class,
        ])
            ->assertSet('selectionTarget', BreakageProductTable::class)
            ->call('selectProduct', $payload)
            ->assertDispatchedTo(BreakageProductTable::class, 'productSelected', function ($eventName, $params) use ($payload) {
                return $params === [$payload];
            });
    }

    public function test_selection_target_persists_across_subsequent_requests(): void
    {
        $this->actingAs($this->user);

        $payload = [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'serial_number_required' => true,
        ];

        Livewire::test(SearchProduct::class, [
            'selectionTarget' => AdjustmentProductTable::class,
        ])
            ->set('query', 'Test')
            ->call('loadMore')
            ->assertSet('selectionTarget', AdjustmentProductTable::class)
            ->call('selectProduct', $payload)
            ->assertDispatchedTo(AdjustmentProductTable::class, 'productSelected', function ($eventName, $params) use ($payload) {
                return $params === [$payload];
            });
    }

    public function test_selection_target_cannot_be_updated_by_client(): void
    {
        $this->actingAs($this->user);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [selectionTarget]');

        Livewire::test(SearchProduct::class, [
            'selectionTarget' => AdjustmentProductTable::class,
        ])
            ->set('selectionTarget', ProductCart::class);
    }

    /**
     * Task 2.2: Parameterized page-render checks for the configured search recipient
     * on all four affected forms using isolated fixtures and existing authorization conventions.
     *
     * @dataProvider pageBindingProvider
     */
    public function test_page_renders_configured_search_recipient(string $routeGetter, string $expectedTarget): void
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $url = call_user_func([$this, $routeGetter]);

        $response = $this->get($url);
        $response->assertOk();

        // Check that the rendered page contains the search component wired with expected selection-target
        $escapedTarget = htmlspecialchars($expectedTarget, ENT_QUOTES, 'UTF-8');
        $response->assertSee('&quot;selectionTarget&quot;:&quot;' . str_replace('\\', '\\\\', $escapedTarget) . '&quot;', false);
    }

    public static function pageBindingProvider(): array
    {
        return [
            'adjustment create' => ['getAdjustmentCreateUrl', AdjustmentProductTable::class],
            'adjustment edit' => ['getAdjustmentEditUrl', AdjustmentProductTable::class],
            'breakage create' => ['getBreakageCreateUrl', BreakageProductTable::class],
            'breakage edit' => ['getBreakageEditUrl', BreakageProductTable::class],
        ];
    }

    public function getAdjustmentCreateUrl(): string
    {
        return route('adjustments.create');
    }

    public function getAdjustmentEditUrl(): string
    {
        $adjustment = Adjustment::create([
            'reference' => 'ADJ-TEST-1',
            'date' => now()->format('Y-m-d'),
            'location_id' => $this->location->id,
            'note' => 'Edit test',
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'quantity_tax' => 0,
            'quantity_non_tax' => 1,
            'serial_numbers' => json_encode([]),
            'type' => 'sub',
            'is_taxable' => 0,
        ]);

        return route('adjustments.edit', $adjustment);
    }

    public function getBreakageCreateUrl(): string
    {
        return route('adjustments.createBreakage');
    }

    public function getBreakageEditUrl(): string
    {
        $adjustment = Adjustment::create([
            'reference' => 'BRK-TEST-1',
            'date' => now()->format('Y-m-d'),
            'location_id' => $this->location->id,
            'note' => 'Breakage edit test',
            'type' => 'breakage',
            'status' => 'pending',
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'quantity_tax' => 0,
            'quantity_non_tax' => 1,
            'serial_numbers' => json_encode([]),
            'type' => 'sub',
            'is_taxable' => 0,
        ]);

        return route('adjustments.editBreakage', $adjustment);
    }

    /**
     * Task 2.3: Verify adjustment and breakage selection events:
     * - produce one correctly initialized row with a selected location
     * - preserve existing rows
     * - retain no-location and duplicate feedback without adding rows in those cases
     */
    public function test_adjustment_table_selection_flow(): void
    {
        $this->actingAs($this->user);

        $product2 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Second Product',
            'product_code' => 'SKU-2',
            'product_quantity' => 5,
            'serial_number_required' => false,
            'product_cost' => 500,
            'product_price' => 750,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product2->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // 1. Without location -> no row added, warning flash
        $component = Livewire::test(AdjustmentProductTable::class)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'serial_number_required' => true,
            ])
            ->assertCount('products', 0)
            ->assertSee('Pilih lokasi terlebih dahulu sebelum menambahkan produk.');

        // 2. Select location -> add product 1
        $component->call('locationSelected', $this->location->id)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'serial_number_required' => true,
            ])
            ->assertCount('products', 1);

        $row1 = $component->get('products')[0];
        $this->assertEquals($this->product->id, $row1['id']);
        $this->assertEquals('Test Laptop', $row1['product_name']);
        $this->assertTrue($row1['serial_number_required']);

        // 3. Duplicate selection -> no extra row, duplicate flash message
        $component->call('productSelected', [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'serial_number_required' => true,
        ])
            ->assertCount('products', 1)
            ->assertSee('Produk sudah dipilih.');

        // 4. Select product 2 -> adds second row and retains existing row
        $component->call('productSelected', [
            'id' => $product2->id,
            'product_name' => $product2->product_name,
            'product_code' => $product2->product_code,
            'serial_number_required' => false,
        ])
            ->assertCount('products', 2);

        $this->assertEquals($this->product->id, $component->get('products')[0]['id']);
        $this->assertEquals($product2->id, $component->get('products')[1]['id']);
    }

    public function test_breakage_table_selection_flow(): void
    {
        $this->actingAs($this->user);

        $product2 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Breakage Item 2',
            'product_code' => 'SKU-BRK-2',
            'product_quantity' => 5,
            'serial_number_required' => false,
            'product_cost' => 200,
            'product_price' => 300,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product2->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // 1. Without location -> no row added, warning flash
        $component = Livewire::test(BreakageProductTable::class)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'serial_number_required' => true,
            ])
            ->assertCount('products', 0)
            ->assertSee('Pilih lokasi terlebih dahulu sebelum menambahkan produk.');

        // 2. Select location -> add product 1
        $component->call('locationSelected', $this->location->id)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'serial_number_required' => true,
            ])
            ->assertCount('products', 1);

        $row1 = $component->get('products')[0];
        $this->assertEquals($this->product->id, $row1['id']);
        $this->assertEquals('Test Laptop', $row1['product_name']);
        $this->assertTrue($row1['serial_number_required']);
        $this->assertEquals(['tax' => 0, 'non_tax' => 0], $component->get('quantities')[0]);

        // 3. Duplicate selection -> no extra row, duplicate flash message
        $component->call('productSelected', [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'serial_number_required' => true,
        ])
            ->assertCount('products', 1)
            ->assertSee('Produk sudah dipilih.');

        // 4. Select product 2 -> adds second row and retains existing row
        $component->call('productSelected', [
            'id' => $product2->id,
            'product_name' => $product2->product_name,
            'product_code' => $product2->product_code,
            'serial_number_required' => false,
        ])
            ->assertCount('products', 2);

        $this->assertEquals($this->product->id, $component->get('products')[0]['id']);
        $this->assertEquals($product2->id, $component->get('products')[1]['id']);
    }
}

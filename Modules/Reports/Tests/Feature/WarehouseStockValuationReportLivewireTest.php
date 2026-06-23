<?php

namespace Modules\Reports\Tests\Feature;

use App\Livewire\Reports\WarehouseStockValuationReport;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseStockValuationReportLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $currency;
    protected $location1;
    protected $location2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
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
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => 'Test Address'
        ]);

        $this->user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'inventoryValuationReports.access', 'guard_name' => 'web']);
        $this->user->givePermissionTo('inventoryValuationReports.access');

        $this->location1 = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse A'
        ]);

        $this->location2 = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse B'
        ]);
    }

    private function makeCategory(string $name = 'General', ?int $settingId = null): Category
    {
        return Category::create([
            'setting_id' => $settingId ?? $this->setting->id,
            'category_code' => 'CAT-' . strtoupper(uniqid()),
            'category_name' => $name, 'created_by' => $this->user->id,
        ]);
    }

    private function makeProduct(Category $category, string $code, string $name, bool $stockManaged = true, float $averagePrice = 0, float $minQty = 0, ?int $settingId = null): Product
    {
        $settingId = $settingId ?? $this->setting->id;

        $product = Product::create([
            'setting_id' => $settingId,
            'category_id' => $category->id,
            'product_name' => $name,
            'product_code' => $code,
            'stock_managed' => $stockManaged,
            'average_purchase_price' => $averagePrice,
            'product_stock_alert' => $minQty, 'product_cost' => 0, 'product_price' => 0, 'product_cost' => 0, 'product_price' => 0
        ]);


        return $product;
    }

                private function makeTransaction(Product $product, Location $location, string $type, float $qty, string $date, string $reason = ''): Transaction
    {
        $trx = Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $product->setting_id,
            'location_id' => $location->id,
            'user_id' => $this->user->id ?? 1,
            'type' => $type,
            'quantity' => $qty,
            'quantity_non_tax' => $qty,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'previous_quantity' => 0,
            'after_quantity' => $qty,
            'current_quantity' => $qty,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => $qty,
            'current_quantity_at_location' => $qty,
            'reason' => $reason,
        ]);
        $trx->created_at = \Carbon\Carbon::parse($date);
        $trx->updated_at = \Carbon\Carbon::parse($date);
        $trx->save(['timestamps' => false]);
        return $trx;
    }

    /** @test */
    public function it_shows_initial_empty_state_and_average_cost_note()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        Livewire::test(WarehouseStockValuationReport::class)
            ->assertSee('Nilai stok gudang (dalam IDR)')
            ->assertSee('Nilai persediaan menggunakan harga rata-rata produk')
            ->assertSee('Silakan atur filter dan klik')
            ->assertSet('filterTriggered', false);
    }

    /** @test */
    public function it_applies_filters_and_displays_warehouse_groups()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'SKU-001', 'Test Product', true, 10000, 5);
        $this->makeTransaction($product, $this->location1, 'init', 10, now()->format('Y-m-d H:i:s'));

        Livewire::test(WarehouseStockValuationReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSet('filterTriggered', true)
            ->assertSee('SKU-001')
            ->assertSee('TEST PRODUCT')
            ->assertSee('WAREHOUSE A')
            ->assertSee('WAREHOUSE B')
            ->assertSee('10,00') // Qty
            ->assertSee('100.000,00'); // Value
    }

    /** @test */
    public function it_shows_blank_dash_for_nullable_product_codes()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $category = $this->makeCategory();
        $product = $this->makeProduct($category, '', 'No Code Product', true, 10000);
        $this->makeTransaction($product, $this->location1, 'init', 10, now()->format('Y-m-d H:i:s'));

        Livewire::test(WarehouseStockValuationReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee('NO CODE PRODUCT')
            ->assertSee('-');
    }

    /** @test */
    public function it_resets_and_cancels_advanced_filters()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        Livewire::test(WarehouseStockValuationReport::class)
            ->set('productStockStatus', 'out_of_stock')
            ->call('resetFilters')
            ->assertSet('productStockStatus', '')
            ->set('productStockStatus', 'available')
            ->call('cancelFilters')
            ->assertSet('productStockStatus', ''); // Falls back to applied filter which is empty initially
    }
}

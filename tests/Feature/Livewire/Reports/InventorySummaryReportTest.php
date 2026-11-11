<?php

namespace Tests\Feature\Livewire\Reports;

use App\Livewire\Reports\InventorySummaryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class InventorySummaryReportTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Location $location;
    private \Modules\Product\Entities\Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventoryValuationReports.access']);
        $role = Role::firstOrCreate(['name' => 'Admin']);
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('inventoryValuationReports.access');
        $this->actingAs($user);
        $this->setting = Setting::factory()->create();
        $user->settings()->attach($this->setting->id, ['role_id' => $role->id]);
        $this->location = Location::create(['setting_id' => $this->setting->id, 'name' => 'Main', 'is_active' => true]);
        session(['setting_id' => $this->setting->id]);
        $this->category = \Modules\Product\Entities\Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C1', 'category_name' => 'Cat1', 'created_by' => $user->id]);
    }

    private function createStock(int $productId, float $quantity = 10): ProductStock
    {
        return ProductStock::create([
            'product_id' => $productId,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'quantity_tax' => $quantity,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
    }

    public function test_it_renders_successfully()
    {
        Livewire::test(InventorySummaryReport::class)
            ->assertStatus(200)
            ->assertSee('Per Tanggal')
            ->assertSee('Silakan atur filter dan klik', false);
    }

    public function test_it_displays_data_after_filter_applied()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'stock_managed' => true,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_price' => 100,
            'product_cost' => 100,
        ]);
        $this->createStock($product->id);

        Livewire::test(InventorySummaryReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSet('filterTriggered', true)
            ->assertSee('Test Product', false)
            ->assertSee('TEST-001')
            ->assertSee('Harga Rata-rata')
            ->assertSee('Nilai');
    }


    public function test_it_exports_excel_successfully()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'stock_managed' => true,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_price' => 100,
            'product_cost' => 100,
        ]);
        $this->createStock($product->id);

        Livewire::test(InventorySummaryReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('exportExcel')
            ->assertFileDownloaded(sprintf('ringkasan-persediaan-barang_%s.xlsx', now()->format('d-m-Y')));
    }

    public function test_it_can_render_the_inventory_summary_report_page_for_authorized_users()
    {
        Permission::firstOrCreate(['name' => 'inventoryValuationReports.access']);
        $user = User::factory()->create();
        $user->givePermissionTo('inventoryValuationReports.access');
        $role = Role::firstOrCreate(['name' => 'Admin']);
        $user->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $this->actingAs($user)
            ->get(route('reports.inventory-summary-report.index'))
            ->assertStatus(200)
            ->assertSeeLivewire('reports.inventory-summary-report');
    }

    public function test_it_hides_the_report_from_unauthorized_users()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('reports.inventory-summary-report.index'))
            ->assertStatus(403);
    }

    public function test_it_can_reset_and_cancel_filters()
    {
        Livewire::test(InventorySummaryReport::class)
            ->set('asOfDate', '2020-01-01')
            ->call('applyFilters')
            ->set('asOfDate', '2021-01-01')
            ->call('cancelFilters')
            ->assertSet('asOfDate', '2020-01-01')
            ->call('resetFilters')
            ->assertSet('asOfDate', now()->format('Y-m-d'));
    }

    public function test_it_does_not_contain_warehouse_selector()
    {
        Livewire::test(InventorySummaryReport::class)
            ->assertDontSee('Pilih Gudang')
            ->assertDontSee('warehouse_id');
    }

    public function test_it_shows_pagination_totals()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'stock_managed' => true,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_price' => 100,
            'product_cost' => 100,
        ]);
        $this->createStock($product->id);

        Livewire::test(InventorySummaryReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSet('filterTriggered', true)
            ->assertSee('Total Produk');
    }
}

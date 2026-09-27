<?php

namespace Tests\Feature\Livewire\Reports;

use App\Livewire\Reports\InventoryValuationReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class InventoryValuationReportTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;

    private \Modules\Product\Entities\Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventoryValuationReports.access']);
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('inventoryValuationReports.access');
        $this->actingAs($user);
        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);
        $this->category = \Modules\Product\Entities\Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C1', 'category_name' => 'Cat1', 'created_by' => $user->id]);
    }

    public function test_it_renders_successfully()
    {
        Livewire::test(InventoryValuationReport::class)
            ->assertStatus(200)
            ->assertSee('Per Tanggal')
            ->assertSee('Silakan atur filter dan klik', false);
    }

    public function test_it_displays_data_after_filter_applied()
    {
        Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'stock_managed' => true,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_price' => 100,
            'product_cost' => 100,
        ]);

        Livewire::test(InventoryValuationReport::class)
            ->set('tanggalAwal', now()->startOfMonth()->format('Y-m-d'))
            ->set('tanggalAkhir', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSet('filterTriggered', true)
            ->assertSee('TEST-001')
            ->assertSee('Harga Rata-rata')
            ->assertSee('Nilai');
    }

    public function test_it_exports_excel_successfully()
    {
        Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'stock_managed' => true,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_price' => 100,
            'product_cost' => 100,
        ]);

        Livewire::test(InventoryValuationReport::class)
            ->set('tanggalAwal', now()->startOfMonth()->format('Y-m-d'))
            ->set('tanggalAkhir', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('exportExcel')
            ->assertFileDownloaded(sprintf('nilai-persediaan-barang_%s_sd_%s.xlsx', now()->startOfMonth()->format('d-m-Y'), now()->endOfMonth()->format('d-m-Y')));
    }

    public function test_it_can_render_the_inventory_valuation_report_page_for_authorized_users()
    {
        Permission::firstOrCreate(['name' => 'inventoryValuationReports.access']);
        $user = User::factory()->create();
        $user->givePermissionTo('inventoryValuationReports.access');

        $this->actingAs($user)
            ->get(route('reports.inventory-valuation-report.index'))
            ->assertStatus(200)
            ->assertSeeLivewire('reports.inventory-valuation-report');
    }

    public function test_it_hides_the_report_from_unauthorized_users()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('reports.inventory-valuation-report.index'))
            ->assertStatus(403);
    }

    public function test_it_shows_pagination_totals()
    {
        Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'stock_managed' => true,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_price' => 100,
            'product_cost' => 100,
        ]);

        Livewire::test(InventoryValuationReport::class)
            ->set('tanggalAwal', now()->startOfMonth()->format('Y-m-d'))
            ->set('tanggalAkhir', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSet('filterTriggered', true)
            ->assertSee('Total Produk');
    }

    public function test_it_can_expand_product_and_load_details()
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

        $component = Livewire::test(InventoryValuationReport::class)
            ->set('tanggalAwal', now()->startOfMonth()->format('Y-m-d'))
            ->set('tanggalAkhir', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('toggleProduct', $product->id);
            
        $this->assertContains($product->id, $component->get('expandedProducts'));
        $this->assertArrayHasKey($product->id, $component->get('loadedProductDetails'));
    }

    public function test_it_clears_expanded_state_on_filter_change()
    {
        Livewire::test(InventoryValuationReport::class)
            ->set('expandedProducts', [1])
            ->set('loadedProductDetails', [1 => []])
            ->set('tanggalAwal', '2026-01-01')
            ->set('tanggalAkhir', '2026-01-31')
            ->call('applyFilters')
            ->assertSet('expandedProducts', [])
            ->assertSet('loadedProductDetails', []);
    }
}

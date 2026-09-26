<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\StockInsights;
use App\Livewire\Reports\StockInsightsMinimumModal;
use App\Models\User;
use App\Services\Reports\StockInsightsQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StockInsightsLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $location;
    protected Customer $customer;
    protected Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Permission::findOrCreate('stockInsights.access', 'web');
        $this->user->givePermissionTo('stockInsights.access');

        $this->setting = Setting::factory()->create(['company_name' => 'Bisnis Utama']);
        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Gudang Pusat',
            'is_active' => true,
        ]);

        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $this->now = Carbon::parse('2026-09-26 12:00:00');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createProduct(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'setting_id' => $this->setting->id,
            'product_name' => 'Produk ' . Str::random(5),
            'product_code' => 'PRD-' . Str::random(5),
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_stock_alert' => 5,
            'is_active' => true,
            'stock_managed' => true,
            'merged_into_id' => null,
            'created_at' => $this->now->copy()->subDays(100),
        ], $attrs));
    }

    protected function attachStock(Product $product, float $qty): void
    {
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => $qty,
            'quantity_tax' => $qty,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
    }

    public function test_component_renders_indonesian_terminology_and_expected_columns(): void
    {
        $this->actingAs($this->user);

        $product = $this->createProduct([
            'product_name' => 'Beras Pandan Wangi',
            'product_code' => 'BPW-001',
            'product_stock_alert' => 10,
        ]);
        $this->attachStock($product, 15);

        Livewire::test(StockInsights::class)
            ->assertStatus(200)
            ->assertSee('Pantauan Stok')
            ->assertSee('Stok Habis')
            ->assertSee('Perlu Dibeli Lagi')
            ->assertSee('Batas Minimum Belum Diatur')
            ->assertSee('Lama Tidak Terjual')
            ->assertSee('Stok Global')
            ->assertSee('Kuantitas Terjual')
            ->assertSee('Nilai Penjualan')
            ->assertSee('Modal Terjual')
            ->assertSee('Laba Kotor')
            ->assertSee('Penjualan Terakhir')
            ->assertSee('Beras Pandan Wangi')
            ->assertSee('BPW-001');
    }

    public function test_global_expansion_and_business_expansion_toggles(): void
    {
        $this->actingAs($this->user);

        $product = $this->createProduct();
        $this->attachStock($product, 10);

        $test = Livewire::test(StockInsights::class)
            ->assertSet('isGlobalExpanded', false)
            ->call('toggleGlobalExpansion')
            ->assertSet('isGlobalExpanded', true)
            ->call('toggleBusinessExpansion', $this->setting->id)
            ->assertSet("expandedBusinesses.{$this->setting->id}", true)
            ->assertSee($this->location->name)
            ->call('toggleGlobalExpansion')
            ->assertSet('isGlobalExpanded', false);
    }

    public function test_period_preset_and_custom_duration_display(): void
    {
        $this->actingAs($this->user);

        Livewire::test(StockInsights::class)
            ->assertSet('preset', '7')
            ->assertSet('startDate', '2026-09-20')
            ->set('preset', '30')
            ->assertSet('startDate', '2026-08-28')
            ->set('startDate', '2026-09-09') // 18 days
            ->assertSet('preset', 'custom')
            ->assertSee('18 Hari Terakhir');
    }

    public function test_inline_minimum_stock_modal_update_and_atomic_save(): void
    {
        $this->actingAs($this->user);

        $product = $this->createProduct([
            'product_name' => 'Minyak Goreng Sawit',
            'product_stock_alert' => 0,
        ]);
        $this->attachStock($product, 8);

        Livewire::test(StockInsightsMinimumModal::class)
            ->call('openMinimumModal', $product->id, '2026-09-20', '7 Hari Terakhir')
            ->assertSet('showMinimumModal', true)
            ->assertSet('modalProductName', 'Minyak Goreng Sawit')
            ->assertSet('modalMinimumInput', '0')
            ->set('modalMinimumInput', '25')
            ->call('saveMinimumStock')
            ->assertSet('showMinimumModal', false)
            ->assertDispatched('stock-insights-minimum-saved');

        $this->assertEquals(25, $product->fresh()->product_stock_alert);
    }

    public function test_modal_prefill_avoids_recomputing_sales_aggregates(): void
    {
        $this->actingAs($this->user);

        $product = $this->createProduct([
            'product_name' => 'Gula Pasir',
            'product_stock_alert' => 3,
        ]);

        Livewire::test(StockInsightsMinimumModal::class)
            ->call(
                'openMinimumModal',
                $product->id,
                '2026-09-20',
                '7 Hari Terakhir',
                'Gula Pasir',
                $product->product_code,
                null,
                3,
                12.5,
                10.0,
                2.5,
                0.0,
                0.0,
                4.0,
                '2026-09-24'
            )
            ->assertSet('showMinimumModal', true)
            ->assertSet('modalProductName', 'Gula Pasir')
            ->assertSet('modalGlobalGoodStock', 12.5)
            ->assertSet('modalSoldQuantity', 4.0)
            ->assertSet('modalLastSaleDate', '2026-09-24')
            ->assertSet('modalMinimumInput', '3');
    }

    public function test_minimum_stock_validation_rejects_negative_or_non_integer(): void
    {
        $this->actingAs($this->user);

        $product = $this->createProduct(['product_stock_alert' => 5]);

        Livewire::test(StockInsightsMinimumModal::class)
            ->call('openMinimumModal', $product->id, '2026-09-20', '7 Hari Terakhir')
            ->set('modalMinimumInput', '-5')
            ->call('saveMinimumStock')
            ->assertSet('showMinimumModal', true)
            ->assertSee('Batas minimum stok harus berupa angka bulat positif')
            ->set('modalMinimumInput', 'abc')
            ->call('saveMinimumStock')
            ->assertSet('showMinimumModal', true)
            ->assertSee('Batas minimum stok harus berupa angka bulat positif');

        $this->assertEquals(5, $product->fresh()->product_stock_alert);
    }

    public function test_composition_tooltips_render_four_bucket_composition(): void
    {
        $this->actingAs($this->user);

        $product = $this->createProduct(['product_name' => 'Kopi Robusta']);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 20,
            'quantity_tax' => 12,
            'quantity_non_tax' => 8,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 3,
            'broken_quantity_non_tax' => 2,
        ]);

        Livewire::test(StockInsights::class)
            ->assertSee('Bagus Pajak: 12')
            ->assertSee('Bagus Non-Pajak: 8')
            ->assertSee('Rusak Pajak: 3')
            ->assertSee('Rusak Non-Pajak: 2')
            ->assertSee('(Rusak: 5)');
    }

    public function test_search_and_filters_and_future_date_rejection(): void
    {
        $this->actingAs($this->user);

        $p1 = $this->createProduct(['product_name' => 'Kecap Manis Cap Bango', 'product_code' => 'KCP-01']);
        $p2 = $this->createProduct(['product_name' => 'Saus Tomat ABC', 'product_code' => 'SAUS-01']);
        $this->attachStock($p1, 5);
        $this->attachStock($p2, 5);

        // Search test
        Livewire::test(StockInsights::class)
            ->set('search', 'Bango')
            ->assertSee('Kecap Manis Cap Bango')
            ->assertDontSee('Saus Tomat ABC')
            ->set('search', '')
            ->assertSee('Saus Tomat ABC');

        // Future date rejection
        Livewire::test(StockInsights::class)
            ->set('startDate', $this->now->copy()->addDays(2)->format('Y-m-d'))
            ->assertHasErrors(['startDate']);
    }

    public function test_sorting_columns_and_fallback_ordering(): void
    {
        $this->actingAs($this->user);

        $p1 = $this->createProduct(['product_name' => 'Alpha Item', 'product_code' => 'A-01']);
        $p2 = $this->createProduct(['product_name' => 'Zeta Item', 'product_code' => 'Z-01']);
        $this->attachStock($p1, 10);
        $this->attachStock($p2, 20);

        Livewire::test(StockInsights::class)
            ->call('sortBy', 'product_name')
            ->assertSet('sortColumn', 'product_name')
            ->assertSet('sortDirection', 'asc')
            ->call('sortBy', 'product_name')
            ->assertSet('sortDirection', 'desc')
            ->call('sortBy', 'invalid_column') // Should be ignored
            ->assertSet('sortColumn', 'product_name');
    }

    public function test_disappearing_row_feedback_when_row_leaves_active_filter(): void
    {
        $this->actingAs($this->user);

        $p = $this->createProduct([
            'product_name' => 'Barang Khusus',
            'product_stock_alert' => 0, // Batas Minimum Belum Diatur
        ]);
        $this->attachStock($p, 10);

        // User filters by 'Batas Minimum Belum Diatur'
        $report = Livewire::test(StockInsights::class)
            ->set('statuses', ['Batas Minimum Belum Diatur'])
            ->assertSee($p->product_code);

        Livewire::test(StockInsightsMinimumModal::class)
            ->call('openMinimumModal', $p->id, '2026-09-20', '7 Hari Terakhir')
            ->set('modalMinimumInput', '15')
            ->call('saveMinimumStock');

        $report->dispatch('stock-insights-minimum-saved', productId: $p->id, productName: $p->product_name, newMinimum: 15)
            ->assertSee('berhasil diperbarui')
            ->assertSee('tidak lagi memenuhi filter status yang aktif')
            ->assertDontSee($p->product_code); // Row leaves active filter!

        $this->assertEquals(15, $p->fresh()->product_stock_alert);
    }

    public function test_toggle_status_card_click(): void
    {
        $this->actingAs($this->user);

        Livewire::test(StockInsights::class)
            ->call('toggleStatus', 'Stok Habis')
            ->assertSet('statuses', ['Stok Habis'])
            ->call('toggleStatus', 'Perlu Dibeli Lagi')
            ->assertSet('statuses', ['Stok Habis', 'Perlu Dibeli Lagi'])
            ->call('toggleStatus', 'Stok Habis')
            ->assertSet('statuses', ['Perlu Dibeli Lagi']);
    }

    public function test_minimum_stock_update_creates_audit_log(): void
    {
        config(['audit.console' => true]);
        $this->actingAs($this->user);

        $product = $this->createProduct(['product_stock_alert' => 5]);

        Livewire::test(StockInsightsMinimumModal::class)
            ->call('openMinimumModal', $product->id, '2026-09-20', '7 Hari Terakhir')
            ->set('modalMinimumInput', '25')
            ->call('saveMinimumStock');

        $this->assertEquals(25, $product->fresh()->product_stock_alert);

        $updateAudit = $product->audits()->where('event', 'updated')->latest()->first();
        $this->assertNotNull($updateAudit, 'Updating minimum stock must create an update audit record.');
        $this->assertEquals($this->user->id, $updateAudit->user_id);
        $this->assertEquals(5, $updateAudit->old_values['product_stock_alert'] ?? null);
        $this->assertEquals(25, $updateAudit->new_values['product_stock_alert'] ?? null);
    }

    public function test_minimum_stock_update_handles_exceptions_with_generic_indonesian_message(): void
    {
        $this->actingAs($this->user);

        \Illuminate\Support\Facades\Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Gagal menyimpan batas minimum stok produk') && isset($context['exception']);
            });

        // Test with non-existent product ID
        Livewire::test(StockInsightsMinimumModal::class)
            ->set('modalProductId', 999999)
            ->set('modalMinimumInput', '10')
            ->call('saveMinimumStock')
            ->assertSet('modalErrorMessage', 'Terjadi kesalahan saat menyimpan batas minimum stok. Silakan coba lagi.')
            ->assertDontSee('No query results for model');
    }
}

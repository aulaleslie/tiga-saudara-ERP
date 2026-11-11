<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\InventorySummaryReport;
use App\Livewire\Reports\WarehouseStockQuantityReport;
use App\Livewire\Reports\WarehouseStockValuationReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductReportsMatrixBusinessScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $settingA;
    protected Setting $settingB;
    protected Setting $settingC;
    protected Location $locationA;
    protected Location $locationB;
    protected Location $locationC;
    protected Role $adminRole;
    protected User $user;
    protected User $superAdmin;
    protected Product $productAB;
    protected Product $productOwnedByB;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        Permission::findOrCreate('inventoryValuationReports.access', 'web');
        Permission::findOrCreate('stockMutationReports.access', 'web');

        $this->adminRole = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $superRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->settingA = Setting::factory()->create(['company_name' => 'BISNIS A']);
        $this->settingB = Setting::factory()->create(['company_name' => 'BISNIS B']);
        $this->settingC = Setting::factory()->create(['company_name' => 'BISNIS C']);

        $this->locationA = Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'GUDANG A',
            'is_active' => true,
        ]);
        $this->locationB = Location::create([
            'setting_id' => $this->settingB->id,
            'name' => 'GUDANG B',
            'is_active' => true,
        ]);
        $this->locationC = Location::create([
            'setting_id' => $this->settingC->id,
            'name' => 'GUDANG C',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('inventoryValuationReports.access');
        $this->user->givePermissionTo('stockMutationReports.access');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);
        $this->user->settings()->attach($this->settingB->id, ['role_id' => $this->adminRole->id]);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('Super Admin');
        $this->superAdmin->givePermissionTo('inventoryValuationReports.access');
        $this->superAdmin->givePermissionTo('stockMutationReports.access');

        session(['setting_id' => $this->settingA->id]);

        $category = Category::create([
            'setting_id' => $this->settingA->id,
            'category_code' => 'CAT1',
            'category_name' => 'Category 1',
            'created_by' => $this->user->id,
        ]);

        // Product AB stocked in both A and B
        $this->productAB = Product::create([
            'setting_id' => $this->settingA->id,
            'category_id' => $category->id,
            'product_name' => 'Produk AB',
            'product_code' => 'P-AB',
            'product_price' => 200,
            'product_cost' => 100,
            'stock_managed' => true,
            'is_active' => true,
            'average_purchase_price' => 100,
        ]);

        ProductStock::create([
            'product_id' => $this->productAB->id,
            'location_id' => $this->locationA->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        ProductStock::create([
            'product_id' => $this->productAB->id,
            'location_id' => $this->locationB->id,
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductPrice::create([
            'setting_id' => $this->settingA->id,
            'product_id' => $this->productAB->id,
            'average_purchase_price' => 100,
            'last_purchase_price' => 100,
            'sale_price' => 200,
        ]);
        ProductPrice::create([
            'setting_id' => $this->settingB->id,
            'product_id' => $this->productAB->id,
            'average_purchase_price' => 120,
            'last_purchase_price' => 120,
            'sale_price' => 220,
        ]);

        $this->createTx($this->productAB->id, $this->settingA->id, $this->locationA->id, 10);
        $this->createTx($this->productAB->id, $this->settingB->id, $this->locationB->id, 20);

        // Product owned by B, but with stock and transactions at location A
        $this->productOwnedByB = Product::create([
            'setting_id' => $this->settingB->id,
            'category_id' => $category->id,
            'product_name' => 'Produk Milik B',
            'product_code' => 'P-OWNED-B',
            'product_price' => 300,
            'product_cost' => 150,
            'stock_managed' => true,
            'is_active' => true,
            'average_purchase_price' => 150,
        ]);

        ProductStock::create([
            'product_id' => $this->productOwnedByB->id,
            'location_id' => $this->locationA->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductPrice::create([
            'setting_id' => $this->settingA->id,
            'product_id' => $this->productOwnedByB->id,
            'average_purchase_price' => 150,
            'last_purchase_price' => 150,
            'sale_price' => 300,
        ]);

        $this->createTx($this->productOwnedByB->id, $this->settingA->id, $this->locationA->id, 5);
    }

    protected function createTx(int $productId, int $settingId, int $locId, float $qty): Transaction
    {
        return Transaction::create([
            'setting_id' => $settingId,
            'product_id' => $productId,
            'location_id' => $locId,
            'type' => 'BUY',
            'current_quantity' => 0,
            'previous_quantity' => 0,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => $qty,
            'quantity_non_tax' => 0,
            'quantity_tax' => $qty,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'quantity' => $qty,
            'after_quantity' => $qty,
            'reason' => '#TX-' . uniqid(),
            'date' => now()->format('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_inventory_summary_scope_options_tampered_id_and_ownership()
    {
        $this->actingAs($this->user);

        // 1. Options limited to assigned businesses (A and B, not C)
        $component = Livewire::test(InventorySummaryReport::class);
        $available = $component->viewData('availableSettings');
        $availableIds = collect($available)->pluck('id')->all();
        $this->assertContains($this->settingA->id, $availableIds);
        $this->assertContains($this->settingB->id, $availableIds);
        $this->assertNotContains($this->settingC->id, $availableIds);

        // 2. Tampered ID dropped
        $component->set('selectedSettingIds', [$this->settingA->id, $this->settingC->id]);
        $sanitized = $component->instance()->getSanitizedSettingIds();
        $this->assertEquals([$this->settingA->id], $sanitized);

        // 3. Product owned by B appears when only A is selected because it is stocked at A
        $component->set('selectedSettingIds', [$this->settingA->id])
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee('Produk Milik B')
            ->assertSee('Produk AB');

        // 4. Multi-business: A and B selected -> Total = sum of business values
        // Produk AB: A has 10 * 100 = 1000; B has 20 * 120 = 2400. Total = 3400.
        $component->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->call('applyFilters')
            ->assertSee('BISNIS A')
            ->assertSee('BISNIS B')
            ->assertSee('Total')
            ->assertSee('30,00') // Total stock of Produk AB (10 + 20)
            ->assertSee('4.150'); // Grand total value: 3400 (AB) + 750 (Milik B in A: 5 * 150) = 4150
    }

    public function test_warehouse_stock_quantity_scope_options_tampered_id_and_ownership()
    {
        $this->actingAs($this->user);

        // 1. Options limited to assigned businesses
        $component = Livewire::test(WarehouseStockQuantityReport::class);
        $available = $component->viewData('availableSettings');
        $availableIds = collect($available)->pluck('id')->all();
        $this->assertContains($this->settingA->id, $availableIds);
        $this->assertContains($this->settingB->id, $availableIds);
        $this->assertNotContains($this->settingC->id, $availableIds);

        // 2. Tampered ID dropped
        $component->set('selectedSettingIds', [$this->settingA->id, $this->settingC->id]);
        $sanitized = $component->instance()->getSanitizedSettingIds();
        $this->assertEquals([$this->settingA->id], $sanitized);

        // 3. Product owned by B appears when only A is selected
        $component->set('selectedSettingIds', [$this->settingA->id])
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee('Produk Milik B')
            ->assertSee('5,00');

        // 4. Multi-business: Total stok = sum of quantities (10 in A + 20 in B = 30)
        $component->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->call('applyFilters')
            ->assertSee('BISNIS A')
            ->assertSee('BISNIS B')
            ->assertSee('30,00') // Total stok
            ->call('toggleBusiness', $this->settingA->id)
            ->assertSee('GUDANG A');
    }

    public function test_warehouse_stock_valuation_scope_options_tampered_id_and_ownership()
    {
        $this->actingAs($this->user);

        // 1. Options limited to assigned businesses
        $component = Livewire::test(WarehouseStockValuationReport::class);
        $available = $component->viewData('availableSettings');
        $availableIds = collect($available)->pluck('id')->all();
        $this->assertContains($this->settingA->id, $availableIds);
        $this->assertContains($this->settingB->id, $availableIds);
        $this->assertNotContains($this->settingC->id, $availableIds);

        // 2. Tampered ID dropped
        $component->set('selectedSettingIds', [$this->settingA->id, $this->settingC->id]);
        $sanitized = $component->instance()->getSanitizedSettingIds();
        $this->assertEquals([$this->settingA->id], $sanitized);

        // 3. Product owned by B appears when only A is selected
        $component->set('selectedSettingIds', [$this->settingA->id])
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee('Produk Milik B')
            ->assertSee('750,00'); // 5 * 150 = 750

        // 4. Multi-business uses each business's average cost
        // A has 10 * 100 = 1000; B has 20 * 120 = 2400. Total value for Produk AB = 3400.
        // Plus Produk Milik B in A: 5 * 150 = 750. Grand Total = 4150.
        $component->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->call('applyFilters')
            ->assertSee('BISNIS A')
            ->assertSee('BISNIS B')
            ->assertSee('30,00') // Total Qty of AB
            ->assertSee('3.400,00') // Total Value of AB
            ->assertSee('4.150,00') // Grand Total
            ->call('toggleBusiness', $this->settingA->id)
            ->assertSee('GUDANG A');
    }

    public function test_super_admin_sees_all_businesses_in_matrix_reports()
    {
        $this->actingAs($this->superAdmin);

        $summary = Livewire::test(InventorySummaryReport::class);
        $availableSummary = collect($summary->viewData('availableSettings'))->pluck('id')->all();
        $this->assertContains($this->settingA->id, $availableSummary);
        $this->assertContains($this->settingB->id, $availableSummary);
        $this->assertContains($this->settingC->id, $availableSummary);

        $qtyReport = Livewire::test(WarehouseStockQuantityReport::class);
        $availableQty = collect($qtyReport->viewData('availableSettings'))->pluck('id')->all();
        $this->assertContains($this->settingA->id, $availableQty);
        $this->assertContains($this->settingB->id, $availableQty);
        $this->assertContains($this->settingC->id, $availableQty);

        $valReport = Livewire::test(WarehouseStockValuationReport::class);
        $availableVal = collect($valReport->viewData('availableSettings'))->pluck('id')->all();
        $this->assertContains($this->settingA->id, $availableVal);
        $this->assertContains($this->settingB->id, $availableVal);
        $this->assertContains($this->settingC->id, $availableVal);
    }

    public function test_single_business_output_unchanged_for_matrix_reports(): void
    {
        $this->actingAs($this->user);

        // InventorySummaryReport with 1 business
        $summary = Livewire::test(InventorySummaryReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');

        $this->assertFalse($summary->viewData('isMultiBusiness'));
        $summary->assertDontSeeHtml('Kuantitas & Nilai Persediaan per Bisnis');

        // WarehouseStockQuantityReport with 1 business
        $wsq = Livewire::test(WarehouseStockQuantityReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');

        $this->assertFalse($wsq->viewData('isMultiBusiness'));
        $wsq->assertSeeHtml('Total stok');
        $wsq->assertDontSeeHtml('wire:click="toggleBusiness(');

        // WarehouseStockValuationReport with 1 business
        $wsv = Livewire::test(WarehouseStockValuationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');

        $this->assertFalse($wsv->viewData('isMultiBusiness'));
        $wsv->assertSee('Nilai Persediaan');
        $wsv->assertDontSeeHtml('wire:click="toggleBusiness(');
        $wsv->assertDontSee('Biaya Rata-rata');
    }

    public function test_tampering_applied_filters_excludes_unassigned_business(): void
    {
        $this->actingAs($this->user);

        // InventorySummaryReport tampering with unassigned setting C
        $summary = Livewire::test(InventorySummaryReport::class)
            ->call('applyFilters');
        $summary->set('appliedFilters.selectedSettingIds', [$this->settingC->id]);

        $activeIds = $summary->viewData('activeSettingIds');
        $this->assertNotContains($this->settingC->id, $activeIds);
        $summary->call('exportExcel');
        $summary->assertDispatched('alert');

        // WarehouseStockQuantityReport tampering with unassigned setting C
        $wsq = Livewire::test(WarehouseStockQuantityReport::class)
            ->call('applyFilters');
        $wsq->set('appliedFilters.selectedSettingIds', [$this->settingC->id]);

        $wsqActiveIds = $wsq->viewData('activeSettingIds');
        $this->assertNotContains($this->settingC->id, $wsqActiveIds);
        $wsq->call('exportExcel');
        $wsq->assertDispatched('alert');

        // WarehouseStockValuationReport tampering with unassigned setting C
        $wsv = Livewire::test(WarehouseStockValuationReport::class)
            ->call('applyFilters');
        $wsv->set('appliedFilters.selectedSettingIds', [$this->settingC->id]);

        $wsvActiveIds = $wsv->viewData('activeSettingIds');
        $this->assertNotContains($this->settingC->id, $wsvActiveIds);
        $wsv->call('exportExcel');
        $wsv->assertDispatched('alert');
    }

    public function test_layout_mismatch_prevents_multi_business_columns_without_applying(): void
    {
        $this->actingAs($this->user);

        // 1. InventorySummaryReport: initially 1 business applied
        $summary = Livewire::test(InventorySummaryReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');
        $this->assertFalse($summary->viewData('isMultiBusiness'));

        // Change dropdown to multi-business [A, B] without calling applyFilters
        $summary->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id]);
        $this->assertFalse($summary->viewData('isMultiBusiness'));
        $summary->assertDontSeeHtml('Kuantitas & Nilai Persediaan per Bisnis')
            ->assertSee('Produk AB')
            ->assertSee('Produk Milik B')
            ->assertSee('1.750');

        // 2. WarehouseStockQuantityReport: initially 1 business applied
        $wsq = Livewire::test(WarehouseStockQuantityReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');
        $this->assertFalse($wsq->viewData('isMultiBusiness'));

        // Change dropdown to multi-business [A, B] without calling applyFilters
        $wsq->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id]);
        $this->assertFalse($wsq->viewData('isMultiBusiness'));
        $wsq->assertDontSeeHtml('wire:click="toggleBusiness(')
            ->assertSee('Produk AB')
            ->assertSee('10,00')
            ->assertSee('Produk Milik B')
            ->assertSee('5,00');

        // 3. WarehouseStockValuationReport: initially 1 business applied
        $wsv = Livewire::test(WarehouseStockValuationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');
        $this->assertFalse($wsv->viewData('isMultiBusiness'));

        // Change dropdown to multi-business [A, B] without calling applyFilters
        $wsv->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id]);
        $this->assertFalse($wsv->viewData('isMultiBusiness'));
        $wsv->assertDontSeeHtml('wire:click="toggleBusiness(')
            ->assertDontSee('Biaya Rata-rata')
            ->assertSee('Produk AB')
            ->assertSee('1.000,00')
            ->assertSee('Produk Milik B')
            ->assertSee('750,00')
            ->assertSee('1.750,00');
    }

    public function test_unauthenticated_guests_are_denied_access(): void
    {
        auth()->logout();

        Livewire::test(InventorySummaryReport::class)
            ->assertStatus(403);

        Livewire::test(WarehouseStockQuantityReport::class)
            ->assertStatus(403);

        Livewire::test(WarehouseStockValuationReport::class)
            ->assertStatus(403);
    }

    public function test_single_business_exports_name_selected_business_not_session_business(): void
    {
        \Maatwebsite\Excel\Facades\Excel::fake();

        // Session business is A, user is assigned to A and B
        session(['setting_id' => $this->settingA->id]);

        $getHeaderCompanyName = function ($export): string {
            $events = $export->registerEvents();
            $closure = $events[\Maatwebsite\Excel\Events\AfterSheet::class];
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $worksheet = $spreadsheet->getActiveSheet();
            $worksheet->setCellValue('A1', 'Placeholder');
            $sheet = new \Maatwebsite\Excel\Sheet($worksheet);
            $event = new \Maatwebsite\Excel\Events\AfterSheet($sheet, $export);
            $closure($event);

            return (string) $worksheet->getCell('A1')->getValue();
        };

        // 1. InventorySummaryReport
        Livewire::actingAs($this->user)
            ->test(InventorySummaryReport::class)
            ->set('asOfDate', '2026-11-11')
            ->set('selectedSettingIds', [$this->settingB->id])
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('ringkasan-persediaan-barang_11-11-2026.xlsx', function (\App\Exports\InventorySummaryReportExport $export) use ($getHeaderCompanyName) {
            return $getHeaderCompanyName($export) === $this->settingB->company_name;
        });

        // 2. WarehouseStockQuantityReport
        Livewire::actingAs($this->user)
            ->test(WarehouseStockQuantityReport::class)
            ->set('asOfDate', '2026-11-11')
            ->set('selectedSettingIds', [$this->settingB->id])
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('kuantitas-stok-gudang_11-11-2026.xlsx', function (\App\Exports\WarehouseStockQuantityReportExport $export) use ($getHeaderCompanyName) {
            return $getHeaderCompanyName($export) === $this->settingB->company_name;
        });

        // 3. WarehouseStockValuationReport
        Livewire::actingAs($this->user)
            ->test(WarehouseStockValuationReport::class)
            ->set('asOfDate', '2026-11-11')
            ->set('selectedSettingIds', [$this->settingB->id])
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('nilai-stok-gudang_11-11-2026.xlsx', function (\App\Exports\WarehouseStockValuationReportExport $export) use ($getHeaderCompanyName) {
            return $getHeaderCompanyName($export) === $this->settingB->company_name;
        });
    }

    public function test_inventory_summary_cancel_filters_does_not_revert_selected_settings(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(InventorySummaryReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->call('applyFilters');

        $this->assertTrue($component->viewData('isMultiBusiness'));

        $component->set('selectedSettingIds', [$this->settingA->id])
            ->call('cancelFilters')
            ->assertSet('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');

        $this->assertFalse($component->viewData('isMultiBusiness'));
        $component->assertSee('Harga Rata-rata');
    }

    public function test_warehouse_stock_quantity_cancel_filters_does_not_revert_selected_settings(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(WarehouseStockQuantityReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->call('applyFilters');

        $this->assertTrue($component->viewData('isMultiBusiness'));
        $component->assertSeeHtml('wire:click="toggleBusiness(');

        $component->set('selectedSettingIds', [$this->settingA->id])
            ->call('cancelFilters')
            ->assertSet('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');

        $this->assertFalse($component->viewData('isMultiBusiness'));
        $component->assertDontSeeHtml('wire:click="toggleBusiness(');
    }

    public function test_warehouse_stock_valuation_cancel_filters_does_not_revert_selected_settings(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(WarehouseStockValuationReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->call('applyFilters');

        $this->assertTrue($component->viewData('isMultiBusiness'));
        $component->assertSeeHtml('wire:click="toggleBusiness(');

        $component->set('selectedSettingIds', [$this->settingA->id])
            ->call('cancelFilters')
            ->assertSet('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');

        $this->assertFalse($component->viewData('isMultiBusiness'));
        $component->assertDontSeeHtml('wire:click="toggleBusiness(');
    }

    public function test_business_checkbox_selector_renders_assigned_businesses_and_actions(): void
    {
        $component = Livewire::actingAs($this->user)->test(InventorySummaryReport::class);

        $component->assertSeeHtml('id="inventory-summary-setting-select"')
            ->assertSeeHtml('business-checkbox-trigger')
            ->assertSee('Pilih semua')
            ->assertSee('Kosongkan')
            ->assertSee('BISNIS A')
            ->assertSee('BISNIS B')
            ->assertDontSee('BISNIS C');
    }
}

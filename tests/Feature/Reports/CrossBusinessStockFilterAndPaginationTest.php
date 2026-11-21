<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\CrossBusinessStockInventory;
use App\Models\User;
use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use App\Services\Reports\CrossBusinessStockInventoryQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CrossBusinessStockFilterAndPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting1;
    private Setting $setting2;
    private Location $location1;
    private Location $location2;
    private User $superAdmin;
    private User $assignedUser;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'inventory.view_remaining_stock', 'guard_name' => 'web']);
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->setting1 = Setting::create([
            'company_name' => 'Alpha Business',
            'company_email' => 'alpha@example.com',
            'company_phone' => '081111',
            'notification_email' => 'alpha@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'company_address' => 'Alpha Address',
            'footer_text' => 'Footer',
            'is_pkp' => true,
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Beta Business',
            'company_email' => 'beta@example.com',
            'company_phone' => '082222',
            'notification_email' => 'beta@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'company_address' => 'Beta Address',
            'footer_text' => 'Footer',
            'is_pkp' => false,
        ]);

        $this->location1 = Location::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Gudang Alpha',
            'is_active' => true,
        ]);

        $this->location2 = Location::create([
            'setting_id' => $this->setting2->id,
            'name' => 'Gudang Beta',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create(['is_active' => 1]);
        $this->superAdmin->assignRole($superAdminRole);
        $this->superAdmin->givePermissionTo('inventory.view_remaining_stock');

        $staffRole = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);
        $this->assignedUser = User::factory()->create(['is_active' => 1]);
        $this->assignedUser->givePermissionTo('inventory.view_remaining_stock');
        $this->assignedUser->settings()->attach($this->setting1->id, ['role_id' => $staffRole->id]);
    }

    private function createProduct(array $overrides = []): Product
    {
        static $seq = 1;
        $n = $seq++;

        return Product::create(array_merge([
            'setting_id' => $this->setting1->id,
            'category_id' => null,
            'brand_id' => null,
            'product_name' => "Product {$n}",
            'product_code' => "PRD-{$n}",
            'product_unit' => 'pc',
            'product_quantity' => 0,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ], $overrides));
    }

    public function test_descendant_category_product_brand_or_within_and_across_without_duplicates(): void
    {
        $this->actingAs($this->superAdmin);

        $atk = Category::create(['category_code' => 'ATK', 'category_name' => 'ATK', 'parent_id' => null, 'created_by' => $this->superAdmin->id]);
        $kertas = Category::create(['category_code' => 'KRT', 'category_name' => 'Kertas', 'parent_id' => $atk->id, 'created_by' => $this->superAdmin->id]);
        $hvs = Category::create(['category_code' => 'HVS', 'category_name' => 'HVS', 'parent_id' => $kertas->id, 'created_by' => $this->superAdmin->id]);
        $elektronik = Category::create(['category_code' => 'ELK', 'category_name' => 'Elektronik', 'parent_id' => null, 'created_by' => $this->superAdmin->id]);

        // Introduce a cycle to verify cycle-safety
        $cycleA = Category::create(['category_code' => 'CYCA', 'category_name' => 'Cycle A', 'parent_id' => null, 'created_by' => $this->superAdmin->id]);
        $cycleB = Category::create(['category_code' => 'CYCB', 'category_name' => 'Cycle B', 'parent_id' => $cycleA->id, 'created_by' => $this->superAdmin->id]);
        DB::table('categories')->where('id', $cycleA->id)->update(['parent_id' => $cycleB->id]);

        $brandA = Brand::create(['name' => 'Sinar Dunia', 'is_active' => true, 'created_by' => $this->superAdmin->id]);
        $brandB = Brand::create(['name' => 'PaperOne', 'is_active' => true, 'created_by' => $this->superAdmin->id]);
        $brandC = Brand::create(['name' => 'OtherBrand', 'is_active' => true, 'created_by' => $this->superAdmin->id]);

        $pMatch1 = $this->createProduct([
            'setting_id' => $this->setting2->id, // Global product identity regardless of owner setting
            'category_id' => $hvs->id,
            'brand_id' => $brandA->id,
            'product_name' => 'Kertas HVS A4',
            'product_code' => 'HVS-A4',
        ]);
        $pMatch2 = $this->createProduct([
            'setting_id' => $this->setting1->id,
            'category_id' => $kertas->id,
            'brand_id' => $brandB->id,
            'product_name' => 'Kertas Folio F4',
            'product_code' => 'KRT-F4',
        ]);
        $pWrongBrand = $this->createProduct([
            'category_id' => $hvs->id,
            'brand_id' => $brandC->id,
            'product_name' => 'Kertas Brand Lain',
            'product_code' => 'KRT-OTH',
        ]);
        $pWrongCat = $this->createProduct([
            'category_id' => $elektronik->id,
            'brand_id' => $brandA->id,
            'product_name' => 'Kabel HDMI',
            'product_code' => 'KBL-HDMI',
        ]);

        // Stock for pMatch1 in setting1 so availability='available' matches
        ProductStock::create([
            'product_id' => $pMatch1->id,
            'location_id' => $this->location1->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $service = new CrossBusinessStockInventoryQueryService();

        // Parent category ATK + 2 brands (OR) + 2 product IDs (OR) + AND across all
        $filter = new CrossBusinessStockInventoryFilterData(
            search: '',
            businessIds: [$this->setting1->id, $this->setting2->id],
            categoryIds: [$atk->id, $kertas->id], // Overlapping parent+child must not duplicate rows
            brandIds: [$brandA->id, $brandB->id],
            availability: 'all',
            productIds: [$pMatch1->id, $pMatch2->id, $pWrongBrand->id, $pWrongCat->id, -1, 0, $pMatch1->id]
        );

        $this->assertSame([$pMatch1->id, $pMatch2->id, $pWrongBrand->id, $pWrongCat->id], $filter->productIds);

        $data = $service->getReportData($filter, 50, 1);
        $ids = $data['rows']->pluck('id')->all();

        $this->assertCount(2, $ids);
        $this->assertContains($pMatch1->id, $ids);
        $this->assertContains($pMatch2->id, $ids);

        // With availability='available', only pMatch1 remains
        $availFilter = new CrossBusinessStockInventoryFilterData(
            businessIds: [$this->setting1->id],
            categoryIds: [$atk->id],
            brandIds: [$brandA->id, $brandB->id],
            availability: 'available',
            productIds: [$pMatch1->id, $pMatch2->id]
        );
        $availData = $service->getReportData($availFilter, 50, 1);
        $this->assertSame([$pMatch1->id], $availData['rows']->pluck('id')->all());

        // Cycle-safe category filter does not hang
        $cycleFilter = new CrossBusinessStockInventoryFilterData(
            businessIds: [$this->setting1->id],
            categoryIds: [$cycleA->id]
        );
        $cycleData = $service->getReportData($cycleFilter, 50, 1);
        $this->assertCount(0, $cycleData['rows']);
    }

    public function test_draft_applied_cancel_reset_empty_business_and_export_guard(): void
    {
        Excel::fake();
        $this->actingAs($this->superAdmin);

        $p1 = $this->createProduct([
            'product_name' => 'Alpha Target Item',
            'product_code' => 'ALP-01',
            'serial_number_required' => true,
        ]);
        $p2 = $this->createProduct([
            'product_name' => 'Beta Other Item',
            'product_code' => 'BET-02',
        ]);

        ProductStock::create([
            'product_id' => $p1->id,
            'location_id' => $this->location1->id,
            'quantity' => 2,
            'quantity_tax' => 2,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductSerialNumber::create([
            'product_id' => $p1->id,
            'location_id' => $this->location1->id,
            'serial_number' => 'SN-DRAFT-TEST-01',
            'status' => 'ACTIVE',
            'is_broken' => false,
            'is_in_return_process' => false,
        ]);

        // Initial load shows both products and both businesses
        $component = Livewire::test(CrossBusinessStockInventory::class)
            ->assertSee('Alpha Target Item')
            ->assertSee('Beta Other Item')
            ->assertSee('ALPHA BUSINESS')
            ->assertSee('BETA BUSINESS');

        // Draft search + business change does NOT alter results, visible columns, or serial marker
        $component
            ->set('search', 'SN-DRAFT-TEST-01')
            ->set('selectedSettingIds', [$this->setting1->id])
            ->assertSee('Beta Other Item')
            ->assertSee('BETA BUSINESS')
            ->assertDontSeeHtml('serial-marker')
            ->assertSet('hasPendingFilterChanges', true);

        // Pending criteria block export
        $component->call('exportExcel', 'stock')
            ->assertHasErrors('export');

        // Cancel restores applied inputs and export eligibility
        $component->call('cancelFilters')
            ->assertSet('search', '')
            ->assertSet('hasPendingFilterChanges', false);

        // Empty business selection on Apply is rejected without mutating applied state
        $component
            ->set('selectedSettingIds', [])
            ->call('applyFilters')
            ->assertHasErrors('selectedSettingIds')
            ->assertSee('ALPHA BUSINESS')
            ->assertSee('BETA BUSINESS');

        // Forged unauthorized business ID for assignedUser is sanitized/rejected
        $this->actingAs($this->assignedUser);
        Livewire::test(CrossBusinessStockInventory::class)
            ->set('selectedSettingIds', [$this->setting2->id])
            ->call('applyFilters')
            ->assertHasErrors('selectedSettingIds');

        // Apply valid search + business scope updates results, columns, and serial marker
        $this->actingAs($this->superAdmin);
        $component = Livewire::test(CrossBusinessStockInventory::class)
            ->set('search', 'SN-DRAFT-TEST-01')
            ->set('selectedSettingIds', [$this->setting1->id])
            ->call('applyFilters')
            ->assertSee('Alpha Target Item')
            ->assertDontSee('Beta Other Item')
            ->assertSeeHtml('serial-marker')
            ->assertSet('hasPendingFilterChanges', false);

        // Reset restores all authorized businesses and clears search
        $component->call('resetFilters')
            ->assertSet('search', '')
            ->assertSee('Alpha Target Item')
            ->assertSee('Beta Other Item')
            ->assertSee('BETA BUSINESS')
            ->assertDontSeeHtml('serial-marker');
    }

    public function test_checkbox_selectors_persistent_search_chips_above_inputs_and_caps(): void
    {
        $this->actingAs($this->superAdmin);

        for ($i = 1; $i <= 27; $i++) {
            $this->createProduct([
                'product_name' => sprintf('Widget Series %02d', $i),
                'product_code' => sprintf('WDG-%02d', $i),
            ]);
        }

        $firstProduct = Product::where('product_code', 'WDG-01')->first();
        $secondProduct = Product::where('product_code', 'WDG-02')->first();

        $component = Livewire::test(CrossBusinessStockInventory::class)
            ->set('productSearch', 'Widget Series')
            ->assertSet('hasMoreProductOptions', true)
            ->assertCount('productOptions', 25)
            ->assertSee('Menampilkan 25 hasil pertama');

        // Selecting two products keeps search text and dropdown options intact
        $component
            ->call('toggleProduct', $firstProduct->id, 'WDG-01 | Widget Series 01')
            ->call('toggleProduct', $secondProduct->id, 'WDG-02 | Widget Series 02')
            ->assertSet('productSearch', 'Widget Series')
            ->assertCount('productIds', 2)
            ->assertSee('2 dipilih');

        // Verify chip scroll area appears BEFORE the search input in rendered HTML and uses scoped Bootstrap 4 checkbox class
        $html = $component->html();
        $chipPos = strpos($html, 'data-chip-area="product"');
        $inputPos = strpos($html, 'x-ref="productSearchInput"');
        $this->assertNotFalse($chipPos);
        $this->assertNotFalse($inputPos);
        $this->assertLessThan($inputPos, $chipPos, 'Selected chips must render above the search input.');
        $this->assertStringContainsString('cbsi-checkbox-input', $html);

        // Removing one chip keeps search text and updates count
        $component
            ->call('removeProduct', $firstProduct->id)
            ->assertSet('productSearch', 'Widget Series')
            ->assertCount('productIds', 1);

        // Clear all removes remaining chips
        $component
            ->call('clearProducts')
            ->assertCount('productIds', 0);
    }

    public function test_bounded_page_reuse_apply_from_page_two_draft_typing_and_scope_revocation(): void
    {
        $this->actingAs($this->superAdmin);

        for ($i = 1; $i <= 20; $i++) {
            $p = $this->createProduct([
                'product_name' => sprintf('Paged Item %02d', $i),
                'product_code' => sprintf('PGD-%02d', $i),
            ]);
            ProductStock::create([
                'product_id' => $p->id,
                'location_id' => $this->location1->id,
                'quantity' => $i,
                'quantity_tax' => $i,
                'quantity_non_tax' => 0,
                'broken_quantity' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
            ]);
        }

        $component = Livewire::test(CrossBusinessStockInventory::class)
            ->assertSet('loadedPage', 1);

        // Go to page 2
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('gotoPage', 2, 'page');
        $page2Queries = DB::getQueryLog();
        DB::disableQueryLog();

        $component->assertSet('loadedPage', 2);
        $stockQueriesOnPage2 = collect($page2Queries)->filter(fn ($q) => str_contains($q['query'], 'product_stocks'))->count();
        $this->assertSame(1, $stockQueriesOnPage2, 'Pagination should load stock matrix exactly once.');

        // Draft selector typing must NOT run stock or purchase aggregation
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->set('productSearch', 'Paged');
        $draftQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $stockQueriesOnDraft = collect($draftQueries)->filter(
            fn ($q) => str_contains($q['query'], 'product_stocks') || str_contains($q['query'], 'purchase_details')
        )->count();
        $this->assertSame(0, $stockQueriesOnDraft, 'Draft selector typing must not run stock or purchase aggregation.');

        // Display-mode toggle must NOT run stock or purchase aggregation
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->set('displayMode', 'conversion');
        $modeQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $stockQueriesOnMode = collect($modeQueries)->filter(
            fn ($q) => str_contains($q['query'], 'product_stocks') || str_contains($q['query'], 'purchase_details')
        )->count();
        $this->assertSame(0, $stockQueriesOnMode, 'Presentation toggle must not run stock or purchase aggregation.');

        // Apply from page 2 must reset to page 1 and load stock matrix exactly ONCE
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->set('availability', 'available')->call('applyFilters');
        $applyQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $component->assertSet('loadedPage', 1);
        $stockMatrixQueriesOnApply = collect($applyQueries)->filter(
            fn ($q) => str_contains($q['query'], 'product_stocks') && str_contains($q['query'], 'group by')
        )->count();
        $this->assertSame(1, $stockMatrixQueriesOnApply, 'Apply from page 2 must load the page exactly once.');

        // Scope revocation: if user loses access to setting1, render must clear unauthorized data
        $this->actingAs($this->assignedUser);
        $assignedComp = Livewire::test(CrossBusinessStockInventory::class)
            ->assertSee('ALPHA BUSINESS');

        $this->assignedUser->settings()->detach($this->setting1->id);

        $assignedComp->call('$refresh')
            ->assertSet('loadedRows', [])
            ->assertSet('loadedBusinesses', [])
            ->assertDontSee('Paged Item 01');
    }
}

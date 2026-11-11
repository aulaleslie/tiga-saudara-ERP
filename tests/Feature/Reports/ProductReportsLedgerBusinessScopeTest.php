<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\InventoryDetailReport;
use App\Livewire\Reports\InventoryValuationReport;
use App\Models\User;
use Carbon\Carbon;
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

class ProductReportsLedgerBusinessScopeTest extends TestCase
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
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

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
            'average_purchase_price' => 10000,
        ]);

        ProductStock::create([
            'product_id' => $this->productAB->id,
            'location_id' => $this->locationA->id,
            'quantity' => 7,
            'quantity_tax' => 7,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        ProductStock::create([
            'product_id' => $this->productAB->id,
            'location_id' => $this->locationB->id,
            'quantity' => 4,
            'quantity_tax' => 4,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductPrice::create([
            'setting_id' => $this->settingA->id,
            'product_id' => $this->productAB->id,
            'average_purchase_price' => 10000,
            'last_purchase_price' => 10000,
            'sale_price' => 15000,
        ]);
        ProductPrice::create([
            'setting_id' => $this->settingB->id,
            'product_id' => $this->productAB->id,
            'average_purchase_price' => 20000,
            'last_purchase_price' => 20000,
            'sale_price' => 25000,
        ]);

        // Activity in Business A: buy 10 @ 10,000, sell 3 @ 10,000 -> stock 7 @ 10,000 = 70,000
        $this->createTx($this->productAB->id, $this->settingA->id, $this->locationA->id, 'BUY', 10, 0, 10, 'Buy #BUY-A', '2026-11-05 10:00:00');
        $this->createTx($this->productAB->id, $this->settingA->id, $this->locationA->id, 'SELL', -3, 10, 7, 'Sell #SELL-A', '2026-11-10 10:00:00');

        // Activity in Business B: buy 5 @ 20,000, sell 1 @ 20,000 -> stock 4 @ 20,000 = 80,000
        $this->createTx($this->productAB->id, $this->settingB->id, $this->locationB->id, 'BUY', 5, 0, 5, 'Buy #BUY-B', '2026-11-06 10:00:00');
        $this->createTx($this->productAB->id, $this->settingB->id, $this->locationB->id, 'SELL', -1, 5, 4, 'Sell #SELL-B', '2026-11-11 10:00:00');

        // Product owned by B, but stocked at location A
        $this->productOwnedByB = Product::create([
            'setting_id' => $this->settingB->id,
            'category_id' => $category->id,
            'product_name' => 'Produk Milik B',
            'product_code' => 'P-OWNED-B',
            'product_price' => 300,
            'product_cost' => 150,
            'stock_managed' => true,
            'is_active' => true,
            'average_purchase_price' => 5000,
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
            'average_purchase_price' => 5000,
            'last_purchase_price' => 5000,
            'sale_price' => 8000,
        ]);

        $this->createTx($this->productOwnedByB->id, $this->settingA->id, $this->locationA->id, 'BUY', 5, 0, 5, 'Buy #BUY-B-AT-A', '2026-11-07 10:00:00');
    }

    protected function createTx(int $productId, int $settingId, int $locationId, string $type, float $qty, float $prevQty, float $afterQty, string $reason, string $date): Transaction
    {
        $tx = Transaction::create([
            'setting_id' => $settingId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'type' => $type,
            'current_quantity' => 0,
            'previous_quantity_at_location' => $prevQty,
            'after_quantity_at_location' => $afterQty,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'quantity' => $qty,
            'previous_quantity' => $prevQty,
            'after_quantity' => $afterQty,
            'reason' => $reason,
        ]);
        $tx->created_at = Carbon::parse($date);
        $tx->save();

        return $tx;
    }

    public function test_scope_options_and_tampered_id_dropped(): void
    {
        $this->actingAs($this->user);

        // Test InventoryValuationReport
        $componentVal = Livewire::test(InventoryValuationReport::class);
        $optionsVal = collect($componentVal->viewData('availableSettings'))->pluck('id')->toArray();
        $this->assertEqualsCanonicalizing([$this->settingA->id, $this->settingB->id], $optionsVal);

        // Tamper with setting C
        $componentVal->set('selectedSettingIds', [$this->settingA->id, $this->settingC->id]);
        $this->assertEquals([$this->settingA->id], $componentVal->instance()->getSanitizedSettingIds());

        // Test InventoryDetailReport
        $componentDet = Livewire::test(InventoryDetailReport::class);
        $optionsDet = collect($componentDet->viewData('availableSettings'))->pluck('id')->toArray();
        $this->assertEqualsCanonicalizing([$this->settingA->id, $this->settingB->id], $optionsDet);

        // Tamper with setting C
        $componentDet->set('selectedSettingIds', [$this->settingB->id, $this->settingC->id]);
        $this->assertEquals([$this->settingB->id], $componentDet->instance()->getSanitizedSettingIds());

        // Super Admin sees all three
        $this->actingAs($this->superAdmin);
        $componentSuperVal = Livewire::test(InventoryValuationReport::class);
        $superOptionsVal = collect($componentSuperVal->viewData('availableSettings'))->pluck('id')->toArray();
        $this->assertEqualsCanonicalizing([$this->settingA->id, $this->settingB->id, $this->settingC->id], $superOptionsVal);
    }

    public function test_per_business_running_balances_never_mix_and_grand_total_equals_sum_of_businesses(): void
    {
        $this->actingAs($this->user);

        // 1. InventoryValuationReport: A + B selected
        $valTest = Livewire::test(InventoryValuationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->call('applyFilters')
            ->assertSet('filterTriggered', true);

        // Grand total must equal sum of businesses
        // Product AB: A ending value = 7 * 10,000 = 70,000. B ending value = 4 * 20,000 = 80,000.
        // Product OwnedByB (at A): 5 * 5,000 = 25,000.
        // Total value = 70,000 + 80,000 + 25,000 = 175,000.
        $this->assertEquals(175000.0, (float) $valTest->viewData('totalValue'));

        // Toggle Product AB to check lazy-loaded details per business
        $valTest->call('toggleProduct', $this->productAB->id);
        $details = $valTest->get('loadedProductDetails')[$this->productAB->id];

        $this->assertArrayHasKey($this->settingA->id, $details);
        $this->assertArrayHasKey($this->settingB->id, $details);

        // Business A ledger: 7 stock, 10,000 avg, 70,000 value
        $detailA = $details[$this->settingA->id];
        $this->assertEquals(7.0, (float) $detailA['ending_stock']);
        $this->assertEquals(10000.0, (float) $detailA['ending_avg']);
        $this->assertEquals(70000.0, (float) $detailA['ending_value']);

        // Business B ledger: 4 stock, 20,000 avg, 80,000 value (Never mixed with A!)
        $detailB = $details[$this->settingB->id];
        $this->assertEquals(4.0, (float) $detailB['ending_stock']);
        $this->assertEquals(20000.0, (float) $detailB['ending_avg']);
        $this->assertEquals(80000.0, (float) $detailB['ending_value']);

        // 2. InventoryDetailReport: A + B selected
        $detTest = Livewire::test(InventoryDetailReport::class)
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->call('applyFilters')
            ->assertSet('filterTriggered', true)
            ->call('toggleProduct', $this->productAB->id);

        $detDetails = $detTest->get('loadedProductDetails')[$this->productAB->id];
        $this->assertArrayHasKey($this->settingA->id, $detDetails);
        $this->assertArrayHasKey($this->settingB->id, $detDetails);
        $this->assertEquals(7.0, (float) $detDetails[$this->settingA->id]['ending_stock']);
        $this->assertEquals(4.0, (float) $detDetails[$this->settingB->id]['ending_stock']);
    }

    public function test_single_business_output_unchanged(): void
    {
        $this->actingAs($this->user);

        // InventoryValuationReport with only Setting A
        $valTest = Livewire::test(InventoryValuationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->call('applyFilters')
            ->assertSet('filterTriggered', true);

        // Only A values: Product AB (70,000) + Product OwnedByB (25,000) = 95,000
        $this->assertEquals(95000.0, (float) $valTest->viewData('totalValue'));
        $this->assertFalse($valTest->viewData('isMultiBusiness'));

        // InventoryDetailReport with only Setting A
        $detTest = Livewire::test(InventoryDetailReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->call('applyFilters')
            ->assertSet('filterTriggered', true);

        $this->assertFalse($detTest->viewData('isMultiBusiness'));
    }

    public function test_product_owned_by_b_appears_when_only_a_selected(): void
    {
        $this->actingAs($this->user);

        // Product Owned by B must appear when only Setting A is selected because it is stocked at Location A
        $valTest = Livewire::test(InventoryValuationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->call('applyFilters')
            ->assertSee('P-OWNED-B')
            ->assertSee('Produk Milik B');

        $detTest = Livewire::test(InventoryDetailReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->call('applyFilters')
            ->assertSee('P-OWNED-B')
            ->assertSee('Produk Milik B');
    }

    public function test_user_assigned_only_to_a_cannot_access_b_via_tampered_applied_filters_in_valuation_report(): void
    {
        $userA = User::factory()->create();
        $userA->givePermissionTo('inventoryValuationReports.access');
        $userA->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);

        $appliedFiltersWithOnlyB = [
            'tanggalAwal' => '2026-11-01',
            'tanggalAkhir' => '2026-11-30',
            'selectedSettingIds' => [$this->settingB->id],
        ];

        // 1. Render check: B's data does not appear when appliedFilters has [B]
        $testable = Livewire::actingAs($userA)
            ->test(InventoryValuationReport::class)
            ->set('appliedFilters', $appliedFiltersWithOnlyB)
            ->set('filterTriggered', true)
            ->call('$refresh');

        $this->assertEquals([], $testable->viewData('activeSettingIds'));
        $this->assertNull($testable->viewData('paginator'));

        // 2. toggleProduct check: no ledger loaded for B
        $testable->call('toggleProduct', $this->productAB->id);
        $details = $testable->get('loadedProductDetails')[$this->productAB->id] ?? [];
        $this->assertArrayNotHasKey($this->settingB->id, $details);

        // When appliedFilters has [A, B], only A is loaded in ledger
        $appliedFiltersWithAB = [
            'tanggalAwal' => '2026-11-01',
            'tanggalAkhir' => '2026-11-30',
            'selectedSettingIds' => [$this->settingA->id, $this->settingB->id],
        ];
        $testableAB = Livewire::actingAs($userA)
            ->test(InventoryValuationReport::class)
            ->set('appliedFilters', $appliedFiltersWithAB)
            ->set('filterTriggered', true)
            ->call('$refresh');

        $this->assertEquals([$this->settingA->id], $testableAB->viewData('activeSettingIds'));
        $testableAB->call('toggleProduct', $this->productAB->id);
        $detailsAB = $testableAB->get('loadedProductDetails')[$this->productAB->id] ?? [];
        $this->assertArrayHasKey($this->settingA->id, $detailsAB);
        $this->assertArrayNotHasKey($this->settingB->id, $detailsAB);

        // 3. Export check: export does not contain B's rows or unassigned IDs
        \Maatwebsite\Excel\Facades\Excel::fake();
        $testableAB->call('exportExcel');
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded(
            sprintf('nilai-persediaan-barang_%s_sd_%s.xlsx', '01-11-2026', '30-11-2026'),
            function (\App\Exports\InventoryValuationReportExport $export) {
                $ref = new \ReflectionClass($export);
                $prop = $ref->getProperty('settingIds');
                $prop->setAccessible(true);
                return $prop->getValue($export) === [$this->settingA->id];
            }
        );
    }

    public function test_user_assigned_only_to_a_cannot_access_b_via_tampered_applied_filters_in_detail_report(): void
    {
        $userA = User::factory()->create();
        $userA->givePermissionTo('stockMutationReports.access');
        $userA->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);

        $appliedFiltersWithOnlyB = [
            'tanggalAwal' => '2026-11-01',
            'tanggalAkhir' => '2026-11-30',
            'selectedSettingIds' => [$this->settingB->id],
        ];

        // 1. Render check: B's data does not appear when appliedFilters has [B]
        $testable = Livewire::actingAs($userA)
            ->test(InventoryDetailReport::class)
            ->set('appliedFilters', $appliedFiltersWithOnlyB)
            ->set('filterTriggered', true)
            ->call('$refresh');

        $this->assertEquals([], $testable->viewData('activeSettingIds'));
        $this->assertNull($testable->viewData('paginator'));

        // 2. toggleProduct check: no ledger loaded for B
        $testable->call('toggleProduct', $this->productAB->id);
        $details = $testable->get('loadedProductDetails')[$this->productAB->id] ?? [];
        $this->assertArrayNotHasKey($this->settingB->id, $details);

        // When appliedFilters has [A, B], only A is loaded in ledger
        $appliedFiltersWithAB = [
            'tanggalAwal' => '2026-11-01',
            'tanggalAkhir' => '2026-11-30',
            'selectedSettingIds' => [$this->settingA->id, $this->settingB->id],
        ];
        $testableAB = Livewire::actingAs($userA)
            ->test(InventoryDetailReport::class)
            ->set('appliedFilters', $appliedFiltersWithAB)
            ->set('filterTriggered', true)
            ->call('$refresh');

        $this->assertEquals([$this->settingA->id], $testableAB->viewData('activeSettingIds'));
        $testableAB->call('toggleProduct', $this->productAB->id);
        $detailsAB = $testableAB->get('loadedProductDetails')[$this->productAB->id] ?? [];
        $this->assertArrayHasKey($this->settingA->id, $detailsAB);
        $this->assertArrayNotHasKey($this->settingB->id, $detailsAB);

        // 3. Export check: export does not contain B's rows or unassigned IDs
        \Maatwebsite\Excel\Facades\Excel::fake();
        $testableAB->call('exportExcel');
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded(
            sprintf('detail-persediaan-barang_%s_sd_%s.xlsx', '01-11-2026', '30-11-2026'),
            function (\App\Exports\InventoryDetailReportExport $export) {
                $ref = new \ReflectionClass($export);
                $prop = $ref->getProperty('settingIds');
                $prop->setAccessible(true);
                return $prop->getValue($export) === [$this->settingA->id];
            }
        );
    }

    public function test_user_without_permission_is_denied_on_toggle_product_in_ledger_reports(): void
    {
        // 1. Valuation report
        $userVal = User::factory()->create();
        $userVal->givePermissionTo('inventoryValuationReports.access');
        $userVal->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);

        $testableVal = Livewire::actingAs($userVal)->test(InventoryValuationReport::class);
        $userVal->revokePermissionTo('inventoryValuationReports.access');
        $testableVal->call('toggleProduct', $this->productAB->id)->assertStatus(403);

        // 2. Detail report
        $userDet = User::factory()->create();
        $userDet->givePermissionTo('stockMutationReports.access');
        $userDet->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);

        $testableDet = Livewire::actingAs($userDet)->test(InventoryDetailReport::class);
        $userDet->revokePermissionTo('stockMutationReports.access');
        $testableDet->call('toggleProduct', $this->productAB->id)->assertStatus(403);
    }

    public function test_empty_selection_followed_by_export_dispatches_alert_in_ledger_reports(): void
    {
        // 1. Valuation Report Excel
        Livewire::actingAs($this->user)
            ->test(InventoryValuationReport::class)
            ->set('selectedSettingIds', [])
            ->set('filterTriggered', true)
            ->set('appliedFilters', [
                'tanggalAwal' => '2026-11-01',
                'tanggalAkhir' => '2026-11-30',
                'selectedSettingIds' => [],
            ])
            ->call('exportExcel')
            ->assertDispatched('alert', fn ($event, $params) => ($params[0]['message'] ?? '') === 'Pilih minimal satu bisnis.');

        // 2. Valuation Report CSV
        Livewire::actingAs($this->user)
            ->test(InventoryValuationReport::class)
            ->set('selectedSettingIds', [])
            ->set('filterTriggered', true)
            ->set('appliedFilters', [
                'tanggalAwal' => '2026-11-01',
                'tanggalAkhir' => '2026-11-30',
                'selectedSettingIds' => [],
            ])
            ->call('exportCsv')
            ->assertDispatched('alert', fn ($event, $params) => ($params[0]['message'] ?? '') === 'Pilih minimal satu bisnis.');

        // 3. Detail Report Excel
        Livewire::actingAs($this->user)
            ->test(InventoryDetailReport::class)
            ->set('selectedSettingIds', [])
            ->set('filterTriggered', true)
            ->set('appliedFilters', [
                'tanggalAwal' => '2026-11-01',
                'tanggalAkhir' => '2026-11-30',
                'selectedSettingIds' => [],
            ])
            ->call('exportExcel')
            ->assertDispatched('alert', fn ($event, $params) => ($params[0]['message'] ?? '') === 'Pilih minimal satu bisnis.');

        // 4. Detail Report CSV
        Livewire::actingAs($this->user)
            ->test(InventoryDetailReport::class)
            ->set('selectedSettingIds', [])
            ->set('filterTriggered', true)
            ->set('appliedFilters', [
                'tanggalAwal' => '2026-11-01',
                'tanggalAkhir' => '2026-11-30',
                'selectedSettingIds' => [],
            ])
            ->call('exportCsv')
            ->assertDispatched('alert', fn ($event, $params) => ($params[0]['message'] ?? '') === 'Pilih minimal satu bisnis.');
    }

    public function test_inventory_detail_excel_header_names_selected_business_not_session_business(): void
    {
        \Maatwebsite\Excel\Facades\Excel::fake();

        // Session business is A, user is assigned to A and B
        session(['setting_id' => $this->settingA->id]);

        Livewire::actingAs($this->user)
            ->test(InventoryDetailReport::class)
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->set('selectedSettingIds', [$this->settingB->id])
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('detail-persediaan-barang_01-11-2026_sd_30-11-2026.xlsx', function (\App\Exports\InventoryDetailReportExport $export) {
            $array = $export->array();
            // Header row 0, col 0 must be BISNIS B, not session's BISNIS A
            return isset($array[0][0]) && $array[0][0] === 'BISNIS B';
        });
    }

    public function test_inventory_valuation_cancel_filters_does_not_revert_selected_settings(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(InventoryValuationReport::class)
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->call('applyFilters');

        $this->assertTrue($component->viewData('isMultiBusiness'));

        $component->set('selectedSettingIds', [$this->settingA->id])
            ->call('cancelFilters')
            ->assertSet('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');

        $this->assertFalse($component->viewData('isMultiBusiness'));
        $this->assertEquals(95000.0, (float) $component->viewData('totalValue'));
    }

    public function test_inventory_detail_cancel_filters_does_not_revert_selected_settings(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(InventoryDetailReport::class)
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->call('applyFilters');

        $this->assertTrue($component->viewData('isMultiBusiness'));

        $component->set('selectedSettingIds', [$this->settingA->id])
            ->call('cancelFilters')
            ->assertSet('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');

        $this->assertFalse($component->viewData('isMultiBusiness'));
    }

    public function test_sale_return_good_non_tax_renders_indonesian_label_in_ledger_reports(): void
    {
        $this->actingAs($this->user);

        $this->createTx(
            $this->productAB->id,
            $this->settingA->id,
            $this->locationA->id,
            'SALE_RETURN_GOOD_NON_TAX',
            5,
            5,
            10,
            'Retur #SRN-DETAIL-001',
            '2026-11-15 10:00:00'
        );

        // 1. InventoryDetailReport
        Livewire::test(InventoryDetailReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->call('applyFilters')
            ->call('toggleProduct', $this->productAB->id)
            ->assertSee('Retur Penjualan Barang Baik (Non Pajak)');

        // 2. InventoryValuationReport
        Livewire::test(InventoryValuationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->set('tanggalAwal', '2026-11-01')
            ->set('tanggalAkhir', '2026-11-30')
            ->call('applyFilters')
            ->call('toggleProduct', $this->productAB->id)
            ->assertSee('Retur Penjualan Barang Baik (Non Pajak)');
    }
}

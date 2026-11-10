<?php

namespace Tests\Feature\Reports;

use App\Exports\StockMutationReportExport;
use App\Livewire\Reports\StockMutationReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockMutationReportFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $settingA;
    protected Setting $settingB;
    protected Setting $settingC;
    protected Location $locationA;
    protected Location $locationB;
    protected Location $locationC;
    protected Role $adminRole;
    protected Product $product;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        Permission::findOrCreate('stockMutationReports.access', 'web');
        Permission::findOrCreate('stockMutationReports.global.access', 'web');

        $this->settingA = Setting::factory()->create(['company_name' => 'Bisnis A']);
        $this->settingB = Setting::factory()->create(['company_name' => 'Bisnis B']);
        $this->settingC = Setting::factory()->create(['company_name' => 'Bisnis C']);

        $this->locationA = Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang A',
            'is_active' => true,
        ]);
        $this->locationB = Location::create([
            'setting_id' => $this->settingB->id,
            'name' => 'Gudang B',
            'is_active' => true,
        ]);
        $this->locationC = Location::create([
            'setting_id' => $this->settingC->id,
            'name' => 'Gudang C',
            'is_active' => true,
        ]);

        $this->adminRole = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('stockMutationReports.access');
        $this->user->givePermissionTo('stockMutationReports.global.access');
        $this->user->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);
        $this->user->settings()->attach($this->settingB->id, ['role_id' => $this->adminRole->id]);

        session(['setting_id' => $this->settingA->id]);

        $category = Category::create([
            'setting_id' => $this->settingA->id,
            'category_code' => 'CAT1',
            'category_name' => 'Category 1',
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->settingA->id,
            'category_id' => $category->id,
            'product_name' => 'Produk Uji',
            'product_code' => 'PRD-01',
            'product_price' => 10000,
            'product_cost' => 5000,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Transactions in A, B, and C
        $this->createTx($this->settingA->id, $this->locationA->id, 10, '#TX-A');
        $this->createTx($this->settingB->id, $this->locationB->id, 20, '#TX-B');
        $this->createTx($this->settingC->id, $this->locationC->id, 30, '#TX-C');
    }

    protected function createTx(int $settingId, int $locId, float $qty, string $ref): Transaction
    {
        return Transaction::create([
            'setting_id' => $settingId,
            'product_id' => $this->product->id,
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
            'reason' => $ref,
            'date' => now()->format('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unchanged_single_business_output()
    {
        // Default load has only session business (A) selected
        $testable = Livewire::actingAs($this->user)
            ->test(StockMutationReport::class)
            ->call('applyFilters');

        $mutations = $testable->get('mutations');
        $this->assertCount(1, $mutations);
        $row = $mutations->first();

        // Row should NOT have 'business' key
        $this->assertArrayNotHasKey('business', $row);
        $this->assertEquals(['date', 'product_name', 'product_code', 'location', 'type', 'qty_in', 'qty_out', 'reference'], array_keys($row));

        // View does not render <th>Bisnis</th>
        $testable->assertDontSeeHtml('<th>Bisnis</th>');

        // Export headings for single business do not have Bisnis
        $export = new StockMutationReportExport([
            'settingIds' => [$this->settingA->id],
        ]);
        $this->assertNotContains('Bisnis', $export->headings());
    }

    public function test_multi_business_shows_bisnis_column_and_exports()
    {
        // Select A and B
        $testable = Livewire::actingAs($this->user)
            ->test(StockMutationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingB->id])
            ->call('applyFilters');

        $mutations = $testable->get('mutations');
        $this->assertCount(2, $mutations);

        // Rows should have 'business'
        foreach ($mutations as $row) {
            $this->assertArrayHasKey('business', $row);
        }

        // View renders <th>Bisnis</th> and company names
        $testable->assertSeeHtml('<th>Bisnis</th>');
        $testable->assertSee('BISNIS A');
        $testable->assertSee('BISNIS B');

        // Export headings include Bisnis
        $export = new StockMutationReportExport([
            'settingIds' => [$this->settingA->id, $this->settingB->id],
        ]);
        $this->assertContains('Bisnis', $export->headings());

        $collection = $export->collection();
        $this->assertCount(2, $collection);
        $this->assertArrayHasKey('business', $collection->first());
    }

    public function test_global_defaults_to_assigned_businesses_only()
    {
        // Mounting with isGlobal = true
        $testable = Livewire::actingAs($this->user)
            ->test(StockMutationReport::class, ['isGlobal' => true]);

        $selected = $testable->get('selectedSettingIds');
        $this->assertEqualsCanonicalizing([$this->settingA->id, $this->settingB->id], $selected);
        $this->assertNotContains($this->settingC->id, $selected);

        $testable->call('applyFilters');
        $mutations = $testable->get('mutations');

        // Should include movements for A and B, but NOT C
        $this->assertCount(2, $mutations);
        $locations = $mutations->pluck('location')->all();
        $this->assertContains('GUDANG A', $locations);
        $this->assertContains('GUDANG B', $locations);
        $this->assertNotContains('GUDANG C', $locations);
    }

    public function test_tampered_id_is_dropped()
    {
        // User submits A and C (where C is not assigned)
        $testable = Livewire::actingAs($this->user)
            ->test(StockMutationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingC->id])
            ->call('applyFilters');

        $sanitized = $testable->instance()->getSanitizedSettingIds();
        $this->assertEquals([$this->settingA->id], $sanitized);

        $mutations = $testable->get('mutations');
        $this->assertCount(1, $mutations);
        $this->assertEquals('GUDANG A', $mutations->first()['location']);
    }

    public function test_empty_selection_shows_message()
    {
        $testable = Livewire::actingAs($this->user)
            ->test(StockMutationReport::class)
            ->set('selectedSettingIds', [])
            ->call('applyFilters');

        $testable->assertSee('Pilih minimal satu bisnis');
        $mutations = $testable->get('mutations');
        $this->assertCount(0, $mutations);
    }

    public function test_user_without_permission_is_denied_on_export_excel(): void
    {
        $userWithAccess = User::factory()->create();
        $userWithAccess->givePermissionTo('stockMutationReports.access');
        $userWithAccess->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);

        // 1. When isGlobal is set to true without global access permission -> 403
        Livewire::actingAs($userWithAccess)
            ->test(StockMutationReport::class)
            ->set('isGlobal', true)
            ->call('exportExcel')
            ->assertStatus(403);

        // 2. When user lacks permission entirely -> 403 on mount or action
        $userWithoutAccess = User::factory()->create();
        $userWithoutAccess->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);
        Livewire::actingAs($userWithoutAccess)
            ->test(StockMutationReport::class)
            ->assertStatus(403);
    }

    public function test_export_receives_only_sanitized_setting_ids_when_unassigned_business_selected(): void
    {
        \Maatwebsite\Excel\Facades\Excel::fake();

        // User is assigned to A and B, but NOT C.
        Livewire::actingAs($this->user)
            ->test(StockMutationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingC->id])
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('laporan-mutasi-stok.xlsx', function (StockMutationReportExport $export) {
            $reflection = new \ReflectionClass($export);
            $prop = $reflection->getProperty('filters');
            $prop->setAccessible(true);
            $filters = $prop->getValue($export);

            return $filters['settingIds'] === [$this->settingA->id];
        });
    }

    public function test_product_owned_by_b_with_movement_at_a_appears_when_only_a_selected(): void
    {
        $category = Category::create([
            'setting_id' => $this->settingB->id,
            'category_code' => 'CAT-B',
            'category_name' => 'Category B',
            'created_by' => $this->user->id,
        ]);

        $productB = Product::create([
            'setting_id' => $this->settingB->id,
            'category_id' => $category->id,
            'product_name' => 'Produk Milik B',
            'product_code' => 'PRD-OWNED-B',
            'product_price' => 15000,
            'product_cost' => 8000,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Movement occurs at Setting A's location A
        Transaction::create([
            'setting_id' => $this->settingA->id,
            'product_id' => $productB->id,
            'location_id' => $this->locationA->id,
            'type' => 'BUY',
            'current_quantity' => 0,
            'previous_quantity' => 0,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 7,
            'quantity_non_tax' => 0,
            'quantity_tax' => 7,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'quantity' => 7,
            'after_quantity' => 7,
            'reason' => '#TX-OWNED-B',
            'date' => now()->format('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $testable = Livewire::actingAs($this->user)
            ->test(StockMutationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->call('applyFilters');

        $mutations = $testable->get('mutations');
        $this->assertTrue($mutations->contains('product_name', 'Produk Milik B'));
    }

    public function test_empty_selection_on_export_dispatches_alert(): void
    {
        Livewire::actingAs($this->user)
            ->test(StockMutationReport::class)
            ->set('selectedSettingIds', [])
            ->call('exportExcel')
            ->assertDispatched('alert', fn ($event, $params) => ($params[0]['message'] ?? '') === 'Pilih minimal satu bisnis.');

        Livewire::actingAs($this->user)
            ->test(StockMutationReport::class)
            ->set('selectedSettingIds', [])
            ->call('exportCsv')
            ->assertDispatched('alert', fn ($event, $params) => ($params[0]['message'] ?? '') === 'Pilih minimal satu bisnis.');
    }

    public function test_non_global_default_only_includes_assigned_session_business(): void
    {
        // 1. When session business is assigned (Setting A)
        session(['setting_id' => $this->settingA->id]);
        $componentA = Livewire::actingAs($this->user)->test(StockMutationReport::class);
        $this->assertEquals([$this->settingA->id], $componentA->get('selectedSettingIds'));

        // 2. When session business is unassigned (Setting C), falls back to first assigned business (Setting A)
        session(['setting_id' => $this->settingC->id]);
        $componentC = Livewire::actingAs($this->user)->test(StockMutationReport::class);
        $this->assertEquals([$this->settingA->id], $componentC->get('selectedSettingIds'));
    }

    public function test_sale_return_good_non_tax_renders_indonesian_label(): void
    {
        Transaction::create([
            'setting_id' => $this->settingA->id,
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'type' => 'SALE_RETURN_GOOD_NON_TAX',
            'current_quantity' => 0,
            'previous_quantity' => 0,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'quantity' => 5,
            'after_quantity' => 5,
            'reason' => 'SRN-TEST-001',
            'date' => now()->format('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($this->user)
            ->test(StockMutationReport::class)
            ->set('selectedSettingIds', [$this->settingA->id])
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee('Retur Penjualan Barang Baik (Non Pajak)');
    }
}

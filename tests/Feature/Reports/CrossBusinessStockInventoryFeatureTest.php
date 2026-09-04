<?php

namespace Tests\Feature\Reports;

use App\Exports\CrossBusinessStockInventoryExport;
use App\Livewire\Reports\CrossBusinessStockInventory;
use App\Models\User;
use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use App\Services\Reports\CrossBusinessStockInventoryQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Currency\Entities\Currency;
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

class CrossBusinessStockInventoryFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected Currency $currency;
    protected Setting $setting1;
    protected Setting $setting2;
    protected Location $location1A;
    protected Location $location1B;
    protected Location $location2A;
    protected User $superAdmin;
    protected User $assignedUser;
    protected User $unpermittedUser;
    protected Category $category;
    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions and Roles
        Permission::firstOrCreate(['name' => 'inventory.view_remaining_stock']);
        $roleSuperAdmin = Role::firstOrCreate(['name' => 'Super Admin']);

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        // Setting 1 (PKP = true)
        $this->setting1 = Setting::create([
            'company_name' => 'Bisnis Alpha PKP',
            'company_email' => 'alpha@example.com',
            'company_phone' => '111111',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'alpha@example.com',
            'footer_text' => 'Footer Alpha',
            'company_address' => 'Alpha Address',
            'is_pkp' => true,
        ]);

        // Setting 2 (PKP = false)
        $this->setting2 = Setting::create([
            'company_name' => 'Bisnis Beta Non-PKP',
            'company_email' => 'beta@example.com',
            'company_phone' => '222222',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'beta@example.com',
            'footer_text' => 'Footer Beta',
            'company_address' => 'Beta Address',
            'is_pkp' => false,
        ]);

        // Locations for Setting 1
        $this->location1A = Location::create([
            'name' => 'Gudang Alpha 1',
            'setting_id' => $this->setting1->id,
            'is_active' => true,
        ]);
        $this->location1B = Location::create([
            'name' => 'Gudang Alpha 2',
            'setting_id' => $this->setting1->id,
            'is_active' => true,
        ]);

        // Location for Setting 2
        $this->location2A = Location::create([
            'name' => 'Gudang Beta 1',
            'setting_id' => $this->setting2->id,
            'is_active' => true,
        ]);

        // Users
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole($roleSuperAdmin);
        $this->superAdmin->givePermissionTo('inventory.view_remaining_stock');

        $roleOperator = Role::firstOrCreate(['name' => 'Operator']);

        $this->assignedUser = User::factory()->create();
        $this->assignedUser->givePermissionTo('inventory.view_remaining_stock');
        // Assign only to Setting 1
        $this->assignedUser->settings()->attach($this->setting1->id, ['role_id' => $roleOperator->id]);

        $this->unpermittedUser = User::factory()->create();

        // Master data
        $this->category = Category::create([
            'setting_id' => $this->setting1->id,
            'category_code' => 'CAT-LAPTOP',
            'category_name' => 'Laptop & Komputer',
            'created_by' => $this->superAdmin->id,
        ]);

        $this->brand = Brand::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Asus ROG',
            'created_by' => $this->superAdmin->id,
        ]);
    }

    /**
     * 10.1 Feature test: non-Super-Admin user sees only assigned businesses in filter options and table columns; Super Admin sees all
     */
    public function test_business_visibility_scoping_for_assigned_user_and_super_admin(): void
    {
        // Assigned user (only assigned to Bisnis Alpha)
        $this->actingAs($this->assignedUser);

        Livewire::test(CrossBusinessStockInventory::class)
            ->assertStatus(200)
            ->assertSee('BISNIS ALPHA PKP')
            ->assertDontSee('BISNIS BETA NON-PKP');

        // Super Admin (sees all businesses)
        $this->actingAs($this->superAdmin);

        Livewire::test(CrossBusinessStockInventory::class)
            ->assertStatus(200)
            ->assertSee('BISNIS ALPHA PKP')
            ->assertSee('BISNIS BETA NON-PKP');
    }

    /**
     * 10.2 Feature test: Good/Bad aggregation correctness across multiple locations for a single business (collapsed vs. expanded values reconcile)
     */
    public function test_good_and_bad_aggregation_and_collapse_expand_reconciliation(): void
    {
        $this->actingAs($this->superAdmin);

        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'product_name' => 'Laptop Gaming Extreme',
            'product_code' => 'LAP-001',
            'product_unit' => 'pc',
            'product_quantity' => 20,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Location 1A: Good = 10 (tax 7, non-tax 3), Bad = 2 (tax 2, non-tax 0)
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location1A->id,
            'quantity' => 10,
            'quantity_tax' => 7,
            'quantity_non_tax' => 3,
            'broken_quantity' => 2,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 0,
        ]);

        // Location 1B: Good = 5 (tax 5, non-tax 0), Bad = 1 (tax 0, non-tax 1)
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location1B->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 1,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 1,
        ]);

        // Query Service check:
        // Bisnis Alpha Total Good = 15, Total Bad = 3
        $service = new CrossBusinessStockInventoryQueryService();
        $filterData = new CrossBusinessStockInventoryFilterData(businessIds: [$this->setting1->id]);
        $matrix = $service->getStockMatrix([$product->id], [$this->setting1->id]);

        $bData = $matrix[$product->id]['businesses'][$this->setting1->id];
        $this->assertEquals(15.0, $bData['good']);
        $this->assertEquals(3.0, $bData['bad']);

        $loc1A = $bData['locations'][$this->location1A->id];
        $loc1B = $bData['locations'][$this->location1B->id];
        $this->assertEquals(10.0, $loc1A['good']);
        $this->assertEquals(2.0, $loc1A['bad']);
        $this->assertEquals(5.0, $loc1B['good']);
        $this->assertEquals(1.0, $loc1B['bad']);

        $this->assertEquals($bData['good'], $loc1A['good'] + $loc1B['good']);
        $this->assertEquals($bData['bad'], $loc1A['bad'] + $loc1B['bad']);

        // Livewire UI check: row renders and toggleBusinessExpand expands/collapses location columns
        $component = Livewire::test(CrossBusinessStockInventory::class)
            ->assertSee('Laptop Gaming Extreme');

        $viewRows = $component->viewData('rows');
        $this->assertNotEmpty($viewRows);
        $productRow = $viewRows->firstWhere('id', $product->id);
        $this->assertEquals(15.0, $productRow['businesses'][$this->setting1->id]['good']);
        $this->assertEquals(3.0, $productRow['businesses'][$this->setting1->id]['bad']);

        $component->call('toggleBusinessExpand', $this->setting1->id)
            ->assertSee('GUDANG ALPHA 1')
            ->assertSee('GUDANG ALPHA 2');

        $expandedLocs = $productRow['businesses'][$this->setting1->id]['locations'];
        $this->assertEquals(10.0, $expandedLocs[$this->location1A->id]['good']);
        $this->assertEquals(5.0, $expandedLocs[$this->location1B->id]['good']);

        $component->call('toggleBusinessExpand', $this->setting1->id)
            ->assertDontSee('GUDANG ALPHA 1');
    }

    /**
     * 10.3 Feature test: tax/non-tax tooltip appears only when the mismatch condition is met, at both collapsed and expanded granularity, with seeded non-zero broken_quantity/quantity_non_tax data
     */
    public function test_tax_nontax_tooltip_computation_collapsed_and_expanded(): void
    {
        $this->actingAs($this->superAdmin);

        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'product_name' => 'Tax Test Product',
            'product_code' => 'TAX-001',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Setting 1 is PKP (is_pkp = true). Unexpected bucket is non_tax.
        // Location 1A: quantity_non_tax = 4, broken_quantity_non_tax = 1
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location1A->id,
            'quantity' => 10,
            'quantity_tax' => 6,
            'quantity_non_tax' => 4,
            'broken_quantity' => 1,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 1,
        ]);

        // Setting 2 is Non-PKP (is_pkp = false). Unexpected bucket is tax.
        // Location 2A: quantity_tax = 2, broken_quantity_tax = 3
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location2A->id,
            'quantity' => 10,
            'quantity_tax' => 2,
            'quantity_non_tax' => 8,
            'broken_quantity' => 3,
            'broken_quantity_tax' => 3,
            'broken_quantity_non_tax' => 0,
        ]);

        $service = new CrossBusinessStockInventoryQueryService();
        $reportData = $service->getReportData(
            new CrossBusinessStockInventoryFilterData(businessIds: [$this->setting1->id, $this->setting2->id])
        );

        $row = $reportData['rows']->first();

        // Setting 1 (PKP): collapsed should show non-tax tooltip
        $this->assertEquals('Non-tax: 4', $row['businesses'][$this->setting1->id]['good_tooltip']);
        $this->assertEquals('Non-tax: 1', $row['businesses'][$this->setting1->id]['bad_tooltip']);

        // Setting 2 (Non-PKP): collapsed should show tax tooltip
        $this->assertEquals('Tax: 2', $row['businesses'][$this->setting2->id]['good_tooltip']);
        $this->assertEquals('Tax: 3', $row['businesses'][$this->setting2->id]['bad_tooltip']);

        // Livewire UI asserts tooltip presence
        Livewire::test(CrossBusinessStockInventory::class)
            ->assertSee('Non-tax: 4')
            ->assertSee('Tax: 2');
    }

    /**
     * 10.4 Feature test: serial dialog returns correct sellable/broken serials scoped to the clicked business+location+condition
     */
    public function test_serial_dialog_scopes_correctly_for_good_and_bad(): void
    {
        $this->actingAs($this->superAdmin);

        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'product_name' => 'Serialized Phone',
            'product_code' => 'SPHONE-01',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_price' => 1000,
            'product_cost' => 500,
            'serial_number_required' => true,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Stock row so quantity > 0
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location1A->id,
            'quantity' => 2,
            'quantity_tax' => 2,
            'quantity_non_tax' => 0,
            'broken_quantity' => 1,
            'broken_quantity_tax' => 1,
            'broken_quantity_non_tax' => 0,
        ]);

        // Serial 1: Good sellable in Location 1A
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location1A->id,
            'serial_number' => 'SN-GOOD-1A',
            'status' => 'ACTIVE',
            'is_broken' => false,
            'is_in_return_process' => false,
            'dispatch_detail_id' => null,
        ]);

        // Serial 2: Broken in Location 1A
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location1A->id,
            'serial_number' => 'SN-BAD-1A',
            'status' => 'ACTIVE',
            'is_broken' => true,
            'is_in_return_process' => false,
        ]);

        // Serial 3: Returned (not sellable even if is_broken = false) in Location 1A
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location1A->id,
            'serial_number' => 'SN-RETURNED-1A',
            'status' => 'RETURNED',
            'is_broken' => false,
            'is_in_return_process' => false,
        ]);

        // Serial 4: Good sellable in Setting 2
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location2A->id,
            'serial_number' => 'SN-GOOD-2A',
            'status' => 'ACTIVE',
            'is_broken' => false,
            'is_in_return_process' => false,
        ]);

        // Test Good dialog for Setting 1
        Livewire::test(CrossBusinessStockInventory::class)
            ->call('openSerialDialog', $product->id, $product->product_name, $this->setting1->id, $this->setting1->company_name, null, 'Semua Lokasi', 'good')
            ->assertSee('SN-GOOD-1A')
            ->assertDontSee('SN-BAD-1A')
            ->assertDontSee('SN-RETURNED-1A')
            ->assertDontSee('SN-GOOD-2A');

        // Test Bad dialog for Setting 1
        Livewire::test(CrossBusinessStockInventory::class)
            ->call('openSerialDialog', $product->id, $product->product_name, $this->setting1->id, $this->setting1->company_name, null, 'Semua Lokasi', 'bad')
            ->assertSee('SN-BAD-1A')
            ->assertDontSee('SN-GOOD-1A');

        // Review Item 2: Inactive location test for getSerialNumbers
        $inactiveLocation = Location::create([
            'name' => 'Gudang Inactive Serials',
            'setting_id' => $this->setting1->id,
            'is_active' => false,
        ]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $inactiveLocation->id,
            'serial_number' => 'SN-INACTIVE-LOC',
            'status' => 'ACTIVE',
            'is_broken' => false,
            'is_in_return_process' => false,
            'dispatch_detail_id' => null,
        ]);

        $service = new CrossBusinessStockInventoryQueryService();
        $inactiveSerials = $service->getSerialNumbers(
            productId: $product->id,
            settingId: $this->setting1->id,
            locationId: $inactiveLocation->id,
            condition: 'good'
        );
        $this->assertEquals(0, $inactiveSerials->total());

        // Also when requesting for whole business (locationId = null), serials in inactive locations must not leak
        $businessSerials = $service->getSerialNumbers(
            productId: $product->id,
            settingId: $this->setting1->id,
            locationId: null,
            condition: 'good'
        );
        $this->assertFalse($businessSerials->getCollection()->contains('serial_number', 'SN-INACTIVE-LOC'));
    }

    /**
     * 10.5 Feature test: product-identity multi-token search still matches order-independently (regression check against existing Product::scopeGlobalSearch behavior)
     */
    public function test_multi_token_search_order_independent(): void
    {
        $this->actingAs($this->superAdmin);

        $product1 = Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'product_name' => 'Acer 8GB RAM Core i3 Laptop',
            'product_code' => 'ACER-8-I3',
            'product_unit' => 'pc',
            'product_quantity' => 5,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        $product2 = Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'product_name' => 'HP 16GB Core i7 Desktop',
            'product_code' => 'HP-16-I7',
            'product_unit' => 'pc',
            'product_quantity' => 5,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Search with out-of-order tokens: "i3 8 core acer"
        Livewire::test(CrossBusinessStockInventory::class)
            ->set('search', 'i3 8 core acer')
            ->assertSee('Acer 8GB RAM Core i3 Laptop')
            ->assertDontSee('HP 16GB Core i7 Desktop');
    }

    /**
     * 10.6 Feature test: exact-match barcode/serial search returns expected row and does not match partial fragments
     */
    public function test_exact_match_barcode_and_serial_does_not_match_partial(): void
    {
        $this->actingAs($this->superAdmin);

        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'product_name' => 'Exact Match Target',
            'product_code' => 'TGT-999',
            'barcode' => '8991234567890',
            'product_unit' => 'pc',
            'product_quantity' => 5,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location1A->id,
            'serial_number' => 'SERIAL-EXACT-UNIQUE-999',
            'status' => 'ACTIVE',
            'is_broken' => false,
            'is_in_return_process' => false,
        ]);

        // 1. Barcode partial search matches via product-identity token path (restored in review finding 1)
        Livewire::test(CrossBusinessStockInventory::class)
            ->set('search', '8991234')
            ->assertSee('Exact Match Target');

        // 2. Exact barcode search matches
        Livewire::test(CrossBusinessStockInventory::class)
            ->set('search', '8991234567890')
            ->assertSee('Exact Match Target');

        // 3. Partial serial does not match (serial path requires exact match, not in product-identity tokens)
        Livewire::test(CrossBusinessStockInventory::class)
            ->set('search', 'EXACT-UNIQUE')
            ->assertDontSee('Exact Match Target');

        // 4. Exact serial search matches owning product
        Livewire::test(CrossBusinessStockInventory::class)
            ->set('search', 'SERIAL-EXACT-UNIQUE-999')
            ->assertSee('Exact Match Target');
    }

    /**
     * 10.7 Feature test: Excel export always shows fully expanded per-location columns regardless of on-screen collapse state, and respects applied filters/business scope
     */
    public function test_excel_export_always_shows_expanded_locations_and_respects_scope(): void
    {
        Excel::fake();

        $this->actingAs($this->assignedUser);

        Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'product_name' => 'Export Product',
            'product_code' => 'EXP-001',
            'product_unit' => 'pc',
            'product_quantity' => 5,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        \Carbon\Carbon::setTestNow(now());

        Livewire::test(CrossBusinessStockInventory::class)
            ->call('exportExcel');

        Excel::assertDownloaded('stok-persediaan-lintas-bisnis_' . now()->format('Y-m-d_His') . '.xlsx', function (CrossBusinessStockInventoryExport $export) {
            \Carbon\Carbon::setTestNow();
            $array = $export->array();
            $titleRow = $array[0];
            $businessRow = $array[1];
            $locationRow = $array[2];
            $conditionRow = $array[3];

            // Title row
            $this->assertEquals('Stok Persediaan Lintas Bisnis', $titleRow[0]);

            // Business header row
            $businessString = implode(' ', $businessRow);
            $this->assertStringContainsString('BISNIS ALPHA PKP', $businessString);
            $this->assertStringNotContainsString('BISNIS BETA NON-PKP', $businessString);

            // Location header row
            $locationString = implode(' ', $locationRow);
            $this->assertStringContainsString('GUDANG ALPHA 1', $locationString);
            $this->assertStringContainsString('GUDANG ALPHA 2', $locationString);

            // Sub-headers must be Bagus and Rusak
            $this->assertContains('Bagus', $conditionRow);
            $this->assertContains('Rusak', $conditionRow);

            return true;
        });
    }

    /**
     * 10.8 Permission test: user without inventory.view_remaining_stock receives 403 on direct route access
     */
     public function test_user_without_permission_receives_403(): void
     {
         $this->actingAs($this->unpermittedUser);

         $response = $this->get(route('reports.cross-business-stock-inventory.index'));
         $response->assertStatus(403);
     }

    /**
     * Test Finding #2: Stocks in inactive locations are excluded from collapsed totals, expanded columns, and Excel export
     */
    public function test_inactive_location_stocks_are_excluded(): void
    {
        Excel::fake();
        $this->actingAs($this->superAdmin);

        // Inactive location under setting 1
        $inactiveLocation = Location::create([
            'name' => 'Gudang Alpha Nonaktif',
            'setting_id' => $this->setting1->id,
            'is_active' => false,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'product_name' => 'Product With Inactive Location Stock',
            'product_code' => 'INACT-001',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Active location stock: 5 good
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location1A->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Inactive location stock: 10 good, 2 bad
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $inactiveLocation->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 2,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 0,
        ]);

        $service = new CrossBusinessStockInventoryQueryService();
        $matrix = $service->getStockMatrix([$product->id], [$this->setting1->id]);
        $bData = $matrix[$product->id]['businesses'][$this->setting1->id];

        // Collapsed total must be 5 (only active location), NOT 15
        $this->assertEquals(5.0, $bData['good']);
        $this->assertEquals(0.0, $bData['bad']);
        $this->assertArrayNotHasKey($inactiveLocation->id, $bData['locations']);

        // Livewire UI: expanded columns must not contain inactive location
        \Carbon\Carbon::setTestNow(now());

        Livewire::test(CrossBusinessStockInventory::class)
            ->call('toggleBusinessExpand', $this->setting1->id)
            ->assertSee('GUDANG ALPHA 1')
            ->assertDontSee('GUDANG ALPHA NONAKTIF')
            ->call('exportExcel');

        Excel::assertDownloaded('stok-persediaan-lintas-bisnis_' . now()->format('Y-m-d_His') . '.xlsx', function (CrossBusinessStockInventoryExport $export) use ($inactiveLocation) {
            \Carbon\Carbon::setTestNow();
            $array = $export->array();
            // Inactive location must not appear in business row (1), location row (2), or anywhere in headers
            $headersCombined = implode(' ', array_merge($array[0], $array[1], $array[2], $array[3]));
            $this->assertStringNotContainsString('GUDANG ALPHA NONAKTIF', $headersCombined);
            return true;
        });
    }

    /**
     * Test Finding #5.1: Expanded-granularity tooltip assertion on specific expanded location cell
     */
    public function test_expanded_granularity_tooltip_on_specific_location(): void
    {
        $this->actingAs($this->superAdmin);

        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'product_name' => 'Granular Tooltip Product',
            'product_code' => 'GRAN-001',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Setting 1 is PKP (is_pkp = true). Non-tax stock is unexpected.
        // Location 1A: has non-tax stock (quantity_non_tax = 4) -> should have tooltip
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location1A->id,
            'quantity' => 10,
            'quantity_tax' => 6,
            'quantity_non_tax' => 4,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Location 1B: compliant tax-only stock (quantity_tax = 5, non-tax = 0) -> NO tooltip
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location1B->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $service = new CrossBusinessStockInventoryQueryService();
        $reportData = $service->getReportData(
            new CrossBusinessStockInventoryFilterData(businessIds: [$this->setting1->id])
        );

        $row = $reportData['rows']->firstWhere('id', $product->id);
        $locations = $row['businesses'][$this->setting1->id]['locations'];

        // Assert on specific expanded location cells
        $this->assertEquals('Non-tax: 4', $locations[$this->location1A->id]['good_tooltip']);
        $this->assertNull($locations[$this->location1B->id]['good_tooltip']);

        // Assert in Livewire rendering when expanded
        Livewire::test(CrossBusinessStockInventory::class)
            ->call('toggleBusinessExpand', $this->setting1->id)
            ->assertSeeHtml('title="Non-tax: 4"');
    }

    /**
     * Test Finding #5.2: Availability filter with visible-business scoping
     */
    public function test_availability_filter_with_visible_business_scoping(): void
    {
        // P1 has stock in Setting 1 (Alpha)
        $p1 = Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'product_name' => 'Product Alpha Stocked',
            'product_code' => 'P-ALPHA-STK',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $p1->id,
            'location_id' => $this->location1A->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // P2 has zero stock everywhere
        $p2 = Product::create([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->category->id,
            'product_name' => 'Product Zero Everywhere',
            'product_code' => 'P-ZERO-STK',
            'product_unit' => 'pc',
            'product_quantity' => 0,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // P3 has stock ONLY in Setting 2 (Beta)
        $p3 = Product::create([
            'setting_id' => $this->setting2->id,
            'category_id' => $this->category->id,
            'product_name' => 'Product Beta Stocked Only',
            'product_code' => 'P-BETA-STK',
            'product_unit' => 'pc',
            'product_quantity' => 15,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $p3->id,
            'location_id' => $this->location2A->id,
            'quantity' => 15,
            'quantity_tax' => 0,
            'quantity_non_tax' => 15,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Part 1: Super Admin (sees both Setting 1 and Setting 2)
        $this->actingAs($this->superAdmin);

        // 'available' shows P1 and P3, but not P2
        Livewire::test(CrossBusinessStockInventory::class)
            ->set('availability', 'available')
            ->assertSee('Product Alpha Stocked')
            ->assertSee('Product Beta Stocked Only')
            ->assertDontSee('Product Zero Everywhere');

        // 'non_available' shows P2, but not P1 or P3
        Livewire::test(CrossBusinessStockInventory::class)
            ->set('availability', 'non_available')
            ->assertSee('Product Zero Everywhere')
            ->assertDontSee('Product Alpha Stocked')
            ->assertDontSee('Product Beta Stocked Only');

        // Part 2: Assigned User (assigned ONLY to Setting 1 Alpha)
        $this->actingAs($this->assignedUser);

        // For assigned user:
        // P1 has stock in Setting 1 -> 'available'
        // P3 has stock only in Setting 2 (not visible to this user) -> must be treated as 'non_available'!
        Livewire::test(CrossBusinessStockInventory::class)
            ->set('availability', 'available')
            ->assertSee('Product Alpha Stocked')
            ->assertDontSee('Product Beta Stocked Only')
            ->assertDontSee('Product Zero Everywhere');

        Livewire::test(CrossBusinessStockInventory::class)
            ->set('availability', 'non_available')
            ->assertSee('Product Zero Everywhere')
            ->assertSee('Product Beta Stocked Only')
            ->assertDontSee('Product Alpha Stocked');
    }
}

<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the shared AJAX Select2 endpoints backing Consignment filter dropdowns.
 *
 * Boundary rules under test:
 * - Supplier and Product are shared master data: globally searchable, never
 *   filtered by setting_id.
 * - Location and Consignment evidence stay scoped to the active setting.
 */
class ConsignmentSelectSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected User $user;
    protected User $unauthorizedUser;
    protected Supplier $supplier;
    protected Product $product;
    protected Location $location;
    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting1 = $this->makeSetting('Select Search One', 'sel1@example.com', 'SS1', $currency->id);
        $this->setting2 = $this->makeSetting('Select Search Two', 'sel2@example.com', 'SS2', $currency->id);

        $role = Role::firstOrCreate(['name' => 'Consignment Clerk', 'guard_name' => 'web']);
        foreach ([
            'consignments.access',
            'consignments.allocations.access',
            'consignments.allocations.create',
            'consignments.billing.access',
        ] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            $role->givePermissionTo($permission);
        }

        $this->user = User::factory()->create();
        $this->user->settings()->attach($this->setting1->id, ['role_id' => $role->id]);
        $this->user->assignRole($role);

        // Attached to the same setting but holding no Consignment permissions.
        $emptyRole = Role::firstOrCreate(['name' => 'No Consignment Access', 'guard_name' => 'web']);
        $this->unauthorizedUser = User::factory()->create();
        $this->unauthorizedUser->settings()->attach($this->setting1->id, ['role_id' => $emptyRole->id]);
        $this->unauthorizedUser->assignRole($emptyRole);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->unit = Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting1->id,
            'supplier_name' => 'Alpha Local Supplier',
            'supplier_email' => 'alpha@example.com',
            'supplier_phone' => '0811111111',
            'city' => 'Surabaya',
            'country' => 'Indonesia',
            'address' => 'Alpha St 1',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Alpha Local Widget',
            'product_code' => 'ALW-01',
            'product_unit' => $this->unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 10,
            'is_active' => true,
            'stock_managed' => true,
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Local Consignment Rack',
            'is_consignment' => true,
        ]);
    }

    private function makeSetting(string $name, string $email, string $prefix, int $currencyId): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => $email,
            'company_phone' => '08123456789',
            'default_currency_id' => $currencyId,
            'default_currency_position' => 'prefix',
            'notification_email' => $email,
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
            'document_prefix' => $prefix,
        ]);
    }

    private function callAs(User $user, string $route, array $params = [])
    {
        return $this->actingAs($user)
            ->withSession(['setting_id' => $this->setting1->id])
            ->getJson(route($route, $params));
    }

    /** @test */
    public function select_endpoints_require_consignment_permissions()
    {
        foreach (['consignments.select.suppliers', 'consignments.select.products', 'consignments.select.locations'] as $route) {
            $this->callAs($this->unauthorizedUser, $route)->assertStatus(403);
        }

        // Sold source search requires the stricter allocation-create permission.
        $this->callAs($this->unauthorizedUser, 'consignments.select.sold-sources')->assertStatus(403);
    }

    /** @test */
    public function select_endpoints_are_reachable_by_permitted_users()
    {
        $this->callAs($this->user, 'consignments.select.suppliers')->assertOk();
        $this->callAs($this->user, 'consignments.select.products')->assertOk();
        $this->callAs($this->user, 'consignments.select.locations')->assertOk();
        $this->callAs($this->user, 'consignments.select.sold-sources')->assertOk();
    }

    /** @test */
    public function supplier_search_returns_shared_suppliers_from_every_setting()
    {
        $foreignSupplier = Supplier::create([
            'setting_id' => $this->setting2->id,
            'supplier_name' => 'Alpha Foreign Supplier',
            'supplier_email' => 'foreign@example.com',
            'supplier_phone' => '0822222222',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Foreign St 2',
            'is_active' => true,
        ]);

        $response = $this->callAs($this->user, 'consignments.select.suppliers', ['q' => 'Alpha']);

        $response->assertOk();
        $ids = collect($response->json('results'))->pluck('id')->all();

        $this->assertContains($this->supplier->id, $ids);
        $this->assertContains($foreignSupplier->id, $ids, 'Suppliers are shared master data and must not be setting-filtered.');
    }

    /** @test */
    public function product_search_returns_shared_products_from_every_setting()
    {
        $foreignProduct = Product::create([
            'setting_id' => $this->setting2->id,
            'product_name' => 'Alpha Foreign Widget',
            'product_code' => 'AFW-01',
            'product_unit' => $this->unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 5,
            'is_active' => true,
            'stock_managed' => true,
        ]);

        $response = $this->callAs($this->user, 'consignments.select.products', ['q' => 'Alpha']);

        $response->assertOk();
        $ids = collect($response->json('results'))->pluck('id')->all();

        $this->assertContains($this->product->id, $ids);
        $this->assertContains($foreignProduct->id, $ids, 'Products are shared master data and must not be setting-filtered.');
    }

    /** @test */
    public function product_search_excludes_inactive_products()
    {
        $inactive = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Alpha Inactive Widget',
            'product_code' => 'AIW-01',
            'product_unit' => $this->unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 5,
            'is_active' => false,
            'stock_managed' => true,
        ]);

        $response = $this->callAs($this->user, 'consignments.select.products', ['q' => 'Alpha']);

        $ids = collect($response->json('results'))->pluck('id')->all();
        $this->assertNotContains($inactive->id, $ids);
    }

    /** @test */
    public function supplier_search_can_restrict_to_active_records_for_write_workflows()
    {
        $inactive = Supplier::create([
            'setting_id' => $this->setting1->id,
            'supplier_name' => 'Alpha Inactive Supplier',
            'supplier_email' => 'inactive@example.com',
            'supplier_phone' => '0833333333',
            'city' => 'Bandung',
            'country' => 'Indonesia',
            'address' => 'Inactive St 3',
            'is_active' => false,
        ]);

        $unfiltered = $this->callAs($this->user, 'consignments.select.suppliers', ['q' => 'Alpha']);
        $this->assertContains($inactive->id, collect($unfiltered->json('results'))->pluck('id')->all());

        $activeOnly = $this->callAs($this->user, 'consignments.select.suppliers', ['q' => 'Alpha', 'active_only' => 1]);
        $this->assertNotContains($inactive->id, collect($activeOnly->json('results'))->pluck('id')->all());
    }

    /** @test */
    public function location_search_is_scoped_to_the_active_setting()
    {
        $foreignLocation = Location::create([
            'setting_id' => $this->setting2->id,
            'name' => 'Foreign Consignment Rack',
            'is_consignment' => true,
        ]);

        $response = $this->callAs($this->user, 'consignments.select.locations', ['q' => 'Rack']);

        $response->assertOk();
        $ids = collect($response->json('results'))->pluck('id')->all();

        $this->assertContains($this->location->id, $ids);
        $this->assertNotContains($foreignLocation->id, $ids, 'Locations are setting-scoped infrastructure.');
    }

    /** @test */
    public function sold_source_search_filters_by_term_and_stays_setting_scoped()
    {
        $localSource = $this->makeSoldSource($this->setting1, $this->product, $this->location, 'SL-LOCAL-001');

        $foreignLocation = Location::create([
            'setting_id' => $this->setting2->id,
            'name' => 'Foreign Rack',
            'is_consignment' => true,
        ]);
        $foreignSource = $this->makeSoldSource($this->setting2, $this->product, $foreignLocation, 'SL-FOREIGN-001');

        // Unfiltered: evidence from another setting must never appear.
        $all = $this->callAs($this->user, 'consignments.select.sold-sources');
        $allIds = collect($all->json('results'))->pluck('id')->all();
        $this->assertContains($localSource->id, $allIds);
        $this->assertNotContains($foreignSource->id, $allIds, 'Sold sources are setting-scoped evidence.');

        // Search by sale reference.
        $byReference = $this->callAs($this->user, 'consignments.select.sold-sources', ['q' => 'SL-LOCAL-001']);
        $this->assertContains($localSource->id, collect($byReference->json('results'))->pluck('id')->all());

        // A non-matching term returns nothing.
        $noMatch = $this->callAs($this->user, 'consignments.select.sold-sources', ['q' => 'ZZZ-NO-MATCH']);
        $this->assertSame([], collect($noMatch->json('results'))->pluck('id')->all());

        // Search by product name.
        $byProduct = $this->callAs($this->user, 'consignments.select.sold-sources', ['q' => 'Alpha Local Widget']);
        $this->assertContains($localSource->id, collect($byProduct->json('results'))->pluck('id')->all());
    }

    /** @test */
    public function search_results_are_paginated()
    {
        for ($i = 0; $i < 25; $i++) {
            Supplier::create([
                'setting_id' => $this->setting1->id,
                'supplier_name' => sprintf('Bulk Supplier %02d', $i),
                'supplier_email' => "bulk{$i}@example.com",
                'supplier_phone' => '08440000' . $i,
                'city' => 'Surabaya',
                'country' => 'Indonesia',
                'address' => "Bulk St {$i}",
                'is_active' => true,
            ]);
        }

        $firstPage = $this->callAs($this->user, 'consignments.select.suppliers', ['q' => 'Bulk Supplier']);
        $firstPage->assertOk()->assertJsonPath('pagination.more', true);
        $this->assertCount(20, $firstPage->json('results'), 'Page size must be bounded.');

        $secondPage = $this->callAs($this->user, 'consignments.select.suppliers', ['q' => 'Bulk Supplier', 'page' => 2]);
        $secondPage->assertOk()->assertJsonPath('pagination.more', false);
        $this->assertCount(5, $secondPage->json('results'));

        $firstIds = collect($firstPage->json('results'))->pluck('id')->all();
        $secondIds = collect($secondPage->json('results'))->pluck('id')->all();
        $this->assertEmpty(array_intersect($firstIds, $secondIds), 'Pages must not overlap.');
    }

    /** @test */
    public function selected_id_lookup_restores_a_label_without_a_search_term()
    {
        // Backs selected-value restoration after reloads and validation failures.
        $response = $this->callAs($this->user, 'consignments.select.suppliers', ['selected_id' => $this->supplier->id]);

        $response->assertOk()
            ->assertJsonPath('results.0.id', $this->supplier->id)
            ->assertJsonPath('pagination.more', false);

        $this->assertStringContainsStringIgnoringCase('Alpha Local Supplier', $response->json('results.0.text'));
    }

    /** @test */
    public function filter_pages_restore_the_selected_supplier_and_product_labels()
    {
        // Receival index restores the selected supplier from the query string.
        $receivals = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting1->id])
            ->get(route('consignments.receivals.index', ['supplier_id' => $this->supplier->id]));

        $receivals->assertOk();
        $this->assertEquals($this->supplier->supplier_name, $receivals->viewData('selectedSupplierText'));

        // Sold sources index restores the selected product.
        $soldSources = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting1->id])
            ->get(route('consignments.sold-sources.index', ['product_id' => $this->product->id]));

        $soldSources->assertOk();
        $this->assertEquals($this->product->product_name, $soldSources->viewData('selectedProductText'));
    }

    /** @test */
    public function confirmation_create_filters_sources_without_loading_full_master_collections()
    {
        $otherProduct = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Beta Filtered Widget',
            'product_code' => 'BFW-01',
            'product_unit' => $this->unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 5,
            'is_active' => true,
            'stock_managed' => true,
        ]);

        $this->makeSoldSource($this->setting1, $this->product, $this->location, 'SL-KEEP-001');
        $this->makeSoldSource($this->setting1, $otherProduct, $this->location, 'SL-DROP-001');

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting1->id])
            ->get(route('consignments.confirmations.create', [
                'supplier_id' => $this->supplier->id,
                'filter_product_id' => $this->product->id,
            ]));

        $response->assertOk();

        // The source list is paginated, and the product filter is applied at query level.
        $sources = $response->viewData('eligibleSources');
        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\Paginator::class, $sources);
        foreach ($sources as $src) {
            $this->assertEquals($this->product->id, $src->product_id);
        }

        // Master collections must not be pushed into the view.
        $this->assertArrayNotHasKey('suppliers', $response->original->getData());
        $this->assertArrayNotHasKey('products', $response->original->getData());

        // The selected supplier label survives so the AJAX select can restore it.
        $this->assertEquals($this->supplier->supplier_name, $response->viewData('selectedSupplierText'));
    }

    private function makeSoldSource(Setting $setting, Product $product, Location $location, string $reference): ConsignmentSoldSource
    {
        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_name' => 'Customer ' . $reference,
            'reference' => $reference,
            'date' => date('Y-m-d'),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'dispatched_quantity' => 3,
        ]);

        return ConsignmentSoldSource::create([
            'setting_id' => $setting->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'original_base_quantity' => 3,
            'dispatched_at' => now(),
            'source_hash' => 'hash_' . $reference,
            'source_snapshot' => [],
        ]);
    }
}

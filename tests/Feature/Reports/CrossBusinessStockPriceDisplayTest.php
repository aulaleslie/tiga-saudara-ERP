<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\CrossBusinessStockInventory;
use App\Models\User;
use App\Services\Reports\CrossBusinessStockInventoryQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CrossBusinessStockPriceDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting1;
    private Setting $setting2;
    private Location $loc1A;
    private Location $loc1B;
    private Location $loc2A;
    private User $priceAuthorizedUser;
    private User $stockOnlyUser;
    private Unit $pcsUnit;
    private Unit $boxUnit;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'inventory.view_remaining_stock', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'products.view_prices', 'guard_name' => 'web']);
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->setting1 = Setting::create([
            'company_name' => 'Alpha Corp',
            'company_email' => 'alpha@example.com',
            'company_phone' => '08111',
            'notification_email' => 'alpha@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'company_address' => 'Alpha Address',
            'footer_text' => 'Footer',
            'is_pkp' => true,
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Beta Corp',
            'company_email' => 'beta@example.com',
            'company_phone' => '08222',
            'notification_email' => 'beta@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'company_address' => 'Beta Address',
            'footer_text' => 'Footer',
            'is_pkp' => false,
        ]);

        $this->loc1A = Location::create(['setting_id' => $this->setting1->id, 'name' => 'Alpha A', 'is_active' => true]);
        $this->loc1B = Location::create(['setting_id' => $this->setting1->id, 'name' => 'Alpha B', 'is_active' => true]);
        $this->loc2A = Location::create(['setting_id' => $this->setting2->id, 'name' => 'Beta A', 'is_active' => true]);

        $this->pcsUnit = Unit::create(['name' => 'Pieces', 'short_name' => 'Pcs', 'is_active' => true, 'setting_id' => $this->setting1->id]);
        $this->boxUnit = Unit::create(['name' => 'Box', 'short_name' => 'Box', 'is_active' => true, 'setting_id' => $this->setting1->id]);

        $this->priceAuthorizedUser = User::factory()->create(['is_active' => 1]);
        $this->priceAuthorizedUser->assignRole($superAdminRole);
        $this->priceAuthorizedUser->givePermissionTo(['inventory.view_remaining_stock', 'products.view_prices']);

        $staffRole = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);
        $this->stockOnlyUser = User::factory()->create(['is_active' => 1]);
        $this->stockOnlyUser->givePermissionTo('inventory.view_remaining_stock');
        $this->stockOnlyUser->settings()->attach($this->setting1->id, ['role_id' => $staffRole->id]);
        $this->stockOnlyUser->settings()->attach($this->setting2->id, ['role_id' => $staffRole->id]);
    }

    public function test_bulk_price_matrix_preserves_distinct_businesses_zero_null_and_magnitude_without_fallback(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Laptop Pro',
            'product_code' => 'LPT-01',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_unit' => 'Pcs',
            'product_quantity' => 10,
            'product_price' => 9999999, // Legacy value — MUST NOT be used as fallback
            'product_cost' => 8888888,  // Legacy value — MUST NOT be used as fallback
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Business 1 has stored prices including 0.00 and 2500000.00 and a null tier_2
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting1->id,
            'average_purchase_price' => 0.00,
            'sale_price' => 2500000.00,
            'tier_1_price' => 2400000.50,
            'tier_2_price' => null,
        ]);
        // Business 2 has NO ProductPrice row at all

        $service = new CrossBusinessStockInventoryQueryService();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $matrix = $service->getPriceMatrix([$product->id], [$this->setting1->id, $this->setting2->id]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'Price loading must execute in a single bulk query.');

        $p1 = $matrix[$product->id][$this->setting1->id];
        $this->assertSame(0.0, $p1['average_purchase_price']);
        $this->assertSame(2500000.0, $p1['sale_price']);
        $this->assertSame(2400000.5, $p1['tier_1_price']);
        $this->assertNull($p1['tier_2_price']);

        // Business 2 must NOT borrow Business 1 or legacy Product values
        $this->assertArrayNotHasKey($this->setting2->id, $matrix[$product->id]);
    }

    public function test_price_display_defaults_off_shows_once_per_business_and_does_not_apply_draft_or_rerun_stock(): void
    {
        $this->actingAs($this->priceAuthorizedUser);

        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Monitor 27',
            'product_code' => 'MON-27',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_unit' => 'Pcs',
            'product_quantity' => 12,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 10,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->loc1A->id,
            'quantity' => 12,
            'quantity_tax' => 12,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting1->id,
            'average_purchase_price' => 0.00,
            'sale_price' => 2500000.00,
            'tier_1_price' => 2450000.00,
            'tier_2_price' => null,
        ]);

        // Default: showPrices is false, no price columns or Satuan column rendered
        $component = Livewire::test(CrossBusinessStockInventory::class)
            ->assertSet('showPrices', false)
            ->assertSet('loadedPrices', [])
            ->assertDontSee('Harga Modal')
            ->assertDontSee('Rp 2.500.000');

        // Make a draft business change (unapplied)
        $component->set('selectedSettingIds', [$this->setting1->id])
            ->assertSet('hasPendingFilterChanges', true);

        // Toggle showPrices ON: must NOT rerun stock/purchase aggregation, must NOT apply draft business change
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->set('showPrices', true);
        $toggleQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $stockOrPurchaseQueries = collect($toggleQueries)->filter(
            fn ($q) => str_contains($q['query'], 'product_stocks') || str_contains($q['query'], 'purchase_details')
        )->count();
        $this->assertSame(0, $stockOrPurchaseQueries, 'Toggling Tampilkan Harga must not rerun stock or purchase aggregation.');

        $component
            ->assertSet('hasPendingFilterChanges', true) // draft still pending
            ->assertSee('Harga Modal')
            ->assertSee('Satuan')
            ->assertSee('PCS')
            ->assertSee('Rp 0')
            ->assertSee('Rp 2.500.000')
            ->assertSee('Rp 2.450.000')
            ->assertSee('—'); // missing tier_2 and missing Beta Corp prices

        // Expand Alpha Corp (2 locations): price headers for Alpha Corp must still appear ONCE
        $component->call('toggleBusinessExpand', $this->setting1->id);
        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'data-price-header="business-' . $this->setting1->id . '-modal"'));
        $this->assertSame(1, substr_count($html, 'data-price-cell="business-' . $this->setting1->id . '-sale"'));

        // Switch quantity format to conversion: prices must remain per base unit and unchanged
        $component->set('displayMode', 'conversion')
            ->assertSee('Rp 2.500.000')
            ->assertSee('PCS');
    }

    public function test_unauthorized_user_cannot_view_prices_or_manipulate_toggle_and_revocation_clears_state(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Secret Price Item',
            'product_code' => 'SEC-01',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_unit' => 'Pcs',
            'product_quantity' => 5,
            'product_price' => 1000,
            'product_cost' => 500,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting1->id,
            'average_purchase_price' => 123456.00,
            'sale_price' => 654321.00,
            'tier_1_price' => 600000.00,
            'tier_2_price' => 550000.00,
        ]);

        // Stock-only user can access stock report, sees no price toggle or price data
        $this->actingAs($this->stockOnlyUser);
        $comp = Livewire::test(CrossBusinessStockInventory::class)
            ->assertSee('Secret Price Item')
            ->assertDontSee('Tampilkan Harga')
            ->assertDontSee('123.456')
            ->assertDontSee('654.321')
            ->assertSet('loadedPrices', []);

        // Manipulated toggle to showPrices=true must be rejected with 403
        $comp->set('showPrices', true)->assertForbidden();

        // Permission revocation mid-session clears loadedPrices on refresh
        $this->actingAs($this->priceAuthorizedUser);
        $authComp = Livewire::test(CrossBusinessStockInventory::class)
            ->set('showPrices', true)
            ->assertSee('Rp 654.321');

        $this->priceAuthorizedUser->revokePermissionTo('products.view_prices');
        $this->priceAuthorizedUser->roles()->detach(); // remove Super Admin bypass
        $this->priceAuthorizedUser->givePermissionTo('inventory.view_remaining_stock');
        $staffRole = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);
        $this->priceAuthorizedUser->settings()->sync([$this->setting1->id => ['role_id' => $staffRole->id]]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->priceAuthorizedUser->fresh());

        $authComp->call('$refresh')
            ->assertSet('showPrices', false)
            ->assertSet('loadedPrices', [])
            ->assertDontSee('Rp 654.321');
    }
}

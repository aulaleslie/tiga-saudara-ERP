<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Transfer\TransferProductTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Task 5.1: Livewire-level payload leakage coverage for the transfer
 * product table using distinctive sentinel stock quantities. A blind user's
 * public component state/snapshot must never contain the sentinel bucket
 * values or a "stock" key; a privileged user must retain them.
 */
class TransferProductTableVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Location $origin;
    private Product $product;

    // Distinctive sentinel quantities chosen to be detectable via string
    // search and unlikely to occur by coincidence in unrelated output.
    private const SENTINEL_TAX = 8811;
    private const SENTINEL_NON_TAX = 8822;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $this->setting->id]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'created_by' => 1,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Sentinel Product',
            'product_code' => 'P-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->origin->id,
            'quantity' => self::SENTINEL_TAX + self::SENTINEL_NON_TAX,
            'quantity_tax' => self::SENTINEL_TAX,
            'quantity_non_tax' => self::SENTINEL_NON_TAX,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        session(['setting_id' => $this->setting->id]);

        Permission::firstOrCreate(['name' => TransferStockVisibility::PERMISSION, 'guard_name' => 'web']);

        // TransferProductTable enforces stockTransfers.create/edit surface
        // authorization on its own (it is an independently callable
        // Livewire endpoint, not secured only by the route/parent form), so
        // every acting user in this suite needs it regardless of whether
        // they also hold TransferStockVisibility::PERMISSION.
        Permission::firstOrCreate(['name' => 'stockTransfers.create', 'guard_name' => 'web']);
    }

    private function actingUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('stockTransfers.create');

        return $user;
    }

    private function scanPayload(int $multiplier = 1): array
    {
        return [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'serial_number_required' => false,
            'is_broken_mode' => false,
            'scan_quantity_multiplier' => $multiplier,
        ];
    }

    /** @test */
    public function blind_user_never_receives_sentinel_stock_in_component_state()
    {
        $user = $this->actingUser();

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
            ])
            ->call('productSelected', $this->scanPayload(5));

        $html = $component->html();
        $serialized = json_encode($component->get('products'));

        $this->assertStringNotContainsString((string) self::SENTINEL_TAX, $html);
        $this->assertStringNotContainsString((string) self::SENTINEL_NON_TAX, $html);
        $this->assertStringNotContainsString((string) self::SENTINEL_TAX, $serialized);
        $this->assertStringNotContainsString((string) self::SENTINEL_NON_TAX, $serialized);
        $this->assertStringNotContainsString('"stock"', $serialized);
        $this->assertStringNotContainsString('quantity_tax', $serialized);
        $this->assertStringNotContainsString('quantity_non_tax', $serialized);

        // operator intent is retained
        $this->assertSame(5, $component->get('products')[0]['requested_quantity']);
    }

    /** @test */
    public function privileged_user_retains_full_stock_and_allocation_state()
    {
        $user = $this->actingUser();
        $user->givePermissionTo(TransferStockVisibility::PERMISSION);

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
            ])
            ->call('productSelected', $this->scanPayload(5));

        $serialized = json_encode($component->get('products'));

        $this->assertStringContainsString((string) self::SENTINEL_TAX, $serialized);
        $this->assertStringContainsString((string) self::SENTINEL_NON_TAX, $serialized);
        $this->assertArrayHasKey('stock', $component->get('products')[0]);
    }

    /** @test */
    public function super_admin_retains_full_stock_and_allocation_state()
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
            ])
            ->call('productSelected', $this->scanPayload(5));

        $serialized = json_encode($component->get('products'));

        $this->assertStringContainsString((string) self::SENTINEL_TAX, $serialized);
        $this->assertArrayHasKey('stock', $component->get('products')[0]);
    }

    /** @test */
    public function blind_insufficient_stock_message_is_neutral()
    {
        $user = $this->actingUser();

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
            ])
            // request far more than the sentinel stock provides
            ->call('productSelected', $this->scanPayload(999999));

        $errors = $component->get('tableValidationErrors');
        $message = collect($errors)->first();

        $this->assertNotEmpty($errors);
        $this->assertStringNotContainsString((string) self::SENTINEL_TAX, $message);
        $this->assertStringNotContainsString((string) self::SENTINEL_NON_TAX, $message);
    }

    /** @test */
    public function view_system_stock_alone_cannot_drive_the_table_without_create_or_edit_authority()
    {
        // Holding only stockTransfers.view-system-stock -- without transfer
        // create or edit surface authority -- must not be enough to query
        // stock through this independently callable Livewire endpoint. The
        // permission governs *what* is shown for an authorized origin, not
        // *whether* this table may be used at all.
        $user = User::factory()->create();
        $user->givePermissionTo(TransferStockVisibility::PERMISSION);

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
            ])
            ->call('productSelected', $this->scanPayload(5));

        $this->assertSame([], $component->get('products'));

        $serialized = json_encode($component->get('products'));
        $this->assertStringNotContainsString((string) self::SENTINEL_TAX, $serialized);
        $this->assertStringNotContainsString((string) self::SENTINEL_NON_TAX, $serialized);
    }
}

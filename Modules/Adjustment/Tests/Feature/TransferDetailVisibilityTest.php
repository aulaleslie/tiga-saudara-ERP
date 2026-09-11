<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 5.2: feature-level coverage proving the transfer detail page omits
 * protected quantities/buckets for a blind viewer while retaining them for
 * a privileged viewer and a Super Admin, using distinctive sentinel values.
 */
class TransferDetailVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Transfer $transfer;
    private Product $product;

    // Distinctive sentinel quantities.
    private const SENTINEL_TAX = 7711;
    private const SENTINEL_NON_TAX = 7722;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);

        foreach ([
            'stockTransfers.show',
            TransferStockVisibility::PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $setting = \Modules\Setting\Entities\Setting::factory()->create();
        $origin = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $setting->id]);
        $destination = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $setting->id]);

        $creator = User::factory()->create();

        $category = Category::create([
            'setting_id' => $setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'created_by' => $creator->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Detail Sentinel Product',
            'product_code' => 'P-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id' => $origin->id,
            'destination_location_id' => $destination->id,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'status' => Transfer::STATUS_PENDING,
            'created_by' => $creator->id,
        ]);

        TransferProduct::create([
            'transfer_id' => $this->transfer->id,
            'product_id' => $this->product->id,
            'quantity' => self::SENTINEL_TAX + self::SENTINEL_NON_TAX,
            'quantity_tax' => self::SENTINEL_TAX,
            'quantity_non_tax' => self::SENTINEL_NON_TAX,
        ]);

        session(['setting_id' => $setting->id]);
    }

    /** @test */
    public function blind_viewer_detail_page_omits_sentinel_quantities()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('stockTransfers.show');

        $response = $this->actingAs($user)->get(route('transfers.show', $this->transfer));

        $response->assertOk();
        $response->assertDontSee((string) self::SENTINEL_TAX);
        $response->assertDontSee((string) self::SENTINEL_NON_TAX);

        // Non-protected document metadata is retained.
        $response->assertSee($this->transfer->document_number ?? $this->transfer->id);
        $response->assertSee('Detail Sentinel Product');
    }

    /** @test */
    public function privileged_viewer_detail_page_retains_sentinel_quantities()
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['stockTransfers.show', TransferStockVisibility::PERMISSION]);

        $response = $this->actingAs($user)->get(route('transfers.show', $this->transfer));

        $response->assertOk();
        $response->assertSee((string) self::SENTINEL_TAX);
        $response->assertSee((string) self::SENTINEL_NON_TAX);
    }

    /** @test */
    public function super_admin_detail_page_retains_sentinel_quantities_without_direct_grant()
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $response = $this->actingAs($user)->get(route('transfers.show', $this->transfer));

        $response->assertOk();
        $response->assertSee((string) self::SENTINEL_TAX);
        $response->assertSee((string) self::SENTINEL_NON_TAX);
    }
}

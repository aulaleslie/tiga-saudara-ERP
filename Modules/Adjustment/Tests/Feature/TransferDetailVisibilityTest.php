<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Entities\TransferReturnObligation;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 5.2 & fix-transfer-detail-obligation-loading:
 * Feature-level coverage proving the transfer detail page omits protected
 * quantities/buckets/obligations for a blind viewer while retaining them for
 * a privileged viewer and a Super Admin, compatible with disabled lazy loading.
 */
class TransferDetailVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    private Transfer $transfer;
    private TransferProduct $transferProduct;
    private Product $product;
    private Location $origin;
    private Location $destination;
    private User $creator;

    // Distinctive sentinel quantities.
    private const SENTINEL_TAX = 7711;
    private const SENTINEL_NON_TAX = 7722;
    private const LEGACY_OBLIGATION_TAX = 9871;
    private const LEGACY_OBLIGATION_BROKEN_TAX = 9872;
    private const V2_OBLIGATION_QTY = 5432;

    private bool $originalPreventsLazyLoading;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPreventsLazyLoading = Model::preventsLazyLoading();
        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);

        foreach ([
            'stockTransfers.show',
            TransferStockVisibility::PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $setting->id]);
        $this->destination = Location::factory()->create(['setting_id' => $setting->id]);

        $this->creator = User::factory()->create();

        $category = Category::create([
            'setting_id' => $setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'created_by' => $this->creator->id,
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
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'status' => Transfer::STATUS_PENDING,
            'created_by' => $this->creator->id,
        ]);

        $this->transferProduct = TransferProduct::create([
            'transfer_id' => $this->transfer->id,
            'product_id' => $this->product->id,
            'quantity' => self::SENTINEL_TAX + self::SENTINEL_NON_TAX,
            'quantity_tax' => self::SENTINEL_TAX,
            'quantity_non_tax' => self::SENTINEL_NON_TAX,
        ]);

        session(['setting_id' => $setting->id]);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading($this->originalPreventsLazyLoading);
        parent::tearDown();
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

    /** @test */
    public function blind_viewer_with_lazy_loading_disabled_renders_detail_and_omits_legacy_and_v2_obligations(): void
    {
        Model::preventLazyLoading(true);

        // Attach legacy return obligation to the transfer product
        TransferReturnObligation::create([
            'transfer_id' => $this->transfer->id,
            'transfer_product_id' => $this->transferProduct->id,
            'required_quantity_tax' => self::LEGACY_OBLIGATION_TAX,
            'required_quantity_broken_tax' => self::LEGACY_OBLIGATION_BROKEN_TAX,
        ]);

        // Configure v2 workflow with route policy and movement return obligation
        $this->transfer->update(['workflow_version' => 2]);

        $policy = $this->createRoutePolicySnapshot(
            $this->transfer,
            $this->origin,
            $this->destination,
            $this->creator,
            1,
            TransferRoutePolicy::CLASSIFICATION_NON_TAX,
            true
        );

        $receiptMovement = TransferMovement::create([
            'transfer_id' => $this->transfer->id,
            'type' => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision' => 1,
            'lock_version' => 1,
            'transfer_revision' => 1,
            'status' => TransferMovement::STATUS_APPROVED,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition' => TransferMovement::CONDITION_GOOD,
            'created_by' => $this->creator->id,
        ]);

        TransferMovementReturnObligation::create([
            'transfer_id' => $this->transfer->id,
            'transfer_route_policy_id' => $policy->id,
            'receipt_movement_id' => $receiptMovement->id,
            'product_id' => $this->product->id,
            'stock_condition' => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity' => self::V2_OBLIGATION_QTY,
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('stockTransfers.show');

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->actingAs($user)->get(route('transfers.show', $this->transfer));
            $executedQueries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response->assertOk();
        // Permitted product identity and context remain visible
        $response->assertSee('Detail Sentinel Product');
        $response->assertSee($this->product->product_code);
        $response->assertSee($this->transfer->document_number ?? $this->transfer->id);

        // Distinctive legacy obligation values and badge are absent
        $response->assertDontSee((string) self::LEGACY_OBLIGATION_TAX);
        $response->assertDontSee((string) self::LEGACY_OBLIGATION_BROKEN_TAX);
        $response->assertDontSee('Butuh Pengembalian');

        // Distinctive v2 route policy and obligation values are absent
        $response->assertDontSee('Kebijakan Rute (V2)');
        $response->assertDontSee('NON_TAX');
        $response->assertDontSee((string) self::V2_OBLIGATION_QTY);

        // Verify no queries touched return obligation tables
        foreach ($executedQueries as $entry) {
            $sql = $entry['query'];
            $this->assertStringNotContainsString('transfer_return_obligations', $sql);
            $this->assertStringNotContainsString('transfer_movement_return_obligations', $sql);
            $this->assertStringNotContainsString('transfer_route_policies', $sql);
        }
    }

    /** @test */
    public function privileged_viewer_with_lazy_loading_disabled_renders_legacy_and_v2_obligations(): void
    {
        Model::preventLazyLoading(true);

        // Attach legacy return obligation to the transfer product
        TransferReturnObligation::create([
            'transfer_id' => $this->transfer->id,
            'transfer_product_id' => $this->transferProduct->id,
            'required_quantity_tax' => self::LEGACY_OBLIGATION_TAX,
            'required_quantity_broken_tax' => self::LEGACY_OBLIGATION_BROKEN_TAX,
        ]);

        // Configure v2 workflow with route policy and movement return obligation
        $this->transfer->update(['workflow_version' => 2]);

        $policy = $this->createRoutePolicySnapshot(
            $this->transfer,
            $this->origin,
            $this->destination,
            $this->creator,
            1,
            TransferRoutePolicy::CLASSIFICATION_NON_TAX,
            true
        );

        $receiptMovement = TransferMovement::create([
            'transfer_id' => $this->transfer->id,
            'type' => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision' => 1,
            'lock_version' => 1,
            'transfer_revision' => 1,
            'status' => TransferMovement::STATUS_APPROVED,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition' => TransferMovement::CONDITION_GOOD,
            'created_by' => $this->creator->id,
        ]);

        TransferMovementReturnObligation::create([
            'transfer_id' => $this->transfer->id,
            'transfer_route_policy_id' => $policy->id,
            'receipt_movement_id' => $receiptMovement->id,
            'product_id' => $this->product->id,
            'stock_condition' => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity' => self::V2_OBLIGATION_QTY,
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo(['stockTransfers.show', TransferStockVisibility::PERMISSION]);

        $response = $this->actingAs($user)->get(route('transfers.show', $this->transfer));

        $response->assertOk();
        // Permitted product identity and context remain visible
        $response->assertSee('Detail Sentinel Product');
        $response->assertSee($this->product->product_code);
        $response->assertSee('Butuh Pengembalian');

        // Distinctive legacy obligation values are present
        $response->assertSee((string) self::LEGACY_OBLIGATION_TAX);
        $response->assertSee((string) self::LEGACY_OBLIGATION_BROKEN_TAX);

        // Distinctive v2 route policy and obligation values are present
        $response->assertSee('Kebijakan Rute (V2)');
        $response->assertSee('NON_TAX');
        $response->assertSee((string) self::V2_OBLIGATION_QTY);
    }
}

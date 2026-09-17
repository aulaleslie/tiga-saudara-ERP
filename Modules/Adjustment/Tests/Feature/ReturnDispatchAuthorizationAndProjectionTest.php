<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementHistory;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\ReturnDispatchPreparationService;
use Modules\Adjustment\Services\ReturnDispatchProjectionService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;

class ReturnDispatchAuthorizationAndProjectionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    private const GUARD = 'web';

    protected User $blindUser;
    protected User $privilegedUser;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;
    protected Transfer $transfer;
    protected TransferMovement $receiptMovement;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stock_transfers.v2_dispatch_enabled' => true]);

        $permissions = [
            'stockTransfers.access',
            'stockTransfers.dispatch.create',
            'stockTransfers.dispatch.approval',
            TransferStockVisibility::PERMISSION,
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => self::GUARD]);
        }

        $this->setting = Setting::factory()->create();

        $this->blindUser = User::factory()->create();
        $this->blindUser->givePermissionTo(['stockTransfers.access', 'stockTransfers.dispatch.create', 'stockTransfers.dispatch.approval']);

        $this->privilegedUser = User::factory()->create();
        $this->privilegedUser->givePermissionTo(['stockTransfers.access', 'stockTransfers.dispatch.create', 'stockTransfers.dispatch.approval', TransferStockVisibility::PERMISSION]);

        $this->origin = Location::create(['setting_id' => $this->setting->id, 'name' => 'Origin']);
        $this->destination = Location::create(['setting_id' => $this->setting->id, 'name' => 'Destination']);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->product = Product::create([
            'product_name'           => 'Secret Return Item',
            'product_code'           => 'SRI-001',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 10,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->product->id,
            'location_id'             => $this->destination->id,
            'quantity'                => 10,
            'quantity_non_tax'        => 10,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->privilegedUser->id,
            'status'                  => Transfer::STATUS_AWAITING_RETURN,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot(
            $this->transfer,
            $this->origin,
            $this->destination,
            $this->privilegedUser,
            1,
            TransferRoutePolicy::CLASSIFICATION_PRESERVE,
            true
        );

        $this->receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_APPROVED,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->privilegedUser->id,
        ]);

        TransferMovementReturnObligation::create([
            'transfer_id'              => $this->transfer->id,
            'transfer_route_policy_id' => TransferRoutePolicy::where('transfer_id', $this->transfer->id)->first()->id,
            'receipt_movement_id'      => $this->receiptMovement->id,
            'product_id'               => $this->product->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => '10.0000',
        ]);
    }

    /** @test */
    public function blind_preparation_projection_shows_product_identity_but_omits_capacity_details(): void
    {
        $prepService = app(ReturnDispatchPreparationService::class);
        $projectionService = app(ReturnDispatchProjectionService::class);

        $movement = $prepService->getOrCreateBatch($this->transfer, $this->blindUser->id);

        // Product identity (which products are obligated) is not protected information, so it
        // must be visible even to a blind operator with no prior scan activity.
        $blindProj = $projectionService->getPreparationProjection($this->transfer, $movement, false);
        $this->assertArrayHasKey('obligated_products', $blindProj);
        $this->assertCount(1, $blindProj['obligated_products']);
        $this->assertEquals($this->product->product_name, $blindProj['obligated_products'][0]['product_name']);
        $this->assertEquals($this->product->product_code, $blindProj['obligated_products'][0]['product_code']);
        $this->assertArrayNotHasKey('available_capacity', $blindProj['obligated_products'][0]);
        $this->assertArrayNotHasKey('outstanding_quantity', $blindProj['obligated_products'][0]);

        $privProj = $projectionService->getPreparationProjection($this->transfer, $movement, true);
        $this->assertArrayHasKey('obligated_products', $privProj);
        $this->assertEquals('10.0000', $privProj['obligated_products'][0]['available_capacity']);
    }

    /** @test */
    public function blind_approval_projection_returns_neutral_guidance_without_summary_or_details(): void
    {
        $prepService = app(ReturnDispatchPreparationService::class);
        $projectionService = app(ReturnDispatchProjectionService::class);

        $movement = $prepService->getOrCreateBatch($this->transfer, $this->blindUser->id);
        $movement = $prepService->setLineQuantity($movement, $this->product->id, 4, true, $movement->lock_version, $this->blindUser->id);
        $pending = $prepService->submit($movement, $movement->lock_version, $this->blindUser->id);

        $blindProj = $projectionService->getApprovalProjection($this->transfer, $pending, false);
        $this->assertTrue($blindProj['matches']);
        $this->assertArrayNotHasKey('summary', $blindProj);
        $this->assertArrayNotHasKey('details', $blindProj);

        $privProj = $projectionService->getApprovalProjection($this->transfer, $pending, true);
        $this->assertArrayHasKey('summary', $privProj);
        $this->assertArrayHasKey('details', $privProj);
    }

    /** @test */
    public function v2_return_routes_are_rejected_with_404_when_activation_is_disabled(): void
    {
        config(['stock_transfers.v2_dispatch_enabled' => false]);

        $response = $this->actingAs($this->privilegedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('transfers.movements.return.prepare', $this->transfer->id));

        $response->assertStatus(404);
    }

    /** @test */
    public function only_the_destination_tenant_may_prepare_a_return_dispatch_batch(): void
    {
        $otherSetting = Setting::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->givePermissionTo(['stockTransfers.access', 'stockTransfers.dispatch.create']);

        $response = $this->actingAs($otherUser)
            ->withSession(['setting_id' => $otherSetting->id])
            ->get(route('transfers.movements.return.prepare', $this->transfer->id));

        $response->assertStatus(403);
    }

    /** @test */
    public function cross_business_origin_tenant_cannot_prepare_the_return_dispatch_batch(): void
    {
        $originSetting = Setting::factory()->create();
        $crossOrigin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Cross Origin']);

        $crossTransfer = Transfer::create([
            'origin_location_id'      => $crossOrigin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->privilegedUser->id,
            'status'                  => Transfer::STATUS_AWAITING_RETURN,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot(
            $crossTransfer,
            $crossOrigin,
            $this->destination,
            $this->privilegedUser,
            1,
            TransferRoutePolicy::CLASSIFICATION_PRESERVE,
            true
        );

        $originUser = User::factory()->create();
        $originUser->givePermissionTo(['stockTransfers.access', 'stockTransfers.dispatch.create']);

        $response = $this->actingAs($originUser)
            ->withSession(['setting_id' => $originSetting->id])
            ->get(route('transfers.movements.return.prepare', $crossTransfer->id));

        $response->assertStatus(403);
    }

    /** @test */
    public function return_receipt_routes_remain_unavailable_for_v2_transfers(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('transfers.movements.return.receive'));
    }

    /** @test */
    public function cross_transfer_movement_mismatch_is_rejected_with_404(): void
    {
        $transferB = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->privilegedUser->id,
            'status'                  => Transfer::STATUS_AWAITING_RETURN,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot(
            $transferB,
            $this->origin,
            $this->destination,
            $this->privilegedUser,
            1,
            TransferRoutePolicy::CLASSIFICATION_PRESERVE,
            true
        );

        $receiptB = TransferMovement::create([
            'transfer_id'             => $transferB->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_APPROVED,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->privilegedUser->id,
        ]);

        TransferMovementReturnObligation::create([
            'transfer_id'              => $transferB->id,
            'transfer_route_policy_id' => TransferRoutePolicy::where('transfer_id', $transferB->id)->first()->id,
            'receipt_movement_id'      => $receiptB->id,
            'product_id'               => $this->product->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => '10.0000',
        ]);

        $prepService = app(ReturnDispatchPreparationService::class);
        $movementA = $prepService->getOrCreateBatch($this->transfer, $this->privilegedUser->id);
        $movementB = $prepService->getOrCreateBatch($transferB, $this->privilegedUser->id);

        $response = $this->actingAs($this->privilegedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('transfers.movements.return.scan', [
                'transfer' => $this->transfer->id,
                'movement' => $movementB->id,
            ]), [
                'query' => 'SRI-001',
            ]);

        $response->assertStatus(404);
    }

    /** @test */
    public function authorized_approver_can_render_pending_return_dispatch_review_page_with_rejection_route(): void
    {
        $prepService = app(ReturnDispatchPreparationService::class);
        $movement = $prepService->getOrCreateBatch($this->transfer, $this->privilegedUser->id);
        $movement = $prepService->setLineQuantity($movement, $this->product->id, 2, true, $movement->lock_version, $this->privilegedUser->id);
        $pending = $prepService->submit($movement, $movement->lock_version, $this->privilegedUser->id);

        $response = $this->actingAs($this->privilegedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('transfers.movements.return.review', [
                'transfer' => $this->transfer->id,
                'movement' => $pending->id,
            ]));

        $response->assertOk();
        $expectedRejectUrl = route('transfers.movements.return.reject', [
            'transfer' => $this->transfer->id,
            'movement' => $pending->id,
        ]);
        $response->assertSee($expectedRejectUrl, false);
        $response->assertSee('Tolak Batch');
    }

    /** @test */
    public function authorized_approver_can_reject_pending_return_dispatch_batch_via_named_route(): void
    {
        $prepService = app(ReturnDispatchPreparationService::class);
        $movement = $prepService->getOrCreateBatch($this->transfer, $this->privilegedUser->id);
        $movement = $prepService->setLineQuantity($movement, $this->product->id, 2, true, $movement->lock_version, $this->privilegedUser->id);
        $pending = $prepService->submit($movement, $movement->lock_version, $this->privilegedUser->id);

        $rejectionReason = 'Barang rusak saat persiapan retur.';

        $response = $this->actingAs($this->privilegedUser)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('transfers.movements.return.reject', [
                'transfer' => $this->transfer->id,
                'movement' => $pending->id,
            ]), [
                'reason' => $rejectionReason,
            ]);

        $response->assertRedirect(route('transfers.show', $this->transfer->id));

        $pending->refresh();
        $this->assertSame(TransferMovement::STATUS_REJECTED, $pending->status);
        $this->assertSame(mb_strtoupper($rejectionReason, 'UTF-8'), $pending->rejection_reason);
        $this->assertSame($this->privilegedUser->id, $pending->reviewed_by);
        $this->assertNotNull($pending->reviewed_at);

        $this->assertDatabaseHas('transfer_movement_histories', [
            'transfer_movement_id' => $pending->id,
            'action'               => TransferMovementHistory::ACTION_REJECTED,
            'reason'               => mb_strtoupper($rejectionReason, 'UTF-8'),
            'actor_id'             => $this->privilegedUser->id,
        ]);
    }
}

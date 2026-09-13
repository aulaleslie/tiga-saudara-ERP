<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Services\ForwardReceiptPreparationService;
use Modules\Adjustment\Services\ForwardReceiptProjectionService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ForwardReceiptAuthorizationAndProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'web';

    protected User $blindUser;
    protected User $privilegedUser;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Location $unrelatedLocation;
    protected Product $product;
    protected Transfer $transfer;
    protected TransferMovement $dispatchMovement;
    protected ForwardReceiptPreparationService $preparationService;
    protected ForwardReceiptProjectionService $projectionService;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('stock_transfers.v2_dispatch_enabled', true);

        $permissions = [
            'stockTransfers.access',
            'stockTransfers.receive.create',
            'stockTransfers.receive.approval',
            TransferStockVisibility::PERMISSION,
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => self::GUARD]);
        }

        $this->setting = Setting::factory()->create(['is_pkp' => false]);

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Location',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Location',
        ]);

        $this->unrelatedLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Unrelated Location',
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->product = Product::create([
            'product_name'           => 'Secret Inventory Item',
            'product_code'           => 'SEC-001',
            'barcode'                => 'BAR-SEC-001',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 100,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->product->id,
            'location_id'             => $this->origin->id,
            'quantity'                => 100,
            'quantity_non_tax'        => 100,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $this->blindUser = User::factory()->create();
        $this->blindUser->givePermissionTo(['stockTransfers.access', 'stockTransfers.receive.create', 'stockTransfers.receive.approval']);

        $this->privilegedUser = User::factory()->create();
        $this->privilegedUser->givePermissionTo(['stockTransfers.access', 'stockTransfers.receive.create', 'stockTransfers.receive.approval', TransferStockVisibility::PERMISSION]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->privilegedUser->id,
        ]);

        $this->dispatchMovement = TransferMovement::create([
            'transfer_id'              => $this->transfer->id,
            'type'                     => TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                 => 1,
            'lock_version'             => 1,
            'transfer_revision'        => 1,
            'status'                   => TransferMovement::STATUS_APPROVED,
            'origin_location_id'       => $this->origin->id,
            'destination_location_id'  => $this->destination->id,
            'stock_condition'          => TransferMovement::CONDITION_GOOD,
            'created_by'               => $this->privilegedUser->id,
            'reviewed_by'              => $this->privilegedUser->id,
            'reviewed_at'              => now(),
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->product->id,
            'stock_condition'      => 'good',
            'quantity'             => 50,
        ]);

        $this->preparationService = app(ForwardReceiptPreparationService::class);
        $this->projectionService = app(ForwardReceiptProjectionService::class);
    }

    public function test_preparation_projection_is_universally_blind_and_never_leaks_dispatch_manifest(): void
    {
        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->blindUser->id);

        // Test with blind user
        $blindProjection = $this->projectionService->getPreparationProjection($this->transfer, $movement, false);
        $this->assertArrayNotHasKey('dispatched_manifest', $blindProjection);
        $this->assertArrayNotHasKey('expected_quantity', $blindProjection);
        $this->assertArrayNotHasKey('applied_tax_quantity', $blindProjection);
        $this->assertCount(0, $blindProjection['lines']);

        // Test with privileged user (even admin/stock viewer gets universally blind draft!)
        $privProjection = $this->projectionService->getPreparationProjection($this->transfer, $movement, true);
        $this->assertArrayNotHasKey('dispatched_manifest', $privProjection);
        $this->assertArrayNotHasKey('expected_quantity', $privProjection);
        $this->assertArrayNotHasKey('applied_tax_quantity', $privProjection);
        $this->assertCount(0, $privProjection['lines']);
    }

    public function test_approval_projection_is_permission_aware(): void
    {
        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->blindUser->id);
        $this->preparationService->applyScan($movement, 'BAR-SEC-001', $this->setting->id, $movement->lock_version, $this->blindUser->id);
        $movement = $this->preparationService->submit($movement->fresh(), $movement->lock_version + 1, $this->blindUser->id);

        // Blind user review projection
        $blindReview = $this->projectionService->getApprovalProjection($this->transfer, $movement, false);
        $this->assertArrayNotHasKey('details', $blindReview);
        $this->assertArrayHasKey('matches', $blindReview);

        // Privileged user review projection
        $privReview = $this->projectionService->getApprovalProjection($this->transfer, $movement, true);
        $this->assertArrayHasKey('details', $privReview);
        $this->assertArrayHasKey($this->product->id, $privReview['details']);
        $this->assertEquals('50.0000', $privReview['details'][$this->product->id]['expected_quantity']);
    }

    public function test_cannot_access_or_prepare_receipt_for_unrelated_setting_session(): void
    {
        $otherSetting = Setting::factory()->create(['is_pkp' => false]);
        $unrelatedUser = User::factory()->create();
        $unrelatedUser->givePermissionTo(['stockTransfers.access', 'stockTransfers.receive.create', 'stockTransfers.receive.approval']);

        $response = $this->actingAs($unrelatedUser)
            ->withSession(['setting_id' => $otherSetting->id])
            ->get(route('transfers.movements.receipt.prepare', $this->transfer));
            
        $response->assertStatus(403);
    }
}

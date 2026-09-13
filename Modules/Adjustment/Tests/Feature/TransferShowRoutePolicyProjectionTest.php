<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferShowRoutePolicyProjectionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    private const GUARD = 'web';

    protected User $privilegedUser;
    protected User $blindUser;
    protected Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['stockTransfers.show', 'stockTransfers.view-system-stock'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => self::GUARD]);
        }

        $this->privilegedUser = User::factory()->create();
        $this->privilegedUser->givePermissionTo(['stockTransfers.show', 'stockTransfers.view-system-stock']);

        $this->blindUser = User::factory()->create();
        $this->blindUser->givePermissionTo(['stockTransfers.show']);

        $originSetting = Setting::factory()->create(['is_pkp' => true]);
        $destSetting = Setting::factory()->create(['is_pkp' => false]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $product = Product::create([
            'product_name'           => 'Projection Product',
            'product_code'           => 'PRJ-001',
            'setting_id'             => $originSetting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 5,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $origin->id,
            'destination_location_id' => $destination->id,
            'status'                  => Transfer::STATUS_AWAITING_RETURN,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->privilegedUser->id,
        ]);

        $policy = $this->createRoutePolicySnapshot(
            $this->transfer,
            $origin,
            $destination,
            $this->privilegedUser,
            1,
            TransferRoutePolicy::CLASSIFICATION_NON_TAX,
            true
        );

        $receiptMovement = TransferMovement::create([
            'transfer_id'              => $this->transfer->id,
            'type'                     => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                 => 1,
            'lock_version'             => 1,
            'transfer_revision'        => 1,
            'status'                   => TransferMovement::STATUS_APPROVED,
            'origin_location_id'       => $origin->id,
            'destination_location_id'  => $destination->id,
            'stock_condition'          => TransferMovement::CONDITION_GOOD,
            'created_by'               => $this->privilegedUser->id,
        ]);

        TransferMovementReturnObligation::create([
            'transfer_id'              => $this->transfer->id,
            'transfer_route_policy_id' => $policy->id,
            'receipt_movement_id'      => $receiptMovement->id,
            'product_id'               => $product->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => 5,
        ]);
    }

    public function test_privileged_viewer_sees_route_policy_and_obligation_detail(): void
    {
        $response = $this->actingAs($this->privilegedUser)->get(route('transfers.show', $this->transfer));

        $response->assertOk();
        $response->assertSee('Kebijakan Rute');
        $response->assertSee('NON_TAX');
        $response->assertSee('OUTSTANDING');
    }

    public function test_blind_viewer_never_sees_route_policy_or_obligation_detail(): void
    {
        $response = $this->actingAs($this->blindUser)->get(route('transfers.show', $this->transfer));

        $response->assertOk();
        $response->assertDontSee('Kebijakan Rute');
        $response->assertDontSee('OUTSTANDING');
    }
}

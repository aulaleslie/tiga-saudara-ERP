<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;

class ForwardDispatchActivationAndCustodyConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    private const GUARD = 'web';

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.dispatch.create',
            'stockTransfers.dispatch.approval',
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => self::GUARD]);
        }

        $this->setting = Setting::factory()->create();

        $this->user = User::factory()->create();
        $this->user->givePermissionTo($permissions);

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Location',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Location',
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->product = Product::create([
            'product_name'           => 'High Value Asset',
            'product_code'           => 'HVA-001',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 1,
            'product_cost'           => 10000000,
            'product_price'          => 15000000,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);
    }

    /** @test */
    public function v2_routes_are_rejected_with_404_when_activation_is_disabled(): void
    {
        config(['stock_transfers.v2_dispatch_enabled' => false]);

        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->user);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('transfers.movements.prepare', $transfer->id));

        $response->assertStatus(404);
    }

    /** @test */
    public function v1_transfers_remain_compatible_and_are_rejected_from_v2_movement_routes(): void
    {
        config(['stock_transfers.v2_dispatch_enabled' => true]);

        // Legacy V1 transfer
        $transferV1 = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('transfers.movements.prepare', $transferV1->id));

        $response->assertStatus(400);
    }

    /** @test */
    public function cross_transfer_movement_mismatch_is_rejected_with_404(): void
    {
        config(['stock_transfers.v2_dispatch_enabled' => true]);

        $transferA = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transferA, $this->origin, $this->destination, $this->user);

        $transferB = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transferB, $this->origin, $this->destination, $this->user);

        $prepService = app(\Modules\Adjustment\Services\ForwardDispatchPreparationService::class);
        $movementA = $prepService->getOrCreateDraft($transferA, $this->user->id);
        $movementB = $prepService->getOrCreateDraft($transferB, $this->user->id);

        // Caller pairs Transfer A with Movement B
        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('transfers.movements.scan', [
                'transfer' => $transferA->id,
                'movement' => $movementB->id,
            ]), [
                'query' => 'HVA-001',
            ]);

        $response->assertStatus(404);
    }
}

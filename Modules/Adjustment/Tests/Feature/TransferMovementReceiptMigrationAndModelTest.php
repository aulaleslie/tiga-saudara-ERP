<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;

class TransferMovementReceiptMigrationAndModelTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Location',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Location',
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $this->createRoutePolicySnapshot($this->transfer, $this->origin, $this->destination, $this->user);
    }

    public function test_migration_adds_empty_count_confirmation_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('transfer_movements', [
            'empty_count_confirmed',
            'empty_count_confirmed_by',
            'empty_count_confirmed_at',
        ]));
    }

    public function test_transfer_movement_model_casts_and_relationships_for_empty_count(): void
    {
        $movement = TransferMovement::create([
            'transfer_id'              => $this->transfer->id,
            'type'                     => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                 => 1,
            'lock_version'             => 1,
            'transfer_revision'        => 1,
            'status'                   => TransferMovement::STATUS_DRAFT,
            'origin_location_id'       => $this->origin->id,
            'destination_location_id'  => $this->destination->id,
            'stock_condition'          => TransferMovement::CONDITION_GOOD,
            'created_by'               => $this->user->id,
            'empty_count_confirmed'    => true,
            'empty_count_confirmed_by' => $this->user->id,
            'empty_count_confirmed_at' => now(),
        ]);

        $movement->refresh();

        $this->assertTrue($movement->empty_count_confirmed);
        $this->assertNotNull($movement->empty_count_confirmed_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $movement->empty_count_confirmed_at);
        $this->assertEquals($this->user->id, $movement->empty_count_confirmed_by);
        $this->assertNotNull($movement->emptyCountConfirmedBy);
        $this->assertEquals($this->user->id, $movement->emptyCountConfirmedBy->id);
    }

    public function test_default_empty_count_confirmed_is_false(): void
    {
        $movement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_DRAFT,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $movement->refresh();

        $this->assertFalse($movement->empty_count_confirmed);
        $this->assertNull($movement->empty_count_confirmed_by);
        $this->assertNull($movement->empty_count_confirmed_at);
    }
}

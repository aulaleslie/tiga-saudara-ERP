<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferMovementDispatchFieldsAndClaimTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;

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

        $this->product = Product::create([
            'product_name'           => 'Serialized Test Product',
            'product_code'           => 'STP-001',
            'setting_id'             => $this->setting->id,
            'product_quantity'       => 10,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);
    }

    /** @test */
    public function movement_line_has_count_confirmed_and_applied_bucket_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('transfer_movement_lines', [
            'count_confirmed',
            'applied_quantity_non_tax',
            'applied_quantity_tax',
            'applied_quantity_broken_non_tax',
            'applied_quantity_broken_tax',
            'stock_snapshot_before',
            'stock_snapshot_after',
            'inventory_transaction_reference',
        ]));

        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $movement = TransferMovement::create([
            'transfer_id'             => $transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_DRAFT,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'created_by'              => $this->user->id,
        ]);

        $line = TransferMovementLine::create([
            'transfer_movement_id'            => $movement->id,
            'product_id'                      => $this->product->id,
            'quantity'                        => 0.0000,
            'count_confirmed'                 => true,
            'applied_quantity_non_tax'        => 3.0000,
            'applied_quantity_tax'            => 2.0000,
            'applied_quantity_broken_non_tax' => 0.0000,
            'applied_quantity_broken_tax'     => 0.0000,
            'stock_snapshot_before'           => ['quantity' => 10],
            'stock_snapshot_after'            => ['quantity' => 5],
            'inventory_transaction_reference' => 'TRX-12345',
        ]);

        $fresh = $line->fresh();
        $this->assertTrue($fresh->count_confirmed);
        $this->assertEquals('0.0000', (string) $fresh->quantity);
        $this->assertEquals('3.0000', (string) $fresh->applied_quantity_non_tax);
        $this->assertEquals('2.0000', (string) $fresh->applied_quantity_tax);
        $this->assertEquals(['quantity' => 10], $fresh->stock_snapshot_before);
        $this->assertEquals(['quantity' => 5], $fresh->stock_snapshot_after);
        $this->assertEquals('TRX-12345', $fresh->inventory_transaction_reference);
    }

    /** @test */
    public function transfer_active_serial_claim_enforces_single_active_claim_per_serial(): void
    {
        $this->assertTrue(Schema::hasTable('transfer_active_serial_claims'));

        $psn = ProductSerialNumber::create([
            'product_id'    => $this->product->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'SN-CUSTODY-001',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
        ]);

        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $movement = TransferMovement::create([
            'transfer_id'             => $transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_APPROVED,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'created_by'              => $this->user->id,
        ]);

        $line = TransferMovementLine::create([
            'transfer_movement_id' => $movement->id,
            'product_id'           => $this->product->id,
            'quantity'             => 1.0000,
            'count_confirmed'      => true,
        ]);

        $movSerial = TransferMovementSerial::create([
            'transfer_movement_id'      => $movement->id,
            'transfer_movement_line_id' => $line->id,
            'product_id'                => $this->product->id,
            'product_serial_number_id'  => $psn->id,
            'serial_number'             => 'SN-CUSTODY-001',
            'stock_condition'           => Transfer::CONDITION_GOOD,
            'transit_custody_status'    => TransferMovementSerial::CUSTODY_IN_TRANSIT,
        ]);

        TransferActiveSerialClaim::create([
            'product_serial_number_id'   => $psn->id,
            'transfer_movement_id'       => $movement->id,
            'transfer_movement_serial_id' => $movSerial->id,
        ]);

        // Second claim on the same live serial must fail
        $this->expectException(QueryException::class);

        TransferActiveSerialClaim::create([
            'product_serial_number_id'   => $psn->id,
            'transfer_movement_id'       => $movement->id,
            'transfer_movement_serial_id' => $movSerial->id,
        ]);
    }
}

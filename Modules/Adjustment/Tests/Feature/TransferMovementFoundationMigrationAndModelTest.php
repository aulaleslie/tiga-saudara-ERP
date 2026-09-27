<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementHistory;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferMovementFoundationMigrationAndModelTest extends TestCase
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
            'name'       => 'Origin Warehouse',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Warehouse',
        ]);

        $this->product = Product::create([
            'product_name'           => 'Test Product',
            'product_code'           => 'TP-001',
            'setting_id'             => $this->setting->id,
            'product_quantity'       => 10,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);
    }

    /** @test */
    public function transfers_table_has_workflow_version_defaulting_to_1(): void
    {
        $this->assertTrue(Schema::hasColumn('transfers', 'workflow_version'));

        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_DRAFT,
            'revision'                => 1,
        ]);

        $this->assertSame(1, (int) $transfer->fresh()->workflow_version);
    }

    /** @test */
    public function movement_foundation_tables_exist_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('transfer_movements'));
        $this->assertTrue(Schema::hasTable('transfer_movement_lines'));
        $this->assertTrue(Schema::hasTable('transfer_movement_serials'));
        $this->assertTrue(Schema::hasTable('transfer_movement_histories'));

        $this->assertTrue(Schema::hasColumns('transfer_movements', [
            'id', 'transfer_id', 'type', 'revision', 'transfer_revision',
            'status', 'stock_condition', 'origin_location_id', 'destination_location_id',
            'source_movement_id', 'supersedes_movement_id', 'created_by',
        ]));

        $this->assertTrue(Schema::hasColumns('transfer_movement_lines', [
            'id', 'transfer_movement_id', 'product_id', 'quantity',
        ]));

        $this->assertTrue(Schema::hasColumns('transfer_movement_serials', [
            'id', 'transfer_movement_id', 'transfer_movement_line_id', 'product_id',
            'serial_number', 'stock_condition', 'transit_custody_status',
        ]));

        $this->assertTrue(Schema::hasColumns('transfer_movement_histories', [
            'id', 'transfer_movement_id', 'revision', 'action', 'to_status', 'actor_id',
        ]));
    }

    /** @test */
    public function transfer_movement_enforces_unique_revision_per_type_in_database(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
        ]);

        TransferMovement::create([
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

        $this->expectException(QueryException::class);

        // Attempting to insert duplicate (transfer_id, type, revision) must fail
        TransferMovement::create([
            'transfer_id'             => $transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'created_by'              => $this->user->id,
        ]);
    }

    /** @test */
    public function movement_line_enforces_unique_product_per_movement_in_database(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
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

        TransferMovementLine::create([
            'transfer_movement_id' => $movement->id,
            'product_id'           => $this->product->id,
            'quantity'             => 5.5,
        ]);

        $this->expectException(QueryException::class);

        // Duplicate line for same product on same movement must fail
        TransferMovementLine::create([
            'transfer_movement_id' => $movement->id,
            'product_id'           => $this->product->id,
            'quantity'             => 2.0,
        ]);
    }

    /** @test */
    public function movement_serial_enforces_unique_serial_per_movement_in_database(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
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
            'transfer_movement_id' => $movement->id,
            'product_id'           => $this->product->id,
            'quantity'             => 2.0,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $movement->id,
            'transfer_movement_line_id' => $line->id,
            'product_id'                => $this->product->id,
            'serial_number'             => 'SN-ABC-01',
            'stock_condition'           => Transfer::CONDITION_GOOD,
        ]);

        $this->expectException(QueryException::class);

        // Duplicate serial on same movement must fail
        TransferMovementSerial::create([
            'transfer_movement_id'      => $movement->id,
            'transfer_movement_line_id' => $line->id,
            'product_id'                => $this->product->id,
            'serial_number'             => 'SN-ABC-01',
            'stock_condition'           => Transfer::CONDITION_GOOD,
        ]);
    }

    /** @test */
    public function relationships_and_decimal_precision_load_correctly(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
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
            'transfer_movement_id' => $movement->id,
            'product_id'           => $this->product->id,
            'quantity'             => 12.3456,
        ]);

        $tax = \Modules\Setting\Entities\Tax::create([
            'name'      => 'PPN 11%',
            'value'     => 11.0,
            'is_active' => 1,
        ]);

        $serial = TransferMovementSerial::create([
            'transfer_movement_id'      => $movement->id,
            'transfer_movement_line_id' => $line->id,
            'product_id'                => $this->product->id,
            'serial_number'             => 'sn-lowercase-001',
            'stock_condition'           => Transfer::CONDITION_GOOD,
            'tax_id'                    => $tax->id,
            'tax_name'                  => $tax->name,
            'tax_rate'                  => $tax->value,
        ]);

        // Modify or delete tax record
        $tax->update(['name' => 'PPN 12%', 'value' => 12.0]);

        $history = TransferMovementHistory::create([
            'transfer_movement_id' => $movement->id,
            'revision'             => 1,
            'action'               => TransferMovementHistory::ACTION_CREATED,
            'to_status'            => TransferMovement::STATUS_DRAFT,
            'actor_id'             => $this->user->id,
        ]);

        $loaded = Transfer::with(['movements.lines.serials', 'movements.histories'])->find($transfer->id);

        $this->assertCount(1, $loaded->movements);
        $mvmt = $loaded->movements->first();
        $this->assertCount(1, $mvmt->lines);
        $this->assertCount(1, $mvmt->serials);
        $this->assertCount(1, $mvmt->histories);

        $this->assertEquals('SN-LOWERCASE-001', $serial->fresh()->serial_number);
        $this->assertEquals('12.3456', (string) $line->fresh()->quantity);
        $this->assertEquals('PPN 11%', $serial->fresh()->tax_name);
        $this->assertEquals('11.0000', (string) $serial->fresh()->tax_rate);
    }
}

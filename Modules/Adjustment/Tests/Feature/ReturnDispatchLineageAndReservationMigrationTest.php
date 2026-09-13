<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferReturnObligationReservation;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ReturnDispatchLineageAndReservationMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Transfer $transfer;
    protected Product $product;
    protected TransferRoutePolicy $policy;
    protected TransferMovement $receiptMovement;
    protected TransferMovementReturnObligation $obligation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create(['is_pkp' => false]);

        $this->origin = Location::create(['setting_id' => $this->setting->id, 'name' => 'Origin']);
        $this->destination = Location::create(['setting_id' => $this->setting->id, 'name' => 'Destination']);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_AWAITING_RETURN,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $this->product = Product::create([
            'product_name'           => 'Returnable Product',
            'product_code'           => 'RET-001',
            'setting_id'             => $this->setting->id,
            'product_quantity'       => 0,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        $this->policy = TransferRoutePolicy::create([
            'transfer_id'                => $this->transfer->id,
            'transfer_revision'          => 1,
            'origin_location_id'         => $this->origin->id,
            'destination_location_id'    => $this->destination->id,
            'origin_setting_id'          => $this->setting->id,
            'destination_setting_id'     => $this->setting->id,
            'origin_is_pkp'              => false,
            'destination_is_pkp'         => false,
            'same_business'              => true,
            'stock_condition'            => Transfer::CONDITION_GOOD,
            'destination_classification' => TransferRoutePolicy::CLASSIFICATION_PRESERVE,
            'mandatory_return'           => true,
            'approved_by'                => $this->user->id,
            'approved_at'                => now(),
        ]);

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
            'created_by'              => $this->user->id,
        ]);

        $this->obligation = TransferMovementReturnObligation::create([
            'transfer_id'              => $this->transfer->id,
            'transfer_route_policy_id' => $this->policy->id,
            'receipt_movement_id'      => $this->receiptMovement->id,
            'product_id'               => $this->product->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => '10.0000',
        ]);
    }

    public function test_transfer_movements_table_has_return_batch_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('transfer_movements', 'return_batch_id'));
    }

    public function test_reservations_table_exists_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('transfer_return_obligation_reservations'));

        foreach ([
            'transfer_movement_return_obligation_id', 'transfer_movement_id', 'transfer_movement_line_id',
            'quantity', 'status', 'created_by', 'closed_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('transfer_return_obligation_reservations', $column), "Missing column {$column}");
        }
    }

    public function test_obligation_quantities_support_exact_decimal_precision(): void
    {
        $this->obligation->update(['required_quantity' => '10.5000']);
        $this->assertSame('10.5000', (string) $this->obligation->refresh()->required_quantity);
    }

    public function test_multiple_independent_return_dispatch_lineages_can_coexist(): void
    {
        $batchOne = (string) Str::uuid();
        $batchTwo = (string) Str::uuid();

        $movementOne = $this->createReturnDispatchDraft($batchOne, 1);
        $movementTwo = $this->createReturnDispatchDraft($batchTwo, 1);

        $this->assertNotEquals($movementOne->id, $movementTwo->id);
        $this->assertSame(1, $movementOne->revision);
        $this->assertSame(1, $movementTwo->revision);
    }

    public function test_correction_revision_increments_within_same_lineage(): void
    {
        $batchId = (string) Str::uuid();

        $rejected = $this->createReturnDispatchDraft($batchId, 1);
        $rejected->update(['status' => TransferMovement::STATUS_REJECTED, 'rejection_reason' => 'test']);

        $correction = $this->createReturnDispatchDraft($batchId, 2, $rejected->id);

        $this->assertSame(strtolower($batchId), strtolower($correction->return_batch_id));
        $this->assertSame(2, $correction->revision);
        $this->assertSame($rejected->id, $correction->supersedes_movement_id);
    }

    public function test_duplicate_revision_within_same_lineage_is_rejected_by_unique_constraint(): void
    {
        $batchId = (string) Str::uuid();
        $this->createReturnDispatchDraft($batchId, 1);

        $this->expectException(QueryException::class);
        $this->createReturnDispatchDraft($batchId, 1);
    }

    public function test_duplicate_forward_dispatch_revision_is_still_rejected_by_database_constraint(): void
    {
        // Regression: the (transfer_id, type, return_batch_id, revision) unique index must reject
        // duplicate revisions for non-batch types exactly like the original
        // (transfer_id, type, revision) constraint did, since every non-RETURN_DISPATCH row now
        // shares the same non-null SINGLETON_LINEAGE discriminator rather than a nullable column.
        TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_DRAFT,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'created_by'              => $this->user->id,
        ]);

        $this->expectException(QueryException::class);

        TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_DRAFT,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'created_by'              => $this->user->id,
        ]);
    }

    public function test_non_return_movements_default_to_the_shared_singleton_lineage(): void
    {
        $movement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 2,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_APPROVED,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'created_by'              => $this->user->id,
        ]);

        $this->assertSame(TransferMovement::SINGLETON_LINEAGE, $movement->return_batch_id);
    }

    public function test_reservation_identity_is_unique_per_movement_line(): void
    {
        $batchId = (string) Str::uuid();
        $movement = $this->createReturnDispatchDraft($batchId, 1);
        $line = TransferMovementLine::create([
            'transfer_movement_id' => $movement->id,
            'product_id'           => $this->product->id,
            'quantity'             => '4.0000',
            'count_confirmed'      => true,
        ]);

        TransferReturnObligationReservation::create([
            'transfer_movement_return_obligation_id' => $this->obligation->id,
            'transfer_movement_id'                    => $movement->id,
            'transfer_movement_line_id'                => $line->id,
            'quantity'                                 => '4.0000',
            'status'                                   => TransferReturnObligationReservation::STATUS_ACTIVE,
            'created_by'                                => $this->user->id,
        ]);

        $this->expectException(QueryException::class);
        TransferReturnObligationReservation::create([
            'transfer_movement_return_obligation_id' => $this->obligation->id,
            'transfer_movement_id'                    => $movement->id,
            'transfer_movement_line_id'                => $line->id,
            'quantity'                                 => '1.0000',
            'status'                                   => TransferReturnObligationReservation::STATUS_ACTIVE,
            'created_by'                                => $this->user->id,
        ]);
    }

    public function test_obligation_capacity_helpers_reflect_active_reservations(): void
    {
        $batchId = (string) Str::uuid();
        $movement = $this->createReturnDispatchDraft($batchId, 1);
        $line = TransferMovementLine::create([
            'transfer_movement_id' => $movement->id,
            'product_id'           => $this->product->id,
            'quantity'             => '4.0000',
            'count_confirmed'      => true,
        ]);

        TransferReturnObligationReservation::create([
            'transfer_movement_return_obligation_id' => $this->obligation->id,
            'transfer_movement_id'                    => $movement->id,
            'transfer_movement_line_id'                => $line->id,
            'quantity'                                 => '4.0000',
            'status'                                   => TransferReturnObligationReservation::STATUS_ACTIVE,
            'created_by'                                => $this->user->id,
        ]);

        $this->obligation->load('activeReservations');

        $this->assertSame('4.0000', $this->obligation->activeInTransitQuantity());
        $this->assertSame('6.0000', $this->obligation->availableCapacity());
        $this->assertSame('10.0000', $this->obligation->outstandingQuantity());
    }

    public function test_no_historical_backfill_of_reservations_for_legacy_transfers(): void
    {
        $legacyTransfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_COMPLETED,
            'revision'                => 1,
            'workflow_version'        => 1,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $this->assertFalse(
            TransferReturnObligationReservation::whereHas('movement', function ($q) use ($legacyTransfer) {
                $q->where('transfer_id', $legacyTransfer->id);
            })->exists()
        );
    }

    private function createReturnDispatchDraft(string $batchId, int $revision, ?int $supersedesId = null): TransferMovement
    {
        return TransferMovement::create([
            'transfer_id'              => $this->transfer->id,
            'type'                     => TransferMovement::TYPE_RETURN_DISPATCH,
            'revision'                 => $revision,
            'lock_version'             => 1,
            'transfer_revision'        => 1,
            'status'                   => TransferMovement::STATUS_DRAFT,
            'stock_condition'          => TransferMovement::CONDITION_GOOD,
            'origin_location_id'       => $this->destination->id,
            'destination_location_id'  => $this->origin->id,
            'source_movement_id'       => $this->receiptMovement->id,
            'supersedes_movement_id'   => $supersedesId,
            'return_batch_id'          => $batchId,
            'created_by'               => $this->user->id,
        ]);
    }
}

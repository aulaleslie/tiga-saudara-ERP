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
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class ReturnReceiptLineageAndTaxSnapshotMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Transfer $transfer;
    protected Product $product;
    protected TransferRoutePolicy $policy;
    protected TransferMovement $forwardReceipt;
    protected TransferMovement $returnDispatch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create(['is_pkp' => true]);

        $this->origin = Location::create(['setting_id' => $this->setting->id, 'name' => 'Origin']);
        $this->destination = Location::create(['setting_id' => $this->setting->id, 'name' => 'Destination']);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_RETURN_DISPATCHED,
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
            'origin_is_pkp'              => true,
            'destination_is_pkp'         => true,
            'same_business'              => true,
            'stock_condition'            => Transfer::CONDITION_GOOD,
            'destination_classification' => TransferRoutePolicy::CLASSIFICATION_PRESERVE,
            'mandatory_return'           => true,
            'approved_by'                => $this->user->id,
            'approved_at'                => now(),
        ]);

        $this->forwardReceipt = TransferMovement::create([
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

        $this->returnDispatch = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_DISPATCH,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_APPROVED,
            'origin_location_id'      => $this->destination->id,
            'destination_location_id' => $this->origin->id,
            'source_movement_id'      => $this->forwardReceipt->id,
            'return_batch_id'         => (string) Str::uuid(),
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);
    }

    public function test_transfer_movements_table_has_tax_snapshot_columns(): void
    {
        foreach ([
            'tax_setting_id',
            'tax_id',
            'tax_name',
            'tax_rate',
            'tax_resolver_provenance',
            'tax_resolved_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('transfer_movements', $column), "Missing column {$column}");
        }
    }

    public function test_tax_snapshot_persists_and_casts_properly_on_return_receipt(): void
    {
        $tax = Tax::create([
            'name'       => 'PPN 11%',
            'value'      => 11.0000,
            'is_default' => true,
        ]);

        $resolvedTime = now();

        $receipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_APPROVED,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->returnDispatch->id,
            'return_batch_id'         => $this->returnDispatch->return_batch_id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'tax_setting_id'          => $this->setting->id,
            'tax_id'                  => $tax->id,
            'tax_name'                => $tax->name,
            'tax_rate'                => '11.0000',
            'tax_resolver_provenance' => 'DEFAULT',
            'tax_resolved_at'         => $resolvedTime,
            'created_by'              => $this->user->id,
        ]);

        $receipt->refresh();

        $this->assertSame($this->setting->id, $receipt->tax_setting_id);
        $this->assertSame($tax->id, $receipt->tax_id);
        $this->assertSame('PPN 11%', $receipt->tax_name);
        $this->assertSame('11.0000', (string) $receipt->tax_rate);
        $this->assertSame('DEFAULT', $receipt->tax_resolver_provenance);
        $this->assertNotNull($receipt->tax_resolved_at);
        $this->assertSame($tax->id, $receipt->tax->id);
        $this->assertSame($this->setting->id, $receipt->taxSetting->id);
    }

    public function test_mysql_index_and_foreign_key_names_are_under_64_characters(): void
    {
        $identifiers = [
            'fk_tm_tax_setting_id',
            'fk_tm_tax_id',
            'idx_tm_type_batch_status',
            'transfer_movements_batch_revision_unique',
            'transfer_movements_return_batch_id_index',
        ];

        foreach ($identifiers as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), "Identifier {$identifier} exceeds 64 chars");
        }
    }

    public function test_multiple_receipt_revisions_in_same_return_batch_lineage(): void
    {
        $batchId = $this->returnDispatch->return_batch_id;

        $receiptRev1 = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_REJECTED,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->returnDispatch->id,
            'return_batch_id'         => $batchId,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'rejection_reason'        => 'Wrong count',
            'created_by'              => $this->user->id,
        ]);

        $receiptRev2 = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 2,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_DRAFT,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->returnDispatch->id,
            'supersedes_movement_id'  => $receiptRev1->id,
            'return_batch_id'         => $batchId,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $this->assertSame(1, $receiptRev1->revision);
        $this->assertSame(2, $receiptRev2->revision);
        $this->assertSame($batchId, $receiptRev2->return_batch_id);
        $this->assertSame($receiptRev1->id, $receiptRev2->supersedes_movement_id);
    }

    public function test_duplicate_receipt_revision_in_same_batch_lineage_is_rejected_by_db_constraint(): void
    {
        $batchId = $this->returnDispatch->return_batch_id;

        TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_DRAFT,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->returnDispatch->id,
            'return_batch_id'         => $batchId,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $this->expectException(QueryException::class);

        TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_DRAFT,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->returnDispatch->id,
            'return_batch_id'         => $batchId,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Services\ForwardReceiptApprovalExecutor;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use RuntimeException;
use Tests\TestCase;

class ForwardReceiptApprovalAndInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $productGood;
    protected Transfer $transfer;
    protected TransferMovement $dispatchMovement;
    protected ForwardReceiptApprovalExecutor $approvalExecutor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create(['is_pkp' => false]);

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Location',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Location',
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->productGood = Product::create([
            'product_name'           => 'Good Product',
            'product_code'           => 'GP-001',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 10,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        // Destination starts at 0 stock
        ProductStock::create([
            'product_id'              => $this->productGood->id,
            'location_id'             => $this->destination->id,
            'quantity'                => 0,
            'quantity_non_tax'        => 0,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
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
            'created_by'               => $this->user->id,
            'reviewed_by'              => $this->user->id,
            'reviewed_at'              => now(),
        ]);

        $trx = \Modules\Product\Entities\Transaction::create([
            'product_id'                    => $this->productGood->id,
            'setting_id'                    => $this->setting->id,
            'type'                          => 'TRF',
            'quantity'                      => -10,
            'current_quantity'              => 0,
            'broken_quantity'               => 0,
            'previous_quantity'             => 10,
            'previous_quantity_at_location' => 10,
            'after_quantity'                => 0,
            'after_quantity_at_location'    => 0,
            'quantity_tax'                  => 0,
            'quantity_non_tax'              => 0,
            'broken_quantity_tax'           => 0,
            'broken_quantity_non_tax'       => 0,
            'location_id'                   => $this->origin->id,
            'user_id'                       => $this->user->id,
            'reason'                        => 'Dispatch test transaction',
        ]);

        TransferMovementLine::create([
            'transfer_movement_id'            => $this->dispatchMovement->id,
            'product_id'                      => $this->productGood->id,
            'quantity'                        => 10,
            'applied_quantity_tax'            => 4,
            'applied_quantity_non_tax'        => 6,
            'applied_quantity_broken_tax'     => 0,
            'applied_quantity_broken_non_tax' => 0,
            'inventory_transaction_reference' => (string) $trx->id,
        ]);

        $this->approvalExecutor = app(ForwardReceiptApprovalExecutor::class);
    }

    public function test_approve_exact_receipt_applies_destination_stock_with_dispatched_provenance_and_completes_transfer(): void
    {
        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productGood->id,
            'quantity'             => 10,
        ]);

        $approvedMovement = $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approvedMovement->status);

        // Verify destination stock increment
        $destStock = ProductStock::where('product_id', $this->productGood->id)
            ->where('location_id', $this->destination->id)
            ->first();

        $this->assertEquals(10, $destStock->quantity);
        $this->assertEquals(4, $destStock->quantity_tax);
        $this->assertEquals(6, $destStock->quantity_non_tax);

        // Verify global product stock increment
        $this->productGood->refresh();
        $this->assertEquals(20, $this->productGood->product_quantity); // 10 original + 10 received

        // Verify transfer header transitioned to COMPLETED
        $this->transfer->refresh();
        $this->assertEquals(Transfer::STATUS_COMPLETED, $this->transfer->status);
    }

    public function test_idempotent_replay_returns_same_approved_movement(): void
    {
        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productGood->id,
            'quantity'             => 10,
        ]);

        $first = $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id, 'IDEM-REC-001');
        $second = $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id, 'IDEM-REC-001');

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(TransferMovement::STATUS_APPROVED, $second->status);

        // Stock not doubled
        $destStock = ProductStock::where('product_id', $this->productGood->id)
            ->where('location_id', $this->destination->id)
            ->first();
        $this->assertEquals(10, $destStock->quantity);
    }

    public function test_approving_receipt_with_corrupted_or_mismatched_provenance_sum_fails(): void
    {
        // Alter dispatch line provenance so that buckets sum to 8 instead of line quantity 10
        $dispatchLine = $this->dispatchMovement->lines->first();
        $dispatchLine->update([
            'applied_quantity_non_tax' => 4, // 4 + 4 = 8 != 10
        ]);

        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productGood->id,
            'quantity'             => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dispatched bucket sum does not match source dispatch line quantity');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    public function test_approving_receipt_with_condition_incompatible_provenance_fails(): void
    {
        // Transfer condition is GOOD, but dispatch line has broken quantity provenance
        $dispatchLine = $this->dispatchMovement->lines->first();
        $dispatchLine->update([
            'applied_quantity_tax'            => 0,
            'applied_quantity_non_tax'        => 0,
            'applied_quantity_broken_tax'     => 10,
            'applied_quantity_broken_non_tax' => 0,
        ]);

        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productGood->id,
            'quantity'             => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Good condition dispatch cannot contain broken stock buckets');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    public function test_approving_receipt_with_missing_or_invalid_source_transaction_reference_fails(): void
    {
        $dispatchLine = $this->dispatchMovement->lines->first();
        $dispatchLine->update([
            'inventory_transaction_reference' => '99999999', // non-existent transaction
        ]);

        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productGood->id,
            'quantity'             => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source dispatch inventory transaction [99999999]');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    public function test_approving_receipt_with_unrelated_transaction_reference_for_same_product_and_location_fails(): void
    {
        // Create an unrelated transaction for the same product and origin location (e.g. an adjustment or sale)
        $unrelatedTrx = \Modules\Product\Entities\Transaction::create([
            'product_id'                    => $this->productGood->id,
            'setting_id'                    => $this->setting->id,
            'type'                          => 'ADJ', // Unrelated type
            'quantity'                      => -10,
            'current_quantity'              => 0,
            'broken_quantity'               => 0,
            'previous_quantity'             => 10,
            'previous_quantity_at_location' => 10,
            'after_quantity'                => 0,
            'after_quantity_at_location'    => 0,
            'quantity_tax'                  => 0,
            'quantity_non_tax'              => 0,
            'broken_quantity_tax'           => 0,
            'broken_quantity_non_tax'       => 0,
            'location_id'                   => $this->origin->id,
            'user_id'                       => $this->user->id,
            'reason'                        => 'Unrelated adjustment transaction',
        ]);

        $dispatchLine = $this->dispatchMovement->lines->first();
        $dispatchLine->update([
            'inventory_transaction_reference' => (string) $unrelatedTrx->id,
        ]);

        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productGood->id,
            'quantity'             => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match expected dispatch provenance');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    public function test_approving_receipt_with_malformed_transaction_reference_fails(): void
    {
        $dispatchLine = $this->dispatchMovement->lines->first();
        $dispatchLine->update([
            'inventory_transaction_reference' => '123-invalid',
        ]);

        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productGood->id,
            'quantity'             => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lacks a valid positive integer inventory transaction reference');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }
}

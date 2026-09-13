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
use Modules\Adjustment\Services\ForwardReceiptApprovalExecutor;
use Modules\Adjustment\Services\TransferLifecycleService;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use RuntimeException;
use Tests\TestCase;

class TransferMovementReturnObligationDormancyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_awaiting_return_v2_transfer_has_no_operational_return_dispatch_route(): void
    {
        $originSetting = Setting::factory()->create(['is_pkp' => true]);
        $destSetting = Setting::factory()->create(['is_pkp' => false]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $product = Product::create([
            'product_name'           => 'Dormant Obligation Product',
            'product_code'           => 'DRM-001',
            'setting_id'             => $originSetting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 5,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $product->id,
            'location_id'             => $destination->id,
            'quantity'                => 0,
            'quantity_non_tax'        => 0,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $transfer = Transfer::create([
            'origin_location_id'      => $origin->id,
            'destination_location_id' => $destination->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $this->createRoutePolicySnapshot($transfer, $origin, $destination, $this->user, 1, TransferRoutePolicy::CLASSIFICATION_NON_TAX, true);

        $dispatchMovement = TransferMovement::create([
            'transfer_id'              => $transfer->id,
            'type'                     => TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                 => 1,
            'lock_version'             => 1,
            'transfer_revision'        => 1,
            'status'                   => TransferMovement::STATUS_APPROVED,
            'origin_location_id'       => $origin->id,
            'destination_location_id'  => $destination->id,
            'stock_condition'          => TransferMovement::CONDITION_GOOD,
            'created_by'               => $this->user->id,
            'reviewed_by'              => $this->user->id,
            'reviewed_at'              => now(),
        ]);

        $trx = Transaction::create([
            'product_id'                    => $product->id,
            'setting_id'                    => $originSetting->id,
            'type'                          => 'TRF',
            'quantity'                      => -5,
            'current_quantity'              => 0,
            'broken_quantity'               => 0,
            'previous_quantity'             => 5,
            'previous_quantity_at_location' => 5,
            'after_quantity'                => 0,
            'after_quantity_at_location'    => 0,
            'quantity_tax'                  => 0,
            'quantity_non_tax'              => 0,
            'broken_quantity_tax'           => 0,
            'broken_quantity_non_tax'       => 0,
            'location_id'                   => $origin->id,
            'user_id'                       => $this->user->id,
            'reason'                        => 'Dispatch test transaction',
        ]);

        TransferMovementLine::create([
            'transfer_movement_id'            => $dispatchMovement->id,
            'product_id'                      => $product->id,
            'quantity'                        => 5,
            'applied_quantity_tax'            => 0,
            'applied_quantity_non_tax'        => 5,
            'applied_quantity_broken_tax'     => 0,
            'applied_quantity_broken_non_tax' => 0,
            'inventory_transaction_reference' => (string) $trx->id,
        ]);

        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $origin->id,
            'destination_location_id' => $destination->id,
            'source_movement_id'      => $dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $product->id,
            'quantity'             => 5,
        ]);

        app(ForwardReceiptApprovalExecutor::class)->approve($transfer, $receiptMovement, $this->user->id);

        $transfer->refresh();
        $this->assertEquals(Transfer::STATUS_AWAITING_RETURN, $transfer->status);

        $obligation = TransferMovementReturnObligation::where('transfer_id', $transfer->id)->firstOrFail();
        $this->assertEquals(TransferMovementReturnObligation::STATUS_OUTSTANDING, $obligation->status);

        // The legacy v1 return-dispatch/receive operational surface must be
        // rejected outright for workflow version 2 transfers: no header,
        // history, stock, serial, or obligation mutation may occur.
        $lifecycleService = app(TransferLifecycleService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workflow version 2 return dispatch is not yet available.');

        try {
            $lifecycleService->dispatchReturn($transfer, $this->user->id, $destSetting->id);
        } finally {
            $transfer->refresh();
            $this->assertEquals(Transfer::STATUS_AWAITING_RETURN, $transfer->status);

            $obligation->refresh();
            $this->assertEquals(0, $obligation->returned_quantity);
            $this->assertEquals(TransferMovementReturnObligation::STATUS_OUTSTANDING, $obligation->status);
            $this->assertEquals(5, $obligation->outstandingQuantity());
        }
    }

    public function test_legacy_return_receive_is_also_rejected_for_workflow_version_two(): void
    {
        $originSetting = Setting::factory()->create(['is_pkp' => true]);
        $destSetting = Setting::factory()->create(['is_pkp' => false]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);

        // Simulate a corrupted/legacy-carried status to prove the guard is
        // defense-in-depth, not merely reachability-dependent on dispatchReturn.
        $transfer = Transfer::create([
            'origin_location_id'      => $origin->id,
            'destination_location_id' => $destination->id,
            'status'                  => Transfer::STATUS_RETURN_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $lifecycleService = app(TransferLifecycleService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workflow version 2 return receipt is not yet available.');

        try {
            $lifecycleService->receiveReturn($transfer, $this->user->id, $originSetting->id);
        } finally {
            $transfer->refresh();
            $this->assertEquals(Transfer::STATUS_RETURN_DISPATCHED, $transfer->status);
        }
    }
}

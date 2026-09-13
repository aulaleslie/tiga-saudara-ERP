<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Entities\TransferReturnObligationReservation;
use Modules\Adjustment\Services\ForwardDispatchApprovalExecutor;
use Modules\Adjustment\Services\ForwardDispatchPreparationService;
use Modules\Adjustment\Services\ForwardReceiptApprovalExecutor;
use Modules\Adjustment\Services\ForwardReceiptPreparationService;
use Modules\Adjustment\Services\ReturnDispatchApprovalExecutor;
use Modules\Adjustment\Services\ReturnDispatchPreparationService;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use RuntimeException;
use Tests\TestCase;

class ReturnDispatchPreparationAndApprovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;
    protected Product $serializedProduct;
    protected Transfer $transfer;
    protected TransferMovement $receiptMovement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();

        $this->origin = Location::create(['setting_id' => $this->setting->id, 'name' => 'Origin']);
        $this->destination = Location::create(['setting_id' => $this->setting->id, 'name' => 'Destination']);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->product = Product::create([
            'product_name'           => 'Returnable Item',
            'product_code'           => 'RI-001',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 10,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->product->id,
            'location_id'             => $this->origin->id,
            'quantity'                => 10,
            'quantity_non_tax'        => 10,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $this->serializedProduct = Product::create([
            'product_name'           => 'Returnable Gadget',
            'product_code'           => 'RG-001',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 2,
            'product_cost'           => 2000,
            'product_price'          => 3000,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->serializedProduct->id,
            'location_id'             => $this->origin->id,
            'quantity'                => 2,
            'quantity_non_tax'        => 2,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'RG-SN-001',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
        ]);
        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'RG-SN-002',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot(
            $this->transfer,
            $this->origin,
            $this->destination,
            $this->user,
            1,
            \Modules\Adjustment\Entities\TransferRoutePolicy::CLASSIFICATION_PRESERVE,
            true
        );

        TransferProduct::create(['transfer_id' => $this->transfer->id, 'product_id' => $this->product->id, 'quantity' => 5]);
        TransferProduct::create([
            'transfer_id'    => $this->transfer->id,
            'product_id'     => $this->serializedProduct->id,
            'quantity'       => 1,
            'serial_numbers' => [['serial_number' => 'RG-SN-001']],
        ]);

        // Drive the transfer through forward dispatch + forward receipt to reach AWAITING_RETURN
        // with real obligations created at destination for this destination-classified route.
        $dispatchPrep = app(ForwardDispatchPreparationService::class);
        $dispatchMovement = $dispatchPrep->getOrCreateDraft($this->transfer, $this->user->id);
        $dispatchMovement = $dispatchPrep->setLineQuantity($dispatchMovement, $this->product->id, 5, true, $dispatchMovement->lock_version, $this->user->id);
        $scanRes = $dispatchPrep->applyScan($dispatchMovement, 'RG-SN-001', $this->setting->id, $dispatchMovement->lock_version, $this->user->id);
        $this->assertEquals('resolved', $scanRes['status']);
        $dispatchMovement = $dispatchMovement->fresh(['lines.serials']);
        $pendingDispatch = $dispatchPrep->submit($dispatchMovement, $dispatchMovement->lock_version, $this->user->id);
        app(ForwardDispatchApprovalExecutor::class)->approve($this->transfer, $pendingDispatch, $this->user->id);

        $this->transfer->refresh();

        $this->createRoutePolicySnapshot(
            $this->transfer,
            $this->origin,
            $this->destination,
            $this->user,
            $this->transfer->revision,
            \Modules\Adjustment\Entities\TransferRoutePolicy::CLASSIFICATION_PRESERVE,
            true
        );

        $receiptPrep = app(ForwardReceiptPreparationService::class);
        $receiptMovement = $receiptPrep->getOrCreateDraft($this->transfer, $this->user->id);
        $receiptMovement = $receiptPrep->setLineQuantity($receiptMovement, $this->product->id, 5, true, $receiptMovement->lock_version, $this->user->id);
        $scanRes2 = $receiptPrep->applyScan($receiptMovement, 'RG-SN-001', $this->setting->id, $receiptMovement->lock_version, $this->user->id);
        $this->assertEquals('resolved', $scanRes2['status']);
        $receiptMovement = $receiptMovement->fresh(['lines.serials']);
        $pendingReceipt = $receiptPrep->submit($receiptMovement, $receiptMovement->lock_version, $this->user->id);
        $this->receiptMovement = app(ForwardReceiptApprovalExecutor::class)->approve($this->transfer, $pendingReceipt, $this->user->id);

        $this->transfer->refresh();
        $this->assertEquals(Transfer::STATUS_AWAITING_RETURN, $this->transfer->status);
        $this->assertTrue(TransferMovementReturnObligation::where('transfer_id', $this->transfer->id)->exists());
    }

    /** @test */
    public function partial_return_batch_can_be_prepared_and_approved_leaving_capacity_for_another_batch(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batch = $prep->setLineQuantity($batch, $this->product->id, 3, true, $batch->lock_version, $this->user->id);
        $pending = $prep->submit($batch, $batch->lock_version, $this->user->id);

        $approved = $executor->approve($this->transfer, $pending, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approved->status);
        $this->transfer->refresh();
        $this->assertEquals(Transfer::STATUS_RETURN_DISPATCHED, $this->transfer->status);

        $obligation = TransferMovementReturnObligation::where('transfer_id', $this->transfer->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $obligation->load('activeReservations');

        $this->assertSame('3.0000', $obligation->activeInTransitQuantity());
        $this->assertSame('2.0000', $obligation->availableCapacity());
        $this->assertSame('0.0000', (string) $obligation->returned_quantity);

        // Destination stock deducted by 3
        $stock = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->destination->id)->first();
        $this->assertEquals(2, $stock->quantity);

        // A second independent batch can now dispatch the remaining 2
        $batch2 = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $this->assertNotEquals($batch->return_batch_id, $batch2->return_batch_id);

        $batch2 = $prep->setLineQuantity($batch2, $this->product->id, 2, true, $batch2->lock_version, $this->user->id);
        $pending2 = $prep->submit($batch2, $batch2->lock_version, $this->user->id);
        $approved2 = $executor->approve($this->transfer, $pending2, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approved2->status);

        $obligation->refresh()->load('activeReservations');
        $this->assertSame('5.0000', $obligation->activeInTransitQuantity());
        $this->assertSame('0.0000', $obligation->availableCapacity());
    }

    /** @test */
    public function approval_rejects_when_batch_would_overcommit_obligation_capacity(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batch = $prep->setLineQuantity($batch, $this->product->id, 5, true, $batch->lock_version, $this->user->id);
        $pending = $prep->submit($batch, $batch->lock_version, $this->user->id);
        $executor->approve($this->transfer, $pending, $this->user->id);

        // Second batch attempting to also return the same product should fail submission (advisory)
        $batch2 = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batch2 = $prep->setLineQuantity($batch2, $this->product->id, 1, true, $batch2->lock_version, $this->user->id);

        $this->expectException(RuntimeException::class);
        $prep->submit($batch2, $batch2->lock_version, $this->user->id);
    }

    /** @test */
    public function empty_batch_cannot_be_submitted(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);

        $this->expectException(RuntimeException::class);
        $prep->submit($batch, $batch->lock_version, $this->user->id);
    }

    /** @test */
    public function substitute_serial_unrelated_to_forward_serial_can_be_approved_and_claims_custody(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        // RG-SN-002 was never part of the forward dispatch/receipt manifest (only RG-SN-001 was),
        // but it is a genuinely eligible substitute already present at the destination location.
        ProductSerialNumber::where('serial_number', 'RG-SN-002')->update(['location_id' => $this->destination->id]);

        ProductStock::create([
            'product_id'              => $this->serializedProduct->id,
            'location_id'             => $this->destination->id,
            'quantity'                => 1,
            'quantity_non_tax'        => 1,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);

        $scanRes = $prep->applyScan($batch, 'RG-SN-002', $this->setting->id, $batch->lock_version, $this->user->id);
        $this->assertEquals('resolved', $scanRes['status']);

        $batch = $batch->fresh(['lines.serials']);
        $pending = $prep->submit($batch, $batch->lock_version, $this->user->id);
        $approved = $executor->approve($this->transfer, $pending, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approved->status);

        $psn = ProductSerialNumber::where('serial_number', 'RG-SN-002')->first();
        $this->assertTrue(TransferActiveSerialClaim::where('product_serial_number_id', $psn->id)->exists());

        $obligation = TransferMovementReturnObligation::where('transfer_id', $this->transfer->id)
            ->where('product_id', $this->serializedProduct->id)
            ->firstOrFail();
        $obligation->load('activeReservations');
        $this->assertSame('1.0000', $obligation->activeInTransitQuantity());
    }
}

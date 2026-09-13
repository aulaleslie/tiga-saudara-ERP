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
use Modules\Adjustment\Entities\TransferRoutePolicy;
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

class ReturnDispatchConcurrencyAndCustodyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $productA;
    protected Product $productB;
    protected Product $serializedProduct;
    protected Transfer $transfer;
    protected TransferMovement $receiptMovement;
    protected TransferRoutePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();

        $this->origin = Location::create(['setting_id' => $this->setting->id, 'name' => 'Origin']);
        $this->destination = Location::create(['setting_id' => $this->setting->id, 'name' => 'Destination']);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->productA = Product::create([
            'product_name' => 'Return Product A', 'product_code' => 'RPA-001',
            'setting_id' => $this->setting->id, 'unit_id' => $unit->id,
            'product_quantity' => 10, 'product_cost' => 1000, 'product_price' => 1500,
            'serial_number_required' => false, 'stock_managed' => true,
        ]);

        $this->productB = Product::create([
            'product_name' => 'Return Product B', 'product_code' => 'RPB-001',
            'setting_id' => $this->setting->id, 'unit_id' => $unit->id,
            'product_quantity' => 10, 'product_cost' => 1000, 'product_price' => 1500,
            'serial_number_required' => false, 'stock_managed' => true,
        ]);

        $this->serializedProduct = Product::create([
            'product_name' => 'Return Serial Product', 'product_code' => 'RSP-001',
            'setting_id' => $this->setting->id, 'unit_id' => $unit->id,
            'product_quantity' => 2, 'product_cost' => 2000, 'product_price' => 3000,
            'serial_number_required' => true, 'stock_managed' => true,
        ]);

        foreach ([$this->productA, $this->productB] as $product) {
            ProductStock::create([
                'product_id' => $product->id, 'location_id' => $this->destination->id,
                'quantity' => 10, 'quantity_non_tax' => 10, 'quantity_tax' => 0,
                'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0,
            ]);
        }

        ProductStock::create([
            'product_id' => $this->serializedProduct->id, 'location_id' => $this->destination->id,
            'quantity' => 2, 'quantity_non_tax' => 2, 'quantity_tax' => 0,
            'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0,
        ]);

        ProductSerialNumber::create([
            'product_id' => $this->serializedProduct->id, 'location_id' => $this->destination->id,
            'serial_number' => 'RSP-SN-001', 'status' => ProductSerialNumber::STATUS_ACTIVE, 'is_broken' => false,
        ]);
        ProductSerialNumber::create([
            'product_id' => $this->serializedProduct->id, 'location_id' => $this->destination->id,
            'serial_number' => 'RSP-SN-002', 'status' => ProductSerialNumber::STATUS_ACTIVE, 'is_broken' => false,
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_AWAITING_RETURN,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->policy = $this->createRoutePolicySnapshot(
            $this->transfer, $this->origin, $this->destination, $this->user,
            1, TransferRoutePolicy::CLASSIFICATION_PRESERVE, true
        );

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

        foreach ([$this->productA, $this->productB, $this->serializedProduct] as $product) {
            TransferMovementReturnObligation::create([
                'transfer_id'              => $this->transfer->id,
                'transfer_route_policy_id' => $this->policy->id,
                'receipt_movement_id'      => $this->receiptMovement->id,
                'product_id'               => $product->id,
                'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
                'required_quantity'        => '5.0000',
            ]);
        }
    }

    /** @test */
    public function two_approved_batches_on_the_same_obligation_cannot_together_exceed_required_quantity(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        $batch1 = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batch1 = $prep->setLineQuantity($batch1, $this->productA->id, 3, true, $batch1->lock_version, $this->user->id);
        $pending1 = $prep->submit($batch1, $batch1->lock_version, $this->user->id);
        $executor->approve($this->transfer, $pending1, $this->user->id);

        $batch2 = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batch2 = $prep->setLineQuantity($batch2, $this->productA->id, 3, true, $batch2->lock_version, $this->user->id);
        // Force submission past the advisory check by bypassing it isn't possible via the service;
        // instead simulate a second batch racing in with a smaller amount that still overflows
        // once combined, verified authoritatively at approval time.
        $batch2 = TransferMovement::find($batch2->id);
        $batch2->update(['status' => TransferMovement::STATUS_PENDING]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/melebihi kewajiban/');
        $executor->approve($this->transfer, $batch2, $this->user->id);
    }

    /** @test */
    public function approving_one_batch_does_not_invalidate_another_already_prepared_concurrent_batch(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        // Both batches are fully prepared and submitted to PENDING before either is approved,
        // exercising the actual concurrency scenario: two operators working at the same time.
        $batchA = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batchA = $prep->setLineQuantity($batchA, $this->productA->id, 2, true, $batchA->lock_version, $this->user->id);
        $pendingA = $prep->submit($batchA, $batchA->lock_version, $this->user->id);

        $batchB = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batchB = $prep->setLineQuantity($batchB, $this->productB->id, 2, true, $batchB->lock_version, $this->user->id);
        $pendingB = $prep->submit($batchB, $batchB->lock_version, $this->user->id);

        // Approving A first must not bump the transfer's header revision out from under B, since
        // return-dispatch batches pin their own transfer_revision at creation and several batches
        // may be concurrently open; the header revision is not the return-leg concurrency ledger.
        $approvedA = $executor->approve($this->transfer, $pendingA, $this->user->id);
        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approvedA->status);

        $this->transfer->refresh();
        $approvedB = $executor->approve($this->transfer, $pendingB->fresh(), $this->user->id);
        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approvedB->status);
    }

    /** @test */
    public function unrelated_obligations_approve_independently_without_interference(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        $batchA = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batchA = $prep->setLineQuantity($batchA, $this->productA->id, 5, true, $batchA->lock_version, $this->user->id);
        $pendingA = $prep->submit($batchA, $batchA->lock_version, $this->user->id);
        $approvedA = $executor->approve($this->transfer, $pendingA, $this->user->id);

        $batchB = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batchB = $prep->setLineQuantity($batchB, $this->productB->id, 5, true, $batchB->lock_version, $this->user->id);
        $pendingB = $prep->submit($batchB, $batchB->lock_version, $this->user->id);
        $approvedB = $executor->approve($this->transfer, $pendingB, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approvedA->status);
        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approvedB->status);

        $obligationA = TransferMovementReturnObligation::where('transfer_id', $this->transfer->id)->where('product_id', $this->productA->id)->first();
        $obligationB = TransferMovementReturnObligation::where('transfer_id', $this->transfer->id)->where('product_id', $this->productB->id)->first();

        $obligationA->load('activeReservations');
        $obligationB->load('activeReservations');

        $this->assertSame('0.0000', $obligationA->availableCapacity());
        $this->assertSame('0.0000', $obligationB->availableCapacity());
    }

    /** @test */
    public function rejected_batch_creates_no_reservation_and_does_not_consume_capacity(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $documentService = app(\Modules\Adjustment\Services\TransferMovementDocumentService::class);

        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batch = $prep->setLineQuantity($batch, $this->productA->id, 5, true, $batch->lock_version, $this->user->id);
        $pending = $prep->submit($batch, $batch->lock_version, $this->user->id);

        $documentService->reject($pending, 'Kuantitas tidak sesuai.', $this->user->id);

        $this->assertFalse(
            TransferReturnObligationReservation::where('transfer_movement_id', $pending->id)->exists()
        );

        $obligation = TransferMovementReturnObligation::where('transfer_id', $this->transfer->id)->where('product_id', $this->productA->id)->first();
        $obligation->load('activeReservations');
        $this->assertSame('5.0000', $obligation->availableCapacity());
    }

    /** @test */
    public function duplicate_serial_selection_within_a_batch_is_rejected(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);

        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $scan1 = $prep->applyScan($batch, 'RSP-SN-001', $this->setting->id, $batch->lock_version, $this->user->id, true);
        $this->assertEquals('resolved', $scan1['status']);

        $batch = $batch->fresh();
        // Privileged (canViewSystemStock=true) so the detailed domain message is asserted directly;
        // a blind caller instead receives the fixed neutral rejection message for every scan failure.
        $scan2 = $prep->applyScan($batch, 'RSP-SN-001', $this->setting->id, $batch->lock_version, $this->user->id, true);
        $this->assertEquals('rejected', $scan2['status']);
        $this->assertStringContainsString('sudah dipilih', $scan2['message']);
    }

    /** @test */
    public function serial_already_claimed_by_another_movement_cannot_be_approved_twice(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        $batch1 = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $scan1 = $prep->applyScan($batch1, 'RSP-SN-001', $this->setting->id, $batch1->lock_version, $this->user->id);
        $this->assertEquals('resolved', $scan1['status']);
        $batch1 = $batch1->fresh(['lines.serials']);
        $pending1 = $prep->submit($batch1, $batch1->lock_version, $this->user->id);
        $executor->approve($this->transfer, $pending1, $this->user->id);

        $psn = ProductSerialNumber::where('serial_number', 'RSP-SN-001')->first();
        $this->assertTrue(TransferActiveSerialClaim::where('product_serial_number_id', $psn->id)->exists());

        // A second batch cannot scan the same (now claimed / in-transit) serial since it is no
        // longer resolvable by the scan resolver's availability rules.
        $batch2 = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $scan2 = $prep->applyScan($batch2, 'RSP-SN-001', $this->setting->id, $batch2->lock_version, $this->user->id);
        $this->assertNotEquals('resolved', $scan2['status']);
    }
}

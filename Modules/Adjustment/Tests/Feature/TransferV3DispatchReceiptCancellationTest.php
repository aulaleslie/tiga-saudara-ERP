<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovementAllocation;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\TransferV3CancellationExecutor;
use Modules\Adjustment\Services\TransferV3ReceiptExecutor;
use Modules\Adjustment\Tests\Support\BuildsV3TransferFixtures;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Transaction;
use RuntimeException;
use Tests\TestCase;

/**
 * Tasks 4.4, 5.1-5.3, 6.2, 6.4, 6.5: atomic multi-route dispatch, confirmed
 * receipt and exact cancellation reversal for workflow version 3.
 */
class TransferV3DispatchReceiptCancellationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsV3TransferFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildV3Fixtures();
    }

    /** @test */
    public function approval_dispatches_every_allocation_from_its_own_source_atomically(): void
    {
        $transfer = $this->submittedTransfer();
        $this->saveCompletePlan($transfer);
        $transfer = $this->dispatchTransfer($transfer);

        $this->assertSame(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);

        // Bulk: A1 non-tax-first 6 of 6 non-tax; A2 4 of 5 non-tax.
        $a1 = $this->stockAt($this->bulk, $this->a1);
        $this->assertSame(0, (int) $a1->quantity_non_tax);
        $this->assertSame(4, (int) $a1->quantity_tax);
        $this->assertSame(2, (int) $a1->broken_quantity_non_tax, 'broken bucket untouched in good transfer');
        $this->assertSame(1, (int) $this->stockAt($this->bulk, $this->a2)->quantity_non_tax);
        $this->assertSame(5, (int) Product::find($this->bulk->id)->product_quantity);

        // Serialized: both A1 serials and the B1 serial left their source stock.
        $this->assertSame(0, (int) $this->stockAt($this->serialized, $this->a1)->quantity);
        $this->assertSame(0, (int) $this->stockAt($this->serialized, $this->b1)->quantity);
        $this->assertSame(0, (int) Product::find($this->serialized->id)->product_quantity);

        // Live serial location remains at the source while in transit, with an exclusive claim.
        foreach ($this->serials as $serial) {
            $this->assertSame($serial->location_id, ProductSerialNumber::find($serial->id)->location_id);
            $this->assertTrue(TransferActiveSerialClaim::where('product_serial_number_id', $serial->id)->exists());
            $this->assertFalse(ProductSerialNumber::find($serial->id)->isSellable(), 'claimed serial is unavailable to competing flows');
        }

        $dispatch = TransferMovementAllocation::where('transfer_id', $transfer->id)->where('kind', 'DISPATCH')->get();
        $this->assertCount(4, $dispatch);

        $crossBulk = $dispatch->firstWhere('destination_location_id', $this->b1->id);
        $this->assertTrue($crossBulk->cross_business);
        $this->assertSame(TransferRoutePolicy::CLASSIFICATION_TAX, $crossBulk->destination_classification);
        $this->assertSame($this->tax->id, (int) $crossBulk->tax_id);

        $sameBulk = $dispatch->where('product_id', $this->bulk->id)->firstWhere('destination_location_id', $this->a1->id);
        $this->assertFalse($sameBulk->cross_business);
        $this->assertSame(TransferRoutePolicy::CLASSIFICATION_PRESERVE, $sameBulk->destination_classification);

        $serialFromB = $dispatch->where('product_id', $this->serialized->id)->firstWhere('source_location_id', $this->b1->id);
        $this->assertTrue($serialFromB->cross_business, 'PKP B -> non-PKP A');
        $this->assertSame(TransferRoutePolicy::CLASSIFICATION_NON_TAX, $serialFromB->destination_classification);

        $actions = TransferActionHistory::where('transfer_id', $transfer->id)->pluck('action')->all();
        $this->assertSame(['CREATED', 'SUBMITTED', 'ALLOCATION_SAVED', 'APPROVED', 'DISPATCHED'], $actions);

        // Stock-mutation reports resolve TRF references from "#<transfer id>" in the reason.
        foreach (Transaction::where('type', 'TRF')->pluck('reason') as $reason) {
            $this->assertMatchesRegularExpression('/#' . $transfer->id . '\b/', $reason);
            $this->assertSame(1, preg_match_all('/#\d+/', $reason));
        }
    }

    /** @test */
    public function repeated_source_is_validated_against_aggregate_consumption(): void
    {
        $transfer = $this->submittedTransfer(12, false);
        $this->saveCompletePlan($transfer, [
            ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->b1->id, 'quantity' => 6],
            ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->a2->id, 'quantity' => 6],
        ], []);

        try {
            $this->dispatchTransfer($transfer);
            $this->fail('Aggregate overdraw must fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak mencukupi', $e->getMessage());
        }

        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
        $this->assertSame(6, (int) $this->stockAt($this->bulk, $this->a1)->quantity_non_tax);
        $this->assertSame(0, TransferMovementAllocation::count());
    }

    /** @test */
    public function incomplete_or_self_directed_plans_cannot_dispatch(): void
    {
        $transfer = $this->submittedTransfer(10, false);

        foreach ([
            [['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->b1->id, 'quantity' => 6]],
            [['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => null, 'quantity' => 10]],
            [['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->a1->id, 'quantity' => 10]],
        ] as $rows) {
            $this->saveCompletePlan($transfer, $rows, []);

            try {
                $this->dispatchTransfer($transfer);
                $this->fail('Invalid plan must not dispatch.');
            } catch (RuntimeException) {
                $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
            }
        }

        $this->assertSame(12, (int) $this->stockAt($this->bulk, $this->a1)->quantity);
    }

    /** @test */
    public function stale_reviewed_revisions_and_moved_serials_block_dispatch(): void
    {
        $transfer = $this->submittedTransfer();
        $this->saveCompletePlan($transfer);
        $transfer->refresh();
        $reviewedConfiguration = (int) $transfer->approval_configuration_revision;

        // Another approver saves in between: the reviewed summary is stale.
        $this->saveCompletePlan($transfer);

        $this->expectException(RuntimeException::class);
        app(\Modules\Adjustment\Services\TransferV3DispatchExecutor::class)->approveAndDispatch(
            $transfer, $this->actor, $this->businessA->id, (int) $transfer->current_request_revision_id, $reviewedConfiguration
        );
    }

    /** @test */
    public function serial_source_drift_after_review_blocks_dispatch_without_effects(): void
    {
        $transfer = $this->submittedTransfer();
        $this->saveCompletePlan($transfer);

        $this->serials[0]->update(['location_id' => $this->a2->id]);

        try {
            $this->dispatchTransfer($transfer);
            $this->fail('Moved serial must block dispatch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('berubah', $e->getMessage());
        }

        $this->assertSame(0, TransferActiveSerialClaim::count());
        $this->assertSame(12, (int) $this->stockAt($this->bulk, $this->a1)->quantity);
    }

    /** @test */
    public function competing_claim_on_a_serial_blocks_the_second_dispatch(): void
    {
        $first = $this->submittedTransfer(1, true);
        $this->saveCompletePlan($first, [
            ['product_id' => $this->bulk->id, 'source_location_id' => $this->a2->id, 'destination_location_id' => $this->a1->id, 'quantity' => 1],
        ]);

        // Second document selecting the same serials is submitted before the first dispatches.
        $second = $this->submittedTransfer(1, true);
        $this->saveCompletePlan($second, [
            ['product_id' => $this->bulk->id, 'source_location_id' => $this->a2->id, 'destination_location_id' => $this->a1->id, 'quantity' => 1],
        ]);

        $this->dispatchTransfer($first);

        $this->expectException(RuntimeException::class);
        try {
            $this->dispatchTransfer($second);
        } finally {
            $this->assertSame(Transfer::STATUS_PENDING, $second->fresh()->status);
            $this->assertSame(3, TransferActiveSerialClaim::count());
        }
    }

    /** @test */
    public function dispatch_replay_with_same_operation_key_has_no_second_effect(): void
    {
        $transfer = $this->submittedTransfer(10, false);
        $this->saveCompletePlan($transfer, null, []);
        $revisionIds = [(int) $transfer->fresh()->current_request_revision_id, (int) $transfer->fresh()->approval_configuration_revision];

        $this->dispatchTransfer($transfer, 'op-dispatch-1');
        app(\Modules\Adjustment\Services\TransferV3DispatchExecutor::class)->approveAndDispatch($transfer, $this->actor, $this->businessA->id, $revisionIds[0], $revisionIds[1], 'op-dispatch-1');

        $this->assertSame(2, TransferMovementAllocation::count());
        $this->assertSame(1, TransferActionHistory::where('transfer_id', $transfer->id)->where('action', 'DISPATCHED')->count());
    }

    /** @test */
    public function late_failure_rolls_back_every_earlier_allocation(): void
    {
        // Destination business B is PKP; with no applicable tax the TAX
        // classification cannot be snapshotted and the whole approval must roll back.
        $this->tax->update(['is_active' => false]);

        $transfer = $this->submittedTransfer(10, false);
        $this->saveCompletePlan($transfer, [
            ['product_id' => $this->bulk->id, 'source_location_id' => $this->a2->id, 'destination_location_id' => $this->a1->id, 'quantity' => 4],
            ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->b1->id, 'quantity' => 6],
        ], []);

        try {
            $this->dispatchTransfer($transfer);
            $this->fail('Missing applicable tax must roll back.');
        } catch (RuntimeException) {
        }

        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
        $this->assertSame(5, (int) $this->stockAt($this->bulk, $this->a2)->quantity_non_tax);
        $this->assertSame(15, (int) Product::find($this->bulk->id)->product_quantity);
        $this->assertSame(0, Transaction::count());
    }

    /** @test */
    public function broken_condition_dispatch_deducts_broken_buckets_only(): void
    {
        $transfer = app(\Modules\Adjustment\Services\TransferV3GoodsService::class)->createAndSubmit(
            Transfer::CONDITION_BREAKAGE,
            [['product_id' => $this->bulk->id, 'quantity' => 2, 'serial_ids' => []]],
            $this->actor,
            $this->businessA->id
        );
        $this->saveCompletePlan($transfer, [
            ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => $this->b1->id, 'quantity' => 2],
        ], []);
        $this->dispatchTransfer($transfer);

        $a1 = $this->stockAt($this->bulk, $this->a1);
        $this->assertSame(0, (int) $a1->broken_quantity_non_tax);
        $this->assertSame(6, (int) $a1->quantity_non_tax);
        $this->assertSame(0, (int) Product::find($this->bulk->id)->broken_quantity);

        app(TransferV3ReceiptExecutor::class)->receive($transfer, $this->actor, $this->businessA->id);
        $b1 = $this->stockAt($this->bulk, $this->b1);
        $this->assertSame(2, (int) $b1->broken_quantity_tax, 'cross-business to PKP destination: broken tax bucket');
        $this->assertSame(2, (int) Product::find($this->bulk->id)->broken_quantity);
    }

    /** @test */
    public function receipt_posts_every_frozen_destination_and_completes_without_return_obligations(): void
    {
        $transfer = $this->submittedTransfer();
        $this->saveCompletePlan($transfer);
        $this->dispatchTransfer($transfer);

        // Tax settings change after dispatch: receipt must use the frozen snapshot.
        $this->businessB->update(['is_pkp' => false]);
        $this->tax->update(['is_default' => false]);

        app(TransferV3ReceiptExecutor::class)->receive($transfer, $this->actor, $this->businessA->id, 'op-receive-1');
        app(TransferV3ReceiptExecutor::class)->receive($transfer, $this->actor, $this->businessA->id, 'op-receive-1');

        $this->assertSame(Transfer::STATUS_COMPLETED, $transfer->fresh()->status);
        $this->assertSame($this->actor->id, (int) $transfer->fresh()->received_by);

        $b1 = $this->stockAt($this->bulk, $this->b1);
        $this->assertSame(6, (int) $b1->quantity_tax, 'frozen TAX classification');
        $this->assertSame(0, (int) $b1->quantity_non_tax);
        $this->assertSame(4, (int) $this->stockAt($this->bulk, $this->a1)->quantity_non_tax, 'same-business preserves non-tax bucket');
        $this->assertSame(15, (int) Product::find($this->bulk->id)->product_quantity);

        $this->assertSame($this->a2->id, (int) ProductSerialNumber::find($this->serials[0]->id)->location_id);
        $this->assertSame($this->a2->id, (int) ProductSerialNumber::find($this->serials[1]->id)->location_id);
        $this->assertSame($this->tax->id, (int) ProductSerialNumber::find($this->serials[1]->id)->tax_id, 'same-business preserves serial tax');
        $moved = ProductSerialNumber::find($this->serials[2]->id);
        $this->assertSame($this->a1->id, (int) $moved->location_id);
        $this->assertNull($moved->tax_id, 'PKP B -> non-PKP A reclassified to non-tax');
        $this->assertTrue($moved->isSellable());

        $this->assertSame(0, TransferActiveSerialClaim::count());
        $this->assertSame(0, TransferMovementReturnObligation::count());
        $this->assertSame(4, TransferMovementAllocation::where('kind', 'RECEIPT')->count(), 'replay posted nothing twice');
        $this->assertSame(1, TransferActionHistory::where('transfer_id', $transfer->id)->where('action', 'RECEIVED')->count());
        $this->assertSame(1, TransferActionHistory::where('transfer_id', $transfer->id)->where('action', 'COMPLETED')->count());
    }

    /** @test */
    public function cancellation_adds_exact_deltas_to_current_balances_and_releases_claims(): void
    {
        $transfer = $this->submittedTransfer();
        $this->saveCompletePlan($transfer);
        $this->dispatchTransfer($transfer);

        // Unrelated intervening movement at the source and a tax-setting change.
        $a2 = $this->stockAt($this->bulk, $this->a2);
        $a2->update(['quantity_non_tax' => 21, 'quantity' => 21]);
        $this->businessB->update(['is_pkp' => false]);

        app(TransferV3CancellationExecutor::class)->cancel($transfer, $this->actor, $this->businessA->id, 'Kendaraan batal berangkat', 'op-cancel-1');
        app(TransferV3CancellationExecutor::class)->cancel($transfer, $this->actor, $this->businessA->id, 'Kendaraan batal berangkat', 'op-cancel-1');

        $fresh = $transfer->fresh();
        $this->assertSame(Transfer::STATUS_CANCELLED, $fresh->status);
        $this->assertSame('KENDARAAN BATAL BERANGKAT', $fresh->cancellation_reason);

        $this->assertSame(25, (int) $this->stockAt($this->bulk, $this->a2)->quantity_non_tax, 'intervening balance preserved + 4');
        $a1 = $this->stockAt($this->bulk, $this->a1);
        $this->assertSame(6, (int) $a1->quantity_non_tax);
        $this->assertSame(4, (int) $a1->quantity_tax);
        $this->assertSame(1, (int) $this->stockAt($this->serialized, $this->b1)->quantity_tax, 'original source bucket restored');

        foreach ($this->serials as $serial) {
            $live = ProductSerialNumber::find($serial->id);
            $this->assertSame($serial->location_id, $live->location_id);
            $this->assertSame($serial->tax_id, $live->tax_id);
        }
        $this->assertSame(0, TransferActiveSerialClaim::count());
        $this->assertSame(4, TransferMovementAllocation::where('kind', 'CANCELLATION')->count());
        $this->assertSame(4, TransferMovementAllocation::where('kind', 'DISPATCH')->count(), 'original evidence kept');

        // Cancelled documents cannot be received.
        $this->expectException(RuntimeException::class);
        app(TransferV3ReceiptExecutor::class)->receive($transfer, $this->actor, $this->businessA->id);
    }

    /** @test */
    public function cancellation_with_mismatched_custody_rolls_back_all_effects(): void
    {
        $transfer = $this->submittedTransfer();
        $this->saveCompletePlan($transfer);
        $this->dispatchTransfer($transfer);

        TransferActiveSerialClaim::where('product_serial_number_id', $this->serials[2]->id)->delete();
        $before = $this->stockAt($this->bulk, $this->a1)->quantity_non_tax;

        try {
            app(TransferV3CancellationExecutor::class)->cancel($transfer, $this->actor, $this->businessA->id, 'Alasan');
            $this->fail('Custody mismatch must block cancellation.');
        } catch (RuntimeException) {
        }

        $this->assertSame(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);
        $this->assertSame($before, $this->stockAt($this->bulk, $this->a1)->quantity_non_tax);
        $this->assertSame(2, TransferActiveSerialClaim::count());
    }

    /** @test */
    public function receipt_and_cancellation_are_mutually_exclusive(): void
    {
        $transfer = $this->submittedTransfer(10, false);
        $this->saveCompletePlan($transfer, null, []);
        $this->dispatchTransfer($transfer);

        app(TransferV3ReceiptExecutor::class)->receive($transfer, $this->actor, $this->businessA->id);

        try {
            app(TransferV3CancellationExecutor::class)->cancel($transfer, $this->actor, $this->businessA->id, 'Terlambat');
            $this->fail('Completed transfer cannot be cancelled.');
        } catch (RuntimeException) {
        }

        $this->assertSame(Transfer::STATUS_COMPLETED, $transfer->fresh()->status);
        $this->assertSame(0, TransferMovementAllocation::where('kind', 'CANCELLATION')->count());
        $this->assertSame(0, TransferActionHistory::where('transfer_id', $transfer->id)->where('action', 'CANCELLED')->count());
    }

    /** @test */
    public function empty_cancellation_reason_is_rejected(): void
    {
        $transfer = $this->submittedTransfer(10, false);
        $this->saveCompletePlan($transfer, null, []);
        $this->dispatchTransfer($transfer);

        $this->expectException(\InvalidArgumentException::class);
        app(TransferV3CancellationExecutor::class)->cancel($transfer, $this->actor, $this->businessA->id, '   ');
    }

    /** @test */
    public function receipt_rejects_serial_tax_drift_without_posting(): void
    {
        $transfer = $this->submittedTransfer();
        $this->saveCompletePlan($transfer);
        $this->dispatchTransfer($transfer);

        $this->serials[0]->update(['tax_id' => $this->tax->id]); // dispatched as non-tax

        try {
            app(TransferV3ReceiptExecutor::class)->receive($transfer, $this->actor, $this->businessA->id);
            $this->fail('Tax drift must block receipt.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak sesuai dengan bukti pengiriman', $e->getMessage());
        }

        $this->assertSame(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);
        $this->assertNull($this->stockAt($this->bulk, $this->b1));
        $this->assertSame(0, TransferMovementAllocation::where('kind', 'RECEIPT')->count());
        $this->assertSame(3, TransferActiveSerialClaim::count());
    }

    /** @test */
    public function cancellation_rejects_serial_condition_drift_without_restoring(): void
    {
        $transfer = $this->submittedTransfer();
        $this->saveCompletePlan($transfer);
        $this->dispatchTransfer($transfer);

        $this->serials[1]->update(['is_broken' => true]); // dispatched as good stock

        try {
            app(TransferV3CancellationExecutor::class)->cancel($transfer, $this->actor, $this->businessA->id, 'Batal');
            $this->fail('Condition drift must block cancellation.');
        } catch (RuntimeException) {
        }

        $this->assertSame(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);
        $this->assertSame(0, (int) $this->stockAt($this->serialized, $this->a1)->quantity);
        $this->assertSame(0, (int) $this->stockAt($this->bulk, $this->a1)->quantity_non_tax);
        $this->assertSame(3, TransferActiveSerialClaim::count());
    }

    /** @test */
    public function disabling_serialization_after_submission_blocks_dispatch(): void
    {
        $transfer = $this->submittedTransfer();
        $this->saveCompletePlan($transfer);

        $this->serialized->update(['serial_number_required' => false]);

        try {
            $this->dispatchTransfer($transfer);
            $this->fail('Changed tracking mode must block dispatch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nomor seri', $e->getMessage());
        }

        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
        $this->assertSame(2, (int) $this->stockAt($this->serialized, $this->a1)->quantity);
        $this->assertSame(0, TransferActiveSerialClaim::count());
        $this->assertSame(0, TransferMovementAllocation::count());
    }

    /** @test */
    public function enabling_serialization_after_submission_blocks_dispatch(): void
    {
        $transfer = $this->submittedTransfer(10, false);
        $this->saveCompletePlan($transfer, null, []);

        $this->bulk->update(['serial_number_required' => true]);

        $this->expectException(RuntimeException::class);
        try {
            $this->dispatchTransfer($transfer);
        } finally {
            $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
            $this->assertSame(0, TransferMovementAllocation::count());
        }
    }
}

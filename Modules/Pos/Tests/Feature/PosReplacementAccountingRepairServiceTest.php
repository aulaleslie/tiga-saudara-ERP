<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosOwnerPaymentTransfer;
use Modules\Pos\Entities\PosReplacementAccountingRepairAudit;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReceiptReprintProjectionService;
use Modules\Pos\Services\PosReplacementAccountingRepairService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosSettlementProjectionService;
use Modules\Pos\Tests\Feature\Support\BuildsSyntheticTransaction10717Fixture;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class PosReplacementAccountingRepairServiceTest extends PosTransactionFeatureTestCase
{
    use BuildsSyntheticTransaction10717Fixture;

    /** @test */
    public function discovery_and_preview_classify_stale_10717_as_repairable_and_corrected_10717_as_unchanged_without_writes(): void
    {
        $stale = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $corrected = $this->buildSyntheticTransaction10717Fixture('pending_approval', [
            'setting_a' => $stale['setting_a'],
            'terminal_a' => $stale['terminal_a'],
            'location_a' => $stale['location_a'],
            'setting_b' => $stale['setting_b'],
            'location_b' => $stale['location_b'],
            'setting_unrelated' => $stale['setting_unrelated'],
            'location_unrelated' => $stale['location_unrelated'],
            'cashier' => $stale['cashier'],
            'session' => $stale['session'],
            'original_serial' => 'XE4Q304991',
            'replacement_serial' => 'XE4Q318992',
        ]);

        $this->actingAsInSetting($stale['cashier'], $stale['setting_a']);
        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($corrected['pos_return']->id);

        $service = app(PosReplacementAccountingRepairService::class);

        $transfersBefore = PosOwnerPaymentTransfer::query()->count();
        $auditsBefore = PosReplacementAccountingRepairAudit::query()->count();
        $paymentsBefore = SalePayment::query()->count();

        // 1. Read-only discovery in Setting A
        $discovery = $service->discoverCandidates($stale['setting_a']->id, 20);
        $this->assertSame(2, $discovery['total_discovered']);

        $byTrxId = collect($discovery['candidates'])->keyBy('transaction_id');
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_REPAIRABLE, $byTrxId[$stale['transaction']->id]['classification']);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_UNCHANGED, $byTrxId[$corrected['transaction']->id]['classification']);

        // 2. Detailed preview of stale 10717
        $planStale = $service->plan($stale['transaction']->id, $stale['setting_a']->id);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_REPAIRABLE, $planStale['classification']);
        $this->assertNotEmpty($planStale['evidence_fingerprint']);
        $this->assertEquals(4825000.0, (float) $planStale['summary']['current_grand_total']);
        $this->assertEquals(2600000.0, (float) $planStale['summary']['proposed_grand_total']);
        $this->assertEquals(2225000.0, (float) $planStale['summary']['duplicated_charge_amount']);
        $this->assertEquals(4825000.0, (float) $planStale['summary']['current_effective_paid']);
        $this->assertEquals(2600000.0, (float) $planStale['summary']['proposed_effective_paid']);
        $this->assertEquals(0.0, (float) $planStale['summary']['proposed_due_total']);

        // Stable fingerprint across repeated preview calls
        $planStaleRepeat = $service->plan($stale['transaction']->code, $stale['setting_a']->id);
        $this->assertSame($planStale['evidence_fingerprint'], $planStaleRepeat['evidence_fingerprint']);

        // Verify expected receipt lines preview: paper is Rp45,000, printer is Rp2,225,000, Logitech is Rp330,000
        $linesByPosLineId = collect($planStale['expected_receipt_lines'])->keyBy('pos_transaction_line_id');
        $this->assertEquals(2225000.0, (float) $linesByPosLineId[$stale['line_printer']->id]['sub_total']);
        $this->assertEquals(45000.0, (float) $linesByPosLineId[$stale['line_paper']->id]['sub_total']);
        $this->assertEquals(330000.0, (float) $linesByPosLineId[$stale['line_logitech']->id]['sub_total']);

        // Zero database writes during discovery and preview
        $this->assertSame($transfersBefore, PosOwnerPaymentTransfer::query()->count());
        $this->assertSame($auditsBefore, PosReplacementAccountingRepairAudit::query()->count());
        $this->assertSame($paymentsBefore, SalePayment::query()->count());
    }

    /** @test */
    public function planner_blocks_wrong_business_scope_missing_payment_provenance_conflicting_edits_and_active_returns(): void
    {
        $service = app(PosReplacementAccountingRepairService::class);

        // Case 1: Wrong setting scope
        $f1 = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $wrongScopePlan = $service->plan($f1['transaction']->id, $f1['setting_b']->id);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_BLOCKED, $wrongScopePlan['classification']);

        // Case 2: Active overlapping return on the checkout
        $pendingReturn = PosReturn::query()->create([
            'setting_id' => $f1['setting_a']->id,
            'pos_transaction_id' => $f1['transaction']->id,
            'pos_checkout_id' => $f1['checkout']->id,
            'transaction_code' => $f1['transaction']->code,
            'receipt_number' => $f1['checkout']->receipt_number,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-active-ret',
            'reference' => 'PR-ACTIVE-BLOCK',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 45000,
            'created_by' => $f1['cashier']->id,
            'updated_by' => $f1['cashier']->id,
        ]);
        $activeRetPlan = $service->plan($f1['transaction']->id, $f1['setting_a']->id);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_BLOCKED, $activeRetPlan['classification']);
        $this->assertStringContainsString('retur aktif', strtolower($activeRetPlan['reason']));
        $pendingReturn->delete();

        // Case 3: Missing payment provenance on source sale (source payment deleted or insufficient)
        $f2 = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $f2['source_payment']->delete();
        $missingPaymentPlan = $service->plan($f2['transaction']->id, $f2['setting_a']->id);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_BLOCKED, $missingPaymentPlan['classification']);

        // Case 4: Unexplained manual conflicting header edit on source sale (e.g., total is Rp2,500,000 instead of Rp2,270,000)
        $f3 = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $f3['source_sale']->forceFill(['total_amount' => 2500000])->save();
        $conflictingEditPlan = $service->plan($f3['transaction']->id, $f3['setting_a']->id);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_BLOCKED, $conflictingEditPlan['classification']);
    }

    /** @test */
    public function cli_command_validates_flags_and_runs_read_only_discovery_and_preview_without_mutations(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $settingId = $fixture['setting_a']->id;
        $trxId = $fixture['transaction']->id;

        // 1. Missing --setting rejected
        $this->artisan('pos:repair-replacement-accounting', [
            '--discover' => true,
        ])->assertExitCode(1);

        // 2. --discover combined with --apply rejected
        $this->artisan('pos:repair-replacement-accounting', [
            '--setting' => $settingId,
            '--discover' => true,
            '--apply' => true,
        ])->assertExitCode(1);

        // 3. Duplicate --transaction rejected
        $this->artisan('pos:repair-replacement-accounting', [
            '--setting' => $settingId,
            '--transaction' => [$trxId, $trxId],
        ])->assertExitCode(1);

        // 4. --apply without --actor / --reason / --fingerprint rejected
        $this->artisan('pos:repair-replacement-accounting', [
            '--setting' => $settingId,
            '--transaction' => [$trxId],
            '--apply' => true,
        ])->assertExitCode(1);

        // 5. Read-only --discover succeeds with 0 writes
        $this->artisan('pos:repair-replacement-accounting', [
            '--setting' => $settingId,
            '--discover' => true,
            '--json' => true,
        ])->assertExitCode(0);

        // 6. Read-only --transaction preview succeeds with 0 writes
        $this->artisan('pos:repair-replacement-accounting', [
            '--setting' => $settingId,
            '--transaction' => [$trxId],
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, PosOwnerPaymentTransfer::query()->count());
        $this->assertSame(0, PosReplacementAccountingRepairAudit::query()->count());
        $this->assertEquals(2270000.0, (float) $fixture['source_sale']->fresh()->total_amount);
    }

    /** @test */
    public function explicit_apply_repairs_stale_10717_atomically_preserves_invariants_and_is_idempotent_on_rerun(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $settingId = $fixture['setting_a']->id;
        $trxId = $fixture['transaction']->id;
        $actorId = $fixture['cashier']->id;

        $service = app(PosReplacementAccountingRepairService::class);
        $preview = $service->plan($trxId, $settingId);
        $fingerprint = $preview['evidence_fingerprint'];

        // Snapshot invariants before repair
        $origSourcePaymentSnap = $fixture['source_payment']->fresh()->only(['id', 'sale_id', 'amount', 'date', 'reference', 'payment_method', 'status']);
        $origUnrelatedPaymentSnap = $fixture['unrelated_payment']->fresh()->only(['id', 'sale_id', 'amount', 'date', 'reference', 'payment_method', 'status']);
        $origDetailsSnap = [
            'printer' => $fixture['detail_printer']->fresh()->only(['quantity', 'price', 'unit_price', 'sub_total']),
            'paper' => $fixture['detail_paper']->fresh()->only(['quantity', 'price', 'unit_price', 'sub_total']),
            'logitech' => $fixture['detail_logitech']->fresh()->only(['quantity', 'price', 'unit_price', 'sub_total']),
            'replacement' => $fixture['replacement_sale_detail']->fresh()->only(['quantity', 'price', 'unit_price', 'sub_total']),
        ];
        $origCheckoutSnap = $fixture['checkout']->fresh()->only(['subtotal', 'grand_total', 'paid_total', 'debt_amount']);
        $origPosReturnSnap = $fixture['pos_return']->fresh()->only(['total_amount', 'status', 'return_option', 'source_snapshot_hash']);
        $stockSnap = ProductStock::query()->orderBy('id')->get(['id', 'product_id', 'location_id', 'quantity'])->toArray();
        $serialHistoryCountBefore = SerialNumberHistory::query()->count();

        // 1. Stale/mismatched fingerprint must be refused without any mutation
        try {
            $service->apply($trxId, $settingId, $actorId, 'Attempt with wrong fingerprint', str_repeat('a', 64));
            $this->fail('Expected mismatched fingerprint to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fingerprint mismatch', strtolower($e->getMessage()));
        }
        $this->assertSame(0, PosOwnerPaymentTransfer::query()->count());
        $this->assertSame(0, PosReplacementAccountingRepairAudit::query()->count());

        // 2. Apply with reviewed fingerprint
        $applyResult = $service->apply(
            $trxId,
            $settingId,
            $actorId,
            'Repair incident 10717 cross-owner replacement duplication',
            $fingerprint
        );
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_REPAIRED, $applyResult['classification']);

        // Also verify CLI command on already-repaired transaction succeeds with exit code 0
        $this->artisan('pos:repair-replacement-accounting', [
            '--setting' => $settingId,
            '--transaction' => [$trxId],
            '--apply' => true,
            '--actor' => $actorId,
            '--reason' => 'Idempotent CLI apply check',
            '--fingerprint' => $applyResult['evidence_fingerprint'],
        ])->assertExitCode(0);

        // Verify repaired state
        $sourceSale = $fixture['source_sale']->fresh();
        $replacementSale = $fixture['replacement_sale']->fresh();
        $unrelatedSale = $fixture['unrelated_sale']->fresh();

        $this->assertEquals(45000.0, (float) $sourceSale->total_amount);
        $this->assertEquals(45000.0, (float) $sourceSale->paid_amount);
        $this->assertEquals(0.0, (float) $sourceSale->due_amount);
        $this->assertEquals(45000.0, $sourceSale->getEffectivePaidAmount());

        $this->assertEquals(2225000.0, (float) $replacementSale->total_amount);
        $this->assertEquals(2225000.0, (float) $replacementSale->paid_amount);
        $this->assertEquals(0.0, (float) $replacementSale->due_amount);
        $this->assertEquals(2225000.0, $replacementSale->getEffectivePaidAmount());

        $this->assertEquals(330000.0, (float) $unrelatedSale->total_amount);
        $this->assertEquals(330000.0, $unrelatedSale->getEffectivePaidAmount());

        // Synthetic payment is invalidated, while original customer payments are untouched!
        $synPayment = $fixture['replacement_payment']->fresh();
        $this->assertTrue($synPayment->isInvalidated());
        $this->assertSame(PosReplacementAccountingRepairService::INVALIDATION_SOURCE, $synPayment->invalidation_source);
        $this->assertSame($actorId, (int) $synPayment->invalidated_by);

        $this->assertSame($origSourcePaymentSnap, $fixture['source_payment']->fresh()->only(['id', 'sale_id', 'amount', 'date', 'reference', 'payment_method', 'status']));
        $this->assertSame($origUnrelatedPaymentSnap, $fixture['unrelated_payment']->fresh()->only(['id', 'sale_id', 'amount', 'date', 'reference', 'payment_method', 'status']));

        // PosOwnerPaymentTransfer created with HISTORICAL_REPAIR source
        $transfers = PosOwnerPaymentTransfer::query()->where('pos_transaction_id', $trxId)->get();
        $this->assertCount(1, $transfers);
        $transfer = $transfers->first();
        $this->assertEquals(2225000.0, (float) $transfer->amount);
        $this->assertSame(PosOwnerPaymentTransfer::SOURCE_HISTORICAL_REPAIR, $transfer->transfer_source);
        $this->assertSame((int) $fixture['source_payment']->id, (int) $transfer->original_sale_payment_id);
        $this->assertSame((int) $synPayment->id, (int) $transfer->invalidated_synthetic_payment_id);

        // Audit record persisted with before/after evidence
        $audits = PosReplacementAccountingRepairAudit::query()->where('pos_transaction_id', $trxId)->get();
        $this->assertCount(1, $audits);
        $audit = $audits->first();
        $this->assertSame($fingerprint, $audit->evidence_fingerprint);
        $this->assertEquals(4825000.0, (float) data_get($audit->before_values, 'summary.current_grand_total'));
        $this->assertEquals(2600000.0, (float) data_get($audit->after_values, 'summary.current_grand_total'));

        // Normal receipt & settlement projections now succeed with Rp2,600,000, paper Rp45,000, and serial XE4Q318288
        $settlement = app(PosSettlementProjectionService::class)->project($fixture['transaction']->fresh());
        $this->assertTrue($settlement['is_valid']);
        $this->assertEquals(2600000.0, (float) $settlement['total_amount']);
        $this->assertEquals(2600000.0, (float) $settlement['effective_paid']);
        $this->assertEquals(0.0, (float) $settlement['live_due']);

        $receiptData = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($fixture['checkout']->fresh());
        $this->assertEquals(2600000.0, (float) $receiptData['grand_total']);
        $linesByPosLineId = collect($receiptData['lines'])->keyBy('pos_transaction_line_id');
        $this->assertEquals(2225000.0, (float) $linesByPosLineId[$fixture['line_printer']->id]['sub_total']);
        $this->assertEquals(45000.0, (float) $linesByPosLineId[$fixture['line_paper']->id]['sub_total']);
        $this->assertEquals(330000.0, (float) $linesByPosLineId[$fixture['line_logitech']->id]['sub_total']);
        $this->assertSame(['XE4Q318288'], $linesByPosLineId[$fixture['line_printer']->id]['assigned_serials']);

        // Preservation invariants: detail rows, checkout snapshot, return record, stock, and serial history untouched
        $this->assertSame($origDetailsSnap['printer'], $fixture['detail_printer']->fresh()->only(['quantity', 'price', 'unit_price', 'sub_total']));
        $this->assertSame($origDetailsSnap['paper'], $fixture['detail_paper']->fresh()->only(['quantity', 'price', 'unit_price', 'sub_total']));
        $this->assertSame($origDetailsSnap['logitech'], $fixture['detail_logitech']->fresh()->only(['quantity', 'price', 'unit_price', 'sub_total']));
        $this->assertSame($origDetailsSnap['replacement'], $fixture['replacement_sale_detail']->fresh()->only(['quantity', 'price', 'unit_price', 'sub_total']));
        $this->assertSame($origCheckoutSnap, $fixture['checkout']->fresh()->only(['subtotal', 'grand_total', 'paid_total', 'debt_amount']));
        $this->assertSame($origPosReturnSnap, $fixture['pos_return']->fresh()->only(['total_amount', 'status', 'return_option', 'source_snapshot_hash']));
        $this->assertSame($stockSnap, ProductStock::query()->orderBy('id')->get(['id', 'product_id', 'location_id', 'quantity'])->toArray());
        $this->assertSame($serialHistoryCountBefore, SerialNumberHistory::query()->count());

        // 3. Rerun preview and apply on already-repaired transaction: classified as UNCHANGED with no new transfer or audit
        $rerunPlan = $service->plan($trxId, $settingId);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_UNCHANGED, $rerunPlan['classification']);

        $rerunApply = $service->apply($trxId, $settingId, $actorId, 'Idempotent rerun check', $rerunPlan['evidence_fingerprint']);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_UNCHANGED, $rerunApply['classification']);
        $this->assertSame(1, PosOwnerPaymentTransfer::query()->where('pos_transaction_id', $trxId)->count());
        $this->assertSame(1, PosReplacementAccountingRepairAudit::query()->where('pos_transaction_id', $trxId)->count());
    }

    /** @test */
    public function planner_blocks_unexplained_replacement_payments_even_without_active_synthetic_payments(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $service = app(PosReplacementAccountingRepairService::class);

        // Invalidate synthetic payment manually to simulate legacy edge case
        $fixture['replacement_payment']->update([
            'status' => SalePayment::STATUS_INVALIDATED,
            'invalidation_source' => 'manual_void',
        ]);

        // Add an unexplained direct independent payment on replacement sale
        SalePayment::query()->create([
            'sale_id' => $fixture['replacement_sale']->id,
            'amount' => 500000,
            'date' => now()->toDateString(),
            'reference' => 'SP-UNEXPLAINED-01',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $plan = $service->plan($fixture['transaction']->id, $fixture['setting_a']->id);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_BLOCKED, $plan['classification']);
        $this->assertStringContainsString('memiliki pembayaran langsung tanpa rekaman pembayaran sintetis', strtolower($plan['reason']));
    }

    /** @test */
    public function apply_rolls_back_all_invalidation_transfer_header_and_audit_mutations_if_post_repair_verification_fails(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $settingId = $fixture['setting_a']->id;
        $trxId = $fixture['transaction']->id;
        $actorId = $fixture['cashier']->id;

        $service = app(PosReplacementAccountingRepairService::class);
        $preview = $service->plan($trxId, $settingId);
        $fingerprint = $preview['evidence_fingerprint'];

        $failingReceiptProjection = new class extends PosReceiptReprintProjectionService {
            public function getCompletedReceiptData(\Modules\Pos\Entities\PosCheckout $checkout): array
            {
                throw new \RuntimeException('Simulated failure during post-repair receipt verification');
            }
        };

        $failingRepairService = new PosReplacementAccountingRepairService(
            new \Modules\Pos\Services\PosReachableSalesResolver(),
            new \Modules\Pos\Services\PosReplacementAccountingTransferService(),
            new PosSettlementProjectionService(),
            $failingReceiptProjection
        );

        try {
            $failingRepairService->apply($trxId, $settingId, $actorId, 'Rollback test', $fingerprint);
            $this->fail('Expected apply to fail and roll back when post-repair receipt verification fails.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Simulated failure during post-repair receipt verification', $e->getMessage());
        }

        // Verify complete rollback: synthetic payment remains ACTIVE, source sale header remains Rp2,270,000, 0 transfers, 0 audits
        $this->assertTrue($fixture['replacement_payment']->fresh()->isActive());
        $this->assertEquals(2270000.0, (float) $fixture['source_sale']->fresh()->total_amount);
        $this->assertEquals(2270000.0, (float) $fixture['source_sale']->fresh()->paid_amount);
        $this->assertSame(0, PosOwnerPaymentTransfer::query()->count());
        $this->assertSame(0, PosReplacementAccountingRepairAudit::query()->count());
    }
}

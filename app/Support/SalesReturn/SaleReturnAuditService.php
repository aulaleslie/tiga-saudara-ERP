<?php

namespace App\Support\SalesReturn;

use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;

/**
 * READ-ONLY evidence audit of standard Sales Returns for ONE business (setting).
 *
 * It only reads. It takes no locks, writes nothing, exposes no apply/repair mode and never infers a
 * historical correction from current stock alone: stock-related findings cite recorded transactions
 * and custody rows, not aggregate balances.
 *
 * Classification of every finding:
 *   proven     the recorded evidence contradicts itself or a hard rule (no other reading exists)
 *   suspected  evidence points at a problem but a legitimate explanation (older rules, missing
 *              history) cannot be excluded
 *   ambiguous  the evidence is insufficient or conflicting to decide either way (legacy records)
 * `record_policy` says whether the return was received under the custody policy ("new-policy"),
 * predates it ("legacy"), or has not been received ("n/a").
 */
class SaleReturnAuditService
{
    public const PROVEN = 'proven';
    public const SUSPECTED = 'suspected';
    public const AMBIGUOUS = 'ambiguous';

    public function __construct(
        private readonly SalesReturnWorkflowResolver $resolver,
        private readonly SaleReturnLifecycleSyncService $sync,
    ) {
    }

    /**
     * @return array{scope: int, filters: array<string, mixed>, summary: array<string, int>, findings: array<int, array<string, mixed>>}
     */
    public function audit(int $settingId, ?int $saleId = null, ?int $returnId = null): array
    {
        $returns = SaleReturn::withArchived()
            ->where('setting_id', $settingId)
            ->whereNull('pos_return_id')
            ->when($saleId, fn ($q) => $q->where('sale_id', $saleId))
            ->when($returnId, fn ($q) => $q->whereKey($returnId))
            ->orderBy('id')
            ->get();

        $findings = [];
        $saleIds = [];

        foreach ($returns as $return) {
            $saleIds[(int) $return->sale_id] = true;
            foreach ($this->auditReturn($return) as $finding) {
                $findings[] = $finding;
            }
        }

        // Sale-level checks (mixed histories, archival) run once per source sale in scope.
        $saleQuery = Sale::withArchived()->where('setting_id', $settingId)
            ->when($saleId, fn ($q) => $q->whereKey($saleId))
            ->when(! $saleId && ! $returnId, fn ($q) => $q->whereIn('id', array_keys($saleIds)))
            ->when($returnId && ! $saleId, fn ($q) => $q->whereIn('id', array_keys($saleIds)));
        foreach ($saleQuery->orderBy('id')->get() as $sale) {
            foreach ($this->auditSale($sale) as $finding) {
                $findings[] = $finding;
            }
        }

        $summary = ['proven' => 0, 'suspected' => 0, 'ambiguous' => 0, 'returns_audited' => $returns->count()];
        foreach ($findings as $f) {
            $summary[$f['classification']]++;
        }

        return [
            'scope' => $settingId,
            'filters' => ['sale' => $saleId, 'return' => $returnId],
            'summary' => $summary,
            'findings' => $findings,
        ];
    }

    // ------------------------------------------------------------------ per return

    /** @return array<int, array<string, mixed>> */
    private function auditReturn(SaleReturn $return): array
    {
        $out = [];
        $status = strtoupper((string) $return->status);
        $received = in_array($status, ['AWAITING SETTLEMENT', 'COMPLETED'], true);
        $policy = $return->custody_policy_version ? 'new-policy' : ($received ? 'legacy' : 'n/a');

        $add = function (string $code, string $classification, string $message, array $evidence = []) use (&$out, $return, $policy) {
            $out[] = [
                'code' => $code, 'classification' => $classification, 'record_policy' => $policy,
                'sale_return_id' => (int) $return->id, 'reference' => $return->reference, 'sale_id' => (int) $return->sale_id,
                'message' => $message, 'evidence' => $evidence,
            ];
        };

        $details = SaleReturnDetail::query()->with('product:id,serial_number_required')
            ->where('sale_return_id', $return->id)->orderBy('id')->get();
        $settlements = Settlement::query()->where('sale_return_id', $return->id)->orderBy('id')->get();
        $custodies = DB::table('sale_return_custodies')->where('sale_return_id', $return->id)->get();

        // ---- receiving evidence
        if ($received && empty($return->received_at)) {
            $add('RECEIVED_WITHOUT_RECEIVED_AT', self::PROVEN,
                'Status says the goods were received but there is no received_at: the receiving evidence is missing.',
                ['status' => $return->status]);
        }
        if (! $received && ! empty($return->received_at)) {
            $add('RECEIVED_AT_WITHOUT_RECEIVED_STATUS', self::PROVEN,
                'received_at is set although the return is not in a received status.', ['status' => $return->status, 'received_at' => (string) $return->received_at]);
        }
        if ($return->custody_policy_version && $custodies->isEmpty() && $received) {
            $add('NEW_POLICY_WITHOUT_CUSTODY', self::PROVEN,
                'Received under the custody policy but no custody rows exist.', ['custody_policy_version' => (int) $return->custody_policy_version]);
        }
        if (! $return->custody_policy_version && $custodies->isNotEmpty()) {
            $add('CUSTODY_WITHOUT_POLICY_MARKER', self::PROVEN, 'Custody rows exist but the return carries no policy marker.', ['custody_rows' => $custodies->count()]);
        }
        if ($received && ! $return->custody_policy_version && $custodies->isEmpty()) {
            $add('LEGACY_RECEIVED_WITHOUT_CUSTODY', self::AMBIGUOUS,
                'Received before the custody policy: stock and serial handling at receiving cannot be reconstructed from custody. Custody-dependent actions are blocked; investigate manually.', []);
        }
        foreach ($custodies as $c) {
            $held = (float) $c->quantity_received - (float) $c->quantity_released - (float) $c->quantity_dispatched;
            if ($held < -0.0004) {
                $add('CUSTODY_OVERDRAWN', self::PROVEN, "Custody {$c->id} released/dispatched more than received.", ['custody_id' => (int) $c->id, 'held' => round($held, 3)]);
            }
            if ($c->product_serial_number_id) {
                $serial = DB::table('product_serial_numbers')->where('id', $c->product_serial_number_id)->first(['id', 'status', 'is_in_return_process']);
                if ($held > 0.0004 && $serial && (strtoupper((string) $serial->status) !== 'RETURN_IN_PROCESS' || ! $serial->is_in_return_process)) {
                    $add('HELD_CUSTODY_SERIAL_NOT_HELD', self::PROVEN, "Serial of custody {$c->id} is held in custody but its own state says otherwise.", ['custody_id' => (int) $c->id, 'serial_status' => $serial->status]);
                }
                if ($held <= 0.0004 && $serial && strtoupper((string) $serial->status) === 'RETURN_IN_PROCESS') {
                    $add('SERIAL_HELD_WITHOUT_CUSTODY', self::SUSPECTED, "Serial still RETURN_IN_PROCESS although custody {$c->id} has nothing held.", ['custody_id' => (int) $c->id]);
                }
            }
        }

        // ---- stock evidence (recorded transactions only; never current balances)
        // Evidence is scoped to THIS business and matched EXACTLY (no suffix / wildcard matching): a transaction
        // of another business, or of a document whose reference merely ends the same way, is not evidence here.
        $productIds = $details->pluck('product_id')->filter()->unique()->values()->all();
        $receivingReason = 'PENERIMAAN RETUR PENJUALAN: ' . strtoupper((string) $return->reference);
        $releaseReason = 'INSPEKSI RETUR PENJUALAN: ' . strtoupper((string) $return->reference);
        $ownerSettings = array_values(array_unique(array_merge([(int) $return->setting_id], $custodies->pluck('owner_setting_id')->map(fn ($id) => (int) $id)->all())));

        $receivingTx = DB::table('transactions')
            ->where('setting_id', (int) $return->setting_id)
            ->whereIn('product_id', $productIds)
            ->whereRaw('UPPER(reason) = ?', [$receivingReason])
            ->whereIn('type', ['SALE_RETURN_GOOD_TAX', 'SALE_RETURN_GOOD_NON_TAX'])->get(['id', 'product_id', 'quantity', 'type']);
        if ($return->custody_policy_version && $receivingTx->isNotEmpty()) {
            $add('NEW_POLICY_RECEIVING_CREDITED_STOCK', self::PROVEN,
                'A return received under the custody policy must hold goods, yet receiving stock transactions exist.',
                ['transaction_ids' => $receivingTx->pluck('id')->all()]);
        }
        if ($received && ! $return->custody_policy_version && $receivingTx->isEmpty()) {
            $add('LEGACY_RECEIVED_WITHOUT_STOCK_TRANSACTION', self::SUSPECTED,
                'A legacy received return has no recorded receiving stock transaction (it may predate transaction logging). Current stock is deliberately not used to decide.', []);
        }
        $releasedTotal = (float) $custodies->sum('quantity_released');
        // Releases credit the custody OWNER's business, which may differ from the return's business.
        $releaseTx = (float) DB::table('transactions')
            ->whereIn('setting_id', $ownerSettings)
            ->whereIn('product_id', $productIds)
            ->whereRaw('UPPER(reason) = ?', [$releaseReason])
            ->whereIn('type', ['SALE_RETURN_RELEASE_TAX', 'SALE_RETURN_RELEASE_NON_TAX'])->sum('quantity');
        if (abs($releasedTotal - $releaseTx) > 0.0004) {
            $add('RELEASE_STOCK_MISMATCH', self::PROVEN, 'Released custody quantity differs from the recorded release stock transactions.',
                ['released' => round($releasedTotal, 3), 'release_transactions' => round($releaseTx, 3)]);
        }

        // ---- settlement occurrences
        foreach ($details as $detail) {
            if ((float) $detail->quantity <= 0) {
                continue;
            }
            $occurrences = StandardReturnCoverage::occurrences($detail);
            $raw = collect($detail->serial_number_ids ?? [])->map(fn ($id) => (int) $id)->filter();
            if ($occurrences === null) {
                $add('SERIAL_CARDINALITY_MISMATCH', self::PROVEN, "Detail {$detail->id}: received quantity, serial ids or serial requirement are inconsistent.",
                    ['detail_id' => (int) $detail->id, 'quantity' => (float) $detail->quantity, 'serial_ids' => $raw->values()->all(), 'serial_required' => (bool) $detail->product?->serial_number_required]);
                continue;
            }
            foreach ($occurrences as $occurrence) {
                $rows = $settlements->filter(fn ($s) => (int) $s->sale_return_detail_id === (int) $detail->id
                    && ($occurrence['serial'] === null ? $s->product_serial_number_id === null : (int) $s->product_serial_number_id === $occurrence['serial']));
                $finals = $rows->filter(fn ($s) => StandardReturnCoverage::isFinal($s));
                if ($finals->count() > 1) {
                    $add('DUPLICATE_FINAL_SETTLEMENTS', self::PROVEN, "Detail {$detail->id}" . ($occurrence['serial'] ? " serial {$occurrence['serial']}" : '') . ' has more than one final settlement.',
                        ['settlement_ids' => $finals->pluck('id')->values()->all()]);
                }
            }
        }
        $known = [];
        foreach ($details as $detail) {
            foreach (StandardReturnCoverage::occurrences($detail) ?? [] as $o) {
                $known[$detail->id . ':' . ($o['serial'] ?? 0)] = true;
            }
        }
        foreach ($settlements as $s) {
            if ($s->status !== Settlement::STATUS_REJECTED && ! isset($known[$s->sale_return_detail_id . ':' . ($s->product_serial_number_id ?? 0)])) {
                $add('UNMATCHED_SETTLEMENT_OCCURRENCE', self::PROVEN, "Settlement {$s->id} matches no received occurrence of this return.", ['settlement_id' => (int) $s->id]);
            }
            if (StandardReturnCoverage::isFinal($s) === false && in_array($s->status, [Settlement::STATUS_APPROVED, Settlement::STATUS_DISPATCHED], true)) {
                $add('METHOD_STATUS_MISMATCH', self::PROVEN, "Settlement {$s->id}: method {$s->method} cannot be final in status {$s->status}.", ['settlement_id' => (int) $s->id]);
            }
        }

        // ---- premature completion
        if ($status === 'COMPLETED' && ! StandardReturnCoverage::returnCompletion($return)['complete']) {
            $coverage = StandardReturnCoverage::returnCompletion($return);
            $add('PREMATURE_COMPLETION', $policy === 'legacy' ? self::SUSPECTED : self::PROVEN,
                'Completed although not every received occurrence has exactly one valid final settlement under the current rules.',
                ['missing' => $coverage['missing']]);
        }

        // ---- financial evidence of approved cash settlements
        $adjustments = DB::table('sale_return_adjustments')->where('sale_return_id', $return->id)->get()->keyBy('sale_return_item_settlement_id');
        $payments = DB::table('sale_return_payments')->where('sale_return_id', $return->id)->get();
        foreach ($settlements->where('method', SaleReturnDetail::METHOD_CASH_REFUND)->where('status', Settlement::STATUS_APPROVED) as $s) {
            $adj = $adjustments->get($s->id);
            $linked = $payments->where('sale_return_item_settlement_id', $s->id);
            if (! $adj) {
                $add('CASH_APPROVED_WITHOUT_ADJUSTMENT', $policy === 'legacy' ? self::AMBIGUOUS : self::PROVEN,
                    $policy === 'legacy'
                        ? "Settlement {$s->id}: approved before the adjustment ledger; the debt/cash split cannot be reconstructed."
                        : "Settlement {$s->id}: approved without adjustment evidence.",
                    ['settlement_id' => (int) $s->id, 'payments' => $linked->pluck('id')->all()]);
                continue;
            }
            if (abs((float) $adj->committed_value - ((float) $adj->debt_reduction + (float) $adj->cash_refund)) > 0.011) {
                $add('ADJUSTMENT_ARITHMETIC_CONTRADICTION', self::PROVEN, "Adjustment {$adj->id}: committed value differs from debt reduction plus cash refund.", ['adjustment_id' => (int) $adj->id]);
            }
            $cash = (float) $adj->cash_refund;
            if ($cash > 0 && ($linked->count() !== 1 || abs((float) $linked->first()->amount - $cash) > 0.011 || (int) $adj->sale_return_payment_id !== (int) ($linked->first()->id ?? 0))) {
                $add('PAYOUT_EVIDENCE_CONTRADICTION', self::PROVEN, "Settlement {$s->id}: the cash refund is not matched by exactly one linked payout of the same amount.",
                    ['settlement_id' => (int) $s->id, 'cash_refund' => $cash, 'linked_payments' => $linked->pluck('amount', 'id')->all()]);
            }
            if ($cash <= 0 && ($linked->isNotEmpty() || $adj->sale_return_payment_id)) {
                $add('PAYOUT_WITHOUT_CASH_REFUND', self::PROVEN, "Settlement {$s->id}: a payout exists although the adjustment records no cash refund.", ['settlement_id' => (int) $s->id]);
            }
        }
        foreach ($payments->whereNull('sale_return_item_settlement_id')->groupBy(fn ($p) => $p->amount . '|' . $p->date) as $group) {
            if ($group->count() > 1) {
                $add('POSSIBLE_DUPLICATE_PAYOUT', self::SUSPECTED, 'Several unlinked payouts share an amount and date; they may be duplicates.', ['payment_ids' => $group->pluck('id')->all(), 'amount' => (float) $group->first()->amount]);
            }
        }
        foreach ($payments->whereNotNull('sale_return_item_settlement_id')->groupBy('sale_return_item_settlement_id') as $settlementId => $group) {
            if ($group->count() > 1) {
                $add('DUPLICATE_PAYOUT_FOR_SETTLEMENT', self::PROVEN, "Settlement {$settlementId} has more than one payout.", ['payment_ids' => $group->pluck('id')->all()]);
            }
        }
        if ($payments->isNotEmpty() && (float) $payments->sum('amount') - (float) $return->total_amount > 0.011) {
            $add('PAYOUTS_EXCEED_RETURN_TOTAL', self::PROVEN, 'Payouts of this return exceed its total.', ['paid' => round((float) $payments->sum('amount'), 2), 'return_total' => (float) $return->total_amount]);
        }

        return $out;
    }

    // ------------------------------------------------------------------ per sale

    /** @return array<int, array<string, mixed>> */
    private function auditSale(Sale $sale): array
    {
        $out = [];
        $add = function (string $code, string $classification, string $message, array $evidence = []) use (&$out, $sale) {
            $out[] = [
                'code' => $code, 'classification' => $classification, 'record_policy' => 'n/a',
                'sale_return_id' => null, 'reference' => $sale->reference, 'sale_id' => (int) $sale->id, 'message' => $message, 'evidence' => $evidence,
            ];
        };

        $class = $this->resolver->classifySale((int) $sale->id);
        if ($class === SalesReturnWorkflowResolver::MIXED) {
            $add('MIXED_WORKFLOW_HISTORY', self::PROVEN,
                'The sale carries both POS-origin evidence and standard returns. New entitlement, financial and archive actions are blocked; history is not rewritten.',
                ['archived' => ! empty($sale->archived_at)]);
        }

        if ($class === SalesReturnWorkflowResolver::STANDARD && ! empty($sale->archived_at)) {
            $coverage = $this->sync->calculateEffectiveStandardReturnCoverage((int) $sale->id);
            $returnsOfSale = SaleReturn::withArchived()->where('sale_id', $sale->id)->whereNull('pos_return_id')->get();
            if ($returnsOfSale->isNotEmpty() && ! $coverage['fully_covered']) {
                $newPolicy = $returnsOfSale->contains(fn ($r) => (bool) $r->custody_policy_version);
                $add('PREMATURE_ARCHIVAL', $newPolicy ? self::PROVEN : self::SUSPECTED,
                    'The sale is archived/returned but final cash-return coverage of its original dispatch lines is not full.'
                    . ($newPolicy ? '' : ' It was archived under earlier rules, so this may have been valid then.'),
                    ['lines' => $coverage['dispatch_lines'], 'pending_contributors' => $coverage['pending_contributors'], 'ambiguous_quantity' => $coverage['ambiguous_quantity']]);
            }
        }

        // Refunds versus payments, standard sales only (POS payment invalidation and rounding have their own rules).
        // Authority is HISTORICAL: a refund can only be proven excessive against everything ever paid. Exceeding only the
        // payments that are active today may just reflect a later, legitimate invalidation, so that is ambiguous.
        if ($class === SalesReturnWorkflowResolver::STANDARD) {
            $everPaid = (float) DB::table('sale_payments')->where('sale_id', $sale->id)->sum('amount');
            $activePaid = (float) DB::table('sale_payments')->where('sale_id', $sale->id)->where('status', 'ACTIVE')->sum('amount');
            $refunded = (float) DB::table('sale_return_payments')->whereIn('sale_return_id', DB::table('sale_returns')->where('sale_id', $sale->id)->select('id'))->sum('amount');
            $evidence = ['ever_paid' => round($everPaid, 2), 'active_payments' => round($activePaid, 2), 'refunded' => round($refunded, 2)];

            if ($refunded - $everPaid > 0.011) {
                $add('REFUNDS_EXCEED_PAYMENTS', self::PROVEN, 'Cash refunded for this sale exceeds everything ever paid on it.', $evidence);
            } elseif ($refunded - $activePaid > 0.011) {
                $add('REFUNDS_EXCEED_ACTIVE_PAYMENTS', self::AMBIGUOUS,
                    'Refunds exceed the payments active today but not the historical payments; a later payment invalidation may explain it. Investigate manually.', $evidence);
            }
        }

        // Aggregate claims (standard sales only: POS corrections mutate quantities under their own model).
        if ($class === SalesReturnWorkflowResolver::STANDARD) {
            foreach ($this->excessClaimFindings($sale) as [$code, $message, $evidence]) {
                $add($code, self::PROVEN, $message, $evidence);
            }
        }

        return $out;
    }

    /**
     * Quantity claimed across returns versus what was actually dispatched, and serials claimed twice.
     * Rejected returns do not claim anything.
     *
     * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function excessClaimFindings(Sale $sale): array
    {
        $out = [];
        $returnIds = SaleReturn::withArchived()->where('sale_id', $sale->id)->whereNull('pos_return_id')
            ->whereRaw('LOWER(COALESCE(approval_status, \'\')) != ?', ['rejected'])->pluck('id');
        if ($returnIds->isEmpty()) {
            return [];
        }

        $details = SaleReturnDetail::query()->whereIn('sale_return_id', $returnIds)->where('quantity', '>', 0)->get();

        $dispatched = DB::table('dispatch_details as dd')
            ->join('dispatches as d', 'd.id', '=', 'dd.dispatch_id')
            ->where('dd.sale_id', $sale->id)->whereRaw('UPPER(d.status) = ?', ['APPROVED'])->where('dd.dispatched_quantity', '>', 0)
            ->pluck('dd.dispatched_quantity', 'dd.id');

        foreach ($details->whereNotNull('dispatch_detail_id')->groupBy('dispatch_detail_id') as $lineId => $rows) {
            $claimed = round((float) $rows->sum('quantity'), 3);
            $limit = (float) ($dispatched[$lineId] ?? 0);
            if ($claimed - $limit > 0.0004) {
                $out[] = ['EXCESS_CLAIM_ON_DISPATCH_LINE',
                    "Dispatch line {$lineId}: returns claim more than was dispatched" . ($limit <= 0 ? ' (the line is not an approved dispatch)' : '') . '.',
                    ['dispatch_detail_id' => (int) $lineId, 'dispatched' => $limit, 'claimed' => $claimed, 'return_ids' => $rows->pluck('sale_return_id')->unique()->values()->all()]];
            }
        }

        $bySerial = [];
        foreach ($details as $detail) {
            foreach (collect($detail->serial_number_ids ?? [])->map(fn ($id) => (int) $id)->filter() as $serialId) {
                $bySerial[$serialId][(int) $detail->sale_return_id] = true;
            }
        }
        foreach ($bySerial as $serialId => $returns) {
            if (count($returns) > 1) {
                $out[] = ['SERIAL_CLAIMED_BY_SEVERAL_RETURNS', "Serial {$serialId} is claimed by more than one return.",
                    ['serial_id' => $serialId, 'return_ids' => array_keys($returns)]];
            }
        }

        return $out;
    }
}

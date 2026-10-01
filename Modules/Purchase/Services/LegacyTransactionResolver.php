<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Collection;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;

/**
 * Resolves legacy receiving details to their original BUY transactions
 * using evidence-based matching when the durable provenance link
 * (transactions.received_note_detail_id) is not available.
 */
class LegacyTransactionResolver
{
    const RESULT_MATCHED = 'matched';
    const RESULT_MISSING = 'missing';
    const RESULT_AMBIGUOUS = 'ambiguous';

    /**
     * Resolve the original BUY transaction for a single receiving detail.
     *
     * @return array{status: string, transaction: ?Transaction, candidates: Collection}
     */
    public function resolve(ReceivedNoteDetail $receivedNoteDetail): array
    {
        $receivedNoteDetail->loadMissing('transaction');
        if ($receivedNoteDetail->transaction) {
            return [
                'status' => self::RESULT_MATCHED,
                'transaction' => $receivedNoteDetail->transaction,
                'candidates' => collect([$receivedNoteDetail->transaction]),
            ];
        }

        // Load required relationships for evidence-based matching
        $receivedNoteDetail->loadMissing([
            'receivedNote.purchase',
            'purchaseDetail.product',
        ]);

        $receivedNote = $receivedNoteDetail->receivedNote;
        $purchaseDetail = $receivedNoteDetail->purchaseDetail;
        $purchase = $receivedNote->purchase;

        if (!$receivedNote || !$purchaseDetail || !$purchase) {
            return [
                'status' => self::RESULT_MISSING,
                'transaction' => null,
                'candidates' => collect(),
            ];
        }

        // Build the evidence-based query:
        // Match by product, setting, location, BUY type, purchase reference in reason,
        // quantity, and approval chronology
        $purchaseReference = $purchase->reference;
        $reasonPattern = '%' . $purchaseReference . '%';

        $candidates = Transaction::query()
            ->where('product_id', $purchaseDetail->product_id)
            ->where('setting_id', $purchase->setting_id)
            ->where('location_id', $receivedNote->location_id)
            ->where('type', 'BUY')
            ->where('reason', 'like', $reasonPattern)
            ->where('quantity', $receivedNoteDetail->quantity_received)
            // Exclude transactions already linked to other receiving details
            ->whereNull('received_note_detail_id')
            ->orderBy('created_at', 'asc')
            ->get();

        return $this->selectFromCandidates($candidates, $receivedNote->approved_at);
    }

    /**
     * Locked variant for execution: the caller passes already-locked receival context (no relation
     * reads), and every matching candidate is locked in deterministic id order before the same
     * uniqueness/chronology rules are applied, so a candidate appearing after a preview is seen.
     *
     * @return array{status: string, transaction: ?Transaction, candidates: Collection}
     */
    public function resolveLocked(
        ReceivedNoteDetail $receivedNoteDetail,
        ?ReceivedNote $receivedNote,
        ?Purchase $purchase,
        ?int $purchaseDetailProductId
    ): array {
        if (!$receivedNote || !$purchase || !$purchaseDetailProductId) {
            return [
                'status' => self::RESULT_MISSING,
                'transaction' => null,
                'candidates' => collect(),
            ];
        }

        $candidates = Transaction::query()
            ->where('product_id', $purchaseDetailProductId)
            ->where('setting_id', $purchase->setting_id)
            ->where('location_id', $receivedNote->location_id)
            ->where('type', 'BUY')
            ->where('reason', 'like', '%' . $purchase->reference . '%')
            ->where('quantity', $receivedNoteDetail->quantity_received)
            ->whereNull('received_note_detail_id')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->sortBy('created_at')
            ->values();

        return $this->selectFromCandidates($candidates, $receivedNote->approved_at);
    }

    /**
     * Uniqueness and approval-chronology rules shared by the preview and locked resolution paths.
     *
     * @return array{status: string, transaction: ?Transaction, candidates: Collection}
     */
    private function selectFromCandidates(Collection $candidates, $approvedAt): array
    {
        if ($candidates->isEmpty()) {
            return [
                'status' => self::RESULT_MISSING,
                'transaction' => null,
                'candidates' => $candidates,
            ];
        }

        if ($candidates->count() === 1) {
            return [
                'status' => self::RESULT_MATCHED,
                'transaction' => $candidates->first(),
                'candidates' => $candidates,
            ];
        }

        // Multiple candidates: try to narrow down by approval chronology
        // If the receiving note has an approved_at timestamp, find the closest transaction
        if ($approvedAt) {
            $byDistance = $candidates->sortBy(function ($txn) use ($approvedAt) {
                return abs($txn->created_at->diffInSeconds($approvedAt));
            })->values();
            $closestCandidate = $byDistance->get(0);
            $secondClosest = $byDistance->get(1);

            // Only accept if there's clear separation (closest is within 60 seconds,
            // next candidate is significantly further)
            if ($closestCandidate && $secondClosest) {
                $closestDiff = abs($closestCandidate->created_at->diffInSeconds($approvedAt));
                $secondDiff = abs($secondClosest->created_at->diffInSeconds($approvedAt));

                if ($closestDiff <= 60 && $secondDiff > 300) {
                    return [
                        'status' => self::RESULT_MATCHED,
                        'transaction' => $closestCandidate,
                        'candidates' => $candidates,
                    ];
                }
            }
        }

        // Still ambiguous
        return [
            'status' => self::RESULT_AMBIGUOUS,
            'transaction' => null,
            'candidates' => $candidates,
        ];
    }

    /**
     * Resolve transactions for multiple receiving details in a batch.
     *
     * @param Collection<ReceivedNoteDetail> $receivedNoteDetails
     * @return array{all_matched: bool, results: array}
     */
    public function resolveAll(Collection $receivedNoteDetails): array
    {
        $results = [];
        $allMatched = true;

        foreach ($receivedNoteDetails as $detail) {
            $result = $this->resolve($detail);
            $results[$detail->id] = $result;

            if ($result['status'] !== self::RESULT_MATCHED) {
                $allMatched = false;
            }
        }

        return [
            'all_matched' => $allMatched,
            'results' => $results,
        ];
    }
}

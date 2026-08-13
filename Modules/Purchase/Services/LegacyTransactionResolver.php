<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Collection;
use Modules\Product\Entities\Transaction;
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
        if ($receivedNote->approved_at) {
            $approvedAt = $receivedNote->approved_at;

            // Find candidate created closest to approval time (within a reasonable window)
            $closestCandidate = $candidates->sortBy(function ($txn) use ($approvedAt) {
                return abs($txn->created_at->diffInSeconds($approvedAt));
            })->first();

            $secondClosest = $candidates->sortBy(function ($txn) use ($approvedAt) {
                return abs($txn->created_at->diffInSeconds($approvedAt));
            })->skip(1)->first();

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

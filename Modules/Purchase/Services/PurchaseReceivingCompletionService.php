<?php

namespace Modules\Purchase\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\PurchaseReceivingCompletion;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;

class PurchaseReceivingCompletionService
{
    public function __construct(
        private PurchaseNormalizer $normalizer,
    ) {}

    /**
     * Build normalizer input for a retained detail, proportionally scaling persisted monetary values.
     * Retains the line's tax identity and proportionally reduces subtotal and tax amount based on
     * approved received quantity relative to original ordered quantity.
     */
    private function buildRetainedLineInput(PurchaseDetail $detail, float $approvedQty): array
    {
        $quantityRatio = $approvedQty / $detail->quantity;

        return [
            'id' => $detail->product_id,
            'product_id' => $detail->product_id,
            'product_name' => $detail->product_name,
            'product_code' => $detail->product_code,
            'quantity' => $approvedQty,
            'unit_price' => $detail->unit_price,
            'price' => $detail->price,
            'discount' => $detail->product_discount_amount,
            'discount_type' => $detail->product_discount_type,
            'tax_id' => $detail->tax_id,
            // Scale persisted monetary values proportionally
            'sub_total' => round($detail->sub_total * $quantityRatio, 2),
            'product_tax_amount' => round($detail->product_tax_amount * $quantityRatio, 2),
        ];
    }

    /**
     * Preview the completion outcome without mutations.
     * Aggregates approved received quantities and calculates final state.
     */
    public function preview(Purchase $purchase): array
    {
        $purchase->load('tenantSetting');
        $this->validateEligibility($purchase);

        $approvedReceivedByDetail = $this->aggregateApprovedReceivedQuantities($purchase);

        $originalLines = $this->captureOriginalLines($purchase);
        $retained = [];
        $removed = [];

        foreach ($purchase->purchaseDetails as $detail) {
            $approvedQty = $approvedReceivedByDetail[$detail->id] ?? 0;
            if ($approvedQty > 0) {
                $retained[] = [
                    'po_detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'product_name' => $detail->product_name,
                    'original_quantity' => $detail->quantity,
                    'received_quantity' => $approvedQty,
                    'retained_quantity' => $approvedQty,
                ];
            } else {
                $removed[] = [
                    'po_detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'product_name' => $detail->product_name,
                    'original_quantity' => $detail->quantity,
                ];
            }
        }

        $detailInputs = $purchase->purchaseDetails->map(function (PurchaseDetail $detail) use ($approvedReceivedByDetail) {
            $approvedQty = $approvedReceivedByDetail[$detail->id] ?? 0;
            if ($approvedQty > 0) {
                return $this->buildRetainedLineInput($detail, $approvedQty);
            }
            return null;
        })->filter()->values()->all();

        $finalized = $this->normalizer->normalize(
            [
                'discount_percentage' => $purchase->discount_percentage,
                'discount_amount' => $purchase->discount_amount,
                'shipping_amount' => $purchase->shipping_amount,
                'tax_percentage' => $purchase->tax_percentage,
                'tax_id' => $purchase->tax_id,
            ],
            $detailInputs,
            (bool)$purchase->tenantSetting->is_pkp
        );

        $finalTotalAmount = $finalized['header']['total_amount'];
        $activePaidAmount = $this->calculateActivePaidAmount($purchase);

        return [
            'purchase_id' => $purchase->id,
            'original' => [
                'lines' => $originalLines,
                'total_amount' => $purchase->total_amount,
                'tax_amount' => $purchase->tax_amount,
                'discount_amount' => $purchase->discount_amount,
            ],
            'retained' => $retained,
            'removed' => $removed,
            'final' => [
                'total_amount' => $finalTotalAmount,
                'tax_amount' => $finalized['header']['tax_amount'],
                'discount_amount' => $finalized['header']['discount_amount'],
                'due_amount' => $finalized['header']['due_amount'],
            ],
            'payment_analysis' => [
                'active_paid_amount' => $activePaidAmount,
                'is_overpaid' => $activePaidAmount > $finalTotalAmount,
                'overage_amount' => max(0, $activePaidAmount - $finalTotalAmount),
            ],
        ];
    }

    /**
     * Execute the completion: lock, normalize, persist audit, update status.
     */
    public function complete(Purchase $purchase, string $reason, int $actorUserId): PurchaseReceivingCompletion
    {
        // Defense in depth: verify active-setting ownership before transaction
        if ((int) $purchase->setting_id !== (int) session('setting_id')) {
            throw new Exception('Purchase does not belong to the active setting');
        }

        return DB::transaction(function () use ($purchase, $reason, $actorUserId) {
            // Lock all affected resources (including archived purchases for validation)
            $purchase = Purchase::withArchived()
                ->with('tenantSetting')
                ->where('id', $purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateEligibility($purchase);

            // Lock detail rows
            PurchaseDetail::query()
                ->where('purchase_id', $purchase->id)
                ->lockForUpdate()
                ->get();

            // Lock received notes and details
            ReceivedNote::query()
                ->where('po_id', $purchase->id)
                ->lockForUpdate()
                ->get();

            ReceivedNoteDetail::query()
                ->whereIn('received_note_id', function ($q) use ($purchase) {
                    $q->select('id')->from('received_notes')->where('po_id', $purchase->id);
                })
                ->lockForUpdate()
                ->get();

            // Lock active payments
            PurchasePayment::query()
                ->where('purchase_id', $purchase->id)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->get();

            // Capture original state
            $originalSnapshot = $this->captureOriginalSnapshot($purchase);

            // Get approved received quantities
            $approvedReceivedByDetail = $this->aggregateApprovedReceivedQuantities($purchase);
            $activePaidAmount = $this->calculateActivePaidAmount($purchase);

            // Build normalized detail inputs first with updated quantities, tracking position for accurate mapping
            $detailInputsForNormalization = [];
            $positionToDetailId = [];
            foreach ($purchase->purchaseDetails as $detail) {
                $approvedQty = $approvedReceivedByDetail[$detail->id] ?? 0;

                if ($approvedQty > 0) {
                    $positionToDetailId[count($detailInputsForNormalization)] = $detail->id;
                    $detailInputsForNormalization[] = $this->buildRetainedLineInput($detail, $approvedQty);
                }
            }

            // Normalize with the updated quantities
            $finalized = $this->normalizer->normalize(
                [
                    'discount_percentage' => $purchase->discount_percentage,
                    'discount_amount' => $purchase->discount_amount,
                    'shipping_amount' => $purchase->shipping_amount,
                    'tax_percentage' => $purchase->tax_percentage,
                    'tax_id' => $purchase->tax_id,
                ],
                $detailInputsForNormalization,
                (bool)$purchase->tenantSetting->is_pkp
            );

            $finalTotalAmount = $finalized['header']['total_amount'];

            // Now update/delete detail rows and persist normalized fields
            $detailIdToNormalizedValues = [];
            foreach ($finalized['details'] as $position => $normalizedDetail) {
                // Map back to the original detail by position
                if (isset($positionToDetailId[$position])) {
                    $detailIdToNormalizedValues[$positionToDetailId[$position]] = $normalizedDetail;
                }
            }

            foreach ($purchase->purchaseDetails as $detail) {
                $approvedQty = $approvedReceivedByDetail[$detail->id] ?? 0;

                if ($approvedQty > 0) {
                    $normalizedDetail = $detailIdToNormalizedValues[$detail->id] ?? null;
                    $detail->update([
                        'quantity' => $approvedQty,
                        'unit_price' => $normalizedDetail['unit_price'] ?? $detail->unit_price,
                        'price' => $normalizedDetail['price'] ?? $detail->price,
                        'product_discount_amount' => $normalizedDetail['product_discount_amount'] ?? $detail->product_discount_amount,
                        'product_discount_type' => $normalizedDetail['product_discount_type'] ?? $detail->product_discount_type,
                        'sub_total' => $normalizedDetail['sub_total'] ?? $detail->sub_total,
                        'product_tax_amount' => $normalizedDetail['product_tax_amount'] ?? $detail->product_tax_amount,
                        'tax_id' => $normalizedDetail['tax_id'] ?? null,
                    ]);
                } else {
                    if (!$this->hasReceivingHistory($detail)) {
                        $detail->delete();
                    }
                }
            }

            // Refresh purchase to get updated details
            $purchase->refresh();

            // Reject if active paid exceeds normalized total
            if ($activePaidAmount > $finalTotalAmount) {
                throw new Exception("Normalized total ({$finalTotalAmount}) would be overpaid ({$activePaidAmount}). Resolve payment manually.");
            }

            // Update purchase with normalized values and RECEIVED status
            $purchase->update([
                'status' => Purchase::STATUS_RECEIVED,
                'total_amount' => $finalTotalAmount,
                'tax_amount' => $finalized['header']['tax_amount'],
                'discount_amount' => $finalized['header']['discount_amount'],
                'tax_percentage' => $finalized['header']['tax_percentage'],
                'tax_id' => $finalized['header']['tax_id'],
                'paid_amount' => $activePaidAmount,
                'due_amount' => $finalized['header']['due_amount'],
            ]);

            // Recalculate payment summary from active payments
            $this->recalculatePaymentSummary($purchase);

            // Capture final snapshot
            $finalSnapshot = $this->captureFinalSnapshot($purchase);

            // Create audit record
            return PurchaseReceivingCompletion::create([
                'setting_id' => $purchase->setting_id,
                'purchase_id' => $purchase->id,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'source_snapshot' => $originalSnapshot,
                'final_snapshot' => $finalSnapshot,
                'financial_before_after' => [
                    'before' => [
                        'total_amount' => $originalSnapshot['total_amount'],
                        'tax_amount' => $originalSnapshot['tax_amount'],
                        'discount_amount' => $originalSnapshot['discount_amount'],
                        'paid_amount' => $originalSnapshot['paid_amount'],
                        'due_amount' => $originalSnapshot['due_amount'],
                    ],
                    'after' => [
                        'total_amount' => $purchase->total_amount,
                        'tax_amount' => $purchase->tax_amount,
                        'discount_amount' => $purchase->discount_amount,
                        'paid_amount' => $purchase->paid_amount,
                        'due_amount' => $purchase->due_amount,
                    ],
                ],
            ]);
        });
    }

    private function validateEligibility(Purchase $purchase): void
    {
        \Modules\Purchase\Services\PurchaseSourceGuard::assertReceivingAllowed($purchase);

        if ($purchase->archived_at) {
            throw new Exception("Purchase {$purchase->reference} is archived and cannot be completed.");
        }

        if ($purchase->status !== Purchase::STATUS_RECEIVED_PARTIALLY) {
            throw new Exception("Purchase {$purchase->reference} is not in RECEIVED PARTIALLY status. Current: {$purchase->status}");
        }

        $approvedCount = ReceivedNote::query()
            ->where('po_id', $purchase->id)
            ->where('status', ReceivedNote::STATUS_APPROVED)
            ->count();

        if ($approvedCount === 0) {
            throw new Exception("Purchase {$purchase->reference} has no approved received notes.");
        }

        $approvedReceivedByDetail = $this->aggregateApprovedReceivedQuantities($purchase);
        $hasShortfall = false;

        foreach ($purchase->purchaseDetails as $detail) {
            $received = $approvedReceivedByDetail[$detail->id] ?? 0;
            if ($received < $detail->quantity) {
                $hasShortfall = true;
                break;
            }
        }

        if (!$hasShortfall) {
            throw new Exception("Purchase {$purchase->reference} has no outstanding shortfall.");
        }

        $pendingCount = ReceivedNote::query()
            ->where('po_id', $purchase->id)
            ->where('status', ReceivedNote::STATUS_PENDING)
            ->count();

        if ($pendingCount > 0) {
            throw new Exception("Purchase {$purchase->reference} has {$pendingCount} pending received note(s). Approve or reject them first.");
        }
    }

    private function aggregateApprovedReceivedQuantities(Purchase $purchase): array
    {
        $result = [];

        $details = DB::table('received_note_details')
            ->join('received_notes', 'received_notes.id', '=', 'received_note_details.received_note_id')
            ->where('received_notes.po_id', $purchase->id)
            ->where('received_notes.status', ReceivedNote::STATUS_APPROVED)
            ->select('received_note_details.po_detail_id', DB::raw('SUM(received_note_details.quantity_received) as total_qty'))
            ->groupBy('received_note_details.po_detail_id')
            ->get();

        foreach ($details as $row) {
            $result[$row->po_detail_id] = (float) $row->total_qty;
        }

        return $result;
    }

    private function calculateActivePaidAmount(Purchase $purchase): float
    {
        return (float) PurchasePayment::query()
            ->where('purchase_id', $purchase->id)
            ->where('status', 'ACTIVE')
            ->sum('amount');
    }

    private function hasReceivingHistory(PurchaseDetail $detail): bool
    {
        return ReceivedNoteDetail::query()
            ->where('po_detail_id', $detail->id)
            ->exists();
    }

    private function captureOriginalSnapshot(Purchase $purchase): array
    {
        return [
            'reference' => $purchase->reference,
            'status' => $purchase->status,
            'total_amount' => $purchase->total_amount,
            'tax_amount' => $purchase->tax_amount,
            'discount_amount' => $purchase->discount_amount,
            'shipping_amount' => $purchase->shipping_amount,
            'tax_percentage' => $purchase->tax_percentage,
            'paid_amount' => $purchase->paid_amount,
            'due_amount' => $purchase->due_amount,
            'lines' => $this->captureOriginalLines($purchase),
        ];
    }

    private function captureOriginalLines(Purchase $purchase): array
    {
        return $purchase->purchaseDetails->map(fn (PurchaseDetail $d) => [
            'po_detail_id' => $d->id,
            'product_id' => $d->product_id,
            'product_name' => $d->product_name,
            'quantity' => $d->quantity,
            'unit_price' => $d->unit_price,
            'price' => $d->price,
            'discount_amount' => $d->discount_amount,
        ])->all();
    }

    private function captureFinalSnapshot(Purchase $purchase): array
    {
        return [
            'reference' => $purchase->reference,
            'status' => $purchase->status,
            'total_amount' => $purchase->total_amount,
            'tax_amount' => $purchase->tax_amount,
            'discount_amount' => $purchase->discount_amount,
            'shipping_amount' => $purchase->shipping_amount,
            'tax_percentage' => $purchase->tax_percentage,
            'paid_amount' => $purchase->paid_amount,
            'due_amount' => $purchase->due_amount,
            'lines' => $purchase->purchaseDetails->map(fn (PurchaseDetail $d) => [
                'po_detail_id' => $d->id,
                'product_id' => $d->product_id,
                'product_name' => $d->product_name,
                'quantity' => $d->quantity,
                'unit_price' => $d->unit_price,
                'price' => $d->price,
                'discount_amount' => $d->discount_amount,
            ])->all(),
        ];
    }

    private function recalculatePaymentSummary(Purchase $purchase): void
    {
        $totalActive = (float) PurchasePayment::query()
            ->where('purchase_id', $purchase->id)
            ->where('status', 'ACTIVE')
            ->sum('amount');

        $paidAmount = $totalActive;
        $dueAmount = max(0, $purchase->total_amount - $paidAmount);

        // Match existing payment status semantics: UNPAID if zero, PARTIAL if positive but below total, PAID if covers total
        if ($paidAmount == 0) {
            $paymentStatus = \Modules\Purchase\Entities\Purchase::PAYMENT_STATUS_UNPAID;
        } elseif ($paidAmount >= $purchase->total_amount) {
            $paymentStatus = \Modules\Purchase\Entities\Purchase::PAYMENT_STATUS_PAID;
        } else {
            $paymentStatus = \Modules\Purchase\Entities\Purchase::PAYMENT_STATUS_PARTIAL;
        }

        $purchase->update([
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $paymentStatus,
        ]);
    }
}

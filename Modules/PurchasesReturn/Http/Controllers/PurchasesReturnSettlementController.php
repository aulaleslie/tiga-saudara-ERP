<?php

namespace Modules\PurchasesReturn\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnSettlement;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Transaction;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use App\Services\SerialNumberHistoryService;
use Modules\Product\Entities\SerialNumberHistory;
use Brick\Math\BigDecimal;
use Modules\PurchasesReturn\Services\PurchaseReturnQuantityService;

class PurchasesReturnSettlementController extends Controller
{
    protected const PURCHASE_TOTAL_MODE_SUBTOTAL = 'subtotal';
    protected const PURCHASE_TOTAL_MODE_SUBTOTAL_PLUS_TAX = 'subtotal_plus_tax';

    public function index()
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.access'), 403);

        $settlements = PurchaseReturnSettlement::with(['purchaseReturn', 'purchaseReturn.supplier'])
            ->latest()
            ->paginate(10);

        return view('purchasesreturn::settlements.index', compact('settlements'));
    }

    public function store(Request $request, PurchaseReturn $purchaseReturn)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.submit'), 403);
        // TODO: Implement logic
    }

    public function submit(PurchaseReturnSettlement $settlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.submit'), 403);
        // TODO: Implement logic
    }

    public function approve(PurchaseReturnSettlement $settlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.approve'), 403);

        if (\Illuminate\Support\Str::lower($settlement->status) !== 'pending') {
            return back()->with('error', 'Hanya penyelesaian dengan status Pending yang dapat disetujui.');
        }

        $settlement->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Penyelesaian berhasil disetujui.');
    }

    public function reject(Request $request, PurchaseReturnSettlement $settlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.approve'), 403);

        if (\Illuminate\Support\Str::lower($settlement->status) !== 'pending') {
            return back()->with('error', 'Hanya penyelesaian dengan status Pending yang dapat ditolak.');
        }

        $settlement->update([
            'status' => 'rejected',
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'rejection_reason' => $request->input('rejection_reason'),
        ]);

        return back()->with('success', 'Penyelesaian ditolak.');
    }

    public function execute(PurchaseReturnSettlement $settlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.execute'), 403);

        if (\Illuminate\Support\Str::lower($settlement->status) !== 'approved') {
            return back()->with('error', 'Hanya penyelesaian yang disetujui yang dapat dieksekusi.');
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($settlement) {
                $purchaseReturn = $settlement->purchaseReturn->load(['settlementItems.detail', 'purchaseReturnDetails']);
                $hasExecutingState = false;

                foreach ($purchaseReturn->settlementItems as $item) {
                    $this->applySettlementEffect($item);
                    
                    if (strtoupper($item->method) === 'PRODUCT_REPAIR') {
                        $hasExecutingState = true;
                    }
                }

                // Update purchase return header rollup (deprecated logic but keeping for compatibility)
                $totalSettled = $purchaseReturn->settlementItems->sum(function($item) {
                    return $item->getEffectiveNominal();
                });
                
                $isFullySettled = $totalSettled >= $purchaseReturn->total_amount;
                $purchaseReturn->update([
                    'payment_status' => $isFullySettled ? 'Paid' : 'Partial',
                    'paid_amount'    => $totalSettled,
                    'settled_at'     => $isFullySettled ? now() : null,
                    'settled_by'     => $isFullySettled ? auth()->id() : null,
                    'status'         => $isFullySettled ? PurchaseReturn::STATUS_COMPLETED : $purchaseReturn->status,
                ]);

                $newSettlementStatus = $hasExecutingState ? 'executing' : 'completed';
                $settlement->update(['status' => $newSettlementStatus]);
            });

            return back()->with('success', 'Penyelesaian berhasil dieksekusi.');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Settlement execution failed: ' . $e->getMessage(), [
                'settlement_id' => $settlement->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Terjadi kesalahan saat mengeksekusi penyelesaian: ' . $e->getMessage());
        }
    }

    /**
     * Approve a single item settlement.
     */
    public function approveItemSettlement(Request $request, \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $itemSettlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.approve'), 403);


        $itemSettlement->load(['detail', 'serialNumber', 'targetPurchase', 'purchaseReturn']);

        if (!$itemSettlement->canApprove()) {
            return back()->with('error', 'Item ini tidak dapat disetujui.');
        }

        if (in_array(strtoupper($itemSettlement->method), ['CREDIT', 'MODIFY_PURCHASE'], true)) {
            $request->validate([
                'approval_note' => 'nullable|string|max:255',
                'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            ]);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $itemSettlement) {
                // Validation at approval time
                $nominal = $itemSettlement->getEffectiveNominal();
                // Only an explicitly targeted MODIFY_PURCHASE settlement is priced from the
                // target purchase and so may legitimately exceed the return line's subtotal.
                // The detail's po_id is the line's originating purchase, not a chosen target,
                // so it must not widen this exemption to untargeted settlements.
                $isTargetedModify = strtoupper($itemSettlement->method) === 'MODIFY_PURCHASE'
                    && $itemSettlement->target_purchase_id;

                if (!$isTargetedModify) {
                    $maxNominal = (float) ($itemSettlement->detail?->sub_total ?? 0);
                    if ($nominal > $maxNominal + 0.01) { // Small epsilon for float comparison
                        throw new \Exception('Nominal penyelesaian melebihi subtotal item.');
                    }
                }

                if (strtoupper($itemSettlement->method) === 'MODIFY_PURCHASE') {
                    $sourcePurchaseId = $itemSettlement->target_purchase_id ?? $itemSettlement->detail?->po_id;
                    if (!$sourcePurchaseId) {
                        throw new \Exception('Nota pembelian target harus dipilih untuk metode Ubah Nota.');
                    }
                    $purchase = \Modules\Purchase\Entities\Purchase::findOrFail($sourcePurchaseId);
                    if ($nominal > (float) $purchase->total_amount + 0.01) {
                         throw new \Exception('Nominal penyelesaian melebihi total nilai nota pembelian target.');
                    }
                }

                // Apply effects with optional data from request
                $options = [
                    'approval_note' => $request->approval_note,
                    'attachments' => $request->file('attachments'),
                ];
                $this->applySettlementEffect($itemSettlement, $options, $request);

                // Update item status based on method
                $finalStatus = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_APPROVED;
                if (in_array(strtoupper($itemSettlement->method), ['PRODUCT_REPAIR', 'BROKEN_STOCK'])) {
                    $finalStatus = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE;
                }

                $updateResult = $itemSettlement->update([
                    'status' => $finalStatus,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'approval_note' => $request->approval_note,
                ]);

                // Update Purchase Return status roll-up using derived attribute
                $itemsRelation = $itemSettlement->purchaseReturn->settlementItems();
                $purchaseReturn = $itemSettlement->purchaseReturn;
                $purchaseReturn->setRelation('settlementItems', $itemsRelation->get());
                
                $purchaseReturn->update(['status' => $purchaseReturn->unified_status]);
            });

            $purchaseReturn = $itemSettlement->purchaseReturn->fresh(['settlementItems']);
            $hasSubmitted = $purchaseReturn->settlementItems->contains('status', \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_SUBMITTED);
            if (!$hasSubmitted) {
                app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($purchaseReturn, 'settlement');
                app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($purchaseReturn, 'settlement');
            }

            return back()->with('success', 'Item penyelesaian berhasil disetujui.');
        } catch (\Exception $e) {
            \Log::error('Approval failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->with('error', 'Gagal menyetujui item: ' . $e->getMessage());
        }
    }

    /**
     * Reject a single item settlement.
     */
    public function rejectItemSettlement(Request $request, \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $itemSettlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.approve'), 403);

        $itemSettlement->load(['purchaseReturn']);

        if (!$itemSettlement->canApprove()) {
            return back()->with('error', 'Item ini tidak dapat ditolak.');
        }

        $request->validate([
            'rejection_reason' => 'required|string|max:255',
        ]);

        $itemSettlement->update([
            'status' => \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_REJECTED,
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'rejection_reason' => $request->rejection_reason,
            'method' => null,
            'nominal' => 0,
            'target_purchase_id' => null,
        ]);

        // Update Purchase Return status roll-up
        $purchaseReturn = $itemSettlement->purchaseReturn->load('settlementItems');
        $purchaseReturn->update(['status' => $purchaseReturn->unified_status]);

        $hasSubmitted = $purchaseReturn->settlementItems->contains('status', \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_SUBMITTED);
        if (!$hasSubmitted) {
            app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($purchaseReturn, 'settlement');
        }
        app(\App\Services\Notification\DocumentNotificationService::class)->notifyRevisionNeeded(
            $purchaseReturn,
            $purchaseReturn->reference ?? 'Penyelesaian Retur Pembelian',
            $purchaseReturn->setting_id,
            $request->rejection_reason,
            null,
            'settlement'
        );

        return back()->with('success', 'Item penyelesaian ditolak.');
    }

    /**
     * Receive a single item settlement (for PRODUCT_REPAIR and BROKEN_STOCK methods).
     */
    public function receiveItemSettlement(Request $request, PurchaseReturnItemSettlement $itemSettlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.receive'), 403);

        $itemSettlement->load(['detail', 'serialNumber', 'purchaseReturn']);

        if ($itemSettlement->status !== PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE) {
            return back()->with('error', 'Item ini tidak dapat diterima.');
        }

        $method = strtoupper($itemSettlement->method);
        $isSerial = $itemSettlement->serialNumber !== null;

        $rules = [
            'location_id' => 'required|exists:locations,id',
            'note' => 'nullable|string|max:255',
        ];

        if ($method === 'PRODUCT_REPAIR' && $isSerial) {
            $rules['replacement_serial_number'] = 'required|string|max:255';
        }

        $quantityService = app(PurchaseReturnQuantityService::class);

        if ($method === 'BROKEN_STOCK') {
            $expectedQty = $isSerial ? '1' : (string) $quantityService->toBigDecimal($itemSettlement->detail?->quantity ?? 1);
            $rules['received_quantity'] = ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'];
        } else {
            $rules['received_quantity'] = ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'];
        }

        $request->validate($rules);

        if ($method === 'BROKEN_STOCK'
            && $quantityService->toBigDecimal($request->received_quantity)->compareTo($quantityService->toBigDecimal($expectedQty)) !== 0
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'received_quantity' => "Jumlah diterima harus tepat {$expectedQty}.",
            ]);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $itemSettlement, $method, $isSerial) {
                $productId = $itemSettlement->detail?->product_id;
                $sourceLocationId = $itemSettlement->detail?->location_id ?? $itemSettlement->purchaseReturn->location_id; 
                
                if (!$sourceLocationId) {
                    throw new \Exception("Lokasi asal tidak ditemukan untuk pemindahan stok.");
                }

                $targetLocationId = $request->location_id;
                $receivedQty = $request->received_quantity;
                $note = $request->note;
                $userId = auth()->id();

                if ($method === 'PRODUCT_REPAIR') {
                    $isDispatched = \Illuminate\Support\Str::lower($itemSettlement->purchaseReturn->return_dispatch_status ?? '') === 'dispatched';
                    $isTaxable = false;
                    if ($isSerial) {
                        $isTaxable = ($itemSettlement->serialNumber?->tax_id ?? null) !== null;
                    } else {
                        $isTaxable = (float) ($itemSettlement->detail?->product_tax_amount ?? 0) > 0;
                    }

                    if ($isSerial) {
                        $serial = $itemSettlement->serialNumber;

                        // Record that the serial was returned (for history tracking on the original purchase)
                        SerialNumberHistoryService::record(
                            $serial->id,
                            SerialNumberHistory::EVENT_PURCHASE_RETURNED,
                            $sourceLocationId,
                            $itemSettlement
                        );

                        $replacementSerialNumber = trim($request->replacement_serial_number);
                        // Resolve origin purchase from return detail context
                        $originPurchaseId = $itemSettlement->detail?->po_id ? (int) $itemSettlement->detail->po_id : null;
                        $sourceReceivedNoteDetail = $this->resolveReceivedNoteDetailForSerialInPurchaseContext($serial, $originPurchaseId);
                        $sourceReceivedNoteDetailId = $sourceReceivedNoteDetail?->id;
                        $sourcePurchaseId = $sourceReceivedNoteDetail?->purchaseDetail?->purchase_id ?? $originPurchaseId;

                        // Handle serial number replacement logic
                        if ($replacementSerialNumber !== $serial->serial_number) {
                            $replacementRecord = ProductSerialNumber::where('product_id', $productId)
                                ->where('serial_number', $replacementSerialNumber)
                                ->where('id', '!=', $serial->id)
                                ->first();

                            if ($replacementRecord) {
                                $status = strtoupper((string) $replacementRecord->status);
                                if ($status === ProductSerialNumber::STATUS_ACTIVE) {
                                    throw new \Exception("Serial number {$replacementSerialNumber} sudah aktif.");
                                }

                                if ((bool) $replacementRecord->is_in_return_process) {
                                    throw new \Exception("Serial number {$replacementSerialNumber} sedang dalam proses retur.");
                                }

                                if (! in_array($status, [ProductSerialNumber::STATUS_RETURNED, ProductSerialNumber::STATUS_BROKEN, ProductSerialNumber::STATUS_SOLD], true)) {
                                    throw new \Exception("Serial number {$replacementSerialNumber} hanya bisa digunakan jika status RETURNED, BROKEN, atau SOLD.");
                                }
                            }

                            // Mark old serial as returned
                            $serial->update([
                                'status' => ProductSerialNumber::STATUS_RETURNED,
                                'is_in_return_process' => false,
                                'purchase_return_id' => $itemSettlement->purchase_return_id,
                            ]);

                            // Create new record for the replacement (or reactivate if it exists as returned/broken)
                            if ($replacementRecord) {
                                $replacementReceivedNoteDetail = $this->resolveReceivedNoteDetailForSerialInPurchaseContext($replacementRecord, $originPurchaseId);
                                $replacementReceivedNoteDetailId = $replacementReceivedNoteDetail?->id;
                                $replacementPurchaseId = $replacementReceivedNoteDetail?->purchaseDetail?->purchase_id;
                                $isCrossPurchaseMove = $sourcePurchaseId
                                    && $replacementPurchaseId
                                    && (int) $sourcePurchaseId !== (int) $replacementPurchaseId;

                                if ($isCrossPurchaseMove && ! $sourceReceivedNoteDetailId) {
                                    throw new \Exception('Tidak dapat mengaitkan serial pengganti ke nota pembelian saat ini karena sumber serial tidak ditemukan.');
                                }

                                $finalReceivedNoteDetailId = $replacementRecord->received_note_detail_id
                                    ? (int) $replacementRecord->received_note_detail_id
                                    : $sourceReceivedNoteDetailId;
                                if ($isCrossPurchaseMove) {
                                    $finalReceivedNoteDetailId = $sourceReceivedNoteDetailId;
                                }

                                $replacementRecord->update([
                                    'location_id' => $targetLocationId,
                                    'status' => ProductSerialNumber::STATUS_ACTIVE,
                                    'is_broken' => false,
                                    'is_in_return_process' => false,
                                    'purchase_return_id' => null,
                                    'received_note_detail_id' => $finalReceivedNoteDetailId,
                                ]);

                                // Link via pivot for M:N support (in addition to legacy FK for backward compat)
                                if ($sourceReceivedNoteDetail) {
                                    $sourceReceivedNoteDetail->productSerialNumbers()->syncWithoutDetaching([$replacementRecord->id]);
                                }

                                $itemSettlement->replacement_serial_number_id = $replacementRecord->id;

                                // Keep lineage in sync with current purchase context for resolver logic.
                                // Only write RECEIVED when we can resolve a concrete received-note detail.
                                if ($finalReceivedNoteDetailId) {
                                    $finalReceivedNoteDetail = null;
                                    if ($sourceReceivedNoteDetail && (int) $sourceReceivedNoteDetail->id === (int) $finalReceivedNoteDetailId) {
                                        $finalReceivedNoteDetail = $sourceReceivedNoteDetail;
                                    } elseif ($replacementReceivedNoteDetail && (int) $replacementReceivedNoteDetail->id === (int) $finalReceivedNoteDetailId) {
                                        $finalReceivedNoteDetail = $replacementReceivedNoteDetail;
                                    } else {
                                        $finalReceivedNoteDetail = ReceivedNoteDetail::find($finalReceivedNoteDetailId);
                                    }

                                    if ($finalReceivedNoteDetail) {
                                        SerialNumberHistoryService::record(
                                            $replacementRecord->id,
                                            SerialNumberHistory::EVENT_RECEIVED,
                                            $targetLocationId,
                                            $finalReceivedNoteDetail
                                        );
                                    }
                                }

                                SerialNumberHistoryService::record(
                                    $replacementRecord->id,
                                    SerialNumberHistory::EVENT_REPAIR_RECEIVED,
                                    $targetLocationId,
                                    $itemSettlement
                                );
                            } else {
                                $newSerial = ProductSerialNumber::create([
                                    'product_id' => $productId,
                                    'serial_number' => $replacementSerialNumber,
                                    'location_id' => $targetLocationId,
                                    'status' => ProductSerialNumber::STATUS_ACTIVE,
                                    'is_broken' => false,
                                    'is_in_return_process' => false,
                                    'tax_id' => $serial->tax_id, // Preserve tax settings
                                    'received_note_detail_id' => $sourceReceivedNoteDetailId,
                                ]);

                                // Link via pivot for M:N support (in addition to legacy FK for backward compat)
                                if ($sourceReceivedNoteDetail) {
                                    $sourceReceivedNoteDetail->productSerialNumbers()->syncWithoutDetaching([$newSerial->id]);
                                }

                                $itemSettlement->replacement_serial_number_id = $newSerial->id;

                                SerialNumberHistoryService::record(
                                    $newSerial->id,
                                    SerialNumberHistory::EVENT_REPAIR_RECEIVED,
                                    $targetLocationId,
                                    $itemSettlement
                                );
                            }
                        } else {
                            // Same serial number, just reactivate it
                            $serial->update([
                                'location_id' => $targetLocationId,
                                'status' => ProductSerialNumber::STATUS_ACTIVE,
                                'is_broken' => false,
                                'is_in_return_process' => false,
                                'purchase_return_id' => null,
                            ]);
                            $itemSettlement->replacement_serial_number_id = $serial->id;

                            SerialNumberHistoryService::record(
                                $serial->id,
                                SerialNumberHistory::EVENT_REPAIR_RECEIVED,
                                $targetLocationId,
                                $itemSettlement
                            );
                        }
                    } else {
                        // Non-serial repair movement
                        if (! $isDispatched && $sourceLocationId != $targetLocationId) {
                            $this->moveStock($productId, $sourceLocationId, $targetLocationId, $receivedQty, 'RETURN_REPAIR', "Penerimaan perbaikan - dipindah ke lokasi {$targetLocationId}", $itemSettlement->purchaseReturn->setting_id);
                        }
                    }

                    if ($isDispatched) {
                        $this->restoreStock(
                            $productId,
                            $targetLocationId,
                            $receivedQty,
                            'RETURN_REPAIR',
                            "Penerimaan perbaikan - masuk ke lokasi {$targetLocationId}",
                            $itemSettlement->purchaseReturn->setting_id,
                            $isTaxable
                        );
                    }
                } elseif ($method === 'BROKEN_STOCK') {
                    $isDispatched = \Illuminate\Support\Str::lower($itemSettlement->purchaseReturn->return_dispatch_status ?? '') === 'dispatched';

                    if ($isSerial) {
                        $itemSettlement->serialNumber->update([
                            'is_broken' => true, // Ensure marked as broken
                            'status' => ProductSerialNumber::STATUS_BROKEN,
                            'location_id' => $targetLocationId,
                            'is_in_return_process' => false,
                            'purchase_return_id' => null,
                        ]);

                        SerialNumberHistoryService::record(
                            $itemSettlement->serialNumber->id,
                            SerialNumberHistory::EVENT_MARKED_BROKEN,
                            $targetLocationId,
                            $itemSettlement
                        );
                    }
                    
                    $this->breakStock($productId, $sourceLocationId, $targetLocationId, $receivedQty, $isSerial, $isDispatched, $itemSettlement->purchaseReturn->setting_id);
                }

                // Update item settlement status
                $itemSettlement->update([
                    'status' => PurchaseReturnItemSettlement::STATUS_RECEIVED,
                    'received_quantity' => $receivedQty,
                    'received_location_id' => $targetLocationId,
                    'received_note' => $note,
                    'received_by' => $userId,
                    'received_at' => now(),
                ]);

                // Update Purchase Return status roll-up
                $purchaseReturn = $itemSettlement->purchaseReturn->load('settlementItems');
                $purchaseReturn->update(['status' => $purchaseReturn->unified_status]);
            });

            return back()->with('success', 'Item berhasil diterima.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menerima item: ' . $e->getMessage());
        }
    }

    /**
     * Resolve ReceivedNoteDetail for a serial in a specific purchase context (M:N aware).
     * Priority: 1) Pivot filtered by originPurchaseId, 2) Legacy FK, 3) Latest RECEIVED history
     */
    protected function resolveReceivedNoteDetailForSerialInPurchaseContext(
        ProductSerialNumber $serial,
        ?int $originPurchaseId
    ): ?ReceivedNoteDetail {
        // Step 1: Pivot-based resolution filtered by origin purchase context
        if ($originPurchaseId) {
            $serial->loadMissing('receivedNoteDetails.purchaseDetail');
            $match = $serial->receivedNoteDetails
                ->first(fn($rnd) => (int) ($rnd->purchaseDetail?->purchase_id) === $originPurchaseId);
            if ($match) {
                return $match;
            }
        }

        // Step 2: Legacy FK fallback (backward compat)
        if (!empty($serial->received_note_detail_id)) {
            return ReceivedNoteDetail::find($serial->received_note_detail_id);
        }

        // Step 3: Latest RECEIVED history fallback
        $receivedHistory = SerialNumberHistory::query()
            ->where('product_serial_number_id', $serial->id)
            ->where('event_type', SerialNumberHistory::EVENT_RECEIVED)
            ->where('reference_type', ReceivedNoteDetail::class)
            ->whereNotNull('reference_id')
            ->latest('id')
            ->first(['reference_id']);

        if (!$receivedHistory || !$receivedHistory->reference_id) {
            return null;
        }

        return ReceivedNoteDetail::find($receivedHistory->reference_id);
    }

    protected function resolveReceivedNoteDetailIdForSerial(ProductSerialNumber $serial): ?int
    {
        if (! empty($serial->received_note_detail_id)) {
            return (int) $serial->received_note_detail_id;
        }

        $receivedHistory = SerialNumberHistory::query()
            ->where('product_serial_number_id', $serial->id)
            ->where('event_type', SerialNumberHistory::EVENT_RECEIVED)
            ->where('reference_type', ReceivedNoteDetail::class)
            ->whereNotNull('reference_id')
            ->latest('id')
            ->first(['reference_id']);

        if (! $receivedHistory || ! $receivedHistory->reference_id) {
            return null;
        }

        $exists = ReceivedNoteDetail::query()
            ->whereKey($receivedHistory->reference_id)
            ->exists();

        return $exists ? (int) $receivedHistory->reference_id : null;
    }

    protected function resolveOriginPurchaseIdByReceivedDetailId(?int $receivedNoteDetailId): ?int
    {
        if (! $receivedNoteDetailId) {
            return null;
        }

        $receivedDetail = ReceivedNoteDetail::query()
            ->with('purchaseDetail:id,purchase_id')
            ->find($receivedNoteDetailId);

        $purchaseId = $receivedDetail?->purchaseDetail?->purchase_id;

        return $purchaseId ? (int) $purchaseId : null;
    }

    /**
     * Helper to move stock between locations with transaction
     */
    protected function moveStock($productId, $sourceId, $targetId, $qty, $type, $reason, $settingId = null, $isBroken = false)
    {
        $settingId = $settingId ?? session('setting_id');
        
        $sourceStock = ProductStock::where('product_id', $productId)
            ->where('location_id', $sourceId)
            ->lockForUpdate()
            ->first();
        
        if (!$sourceStock) {
            throw new \Exception("Stok tidak ditemukan di lokasi asal.");
        }

        $quantityService = app(PurchaseReturnQuantityService::class);
        $remaining = $quantityService->toBigDecimal($qty);
        $deductedByBucket = [
            'non_tax' => $quantityService->toBigDecimal(0),
            'tax' => $quantityService->toBigDecimal(0),
        ];

        // Priority logic for moving Good or Broken stock
        $sourceBucketNonTax = $isBroken ? 'broken_quantity_non_tax' : 'quantity_non_tax';
        $sourceBucketTax = $isBroken ? 'broken_quantity_tax' : 'quantity_tax';
        $sourceBucketMain = $isBroken ? 'broken_quantity' : 'quantity';

        // 1. Non-tax
        $take = $quantityService->min($remaining, $sourceStock->$sourceBucketNonTax ?? 0);
        if ($take->isPositive()) {
            $sourceStock->decrement($sourceBucketNonTax, $take->toFloat());
            $sourceStock->decrement($sourceBucketMain, $take->toFloat());
            $deductedByBucket['non_tax'] = $take;
            $remaining = $remaining->minus($take);
        }

        // 2. Tax
        if ($remaining->isPositive()) {
            $take = $quantityService->min($remaining, $sourceStock->$sourceBucketTax ?? 0);
            if ($take->isPositive()) {
                $sourceStock->decrement($sourceBucketTax, $take->toFloat());
                $sourceStock->decrement($sourceBucketMain, $take->toFloat());
                $deductedByBucket['tax'] = $take;
                $remaining = $remaining->minus($take);
            }
        }

        if ($remaining->isPositive()) {
             throw new \Exception("Stok tidak mencukupi di lokasi asal.");
        }

        $sourceStock->save();

        $targetStock = ProductStock::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $targetId],
            ['quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]
        );

        $prevTargetQty = (float) $targetStock->$sourceBucketMain;

        // Apply to target
        if ($deductedByBucket['non_tax']->isPositive()) {
            $targetStock->increment($sourceBucketNonTax, $deductedByBucket['non_tax']->toFloat());
            $targetStock->increment($sourceBucketMain, $deductedByBucket['non_tax']->toFloat());
        }
        if ($deductedByBucket['tax']->isPositive()) {
            $targetStock->increment($sourceBucketTax, $deductedByBucket['tax']->toFloat());
            $targetStock->increment($sourceBucketMain, $deductedByBucket['tax']->toFloat());
        }
        $targetStock->save();

        // Create granular transactions
        $product = Product::find($productId);

        foreach ($deductedByBucket as $bucket => $bucketQtyBd) {
            if (! $bucketQtyBd->isPositive()) continue;

            $bucketQty = $bucketQtyBd->toFloat();

            $granularType = $type;
            if ($type === 'RETURN_REPAIR') {
                $granularType = $bucket === 'tax' ? 'PURCHASE_RETURN_GOOD_TAX' : 'PURCHASE_RETURN_GOOD_NON_TAX';
            }

            Transaction::create([
                'product_id' => $productId,
                'setting_id' => $settingId,
                'type' => $granularType,
                'quantity' => -$bucketQty, 
                'current_quantity' => $product?->product_quantity ?? 0,
                'broken_quantity' => $sourceStock->broken_quantity,
                'location_id' => $sourceId,
                'user_id' => auth()->id(),
                'reason' => $reason,
                'previous_quantity' => $product?->product_quantity ?? 0, // Simplified
                'after_quantity' => $product?->product_quantity ?? 0,
                'previous_quantity_at_location' => (float) $sourceStock->$sourceBucketMain + $bucketQty,
                'after_quantity_at_location' => (float) $sourceStock->$sourceBucketMain,
                'quantity_tax' => (!$isBroken && $bucket === 'tax') ? -$bucketQty : 0,
                'quantity_non_tax' => (!$isBroken && $bucket === 'non_tax') ? -$bucketQty : 0,
                'broken_quantity_tax' => ($isBroken && $bucket === 'tax') ? -$bucketQty : 0,
                'broken_quantity_non_tax' => ($isBroken && $bucket === 'non_tax') ? -$bucketQty : 0,
            ]);

            // Gain at target
            Transaction::create([
                'product_id' => $productId,
                'setting_id' => $settingId,
                'type' => $granularType,
                'quantity' => $bucketQty,
                'current_quantity' => $product?->product_quantity ?? 0,
                'broken_quantity' => $targetStock->broken_quantity,
                'location_id' => $targetId,
                'user_id' => auth()->id(),
                'reason' => $reason . " (Diterima)",
                'previous_quantity' => $product?->product_quantity ?? 0,
                'after_quantity' => $product?->product_quantity ?? 0,
                'previous_quantity_at_location' => $prevTargetQty,
                'after_quantity_at_location' => (float) $targetStock->$sourceBucketMain,
                'quantity_tax' => (!$isBroken && $bucket === 'tax') ? $bucketQty : 0,
                'quantity_non_tax' => (!$isBroken && $bucket === 'non_tax') ? $bucketQty : 0,
                'broken_quantity_tax' => ($isBroken && $bucket === 'tax') ? $bucketQty : 0,
                'broken_quantity_non_tax' => ($isBroken && $bucket === 'non_tax') ? $bucketQty : 0,
            ]);
        }
    }

    /**
     * Helper to restore stock after dispatch approval (adds back to target only).
     */
    protected function restoreStock($productId, $targetId, $qty, $type, $reason, $settingId = null, $isTaxable = false): void
    {
        $settingId = $settingId ?? session('setting_id');

        $product = Product::whereKey($productId)->lockForUpdate()->first();
        if (!$product) {
            throw new \Exception("Produk tidak ditemukan.");
        }

        $targetStock = ProductStock::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $targetId],
            ['quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]
        );

        $prevProductQty = (int) $product->product_quantity;
        $prevTargetQty = (int) $targetStock->quantity;

        $targetStock->increment('quantity', $qty);
        if ($isTaxable) {
            $targetStock->increment('quantity_tax', $qty);
        } else {
            $targetStock->increment('quantity_non_tax', $qty);
        }
        $product->increment('product_quantity', $qty);

        Transaction::create([
            'product_id' => $productId,
            'setting_id' => $settingId,
            'type' => $type,
            'quantity' => $qty,
            'current_quantity' => $product->product_quantity,
            'broken_quantity' => $targetStock->broken_quantity,
            'location_id' => $targetId,
            'user_id' => auth()->id(),
            'reason' => $reason,
            'previous_quantity' => $prevProductQty,
            'after_quantity' => $product->product_quantity,
            'previous_quantity_at_location' => $prevTargetQty,
            'after_quantity_at_location' => $targetStock->quantity,
            'quantity_tax' => $isTaxable ? $qty : 0,
            'quantity_non_tax' => $isTaxable ? 0 : $qty,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
    }

    /**
     * Helper to move stock to broken_quantity
     */
    protected function breakStock($productId, $sourceId, $targetId, $qty, $isSerial, $isDispatched = false, $settingId = null)
    {
        $settingId = $settingId ?? session('setting_id');
        $product = Product::find($productId);
        $quantityService = app(PurchaseReturnQuantityService::class);
        $qtyBd = $quantityService->toBigDecimal($qty);

        $deductedBreakdown = [
            'non_tax' => $quantityService->toBigDecimal(0),
            'tax' => $quantityService->toBigDecimal(0),
        ];

        if (!$isDispatched) {
            $sourceStock = ProductStock::where('product_id', $productId)
                ->where('location_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if (!$sourceStock && !$isSerial) {
                throw new \Exception("Stok tidak ditemukan di lokasi asal.");
            }

            $prevSourceQty = $sourceStock?->quantity ?? 0;
            $prevSourceBrokenQty = $sourceStock?->broken_quantity ?? 0;

            $remaining = $qtyBd;
            $deductedByBucket = [
                'broken_non_tax' => $quantityService->toBigDecimal(0),
                'broken_tax' => $quantityService->toBigDecimal(0),
                'good_non_tax' => $quantityService->toBigDecimal(0),
                'good_tax' => $quantityService->toBigDecimal(0),
            ];

            // Priority 1: broken_quantity_non_tax
            $take = $quantityService->min($remaining, $sourceStock->broken_quantity_non_tax ?? 0);
            if ($take->isPositive()) {
                $sourceStock->decrement('broken_quantity_non_tax', $take->toFloat());
                $sourceStock->decrement('broken_quantity', $take->toFloat());
                $deductedByBucket['broken_non_tax'] = $take;
                $deductedBreakdown['non_tax'] = $deductedBreakdown['non_tax']->plus($take);
                $remaining = $remaining->minus($take);
                if ($product) $product->decrement('broken_quantity', $take->toFloat());
            }

            // Priority 2: broken_quantity_tax
            if ($remaining->isPositive()) {
                $take = $quantityService->min($remaining, $sourceStock->broken_quantity_tax ?? 0);
                if ($take->isPositive()) {
                    $sourceStock->decrement('broken_quantity_tax', $take->toFloat());
                    $sourceStock->decrement('broken_quantity', $take->toFloat());
                    $deductedByBucket['broken_tax'] = $take;
                    $deductedBreakdown['tax'] = $deductedBreakdown['tax']->plus($take);
                    $remaining = $remaining->minus($take);
                    if ($product) $product->decrement('broken_quantity', $take->toFloat());
                }
            }

            // Priority 3: quantity_non_tax (Good stock)
            if ($remaining->isPositive()) {
                $take = $quantityService->min($remaining, $sourceStock->quantity_non_tax ?? 0);
                if ($take->isPositive()) {
                    $sourceStock->decrement('quantity_non_tax', $take->toFloat());
                    $sourceStock->decrement('quantity', $take->toFloat());
                    $deductedByBucket['good_non_tax'] = $take;
                    $deductedBreakdown['non_tax'] = $deductedBreakdown['non_tax']->plus($take);
                    $remaining = $remaining->minus($take);
                    if ($product) $product->decrement('product_quantity', $take->toFloat());
                }
            }

            // Priority 4: quantity_tax (Good stock)
            if ($remaining->isPositive()) {
                $take = $quantityService->min($remaining, $sourceStock->quantity_tax ?? 0);
                if ($take->isPositive()) {
                    $sourceStock->decrement('quantity_tax', $take->toFloat());
                    $sourceStock->decrement('quantity', $take->toFloat());
                    $deductedByBucket['good_tax'] = $take;
                    $deductedBreakdown['tax'] = $deductedBreakdown['tax']->plus($take);
                    $remaining = $remaining->minus($take);
                    if ($product) $product->decrement('product_quantity', $take->toFloat());
                }
            }

            if ($remaining->isPositive()) {
                throw new \Exception("Stok tidak mencukupi di lokasi asal (Kurang {$remaining}).");
            }

            if ($sourceStock) $sourceStock->save();

            // Record granular transactions for deduction
            foreach ($deductedByBucket as $source => $bucketQtyBd) {
                if (! $bucketQtyBd->isPositive()) continue;

                $bucketQty = $bucketQtyBd->toFloat();

                $type = match($source) {
                    'broken_non_tax' => 'PURCHASE_RETURN_BROKEN_NON_TAX',
                    'broken_tax' => 'PURCHASE_RETURN_BROKEN_TAX',
                    'good_non_tax' => 'PURCHASE_RETURN_GOOD_NON_TAX',
                    'good_tax' => 'PURCHASE_RETURN_GOOD_TAX',
                };

                Transaction::create([
                    'product_id' => $productId,
                    'setting_id' => $settingId,
                    'type' => $type,
                    'quantity' => -$bucketQty,
                    'current_quantity' => $product?->product_quantity ?? 0,
                    'broken_quantity' => $sourceStock?->broken_quantity ?? 0,
                    'previous_quantity' => $product?->product_quantity + $bucketQty, // Approximation
                    'after_quantity' => $product?->product_quantity ?? 0,
                    'previous_quantity_at_location' => $prevSourceQty,
                    'after_quantity_at_location' => $sourceStock?->quantity ?? 0,
                    'quantity_tax' => $sourceStock?->quantity_tax ?? 0,
                    'quantity_non_tax' => $sourceStock?->quantity_non_tax ?? 0,
                    'broken_quantity_tax' => $sourceStock?->broken_quantity_tax ?? 0,
                    'broken_quantity_non_tax' => $sourceStock?->broken_quantity_non_tax ?? 0,
                    'location_id' => $sourceId,
                    'user_id' => auth()->id(),
                    'reason' => 'Penerimaan barang rusak dari retur pembelian (Keluar)',
                ]);
            }
        } else {
            // Already dispatched. If we don't know the breakdown, we assume non-tax for simplicity OR attempt to resolve.
            // But usually we should have tracked it. For now, prioritize non-tax.
            $deductedBreakdown['non_tax'] = $qtyBd;
        }

        // Add to target Broken
        $targetStock = ProductStock::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $targetId],
            ['quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]
        );

        $prevTargetBrokenQty = $targetStock->broken_quantity;

        // Preserve tax status from deducted breakdown
        if ($deductedBreakdown['non_tax']->isPositive()) {
            $targetStock->increment('broken_quantity', $deductedBreakdown['non_tax']->toFloat());
            $targetStock->increment('broken_quantity_non_tax', $deductedBreakdown['non_tax']->toFloat());
        }
        if ($deductedBreakdown['tax']->isPositive()) {
            $targetStock->increment('broken_quantity', $deductedBreakdown['tax']->toFloat());
            $targetStock->increment('broken_quantity_tax', $deductedBreakdown['tax']->toFloat());
        }
        $targetStock->save();

        if ($product) {
            $product->increment('broken_quantity', $qtyBd->toFloat());
        }

        Transaction::create([
            'product_id' => $productId,
            'setting_id' => $settingId,
            'type' => 'RETURN_BROKEN',
            'quantity' => $qtyBd->toFloat(),
            'current_quantity' => $targetStock->quantity,
            'broken_quantity' => $targetStock->broken_quantity,
            'previous_quantity' => $product?->product_quantity ?? 0,
            'after_quantity' => $product?->product_quantity ?? 0,
            'previous_quantity_at_location' => $targetStock->quantity,
            'after_quantity_at_location' => $targetStock->quantity,
            'quantity_tax' => $targetStock->quantity_tax,
            'quantity_non_tax' => $targetStock->quantity_non_tax,
            'broken_quantity_tax' => $targetStock->broken_quantity_tax,
            'broken_quantity_non_tax' => $targetStock->broken_quantity_non_tax,
            'location_id' => $targetId,
            'user_id' => auth()->id(),
            'reason' => 'Penerimaan barang rusak dari retur pembelian (Masuk)',
        ]);
    }

    /**
     * Helper to apply settlement effect for a single item.
     */
    protected function applySettlementEffect(\Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $item, array $options = [], ?Request $request = null)
    {
        $method = strtoupper($item->method);
        $itemAmount = $item->getEffectiveNominal();
        $purchaseReturn = $item->purchaseReturn;
        $request = $request ?: request(); // Fallback to global request if not provided

        switch ($method) {
            case 'MODIFY_PURCHASE':
                $detail = $item->detail;
                if (! $detail || ! $detail->product_id) {
                    throw new \Exception('Detail retur tidak ditemukan untuk penyesuaian nota pembelian.');
                }

                $sourcePurchaseId = $item->target_purchase_id ?? $detail->po_id;
                if (! $sourcePurchaseId) {
                    throw new \Exception('Nota pembelian target harus dipilih untuk metode Ubah Nota.');
                }

                $purchase = \Modules\Purchase\Entities\Purchase::with('purchaseDetails')
                    ->lockForUpdate()
                    ->findOrFail($sourcePurchaseId);
                \Modules\Purchase\Services\PurchaseSourceGuard::assertReturnAllowed($purchase);
                $purchaseTotalMode = $this->resolvePurchaseTotalMode($purchase);

                $returnQty = $this->resolveReturnQuantity($item, $detail);
                if (! $returnQty->isPositive()) {
                    break;
                }

                $previousTotal = (float) $purchase->total_amount;
                $previousPaidAmount = (float) $purchase->paid_amount;
                $previousDueAmount = $purchase->due_amount !== null
                    ? (float) $purchase->due_amount
                    : max(0, $previousTotal - $previousPaidAmount);

                $serial = null;
                $matchedRnd = null;
                if ($item->product_serial_number_id) {
                    $serial = \Modules\Product\Entities\ProductSerialNumber::with(['receivedNoteDetails.purchaseDetail'])
                        ->lockForUpdate()
                        ->find($item->product_serial_number_id);
                    // Filter pivot by target purchase to get the correct received note detail in purchase context
                    if ($serial) {
                        $targetPurchaseId = (int) $purchase->id;
                        $matchedRnd = $serial->receivedNoteDetails
                            ->first(fn($rnd) => (int) ($rnd->purchaseDetail?->purchase_id) === $targetPurchaseId);
                    }
                }

                if ($serial && $matchedRnd?->purchaseDetail) {
                    $purchaseDetail = $matchedRnd->purchaseDetail;
                    // Verify purchase match (guaranteed by filter above, but defensive check)
                    if ((int) $purchaseDetail->purchase_id !== (int) $purchase->id) {
                        throw new \Exception('Nota pembelian target tidak sesuai dengan asal serial number.');
                    }

                    $this->ensurePurchaseDetailHasQuantity($purchaseDetail, $returnQty);
                    $this->reducePurchaseDetailAmounts($purchaseDetail, $returnQty);
                    $this->reduceReceivedNoteDetailQuantity($matchedRnd, $returnQty);
                } else {
                    $purchaseDetails = $this->orderedPurchaseDetailsForProduct($purchase, $detail->product_id);
                    if ($purchaseDetails->isEmpty()) {
                        throw new \Exception('Produk tidak ditemukan pada nota pembelian target.');
                    }

                    $this->ensurePurchaseDetailsHaveQuantity($purchaseDetails, $returnQty);
                    $this->reducePurchaseDetailCollection($purchaseDetails, $returnQty);
                }

                $this->recalculatePurchaseTotals($purchase, $purchaseTotalMode);

                if ($serial) {
                    $serial->update([
                        'status' => 'returned',
                        'received_note_detail_id' => null,
                        'is_in_return_process' => false,
                        'purchase_return_id' => $purchaseReturn->id,
                    ]);
                }

                // Archival logic: if all items are returned (total qty == 0), archive
                $remainingPurchaseQty = app(PurchaseReturnQuantityService::class)->sum(
                    $purchase->purchaseDetails()->pluck('quantity')
                );
                if (! $remainingPurchaseQty->isPositive()) {
                    $purchase->update([
                        'archived_at' => now(),
                        'archived_by' => auth()->id(),
                        'note' => ($purchase->note ? $purchase->note . "\n" : "") . "Barang sudah diretur {$purchaseReturn->reference}"
                    ]);
                }

                // Payment handling based on surplus vs unpaid amount
                $purchase->refresh();
                $newTotal = (float) $purchase->total_amount;
                $returnAmount = max(0, $previousTotal - $newTotal);
                $unpaidBefore = $previousDueAmount > 0
                    ? $previousDueAmount
                    : max(0, $previousTotal - $previousPaidAmount);

                $hasSurplus = $returnAmount > ($unpaidBefore + 0.01);
                $remainingSurplus = max(0, $previousPaidAmount - $newTotal);

                if ($hasSurplus) {
                    // Invalidate all active source payments when surplus exists
                    \Modules\Purchase\Entities\PurchasePayment::invalidateAllActiveForPurchase(
                        $purchase->id,
                        'MODIFY_PURCHASE_SETTLEMENT',
                        $item->id
                    );
                    // Reset paid amount since payments are invalidated; surplus is handled separately.
                    $purchase->paid_amount = 0;
                    $purchase->save();

                    // Allocate surplus to a target purchase if selected
                    $allocationTargetId = $request->input('allocation_purchase_id');
                    if ($allocationTargetId && (int) $allocationTargetId !== (int) $purchase->id && $remainingSurplus > 0.01) {
                        $targetPurchase = \Modules\Purchase\Entities\Purchase::find($allocationTargetId);
                        if ($targetPurchase) {
                            $targetDue = max(0, (float) $targetPurchase->due_amount);
                            $allocationAmount = min($remainingSurplus, $targetDue);

                            if ($allocationAmount > 0.01) {
                                $allocationPayment = \Modules\Purchase\Entities\PurchasePayment::create([
                                    'purchase_id' => $targetPurchase->id,
                                    'amount' => $allocationAmount,
                                    'date' => now(),
                                    'reference' => 'PAY-RET/' . $purchaseReturn->reference . '/' . time(),
                                    'note' => 'Alokasi dari retur ' . $purchaseReturn->reference . ' (Asal: ' . $purchase->reference . ')',
                                    'payment_method' => 'Settlement Retur',
                                ]);

                                if (! empty($options['attachments'])) {
                                    foreach ($options['attachments'] as $file) {
                                        $allocationPayment->addMedia($file)->toMediaCollection('attachments');
                                    }
                                }

                                $targetPurchase->paid_amount += $allocationAmount;
                                $targetPurchase->due_amount = max(0, $targetPurchase->total_amount - $targetPurchase->paid_amount);
                                if ($targetPurchase->paid_amount <= 0.01) {
                                    $targetPurchase->payment_status = 'Unpaid';
                                } elseif ($targetPurchase->due_amount <= 0.01) {
                                    $targetPurchase->payment_status = 'Paid';
                                } else {
                                    $targetPurchase->payment_status = 'Partial';
                                }
                                $targetPurchase->save();

                                $remainingSurplus -= $allocationAmount;
                            }
                        }
                    }

                    if ($remainingSurplus > 0.01) {
                        session()->flash('warning', 'Masih ada kelebihan bayar sebesar ' . format_currency($remainingSurplus) . '. Silakan atur pengembalian dana manual.');
                    }
                } else {
                    // Keep paid amount and existing payments when no surplus
                    $purchase->paid_amount = $previousPaidAmount;
                }

                // Update Status based on new numbers
                $purchase->due_amount = max(0, $purchase->total_amount - $purchase->paid_amount);
                if ($purchase->paid_amount <= 0.01) {
                    $purchase->payment_status = 'Unpaid';
                } elseif ($purchase->due_amount <= 0.01) {
                    $purchase->payment_status = 'Paid';
                } else {
                    $purchase->payment_status = 'Partial';
                }
                $purchase->save();

                $purchase->refresh();
                $reductionAmount = max(0, $previousTotal - (float) $purchase->total_amount);
                if ($reductionAmount > 0 && (float) $item->nominal !== $reductionAmount && strtoupper($item->method) === 'MODIFY_PURCHASE') {
                    $item->update(['nominal' => $reductionAmount]);
                }

                // Record/Update the usage payment on the purchase return
                $existingPayment = \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::where('purchase_return_id', $purchaseReturn->id)
                    ->where('reference', 'like', 'PAY-RET/%')
                    ->latest()
                    ->first();
                
                if ($existingPayment) {
                    $existingPayment->increment('amount', $itemAmount);
                } else {
                    \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
                        'date' => now(),
                        'reference' => 'PAY-RET/' . $purchaseReturn->reference . '/' . time(),
                        'amount' => $itemAmount,
                        'purchase_return_id' => $purchaseReturn->id,
                        'payment_method' => 'Ubah Nota',
                        'note' => 'Penyesuaian nilai nota pembelian: ' . ($item->detail->product_name ?? 'N/A'),
                    ]);
                }

                // Update Purchase Return financial status
                $purchaseReturn->paid_amount += $itemAmount;
                $purchaseReturn->due_amount = max(0, $purchaseReturn->total_amount - $purchaseReturn->paid_amount);
                if ($purchaseReturn->due_amount <= 0.01) {
                    $purchaseReturn->payment_status = 'Paid';
                } elseif ($purchaseReturn->paid_amount > 0) {
                    $purchaseReturn->payment_status = 'Partial';
                } else {
                    $purchaseReturn->payment_status = 'Unpaid';
                }
                $purchaseReturn->save();

                break;

            case 'CREDIT':
                // 1. Manage SupplierCredit (Source)
                $credit = \Modules\PurchasesReturn\Entities\SupplierCredit::firstOrCreate(
                    [
                        'purchase_return_id' => $purchaseReturn->id,
                        'supplier_id' => $purchaseReturn->supplier_id,
                    ],
                    [
                        'amount' => 0,
                        'remaining_amount' => 0,
                        'status' => 'OPEN',
                        'setting_id' => $purchaseReturn->setting_id,
                    ]
                );
                $credit->increment('amount', $itemAmount);
                $credit->increment('remaining_amount', $itemAmount);

                // 2. Apply to Target Purchase (Usage)
                if ($item->target_purchase_id) {
                    $purchase = \Modules\Purchase\Entities\Purchase::lockForUpdate()->findOrFail($item->target_purchase_id);
                    \Modules\Purchase\Services\PurchaseSourceGuard::assertReturnAllowed($purchase);
                    
                    // Reset payments and set Unpaid if Paid/Partial
                    if (in_array(strtoupper($purchase->payment_status), ['PAID', 'PARTIAL'])) {
                        $purchase->purchasePayments()->delete();
                        $purchase->update([
                            'paid_amount' => 0,
                            'due_amount' => $purchase->total_amount,
                            'payment_status' => 'Unpaid'
                        ]);
                        $purchase->refresh();
                    }

                    // Ticket 4: Cap the credit application at the target's due amount
                    $appliedAmount = min($itemAmount, (float) $purchase->due_amount);
                    if ($appliedAmount <= 0) {
                        break;
                    }

                    // Ticket 6: Create PurchasePayment
                    $payment = \Modules\Purchase\Entities\PurchasePayment::create([
                        'purchase_id' => $purchase->id,
                        'amount' => $appliedAmount,
                        'date' => now(),
                        'reference' => 'PAY/' . $purchase->reference . '/' . time(),
                        'note' => $options['approval_note'] ?? 'Settlement retur: ' . $purchaseReturn->reference,
                        'payment_method' => 'Credit',
                    ]);

                    // Ticket 5: Handle Attachments
                    if (!empty($options['attachments'])) {
                        foreach ($options['attachments'] as $file) {
                             $payment->addMedia($file)->toMediaCollection('attachments');
                        }
                    }

                    // Ticket 6: Update Purchase (paid_amount, payment_status, due_amount)
                    $purchase->increment('paid_amount', $appliedAmount);
                    $this->recalculatePurchaseTotals($purchase);

                    // Ticket 6: Create Credit Application Linkage
                    \Modules\PurchasesReturn\Entities\PurchasePaymentCreditApplication::create([
                        'purchase_payment_id' => $payment->id,
                        'supplier_credit_id' => $credit->id,
                        'amount' => $appliedAmount,
                    ]);

                    // Ticket 6: Decrement remaining credit
                    $credit->decrement('remaining_amount', $appliedAmount);
                    if ($credit->remaining_amount <= 0.01) {
                        $credit->update(['status' => 'closed']);
                    }
                }

                // Record/Update the credit payment on the purchase return
                $existingPayment = \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::where('purchase_return_id', $purchaseReturn->id)
                    ->where('reference', 'like', 'PAY-RET/%')
                    ->latest()
                    ->first();
                
                if ($existingPayment) {
                    $existingPayment->increment('amount', $itemAmount);
                } else {
                    \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
                        'date' => now(),
                        'reference' => 'PAY-RET/' . $purchaseReturn->reference . '/' . time(),
                        'amount' => $itemAmount,
                        'purchase_return_id' => $purchaseReturn->id,
                        'payment_method' => 'Kredit Supplier',
                        'note' => 'Penyelesaian sebagai kredit supplier: ' . ($item->detail->product_name ?? 'N/A'),
                    ]);
                }

                // Update Purchase Return financial status
                $purchaseReturn->paid_amount += $itemAmount;
                $purchaseReturn->due_amount = max(0, $purchaseReturn->total_amount - $purchaseReturn->paid_amount);
                if ($purchaseReturn->due_amount <= 0.01) {
                    $purchaseReturn->payment_status = 'Paid';
                } elseif ($purchaseReturn->paid_amount > 0) {
                    $purchaseReturn->payment_status = 'Partial';
                } else {
                    $purchaseReturn->payment_status = 'Unpaid';
                }
                $purchaseReturn->save();

                break;

            case 'CASH':
                if ($item->target_purchase_id) {
                    $purchase = \Modules\Purchase\Entities\Purchase::with('purchaseDetails')
                        ->lockForUpdate()
                        ->findOrFail($item->target_purchase_id);
                    \Modules\Purchase\Services\PurchaseSourceGuard::assertReturnAllowed($purchase);
                    $purchaseTotalMode = $this->resolvePurchaseTotalMode($purchase);

                    $detail = $item->detail;
                    if (!$detail || !$detail->product_id) {
                        throw new \Exception('Detail retur tidak ditemukan untuk pengembalian tunai.');
                    }

                    $returnQty = $this->resolveReturnQuantity($item, $detail);
                    if ($returnQty->isPositive()) {
                        $previousTotal = (float) $purchase->total_amount;

                        $serial = null;
                        $matchedRnd = null;
                        if ($item->product_serial_number_id) {
                            $serial = \Modules\Product\Entities\ProductSerialNumber::with(['receivedNoteDetails.purchaseDetail'])
                                ->lockForUpdate()
                                ->find($item->product_serial_number_id);
                            // Filter pivot by target purchase to get the correct received note detail in purchase context
                            if ($serial) {
                                $targetPurchaseId = (int) $purchase->id;
                                $matchedRnd = $serial->receivedNoteDetails
                                    ->first(fn($rnd) => (int) ($rnd->purchaseDetail?->purchase_id) === $targetPurchaseId);
                            }
                        }

                        if ($serial && $matchedRnd?->purchaseDetail) {
                            $purchaseDetail = $matchedRnd->purchaseDetail;
                            $this->ensurePurchaseDetailHasQuantity($purchaseDetail, $returnQty);
                            $this->reducePurchaseDetailAmounts($purchaseDetail, $returnQty);
                            $this->reduceReceivedNoteDetailQuantity($matchedRnd, $returnQty);
                        } else {
                            $purchaseDetails = $this->orderedPurchaseDetailsForProduct($purchase, $detail->product_id);
                            if ($purchaseDetails->isEmpty()) {
                                throw new \Exception('Produk tidak ditemukan pada nota pembelian target.');
                            }
                            $this->ensurePurchaseDetailsHaveQuantity($purchaseDetails, $returnQty);
                            $this->reducePurchaseDetailCollection($purchaseDetails, $returnQty);
                        }

                        $this->recalculatePurchaseTotals($purchase, $purchaseTotalMode);

                        // Reset payments and set Unpaid (Cash refund means original payment is returned)
                        if (in_array(strtoupper($purchase->payment_status), ['PAID', 'PARTIAL'])) {
                            $purchase->purchasePayments()->delete();
                            $purchase->update([
                                'paid_amount' => 0,
                                'due_amount' => $purchase->total_amount,
                                'payment_status' => 'Unpaid'
                            ]);
                        }
                    }
                    
                    $purchase->refresh();
                }

                // Record/Update the refund payment on the purchase return regardless of target allocation
                $existingPayment = \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::where('purchase_return_id', $purchaseReturn->id)
                    ->where('reference', 'like', 'PAY-RET/%')
                    ->latest()
                    ->first();
                
                if ($existingPayment) {
                    $existingPayment->increment('amount', $itemAmount);
                } else {
                    \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
                        'date' => now(),
                        'reference' => 'PAY-RET/' . $purchaseReturn->reference . '/' . time(),
                        'amount' => $itemAmount,
                        'purchase_return_id' => $purchaseReturn->id,
                        'payment_method' => 'CASH',
                        'note' => 'Refund tunai dari retur item: ' . ($item->detail->product_name ?? 'N/A'),
                    ]);
                }

                // Update Purchase Return financial status
                $purchaseReturn->paid_amount += $itemAmount;
                $purchaseReturn->due_amount = max(0, $purchaseReturn->total_amount - $purchaseReturn->paid_amount);
                if ($purchaseReturn->due_amount <= 0.01) {
                    $purchaseReturn->payment_status = 'Paid';
                } elseif ($purchaseReturn->paid_amount > 0) {
                    $purchaseReturn->payment_status = 'Partial';
                } else {
                    $purchaseReturn->payment_status = 'Unpaid';
                }
                $purchaseReturn->save();

                break;
                
            case 'PRODUCT_REPAIR':
            case 'BROKEN_STOCK':
                // No immediate financial effect at approval time
                break;
        }
    }

    /**
     * Helper to deduct stock for return (Redundant - handled by dispatch)
     */
    protected function deductStockForReturn(\Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $item, $detail, $purchaseReturn, int $returnQty)
    {
        // NO-OP: Deduction now happens at dispatch approval.
        // Serial status is already updated in applySettlementEffect or lockReturnStock.
        return;
    }

    /**
     * The deterministic allocation order used both to reduce a purchase's matching
     * detail rows on approval and to price a settlement's nominal in the Livewire
     * form (PurchaseReturnSettlementForm::resolveSettlementNominal). A purchase can
     * carry more than one detail row for the same product at different prices (e.g.
     * re-added at a corrected price); without a fixed, shared order the
     * displayed/approved nominal and the amount actually removed from the purchase
     * can diverge. Ordering by id gives both sides the same, stable row sequence.
     */
    protected function orderedPurchaseDetailsForProduct(\Modules\Purchase\Entities\Purchase $purchase, $productId): \Illuminate\Support\Collection
    {
        return app(PurchaseReturnQuantityService::class)->orderedPurchaseDetailsForProduct($purchase, $productId);
    }

    protected function resolveReturnQuantity(\Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $item, $detail): BigDecimal
    {
        $quantityService = app(PurchaseReturnQuantityService::class);

        if ($item->product_serial_number_id) {
            return $quantityService->toBigDecimal(1);
        }

        $available = $quantityService->toBigDecimal($detail->quantity ?? 0);

        return $available->isPositive() ? $available : $quantityService->toBigDecimal(0);
    }

    protected function ensurePurchaseDetailHasQuantity(PurchaseDetail $detail, BigDecimal $returnQty): void
    {
        $quantityService = app(PurchaseReturnQuantityService::class);
        $available = $quantityService->toBigDecimal($detail->quantity);
        if ($quantityService->greaterThan($returnQty, $available)) {
            throw new \Exception('Jumlah retur melebihi kuantitas pesanan pada nota pembelian target.');
        }

        $receivedQty = $quantityService->sum(
            ReceivedNoteDetail::where('po_detail_id', $detail->id)
                ->whereHas('receivedNote', function ($query) {
                    $query->where('status', ReceivedNote::STATUS_APPROVED);
                })
                ->pluck('quantity_received')
        );

        if ($quantityService->greaterThan($returnQty, $receivedQty)) {
            throw new \Exception('Jumlah retur melebihi kuantitas diterima pada nota pembelian target.');
        }
    }

    protected function ensurePurchaseDetailsHaveQuantity($details, BigDecimal $returnQty): void
    {
        $quantityService = app(PurchaseReturnQuantityService::class);
        $totalOrdered = $quantityService->sum($details->pluck('quantity'));
        if ($quantityService->greaterThan($returnQty, $totalOrdered)) {
            throw new \Exception('Jumlah retur melebihi kuantitas pesanan pada nota pembelian target.');
        }

        $detailIds = $details->pluck('id')->values()->all();
        $receivedQty = $quantityService->sum(
            ReceivedNoteDetail::whereIn('po_detail_id', $detailIds)
                ->whereHas('receivedNote', function ($query) {
                    $query->where('status', ReceivedNote::STATUS_APPROVED);
                })
                ->pluck('quantity_received')
        );

        if ($quantityService->greaterThan($returnQty, $receivedQty)) {
            throw new \Exception('Jumlah retur melebihi kuantitas diterima pada nota pembelian target.');
        }
    }

    protected function reducePurchaseDetailAmounts(PurchaseDetail $detail, BigDecimal $returnQty): void
    {
        $quantityService = app(PurchaseReturnQuantityService::class);
        $currentQty = $quantityService->toBigDecimal($detail->quantity);
        if (! $returnQty->isPositive() || ! $currentQty->isPositive()) {
            return;
        }

        $newQty = $currentQty->minus($returnQty);
        if (! $newQty->isPositive()) {
            $newQty = BigDecimal::zero()->toScale(PurchaseReturnQuantityService::QUANTITY_SCALE);
        }

        $scale = 10; // extra precision for the per-unit rate before multiplying back out
        $perUnitSubTotal = BigDecimal::of((string) $detail->sub_total)->dividedBy($currentQty, $scale, \Brick\Math\RoundingMode::HALF_UP);
        $perUnitDiscount = BigDecimal::of((string) $detail->product_discount_amount)->dividedBy($currentQty, $scale, \Brick\Math\RoundingMode::HALF_UP);
        $perUnitTax = BigDecimal::of((string) $detail->product_tax_amount)->dividedBy($currentQty, $scale, \Brick\Math\RoundingMode::HALF_UP);

        $update = [
            'quantity' => (string) $newQty,
            'sub_total' => $perUnitSubTotal->multipliedBy($newQty)->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
            'product_discount_amount' => $perUnitDiscount->multipliedBy($newQty)->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
            'product_tax_amount' => $perUnitTax->multipliedBy($newQty)->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
        ];

        $update += $this->rebaseEnteredUnitSnapshot($detail, $newQty);

        $detail->update($update);
    }

    /**
     * A partial return changes the canonical quantity but the line's entered-unit
     * snapshot (e.g. "2 BOX") is display-only metadata derived from it. Leaving it
     * untouched would show a stale entered quantity against a reduced canonical
     * quantity and subtotal. The entered quantity is rebased proportionally through
     * the line's own conversion factor so it keeps meaning "quantity, in the entered
     * unit, that this canonical amount represents" rather than the original order.
     *
     * When the new canonical quantity cannot be expressed in the entered unit within
     * the supported 3-decimal precision (or the line carries no conversion snapshot
     * at all), the snapshot is invalidated instead of showing a number that silently
     * lost precision: the line falls back to base-unit-only rendering through the
     * existing `effective_*` accessors, which is honest about the return having
     * broken the original entered-unit framing.
     *
     * @return array<string, mixed>
     */
    private function rebaseEnteredUnitSnapshot(PurchaseDetail $detail, BigDecimal $newQty): array
    {
        if ($detail->entered_quantity === null || $detail->conversion_factor === null) {
            return [];
        }

        $factor = BigDecimal::of((string) $detail->conversion_factor);
        if (! $factor->isPositive()) {
            return [];
        }

        if (! $newQty->isPositive()) {
            return $this->invalidatedEnteredUnitSnapshot();
        }

        $rebasedEntered = $newQty->dividedBy($factor, 10, \Brick\Math\RoundingMode::HALF_UP);

        if ($rebasedEntered->stripTrailingZeros()->getScale() > \Modules\Purchase\Services\PurchaseReceivingQuantityService::QUANTITY_SCALE) {
            // Cannot represent the reduced canonical quantity as a clean entered-unit
            // count (e.g. 12.5 PCS / 12 does not land on a supported 3-decimal BOX
            // count). Invalidate the entered-unit snapshot rather than round it.
            return $this->invalidatedEnteredUnitSnapshot();
        }

        return [
            'entered_quantity' => (string) $rebasedEntered->toScale(
                \Modules\Purchase\Services\PurchaseReceivingQuantityService::QUANTITY_SCALE,
                \Brick\Math\RoundingMode::UNNECESSARY
            ),
        ];
    }

    /**
     * Nulls the entire entered-unit snapshot, including the per-entered-unit price
     * and discount rate: once the entered quantity/unit is gone, a leftover per-BOX
     * rate would otherwise be paired against a canonical (PCS) quantity in display.
     *
     * @return array<string, mixed>
     */
    private function invalidatedEnteredUnitSnapshot(): array
    {
        return [
            'entered_quantity' => null,
            'entered_unit_price' => null,
            'entered_product_discount_amount' => null,
            'purchase_unit_id' => null,
            'product_unit_conversion_id' => null,
            'unit_name' => null,
            'conversion_factor' => null,
        ];
    }

    protected function reduceReceivedNoteDetailQuantity(ReceivedNoteDetail $detail, BigDecimal $returnQty): void
    {
        $quantityService = app(PurchaseReturnQuantityService::class);
        $currentQty = $quantityService->toBigDecimal($detail->quantity_received);
        if (! $returnQty->isPositive() || ! $currentQty->isPositive()) {
            return;
        }

        if ($quantityService->greaterThan($returnQty, $currentQty)) {
            throw new \Exception('Jumlah retur melebihi kuantitas diterima pada nota pembelian target.');
        }

        $detail->update([
            'quantity_received' => (string) $currentQty->minus($returnQty),
        ]);
    }

    protected function reducePurchaseDetailCollection($details, BigDecimal $returnQty): void
    {
        $quantityService = app(PurchaseReturnQuantityService::class);
        $remaining = $returnQty;

        foreach ($details as $detail) {
            if (! $remaining->isPositive()) {
                break;
            }

            $available = $quantityService->toBigDecimal($detail->quantity);
            if (! $available->isPositive()) {
                continue;
            }

            $deduct = $quantityService->min($remaining, $available);
            $this->reducePurchaseDetailAmounts($detail, $deduct);
            $this->reduceReceivedQuantitiesForDetail($detail, $deduct);
            $remaining = $remaining->minus($deduct);
        }

        if ($remaining->isPositive()) {
            throw new \Exception('Jumlah retur melebihi kuantitas pesanan pada nota pembelian target.');
        }
    }

    protected function reduceReceivedQuantitiesForDetail(PurchaseDetail $detail, BigDecimal $returnQty): void
    {
        $quantityService = app(PurchaseReturnQuantityService::class);
        $remaining = $returnQty;
        if (! $remaining->isPositive()) {
            return;
        }

        $receivedDetails = ReceivedNoteDetail::where('po_detail_id', $detail->id)
            ->whereHas('receivedNote', function ($query) {
                $query->where('status', ReceivedNote::STATUS_APPROVED);
            })
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($receivedDetails as $receivedDetail) {
            if (! $remaining->isPositive()) {
                break;
            }

            $available = $quantityService->toBigDecimal($receivedDetail->quantity_received);
            if (! $available->isPositive()) {
                continue;
            }

            $deduct = $quantityService->min($remaining, $available);
            $receivedDetail->update([
                'quantity_received' => (string) $available->minus($deduct),
            ]);
            $remaining = $remaining->minus($deduct);
        }

        if ($remaining->isPositive()) {
            throw new \Exception('Jumlah retur melebihi kuantitas diterima pada nota pembelian target.');
        }
    }

    protected function recalculatePurchaseTotals(\Modules\Purchase\Entities\Purchase $purchase, ?string $totalMode = null): void
    {
        $purchase->load('purchaseDetails');

        $subtotal = (float) $purchase->purchaseDetails->sum('sub_total');
        $taxTotal = (float) $purchase->purchaseDetails->sum('product_tax_amount');
        $shipping = (float) $purchase->shipping_amount;

        $effectiveMode = $totalMode ?? $this->resolvePurchaseTotalMode($purchase);
        $baseTotal = $effectiveMode === self::PURCHASE_TOTAL_MODE_SUBTOTAL_PLUS_TAX
            ? ($subtotal + $taxTotal)
            : $subtotal;

        $discountAmount = (float) $purchase->discount_amount;
        if ((float) $purchase->discount_percentage > 0) {
            $discountAmount = round($baseTotal * ((float) $purchase->discount_percentage / 100), 2);
        }

        if ($discountAmount > $baseTotal) {
            $discountAmount = $baseTotal;
        }

        $grandTotal = max($baseTotal - $discountAmount + $shipping, 0);
        $paidAmount = $purchase->getEffectivePaidAmount();
        $dueAmount = max($grandTotal - $paidAmount, 0);
        $paymentStatus = $dueAmount <= 0.01 ? \Modules\Purchase\Entities\Purchase::PAYMENT_STATUS_PAID : ($paidAmount > 0 ? \Modules\Purchase\Entities\Purchase::PAYMENT_STATUS_PARTIAL : \Modules\Purchase\Entities\Purchase::PAYMENT_STATUS_UNPAID);

        $purchase->fill([
            'tax_amount' => $taxTotal,
            'discount_amount' => $discountAmount,
            'total_amount' => $grandTotal,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $paymentStatus,
        ]);
        $purchase->save();
    }

    protected function resolvePurchaseTotalMode(\Modules\Purchase\Entities\Purchase $purchase): string
    {
        $purchase->loadMissing('purchaseDetails');

        $subtotal = (float) $purchase->purchaseDetails->sum('sub_total');
        $taxTotal = (float) $purchase->purchaseDetails->sum('product_tax_amount');
        $totalAmount = (float) $purchase->total_amount;

        if ($this->isApproximatelyEqual($totalAmount, $subtotal)) {
            return self::PURCHASE_TOTAL_MODE_SUBTOTAL;
        }

        if ($this->isApproximatelyEqual($totalAmount, $subtotal + $taxTotal)) {
            return self::PURCHASE_TOTAL_MODE_SUBTOTAL_PLUS_TAX;
        }

        return (bool) $purchase->is_tax_included
            ? self::PURCHASE_TOTAL_MODE_SUBTOTAL
            : self::PURCHASE_TOTAL_MODE_SUBTOTAL_PLUS_TAX;
    }

    protected function isApproximatelyEqual(float $a, float $b, float $epsilon = 0.01): bool
    {
        return abs($a - $b) <= $epsilon;
    }

    public function dispatchStock(PurchaseReturnSettlement $settlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.dispatch'), 403);
        // TODO: Implement logic
    }

    public function receiveStock(Request $request, PurchaseReturnSettlement $settlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.receive'), 403);

        if (\Illuminate\Support\Str::lower($settlement->status) !== 'executing') {
            return back()->with('error', 'Status penyelesaian harus "Executing" untuk menerima barang.');
        }
        $purchaseReturn = $settlement->purchaseReturn;
        if (!$purchaseReturn->return_dispatched_at) {
             return back()->with('error', 'Barang retur harus didispatch terlebih dahulu.');
        }

        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:purchase_return_goods,id',
            'items.*.received_quantity' => 'required|integer|min:1',
            'items.*.note' => 'nullable|string|max:255',
            'items.*.serial_numbers' => 'nullable|array',
        ]);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $settlement, $purchaseReturn) {
                $allReceived = true;

                foreach ($request->items as $itemData) {
                    $good = \Modules\PurchasesReturn\Entities\PurchaseReturnGood::findOrFail($itemData['id']);
                    
                    // Validate quantity cap
                    $remaining = $good->quantity - $good->received_quantity;
                    if ($itemData['received_quantity'] > $remaining) {
                        throw new \Exception("Jumlah yang diterima melebihi sisa yang diharapkan untuk {$good->product_name}");
                    }

                    // Update Good
                    $good->increment('received_quantity', $itemData['received_quantity']);
                    $good->update([
                        'received_at' => now(),
                        'received_by' => auth()->id(),
                        'note' => $itemData['note'] ?? null,
                    ]);

                    // Update Stock
                    if ($good->product_id) {
                        $product = \Modules\Product\Entities\Product::findOrFail($good->product_id);
                        $product->increment('product_quantity', $itemData['received_quantity']);

                        // Handle Serial Numbers
                        if ($good->product->serial_number_required && !empty($itemData['serial_numbers'])) {
                            // If it's a repair, we might be receiving the same serials back. 
                            // If it's a replacement, they are new serials.
                            // Simplified logic: Create new or Activate existing.
                            // Since serials need to be unique per product, we check existence.
                            
                            foreach ($itemData['serial_numbers'] as $sn) {
                                $existingSn = \Modules\Product\Entities\ProductSerialNumber::where('product_id', $product->id)
                                    ->where('serial_number', $sn)
                                    ->first();

                                if ($existingSn) {
                                    // Reactivate/Update
                                    $existingSn->update([
                                        'status' => 'active', 
                                        'location_id' => $purchaseReturn->location_id ?? null // TODO: Update to per-line location in Ticket 3
                                    ]);
                                } else {
                                    // Create New
                                    \Modules\Product\Entities\ProductSerialNumber::create([
                                        'product_id' => $product->id,
                                        'serial_number' => $sn,
                                        'status' => 'active',
                                        'location_id' => $purchaseReturn->location_id ?? null, // TODO: Update to per-line location in Ticket 3
                                    ]);
                                }
                            }
                        }
                    }

                    // Re-check if this item is fully received
                    if ($good->fresh()->received_quantity < $good->quantity) {
                        $allReceived = false;
                    }
                }

                // Check other items in the same return not in this request
                if ($allReceived) {
                    $pendingGoods = $purchaseReturn->goods()->whereRaw('received_quantity < quantity')->exists();
                    if ($pendingGoods) {
                        $allReceived = false;
                    }
                }

                if ($allReceived) {
                    $settlement->update(['status' => 'completed']);
                    $purchaseReturn->update([
                        'status' => 'Completed',
                        'payment_status' => 'Paid', // Assuming exchange is fully settled
                        'paid_amount' => $purchaseReturn->total_amount,
                        'settled_at' => now(),
                        'settled_by' => auth()->id(),
                    ]);
                }
            });

            return back()->with('success', 'Barang pengganti berhasil diterima.');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Receive stock failed: ' . $e->getMessage());
            return back()->with('error', 'Gagal menerima barang: ' . $e->getMessage());
        }
    }
}

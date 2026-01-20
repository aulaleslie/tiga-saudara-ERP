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

class PurchasesReturnSettlementController extends Controller
{
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
                    'status'         => $isFullySettled ? 'Completed' : $purchaseReturn->status,
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

        if (!$itemSettlement->canApprove()) {
            return back()->with('error', 'Item ini tidak dapat disetujui.');
        }

        if (strtoupper($itemSettlement->method) === 'CREDIT') {
            $request->validate([
                'approval_note' => 'nullable|string|max:255',
                'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            ]);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $itemSettlement) {
                // Validation at approval time
                $nominal = $itemSettlement->getEffectiveNominal();
                $maxNominal = (float) ($itemSettlement->detail?->sub_total ?? 0);
                
                if ($nominal > $maxNominal + 0.01) { // Small epsilon for float comparison
                    throw new \Exception('Nominal penyelesaian melebihi subtotal item.');
                }

                if (strtoupper($itemSettlement->method) === 'MODIFY_PURCHASE') {
                    if (!$itemSettlement->target_purchase_id) {
                        throw new \Exception('Nota pembelian target harus dipilih untuk metode Ubah Nota.');
                    }
                    $purchase = \Modules\Purchase\Entities\Purchase::findOrFail($itemSettlement->target_purchase_id);
                    if ($nominal > (float) $purchase->total_amount + 0.01) {
                         throw new \Exception('Nominal penyelesaian melebihi total nilai nota pembelian target.');
                    }
                }

                // Apply effects with optional data from request
                $options = [
                    'approval_note' => $request->approval_note,
                    'attachments' => $request->file('attachments'),
                ];
                $this->applySettlementEffect($itemSettlement, $options);

                // Update item status based on method
                $finalStatus = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_APPROVED;
                if (in_array(strtoupper($itemSettlement->method), ['PRODUCT_REPAIR', 'BROKEN_STOCK'])) {
                    $finalStatus = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE;
                }

                $itemSettlement->update([
                    'status' => $finalStatus,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'approval_note' => $request->approval_note,
                ]);

                // Update Purchase Return status roll-up using derived attribute
                $purchaseReturn = $itemSettlement->purchaseReturn->load('settlementItems');
                $purchaseReturn->update(['status' => $purchaseReturn->settlement_status]);
            });

            return back()->with('success', 'Item penyelesaian berhasil disetujui.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menyetujui item: ' . $e->getMessage());
        }
    }

    /**
     * Reject a single item settlement.
     */
    public function rejectItemSettlement(Request $request, \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $itemSettlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.approve'), 403);

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
        $purchaseReturn->update(['status' => $purchaseReturn->settlement_status]);

        return back()->with('success', 'Item penyelesaian ditolak.');
    }

    /**
     * Receive a single item settlement (for PRODUCT_REPAIR and BROKEN_STOCK methods).
     */
    public function receiveItemSettlement(Request $request, PurchaseReturnItemSettlement $itemSettlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.receive'), 403);

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

        if ($method === 'BROKEN_STOCK') {
            $expectedQty = $isSerial ? 1 : ($itemSettlement->detail?->quantity ?? 1);
            $rules['received_quantity'] = "required|integer|in:{$expectedQty}";
        } else {
            $rules['received_quantity'] = 'required|integer|min:1';
        }

        $request->validate($rules);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $itemSettlement, $method, $isSerial) {
                $productId = $itemSettlement->detail?->product_id;
                $sourceLocationId = $itemSettlement->purchaseReturn->location_id; 
                $targetLocationId = $request->location_id;
                $receivedQty = $request->received_quantity;
                $note = $request->note;
                $userId = auth()->id();

                if ($method === 'PRODUCT_REPAIR') {
                    if ($isSerial) {
                        $oldSerial = $itemSettlement->serialNumber;
                        $replacementSerialNumber = trim($request->replacement_serial_number);
                        
                        if ($replacementSerialNumber === $oldSerial->serial_number) {
                            // Same serial - restore to active
                            $oldSerial->update([
                                'is_in_return_process' => false,
                                'purchase_return_id' => null,
                                'location_id' => $targetLocationId,
                                'status' => 'AVAILABLE',
                            ]);
                            $replacementSerialId = $oldSerial->id;
                        } else {
                            // Different serial - mark old as RETURNED, create new
                            $oldSerial->update([
                                'status' => 'RETURNED',
                                'is_in_return_process' => false,
                                'purchase_return_id' => null,
                                'received_note_detail_id' => null,
                                'dispatch_detail_id' => null,
                            ]);
                            
                            // Uniqueness check for new serial
                            $existingActive = ProductSerialNumber::where('product_id', $productId)
                                ->where('serial_number', $replacementSerialNumber)
                                ->where('status', '!=', 'RETURNED')
                                ->exists();
                            
                            if ($existingActive) {
                                throw new \Exception("Serial number {$replacementSerialNumber} sudah aktif digunakan oleh produk lain.");
                            }
                            
                            $newSerial = ProductSerialNumber::create([
                                'product_id' => $productId,
                                'location_id' => $targetLocationId,
                                'serial_number' => $replacementSerialNumber,
                                'tax_id' => $oldSerial->tax_id, 
                                'status' => 'AVAILABLE',
                                'is_broken' => false,
                                'is_in_return_process' => false,
                            ]);
                            $replacementSerialId = $newSerial->id;
                        }
                        $itemSettlement->replacement_serial_number_id = $replacementSerialId;
                    } else {
                        // Non-serial repair movement
                        if ($sourceLocationId != $targetLocationId) {
                            $this->moveStock($productId, $sourceLocationId, $targetLocationId, $receivedQty, 'RETURN_REPAIR', "Penerimaan perbaikan - dipindah ke lokasi {$targetLocationId}", $itemSettlement->purchaseReturn->setting_id);
                        }
                    }
                } elseif ($method === 'BROKEN_STOCK') {
                    if ($isSerial) {
                        $itemSettlement->serialNumber->update([
                            'is_broken' => true,
                            'status' => 'BROKEN',
                            'location_id' => $targetLocationId,
                            'is_in_return_process' => false,
                            'purchase_return_id' => null,
                        ]);
                    }
                    
                    $this->breakStock($productId, $sourceLocationId, $targetLocationId, $receivedQty, $isSerial, $itemSettlement->purchaseReturn->setting_id);
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
                $purchaseReturn->update(['status' => $purchaseReturn->settlement_status]);
            });

            return back()->with('success', 'Item berhasil diterima.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menerima item: ' . $e->getMessage());
        }
    }

    /**
     * Helper to move stock between locations with transaction
     */
    protected function moveStock($productId, $sourceId, $targetId, $qty, $type, $reason, $settingId = null)
    {
        $settingId = $settingId ?? session('setting_id');
        
        $sourceStock = ProductStock::where('product_id', $productId)
            ->where('location_id', $sourceId)
            ->lockForUpdate()
            ->first();
        
        if (!$sourceStock) {
            throw new \Exception("Stok tidak ditemukan di lokasi asal.");
        }

        $nonTaxDeduct = min($qty, $sourceStock->quantity_non_tax);
        $taxDeduct = max(0, $qty - $nonTaxDeduct);

        if ($taxDeduct > $sourceStock->quantity_tax) {
             throw new \Exception("Stok tidak mencukupi di lokasi asal.");
        }

        $prevSourceQty = $sourceStock->quantity;
        $sourceStock->quantity_non_tax -= $nonTaxDeduct;
        $sourceStock->quantity_tax -= $taxDeduct;
        $sourceStock->quantity = $sourceStock->quantity_tax + $sourceStock->quantity_non_tax;
        $sourceStock->save();

        $targetStock = ProductStock::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $targetId],
            ['quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]
        );

        $prevTargetQty = $targetStock->quantity;
        $targetStock->quantity_non_tax += $nonTaxDeduct;
        $targetStock->quantity_tax += $taxDeduct;
        $targetStock->quantity = $targetStock->quantity_tax + $targetStock->quantity_non_tax;
        $targetStock->save();

        Transaction::create([
            'product_id' => $productId,
            'setting_id' => $settingId,
            'type' => $type,
            'quantity' => -$qty, // Negative for source
            'current_quantity' => $sourceStock->quantity,
            'previous_quantity' => $prevSourceQty,
            'after_quantity' => $sourceStock->quantity,
            'previous_quantity_at_location' => $prevSourceQty,
            'after_quantity_at_location' => $sourceStock->quantity,
            'quantity_tax' => $sourceStock->quantity_tax,
            'quantity_non_tax' => $sourceStock->quantity_non_tax,
            'broken_quantity_tax' => $sourceStock->broken_quantity_tax,
            'broken_quantity_non_tax' => $sourceStock->broken_quantity_non_tax,
            'location_id' => $sourceId,
            'user_id' => auth()->id(),
            'reason' => $reason,
        ]);

        // Record the gain at target location
        Transaction::create([
            'product_id' => $productId,
            'setting_id' => $settingId,
            'type' => $type,
            'quantity' => $qty,
            'current_quantity' => $targetStock->quantity,
            'previous_quantity' => Product::find($productId)?->product_quantity ?? 0,
            'after_quantity' => Product::find($productId)?->product_quantity ?? 0,
            'previous_quantity_at_location' => $prevTargetQty,
            'after_quantity_at_location' => $targetStock->quantity,
            'quantity_tax' => $targetStock->quantity_tax,
            'quantity_non_tax' => $targetStock->quantity_non_tax,
            'broken_quantity_tax' => $targetStock->broken_quantity_tax,
            'broken_quantity_non_tax' => $targetStock->broken_quantity_non_tax,
            'location_id' => $targetId,
            'user_id' => auth()->id(),
            'reason' => $reason . " (Diterima)",
        ]);
    }

    /**
     * Helper to move stock to broken_quantity
     */
    protected function breakStock($productId, $sourceId, $targetId, $qty, $isSerial, $settingId = null)
    {
        $settingId = $settingId ?? session('setting_id');

        $sourceStock = ProductStock::where('product_id', $productId)
            ->where('location_id', $sourceId)
            ->lockForUpdate()
            ->first();

        // For serials, source stock might not strictly need to be here if we only track serial location, 
        // but for counting totals it must be consistent.
        if (!$sourceStock && !$isSerial) {
             throw new \Exception("Stok tidak ditemukan di lokasi asal.");
        }

        $prevSourceQty = $sourceStock?->quantity ?? 0;
        $nonTaxDeduct = min($qty, $sourceStock?->quantity_non_tax ?? 0);
        $taxDeduct = max(0, $qty - $nonTaxDeduct);

        if (!$isSerial && $sourceStock) {
            if ($taxDeduct > $sourceStock->quantity_tax) {
                throw new \Exception("Stok tidak mencukupi di lokasi asal.");
            }
            $sourceStock->quantity_non_tax -= $nonTaxDeduct;
            $sourceStock->quantity_tax -= $taxDeduct;
            $sourceStock->quantity = $sourceStock->quantity_tax + $sourceStock->quantity_non_tax;
            $sourceStock->save();
        }

        $targetStock = ProductStock::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $targetId],
            ['quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]
        );

        $targetStock->broken_quantity_non_tax += $nonTaxDeduct;
        $targetStock->broken_quantity_tax += $taxDeduct;
        $targetStock->broken_quantity = $targetStock->broken_quantity_tax + $targetStock->broken_quantity_non_tax;
        $targetStock->save();

        $product = Product::find($productId);
        if ($product) {
            $product->increment('broken_quantity', $qty);
        }

        Transaction::create([
            'product_id' => $productId,
            'setting_id' => $settingId,
            'type' => 'RETURN_BROKEN',
            'quantity' => -$qty, // Deduction from source
            'current_quantity' => $sourceStock?->quantity ?? 0,
            'broken_quantity' => $sourceStock?->broken_quantity ?? 0,
            'previous_quantity' => $product?->product_quantity ?? 0,
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

        Transaction::create([
            'product_id' => $productId,
            'setting_id' => $settingId,
            'type' => 'RETURN_BROKEN',
            'quantity' => $qty, // Addition to target broken
            'current_quantity' => $targetStock->quantity,
            'broken_quantity' => $targetStock->broken_quantity,
            'previous_quantity' => $product?->product_quantity ?? 0,
            'after_quantity' => $product?->product_quantity ?? 0,
            'previous_quantity_at_location' => $targetStock->quantity - $qty,
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
    protected function applySettlementEffect(\Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $item, array $options = [])
    {
        $method = strtoupper($item->method);
        $itemAmount = $item->getEffectiveNominal();
        $purchaseReturn = $item->purchaseReturn;

        switch ($method) {
            case 'MODIFY_PURCHASE':
                if ($item->target_purchase_id) {
                    $purchase = \Modules\Purchase\Entities\Purchase::with('purchaseDetails')
                        ->lockForUpdate()
                        ->findOrFail($item->target_purchase_id);

                    $detail = $item->detail;
                    if (! $detail || ! $detail->product_id) {
                        throw new \Exception('Detail retur tidak ditemukan untuk penyesuaian nota pembelian.');
                    }

                    $returnQty = $this->resolveReturnQuantity($item, $detail);
                    if ($returnQty <= 0) {
                        break;
                    }

                    $previousTotal = (float) $purchase->total_amount;

                    $serial = null;
                    if ($item->product_serial_number_id) {
                        $serial = \Modules\Product\Entities\ProductSerialNumber::with(['receivedNoteDetail.purchaseDetail'])
                            ->lockForUpdate()
                            ->find($item->product_serial_number_id);
                    }

                    if ($serial && $serial->receivedNoteDetail && $serial->receivedNoteDetail->purchaseDetail) {
                        $purchaseDetail = $serial->receivedNoteDetail->purchaseDetail;
                        if ((int) $purchaseDetail->purchase_id !== (int) $purchase->id) {
                            throw new \Exception('Nota pembelian target tidak sesuai dengan asal serial number.');
                        }

                        $this->ensurePurchaseDetailHasQuantity($purchaseDetail, $returnQty);
                        $this->reducePurchaseDetailAmounts($purchaseDetail, $returnQty);
                        $this->reduceReceivedNoteDetailQuantity($serial->receivedNoteDetail, $returnQty);
                    } else {
                        $purchaseDetails = $purchase->purchaseDetails->where('product_id', $detail->product_id);
                        if ($purchaseDetails->isEmpty()) {
                            throw new \Exception('Produk tidak ditemukan pada nota pembelian target.');
                        }

                        $this->ensurePurchaseDetailsHaveQuantity($purchaseDetails, $returnQty);
                        $this->reducePurchaseDetailCollection($purchaseDetails, $returnQty);
                    }

                    $this->recalculatePurchaseTotals($purchase);

                    // Ticket 4: Reset payments and set Unpaid on MODIFY_PURCHASE approval for paid/partial purchases
                    if (in_array(strtoupper($purchase->payment_status), ['PAID', 'PARTIAL'])) {
                        // Hard delete payments as requested
                        $purchase->purchasePayments()->delete();
                        
                        // Reset amounts and status
                        $purchase->update([
                            'paid_amount' => 0,
                            'due_amount' => $purchase->total_amount,
                            'payment_status' => 'Unpaid'
                        ]);
                    }

                    $purchase->refresh();
                    $reductionAmount = max(0, $previousTotal - (float) $purchase->total_amount);
                    if ($reductionAmount > 0 && (float) $item->nominal !== $reductionAmount) {
                        $item->update(['nominal' => $reductionAmount]);
                    }

                    // Deduct stock
                    $product = \Modules\Product\Entities\Product::find($detail->product_id);
                    if ($product) {
                        $qtyToDeduct = $returnQty;
                        $locationId = $detail->location_id ?? $purchaseReturn->location_id;

                        // Update Product global quantity
                        $previousQtyGlobal = $product->fresh()->product_quantity;
                        $product->decrement('product_quantity', $qtyToDeduct);

                        // Update ProductStock (location specific)
                        $productStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
                            ->where('location_id', $locationId)
                            ->first();

                        $prevQtyAtLocation = 0;
                        if ($productStock) {
                            $prevQtyAtLocation = $productStock->fresh()->quantity;
                            $productStock->decrement('quantity', $qtyToDeduct);
                        }

                        // Handle serial number return
                        $isTaxable = false;
                        if ($item->product_serial_number_id) {
                            $sn = \Modules\Product\Entities\ProductSerialNumber::find($item->product_serial_number_id);
                            if ($sn) {
                                $isTaxable = $sn->tax_id !== null;
                                $sn->update([
                                    'status' => 'RETURNED',
                                    'received_note_detail_id' => null,
                                    'is_in_return_process' => false,
                                    'purchase_return_id' => $purchaseReturn->id,
                                ]);
                            }
                        } elseif ($detail->product_tax_amount > 0) {
                            $isTaxable = true;
                        }

                        // Create transaction record
                        \Modules\Product\Entities\Transaction::create([
                            'product_id' => $product->id,
                            'setting_id' => $purchaseReturn->setting_id,
                            'type' => 'PURCHASE_RETURN',
                            'quantity' => -$qtyToDeduct,
                            'current_quantity' => $product->fresh()->product_quantity,
                            'location_id' => $locationId,
                            'user_id' => auth()->id(),
                            'reason' => "Settlement retur: {$purchaseReturn->reference}",
                            'previous_quantity' => $previousQtyGlobal,
                            'after_quantity' => $product->fresh()->product_quantity,
                            'previous_quantity_at_location' => $prevQtyAtLocation,
                            'after_quantity_at_location' => $productStock ? $productStock->fresh()->quantity : 0,
                            'quantity_tax' => $isTaxable ? -$qtyToDeduct : 0,
                            'quantity_non_tax' => ! $isTaxable ? -$qtyToDeduct : 0,
                            'broken_quantity_tax' => 0,
                            'broken_quantity_non_tax' => 0,
                        ]);
                    }
                }
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
                    
                    // Ticket 6: Create PurchasePayment
                    $payment = \Modules\Purchase\Entities\PurchasePayment::create([
                        'purchase_id' => $purchase->id,
                        'amount' => $itemAmount,
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
                    $purchase->increment('paid_amount', $itemAmount);
                    $this->recalculatePurchaseTotals($purchase);

                    // Ticket 6: Create Credit Application Linkage
                    \Modules\PurchasesReturn\Entities\PurchasePaymentCreditApplication::create([
                        'purchase_payment_id' => $payment->id,
                        'supplier_credit_id' => $credit->id,
                        'amount' => $itemAmount,
                    ]);

                    // Ticket 6: Decrement remaining credit
                    $credit->decrement('remaining_amount', $itemAmount);
                    if ($credit->remaining_amount <= 0.01) {
                        $credit->update(['status' => 'closed']);
                    }
                }
                break;

            case 'CASH':
                // Find or create payment record for this purchase return
                $payment = \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::firstOrCreate(
                    [
                        'purchase_return_id' => $purchaseReturn->id,
                        'payment_method' => 'CASH',
                    ],
                    [
                        'date' => now(),
                        'reference' => 'REF/' . $purchaseReturn->reference . '/CASH',
                        'amount' => 0,
                        'note' => 'SETTLEMENT EXECUTION (CASH REFUND)',
                        'setting_id' => $purchaseReturn->setting_id,
                    ]
                );
                $payment->increment('amount', $itemAmount);
                break;
                
            case 'PRODUCT_REPAIR':
            case 'BROKEN_STOCK':
                // No immediate financial effect at approval time
                break;
        }
    }

    protected function resolveReturnQuantity(\Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $item, $detail): int
    {
        if ($item->product_serial_number_id) {
            return 1;
        }

        return max(0, (int) ($detail->quantity ?? 0));
    }

    protected function ensurePurchaseDetailHasQuantity(PurchaseDetail $detail, int $returnQty): void
    {
        $available = max(0, (int) $detail->quantity);
        if ($returnQty > $available) {
            throw new \Exception('Jumlah retur melebihi kuantitas pesanan pada nota pembelian target.');
        }

        $receivedQty = (int) ReceivedNoteDetail::where('po_detail_id', $detail->id)
            ->whereHas('receivedNote', function ($query) {
                $query->where('status', ReceivedNote::STATUS_APPROVED);
            })
            ->sum('quantity_received');

        if ($returnQty > $receivedQty) {
            throw new \Exception('Jumlah retur melebihi kuantitas diterima pada nota pembelian target.');
        }
    }

    protected function ensurePurchaseDetailsHaveQuantity($details, int $returnQty): void
    {
        $totalOrdered = (int) $details->sum('quantity');
        if ($returnQty > $totalOrdered) {
            throw new \Exception('Jumlah retur melebihi kuantitas pesanan pada nota pembelian target.');
        }

        $detailIds = $details->pluck('id')->values()->all();
        $receivedQty = (int) ReceivedNoteDetail::whereIn('po_detail_id', $detailIds)
            ->whereHas('receivedNote', function ($query) {
                $query->where('status', ReceivedNote::STATUS_APPROVED);
            })
            ->sum('quantity_received');

        if ($returnQty > $receivedQty) {
            throw new \Exception('Jumlah retur melebihi kuantitas diterima pada nota pembelian target.');
        }
    }

    protected function reducePurchaseDetailAmounts(PurchaseDetail $detail, int $returnQty): void
    {
        $currentQty = (int) $detail->quantity;
        if ($returnQty <= 0 || $currentQty <= 0) {
            return;
        }

        $newQty = max(0, $currentQty - $returnQty);
        $perUnitSubTotal = $currentQty > 0 ? ((float) $detail->sub_total / $currentQty) : 0;
        $perUnitDiscount = $currentQty > 0 ? ((float) $detail->product_discount_amount / $currentQty) : 0;
        $perUnitTax = $currentQty > 0 ? ((float) $detail->product_tax_amount / $currentQty) : 0;

        $detail->update([
            'quantity' => $newQty,
            'sub_total' => round($perUnitSubTotal * $newQty, 2),
            'product_discount_amount' => round($perUnitDiscount * $newQty, 2),
            'product_tax_amount' => round($perUnitTax * $newQty, 2),
        ]);
    }

    protected function reduceReceivedNoteDetailQuantity(ReceivedNoteDetail $detail, int $returnQty): void
    {
        $currentQty = (int) $detail->quantity_received;
        if ($returnQty <= 0 || $currentQty <= 0) {
            return;
        }

        if ($returnQty > $currentQty) {
            throw new \Exception('Jumlah retur melebihi kuantitas diterima pada nota pembelian target.');
        }

        $detail->update([
            'quantity_received' => $currentQty - $returnQty,
        ]);
    }

    protected function reducePurchaseDetailCollection($details, int $returnQty): void
    {
        $remaining = $returnQty;

        foreach ($details as $detail) {
            if ($remaining <= 0) {
                break;
            }

            $available = (int) $detail->quantity;
            if ($available <= 0) {
                continue;
            }

            $deduct = min($remaining, $available);
            $this->reducePurchaseDetailAmounts($detail, $deduct);
            $this->reduceReceivedQuantitiesForDetail($detail, $deduct);
            $remaining -= $deduct;
        }

        if ($remaining > 0) {
            throw new \Exception('Jumlah retur melebihi kuantitas pesanan pada nota pembelian target.');
        }
    }

    protected function reduceReceivedQuantitiesForDetail(PurchaseDetail $detail, int $returnQty): void
    {
        $remaining = $returnQty;
        if ($remaining <= 0) {
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
            if ($remaining <= 0) {
                break;
            }

            $available = (int) $receivedDetail->quantity_received;
            if ($available <= 0) {
                continue;
            }

            $deduct = min($remaining, $available);
            $receivedDetail->update([
                'quantity_received' => $available - $deduct,
            ]);
            $remaining -= $deduct;
        }

        if ($remaining > 0) {
            throw new \Exception('Jumlah retur melebihi kuantitas diterima pada nota pembelian target.');
        }
    }

    protected function recalculatePurchaseTotals(\Modules\Purchase\Entities\Purchase $purchase): void
    {
        $purchase->load('purchaseDetails');

        $subtotal = (float) $purchase->purchaseDetails->sum('sub_total');
        $taxTotal = (float) $purchase->purchaseDetails->sum('product_tax_amount');
        $shipping = (float) $purchase->shipping_amount;

        $isTaxIncluded = (bool) $purchase->is_tax_included;
        $baseTotal = $isTaxIncluded ? $subtotal : ($subtotal + $taxTotal);

        $discountAmount = (float) $purchase->discount_amount;
        if ((float) $purchase->discount_percentage > 0) {
            $discountAmount = round($baseTotal * ((float) $purchase->discount_percentage / 100), 2);
        }

        if ($discountAmount > $baseTotal) {
            $discountAmount = $baseTotal;
        }

        $grandTotal = max($baseTotal - $discountAmount + $shipping, 0);
        $paidAmount = (float) $purchase->paid_amount;
        $dueAmount = max($grandTotal - $paidAmount, 0);
        $paymentStatus = $dueAmount <= 0.01 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid');

        $purchase->fill([
            'tax_amount' => $taxTotal,
            'discount_amount' => $discountAmount,
            'total_amount' => $grandTotal,
            'due_amount' => $dueAmount,
            'payment_status' => $paymentStatus,
        ]);
        $purchase->save();
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

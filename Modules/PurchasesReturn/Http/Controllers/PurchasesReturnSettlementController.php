<?php

namespace Modules\PurchasesReturn\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnSettlement;

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
    public function approveItemSettlement(\Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $itemSettlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.approve'), 403);

        if (!$itemSettlement->canApprove()) {
            return back()->with('error', 'Item ini tidak dapat disetujui.');
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($itemSettlement) {
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
                    if ($nominal > (float) $purchase->due_amount + 0.01) {
                         throw new \Exception('Nominal penyelesaian melebihi sisa tagihan nota pembelian target.');
                    }
                }

                // Apply effects
                $this->applySettlementEffect($itemSettlement);

                // Update item status based on method
                $finalStatus = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_APPROVED;
                if (in_array(strtoupper($itemSettlement->method), ['PRODUCT_REPAIR', 'BROKEN_STOCK'])) {
                    $finalStatus = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE;
                }

                $itemSettlement->update([
                    'status' => $finalStatus,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
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
    public function receiveItemSettlement(Request $request, \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $itemSettlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('purchaseReturnSettlements.receive'), 403);

        if ($itemSettlement->status !== \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE) {
            return back()->with('error', 'Item ini tidak dapat diterima.');
        }

        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'received_quantity' => 'required|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $itemSettlement) {
                $method = strtoupper($itemSettlement->method);
                $locationId = $request->location_id;
                $receivedQuantity = $request->received_quantity;
                $note = $request->note;

                // Handle stock movement based on method
                if ($method === 'PRODUCT_REPAIR') {
                    // Move repaired product to selected location
                    if ($itemSettlement->serialNumber) {
                        // Update serial number location
                        $itemSettlement->serialNumber->update([
                            'location_id' => $locationId,
                            'status' => 'AVAILABLE', // Assuming repaired items are available
                        ]);
                    } else {
                        // Handle non-serial stock movement
                        // This would require integration with stock management system
                        // For now, just log the movement
                    }
                } elseif ($method === 'BROKEN_STOCK') {
                    // Mark as broken stock and move to selected location
                    if ($itemSettlement->serialNumber) {
                        $itemSettlement->serialNumber->update([
                            'location_id' => $locationId,
                            'is_broken' => true,
                            'status' => 'BROKEN',
                        ]);
                    } else {
                        // Handle non-serial broken stock
                        // This would require integration with stock management system
                    }
                }

                // Update item settlement status
                $itemSettlement->update([
                    'status' => \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_RECEIVED,
                    'received_quantity' => $receivedQuantity,
                    'received_location_id' => $locationId,
                    'received_note' => $note,
                    'received_by' => auth()->id(),
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
     * Helper to apply settlement effect for a single item.
     */
    protected function applySettlementEffect(\Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement $item)
    {
        $method = strtoupper($item->method);
        $itemAmount = $item->getEffectiveNominal();
        $purchaseReturn = $item->purchaseReturn;

        switch ($method) {
            case 'MODIFY_PURCHASE':
                if ($item->target_purchase_id) {
                    $purchase = \Modules\Purchase\Entities\Purchase::findOrFail($item->target_purchase_id);
                    $amountToReduce = min($itemAmount, (float) $purchase->due_amount);
                    
                    if ($amountToReduce > 0) {
                        $purchase->decrement('due_amount', $amountToReduce);
                        $purchase->increment('paid_amount', $amountToReduce);
                        
                        if ($purchase->fresh()->due_amount <= 0.01) {
                            $purchase->update(['payment_status' => 'Paid']);
                        }
                    }
                }
                break;

            case 'CREDIT':
                // Find or create supplier credit record for this purchase return
                $credit = \Modules\PurchasesReturn\Entities\SupplierCredit::firstOrCreate(
                    [
                        'purchase_return_id' => $purchaseReturn->id,
                        'supplier_id' => $purchaseReturn->supplier_id,
                    ],
                    [
                        'amount' => 0,
                        'remaining_amount' => 0,
                        'status' => 'OPEN',
                    ]
                );
                $credit->increment('amount', $itemAmount);
                $credit->increment('remaining_amount', $itemAmount);
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

<?php

namespace Modules\PurchasesReturn\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnSettlement;

class PurchasesReturnSettlementController extends Controller
{
    public function store(Request $request, PurchaseReturn $purchaseReturn)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.submit'), 403);
        // TODO: Implement logic
    }

    public function submit(PurchaseReturnSettlement $settlement)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.submit'), 403);
        // TODO: Implement logic
    }

    public function approve(PurchaseReturnSettlement $settlement)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.approve'), 403);

        if ($settlement->status !== 'pending') {
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
        abort_if(gate()->denies('purchaseReturnSettlements.approve'), 403);

        if ($settlement->status !== 'pending') {
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
        abort_if(gate()->denies('purchaseReturnSettlements.execute'), 403);

        if ($settlement->status !== 'approved') {
            return back()->with('error', 'Hanya penyelesaian yang disetujui yang dapat dieksekusi.');
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($settlement) {
                $purchaseReturn = $settlement->purchaseReturn;
                $method = \Illuminate\Support\Str::lower($settlement->method);

                if ($method === 'cash') {
                    // Create Payment
                     \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
                        'date'               => now(),
                        'reference'          => 'REF/' . $purchaseReturn->reference . '/SETTLEMENT', // Improved reference
                        'amount'             => $purchaseReturn->total_amount,
                        'purchase_return_id' => $purchaseReturn->id,
                        'payment_method'     => 'Cash',
                        'note'               => 'Settlement execution (Cash)',
                    ]);

                    $purchaseReturn->update([
                        'payment_status' => 'Paid',
                        'paid_amount' => $purchaseReturn->total_amount,
                        'settled_at' => now(),
                        'settled_by' => auth()->id(),
                        'status' => 'Completed',
                    ]);

                    $settlement->update(['status' => 'completed']);

                } elseif ($method === 'deposit') {
                    // Create Supplier Credit
                    \Modules\PurchasesReturn\Entities\SupplierCredit::create([
                        'supplier_id'        => $purchaseReturn->supplier_id,
                        'purchase_return_id' => $purchaseReturn->id,
                        'amount'             => $purchaseReturn->total_amount,
                        'reason'             => 'Purchase Return Settlement ' . $purchaseReturn->reference,
                        'created_by'         => auth()->id(),
                    ]);

                    $purchaseReturn->update([
                        'payment_status' => 'Paid', // Technically settled via credit
                        'paid_amount' => $purchaseReturn->total_amount,
                        'settled_at' => now(),
                        'settled_by' => auth()->id(),
                        'status' => 'Completed',
                    ]);

                    $settlement->update(['status' => 'completed']);

                } elseif ($method === 'exchange') {
                    // Start execution flow for exchange
                    $settlement->update(['status' => 'executing']);
                    
                    // Note: Actual stock movement happens in Dispatch/Receive steps.
                    // We just mark it as started here.
                }
            });

            return back()->with('success', 'Penyelesaian berhasil dieksekusi.');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Settlement execution failed: ' . $e->getMessage());
            return back()->with('error', 'Terjadi kesalahan saat mengeksekusi penyelesaian: ' . $e->getMessage());
        }
    }

    public function dispatchStock(PurchaseReturnSettlement $settlement)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.dispatch'), 403);
        // TODO: Implement logic
    }

    public function receiveStock(PurchaseReturnSettlement $settlement)
    {
        abort_if(gate()->denies('purchaseReturnSettlements.receive'), 403);
        // TODO: Implement logic
    }
}

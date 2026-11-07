<?php

namespace Modules\SalesReturn\Http\Controllers;

use App\Support\SalesReturn\SaleReturnLifecycleSyncService;
use App\Support\SalesReturn\SalesReturnStateException;
use App\Support\SalesReturn\StandardReturnGuard;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\Sale\Entities\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class SalesReturnSettlementController extends Controller
{
    /**
     * Approve a single item settlement.
     */
    public function approveItemSettlement(Request $request, SaleReturnItemSettlement $itemSettlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('saleReturnSettlements.approve'), 403);

        try {
            DB::transaction(function () use ($request, $itemSettlement) {
                $guard = app(StandardReturnGuard::class);
                // Source sale -> header -> settlement row, then re-read state under the locks:
                // a replayed or concurrent approval finds the row no longer SUBMITTED.
                $itemSettlement = $guard->lockSettlement($itemSettlement->id);
                $itemSettlement->load(['detail', 'serialNumber', 'saleReturn']);
                $guard->assertCanSettle($itemSettlement->saleReturn);

                if (! $itemSettlement->canApprove()) {
                    throw new SalesReturnStateException('Item ini tidak dapat disetujui.');
                }

                // Decide behavior based on method
                $method = strtoupper($itemSettlement->method ?? '');

                if ($method === 'CASH_REFUND') {
                    // Debt first, then a positive-only cash payout, with immutable adjustment evidence
                    // and every projection updated in this one transaction (source sale already locked).
                    app(\App\Support\SalesReturn\StandardFinancialService::class)->commit($itemSettlement);
                    $itemSettlement->update([
                        'status' => SaleReturnItemSettlement::STATUS_APPROVED,
                        'approved_by' => Auth::id(),
                        'approved_at' => now(),
                        'approval_note' => $request->approval_note,
                    ]);
                } elseif ($method === 'CUSTOMER_CREDIT' || $method === 'MODIFY_SALE') {
                    // immediate effects on approval
                    $this->applySettlementEffect($itemSettlement, $request);
                    $itemSettlement->update([
                        'status' => SaleReturnItemSettlement::STATUS_APPROVED,
                        'approved_by' => Auth::id(),
                        'approved_at' => now(),
                        'approval_note' => $request->approval_note,
                    ]);
                } else {
                    // REPAIR and UNPROCESSED: defer dispatch/stock effects until dispatch phase
                    $itemSettlement->update([
                        'status' => SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH,
                        'approved_by' => Auth::id(),
                        'approved_at' => now(),
                        'approval_note' => $request->approval_note,
                    ]);
                }

                $saleReturn = $itemSettlement->saleReturn;
                $lifecycleSync = app(SaleReturnLifecycleSyncService::class);
                $actorId = (int) Auth::id();

                $lifecycleSync->syncSaleReturnCompletionRollup($saleReturn, $actorId);
                $lifecycleSync->archiveSourceSaleIfFullyReturnedAndCompleted($saleReturn, $actorId);
            });

            $saleReturn = $itemSettlement->saleReturn->fresh(['settlementItems']);
            $hasSubmitted = $saleReturn->settlementItems->contains('status', \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_SUBMITTED);
            if (!$hasSubmitted) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($saleReturn, 'settlement'));
                \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($saleReturn, 'settlement'));
            }

            return back()->with('success', 'Item penyelesaian berhasil disetujui.');
        } catch (SalesReturnStateException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Sales Return Item Approval failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->with('error', 'Gagal menyetujui item: ' . $e->getMessage());
        }
    }

    /**
     * Reject a single item settlement.
     */
    public function rejectItemSettlement(Request $request, SaleReturnItemSettlement $itemSettlement)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('saleReturnSettlements.approve'), 403);

        $request->validate([
            'rejection_reason' => 'required|string|max:255',
        ]);

        try {
            DB::transaction(function () use ($request, $itemSettlement) {
                $guard = app(StandardReturnGuard::class);
                $itemSettlement = $guard->lockSettlement($itemSettlement->id);
                $itemSettlement->load('saleReturn');
                $guard->assertCanSettle($itemSettlement->saleReturn);

                if (! $itemSettlement->canApprove()) {
                    throw new SalesReturnStateException('Item ini tidak dapat ditolak.');
                }

                $itemSettlement->update([
                    'status' => SaleReturnItemSettlement::STATUS_REJECTED,
                    'rejected_by' => Auth::id(),
                    'rejected_at' => now(),
                    'rejection_reason' => $request->rejection_reason,
                ]);
            });

            $saleReturn = $itemSettlement->saleReturn->fresh(['settlementItems']);
            $hasSubmitted = $saleReturn->settlementItems->contains('status', \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_SUBMITTED);
            if (!$hasSubmitted) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($saleReturn, 'settlement'));
            }
            \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->notifyRevisionNeeded(
                $saleReturn,
                $saleReturn->reference ?? 'Penyelesaian Retur Penjualan',
                $saleReturn->setting_id,
                $request->rejection_reason,
                null,
                'settlement'
            ));

            return back()->with('success', 'Item penyelesaian ditolak.');
        } catch (SalesReturnStateException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Sales Return Item Rejection failed: ' . $e->getMessage());
            return back()->with('error', 'Gagal menolak item.');
        }
    }

    /**
     * Apply the technical effect of a settlement (money, stock, etc.)
     */
    protected function applySettlementEffect(SaleReturnItemSettlement $item, Request $request)
    {
        $method = strtoupper($item->method);
        $nominal = $item->getEffectiveNominal();
        $saleReturn = $item->saleReturn;

        switch ($method) {
            // CASH_REFUND is handled by StandardFinancialService::commit() (debt first, positive-only payout).
            case 'CUSTOMER_CREDIT':
                CustomerCredit::create([
                    'sale_return_id' => $saleReturn->id,
                    'customer_id' => $saleReturn->customer_id,
                    'amount' => $nominal,
                    'status' => 'Available',
                    'sale_return_item_settlement_id' => $item->id,
                ]);
                break;

            case 'MODIFY_SALE':
                if (!$item->target_sale_id) {
                    throw new \Exception('Nota penjualan target harus dipilih.');
                }
                $sale = Sale::findOrFail($item->target_sale_id);
                // logic to deduct from sale
                $sale->update([
                    'total_amount' => $sale->total_amount - $nominal,
                    'due_amount' => $sale->due_amount - $nominal,
                ]);
                break;

            case 'REPAIR':
            case 'UNPROCESSED':
                // Stock effects are usually handled during receiving or already handled 
                // in some other partial. For now we follow Purchase Return where 
                // Repair/Broken Stock might need a receiving step.
                break;
        }
    }
}

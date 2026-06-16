<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Notification;
use App\Services\Notification\StockNotificationService;
use App\Services\Notification\DocumentNotificationService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Illuminate\Support\Str;

class SyncNotificationsCommand extends Command
{
    protected $signature = 'notifications:sync';
    protected $description = 'Repair and sync notification rows for active states (low stock, approvals, revisions)';

    public function handle(StockNotificationService $stockService, DocumentNotificationService $docService)
    {
        $this->info('Starting notification sync...');

        // 1. Resolve stale notifications
        $this->info('Resolving stale notifications...');
        $activeNotifications = Notification::whereNull('resolved_at')->get();

        foreach ($activeNotifications as $notification) {
            $this->checkAndResolve($notification);
        }

        // 2. Stock Notifications
        $this->info('Syncing stock notifications...');
        $products = Product::whereColumn('product_quantity', '<=', 'product_stock_alert')
            ->where('product_stock_alert', '>', 0)
            ->get();
        
        foreach ($products as $product) {
            $stockService->createGlobalStockNotifications($product);
        }

        $stocks = ProductStock::whereHas('product', function ($q) {
            $q->whereColumn('product_stocks.quantity', '<=', 'products.product_stock_alert')
              ->where('products.product_stock_alert', '>', 0);
        })->get();

        foreach ($stocks as $stock) {
            $stockService->createLocationStockNotifications($stock);
        }

        // 3. Document Notifications
        $this->info('Generating missing document notifications...');
        $this->syncDocumentNotifications($docService);

        $this->info('Sync completed successfully.');
    }

    protected function checkAndResolve(Notification $notification)
    {
        if ($notification->category === 'stock') {
            if ($notification->type === 'global_low_stock') {
                $product = Product::find($notification->source_id);
                if (!$product || $product->product_quantity > $product->product_stock_alert) {
                    $notification->update(['resolved_at' => now()]);
                }
            } elseif ($notification->type === 'location_low_stock') {
                $stock = ProductStock::with('product')->find($notification->source_id);
                if (!$stock || !$stock->product || $stock->quantity > $stock->product->product_stock_alert) {
                    $notification->update(['resolved_at' => now()]);
                }
            }
            return;
        }

        $class = $notification->source_type;
        if (!class_exists($class)) return;
        
        $source = $class::find($notification->source_id);

        if (!$source) {
            $notification->update(['resolved_at' => now()]);
            return;
        }

        $categoryParts = explode(':', $notification->category);
        $baseCategory = $categoryParts[0];
        $subCategory = $categoryParts[1] ?? null;

        $isApproval = $baseCategory === 'approval';
        $isRevision = $baseCategory === 'revision';

        if ($subCategory === 'settlement') {
            $needsApproval = false;
            $needsRevision = false;
            if (method_exists($source, 'settlementItems')) {
                if ($isApproval) {
                    $needsApproval = $source->settlementItems()->whereIn('status', ['SUBMITTED'])->exists();
                }
                if ($isRevision) {
                    $needsRevision = $source->settlementItems()->whereIn('status', ['REJECTED'])->exists();
                }
            }
            
            if ($isApproval && !$needsApproval) {
                $notification->update(['resolved_at' => now()]);
            } elseif ($isRevision && !$needsRevision) {
                $notification->update(['resolved_at' => now()]);
            }
            return;
        } elseif ($subCategory === 'dispatch') {
            $needsApproval = false;
            $needsRevision = false;
            
            if ($class === \Modules\SalesReturn\Entities\SaleReturn::class) {
                if (method_exists($source, 'settlementItems')) {
                    if ($isApproval) {
                        $needsApproval = $source->settlementItems()->whereIn('status', ['DISPATCH_REQUESTED'])->exists();
                    }
                    if ($isRevision) {
                        $needsRevision = $source->settlementItems()
                            ->whereIn('status', ['APPROVED_AWAITING_DISPATCH'])
                            ->whereNotNull('dispatch_rejected_at')
                            ->exists();
                    }
                }
            } else {
                $dispatchStatus = Str::lower($source->return_dispatch_status ?? $source->dispatch_status ?? '');
                if ($isApproval) {
                    $needsApproval = in_array($dispatchStatus, ['pending_approval']);
                } elseif ($isRevision) {
                    $needsRevision = in_array($dispatchStatus, ['rejected']);
                }
            }
            
            if ($isApproval && !$needsApproval) {
                $notification->update(['resolved_at' => now()]);
            } elseif ($isRevision && !$needsRevision) {
                $notification->update(['resolved_at' => now()]);
            }
            return;
        }

        $status = Str::lower($source->status ?? $source->approval_status ?? '');
        
        if ($isApproval) {
            $needsApproval = in_array($status, [
                'waiting_approval', 'submitted', 'pending approval', 'pending', 'pending_approval'
            ]);
            if (!$needsApproval) {
                $notification->update(['resolved_at' => now()]);
            }
        } elseif ($isRevision) {
            $needsRevision = in_array($status, ['rejected']);
            if (!$needsRevision) {
                $notification->update(['resolved_at' => now()]);
            }
        }
    }

    protected function syncDocumentNotifications(DocumentNotificationService $docService)
    {
        $purchases = \Modules\Purchase\Entities\Purchase::whereIn('status', ['WAITING_APPROVAL', 'REJECTED'])->get();
        foreach ($purchases as $p) {
            if ($p->status === 'WAITING_APPROVAL') $docService->notifyApprovalNeeded($p, $p->reference ?? 'Pembelian', $p->setting_id);
            if ($p->status === 'REJECTED') $docService->notifyRevisionNeeded($p, $p->reference ?? 'Pembelian', $p->setting_id);
        }

        $sales = \Modules\Sale\Entities\Sale::whereIn('status', ['WAITING_APPROVAL', 'REJECTED'])->get();
        foreach ($sales as $s) {
            if ($s->status === 'WAITING_APPROVAL') $docService->notifyApprovalNeeded($s, $s->reference ?? 'Penjualan', $s->setting_id);
            if ($s->status === 'REJECTED') $docService->notifyRevisionNeeded($s, $s->reference ?? 'Penjualan', $s->setting_id);
        }

        $expenses = \Modules\Expense\Entities\Expense::whereIn('status', ['SUBMITTED', 'REJECTED'])->get();
        foreach ($expenses as $e) {
            if ($e->status === 'SUBMITTED') $docService->notifyApprovalNeeded($e, $e->reference ?? 'Pengeluaran', $e->setting_id);
            if ($e->status === 'REJECTED') $docService->notifyRevisionNeeded($e, $e->reference ?? 'Pengeluaran', $e->setting_id);
        }

        $adjustments = \Modules\Adjustment\Entities\Adjustment::whereIn('status', ['PENDING APPROVAL', 'REJECTED'])->get();
        foreach ($adjustments as $a) {
            if ($a->status === 'PENDING APPROVAL') $docService->notifyApprovalNeeded($a, $a->reference ?? 'Penyesuaian', $a->setting_id);
            if ($a->status === 'REJECTED') $docService->notifyRevisionNeeded($a, $a->reference ?? 'Penyesuaian', $a->setting_id);
        }

        $purchaseReturns = \Modules\PurchasesReturn\Entities\PurchaseReturn::whereIn('status', ['Pending Approval', 'Rejected', 'PENDING_APPROVAL', 'REJECTED', 'pending_approval', 'rejected'])->get();
        foreach ($purchaseReturns as $pr) {
            $status = Str::lower($pr->status);
            if ($status === 'pending approval' || $status === 'pending_approval') $docService->notifyApprovalNeeded($pr, $pr->reference ?? 'Retur', $pr->setting_id);
            if ($status === 'rejected') $docService->notifyRevisionNeeded($pr, $pr->reference ?? 'Retur', $pr->setting_id);
        }

        $saleReturns = \Modules\SalesReturn\Entities\SaleReturn::whereIn('status', ['Pending Approval', 'Rejected', 'PENDING_APPROVAL', 'REJECTED', 'pending_approval', 'rejected'])->get();
        foreach ($saleReturns as $sr) {
            $status = Str::lower($sr->status);
            if ($status === 'pending approval' || $status === 'pending_approval') $docService->notifyApprovalNeeded($sr, $sr->reference ?? 'Retur', $sr->setting_id);
            if ($status === 'rejected') $docService->notifyRevisionNeeded($sr, $sr->reference ?? 'Retur', $sr->setting_id);
        }

        $posReturns = \Modules\Pos\Entities\PosReturn::whereIn('status', ['Pending Approval', 'Rejected', 'PENDING_APPROVAL', 'REJECTED', 'pending_approval', 'rejected'])->get();
        foreach ($posReturns as $pr) {
            $status = Str::lower($pr->status);
            if ($status === 'pending approval' || $status === 'pending_approval') $docService->notifyApprovalNeeded($pr, $pr->reference ?? 'POS Return', $pr->setting_id);
            if ($status === 'rejected') $docService->notifyRevisionNeeded($pr, $pr->reference ?? 'POS Return', $pr->setting_id);
        }

        $saleReturnSettlements = \Modules\SalesReturn\Entities\SaleReturnItemSettlement::whereIn('status', ['SUBMITTED', 'REJECTED'])->with('saleReturn')->get();
        $saleReturnSettlementsGrouped = $saleReturnSettlements->groupBy('sale_return_id');
        foreach ($saleReturnSettlementsGrouped as $saleReturnId => $settlements) {
            $saleReturn = $settlements->first()->saleReturn;
            if (!$saleReturn) continue;

            if ($settlements->contains('status', 'SUBMITTED')) {
                $docService->notifyApprovalNeeded($saleReturn, $saleReturn->reference ?? 'Penyelesaian Retur Penjualan', $saleReturn->setting_id, null, 'settlement');
            }
            if ($settlements->contains('status', 'REJECTED')) {
                $docService->notifyRevisionNeeded($saleReturn, $saleReturn->reference ?? 'Penyelesaian Retur Penjualan', $saleReturn->setting_id, '', null, 'settlement');
            }
        }

        $purchaseReturnSettlements = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::whereIn('status', ['SUBMITTED', 'REJECTED'])->with('purchaseReturn')->get();
        $purchaseReturnSettlementsGrouped = $purchaseReturnSettlements->groupBy('purchase_return_id');
        foreach ($purchaseReturnSettlementsGrouped as $purchaseReturnId => $settlements) {
            $purchaseReturn = $settlements->first()->purchaseReturn;
            if (!$purchaseReturn) continue;

            if ($settlements->contains('status', 'SUBMITTED')) {
                $docService->notifyApprovalNeeded($purchaseReturn, $purchaseReturn->reference ?? 'Penyelesaian Retur Pembelian', $purchaseReturn->setting_id, null, 'settlement');
            }
            if ($settlements->contains('status', 'REJECTED')) {
                $docService->notifyRevisionNeeded($purchaseReturn, $purchaseReturn->reference ?? 'Penyelesaian Retur Pembelian', $purchaseReturn->setting_id, '', null, 'settlement');
            }
        }

        $receivedNotes = \Modules\Purchase\Entities\ReceivedNote::with('purchase')->whereIn('status', ['PENDING', 'REJECTED'])->get();
        foreach ($receivedNotes as $rn) {
            $status = Str::upper($rn->status);
            $settingId = $rn->purchase ? $rn->purchase->setting_id : 1;
            $reference = $rn->purchase ? $rn->purchase->reference : 'Penerimaan';
            if ($status === 'PENDING') $docService->notifyApprovalNeeded($rn, $reference, $settingId);
            if ($status === 'REJECTED') $docService->notifyRevisionNeeded($rn, $reference, $settingId);
        }

        $dispatches = \Modules\Sale\Entities\Dispatch::with('sale')->whereIn('status', ['PENDING', 'REJECTED'])->get();
        foreach ($dispatches as $d) {
            $status = Str::upper($d->status);
            $settingId = $d->sale ? $d->sale->setting_id : 1;
            $reference = $d->sale ? $d->sale->reference : 'Pengiriman';
            if ($status === 'PENDING') $docService->notifyApprovalNeeded($d, $reference, $settingId);
            if ($status === 'REJECTED') $docService->notifyRevisionNeeded($d, $reference, $settingId);
        }

        $purchaseReturnsDispatch = \Modules\PurchasesReturn\Entities\PurchaseReturn::whereIn('return_dispatch_status', ['PENDING_APPROVAL', 'pending_approval', 'REJECTED', 'rejected'])->get();
        foreach ($purchaseReturnsDispatch as $pr) {
            $status = Str::lower($pr->return_dispatch_status);
            if ($status === 'pending_approval') $docService->notifyApprovalNeeded($pr, $pr->reference ?? 'Pengiriman Retur Pembelian', $pr->setting_id, null, 'dispatch');
            if ($status === 'rejected') $docService->notifyRevisionNeeded($pr, $pr->reference ?? 'Pengiriman Retur Pembelian', $pr->setting_id, '', null, 'dispatch');
        }

        $saleReturnsDispatch = \Modules\SalesReturn\Entities\SaleReturn::whereHas('settlementItems', function($q) {
            $q->whereIn('status', ['DISPATCH_REQUESTED']);
        })->get();
        foreach ($saleReturnsDispatch as $sr) {
            $docService->notifyApprovalNeeded($sr, $sr->reference ?? 'Pengiriman Retur Penjualan', $sr->setting_id, null, 'dispatch');
        }

        $saleReturnsDispatchRevision = \Modules\SalesReturn\Entities\SaleReturn::whereHas('settlementItems', function($q) {
            $q->whereIn('status', ['APPROVED_AWAITING_DISPATCH'])
              ->whereNotNull('dispatch_rejected_at');
        })->get();
        foreach ($saleReturnsDispatchRevision as $sr) {
            $docService->notifyRevisionNeeded($sr, $sr->reference ?? 'Pengiriman Retur Penjualan', $sr->setting_id, '', null, 'dispatch');
        }
    }
}

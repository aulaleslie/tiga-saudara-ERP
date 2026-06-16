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

        $isApproval = Str::startsWith($notification->category, 'approval');
        $isRevision = Str::startsWith($notification->category, 'revision');

        $status = Str::lower($source->status ?? $source->approval_status ?? '');
        
        if ($isApproval) {
            $needsApproval = in_array($status, [
                'waiting_approval', 'submitted', 'pending approval', 'pending'
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

        $purchaseReturns = \Modules\PurchasesReturn\Entities\PurchaseReturn::whereIn('status', ['Pending Approval', 'Rejected'])->get();
        foreach ($purchaseReturns as $pr) {
            if ($pr->status === 'Pending Approval') $docService->notifyApprovalNeeded($pr, $pr->reference ?? 'Retur', $pr->setting_id);
            if ($pr->status === 'Rejected') $docService->notifyRevisionNeeded($pr, $pr->reference ?? 'Retur', $pr->setting_id);
        }

        $saleReturns = \Modules\SalesReturn\Entities\SaleReturn::whereIn('status', ['Pending Approval', 'Rejected'])->get();
        foreach ($saleReturns as $sr) {
            if ($sr->status === 'Pending Approval') $docService->notifyApprovalNeeded($sr, $sr->reference ?? 'Retur', $sr->setting_id);
            if ($sr->status === 'Rejected') $docService->notifyRevisionNeeded($sr, $sr->reference ?? 'Retur', $sr->setting_id);
        }

        $posReturns = \Modules\Pos\Entities\PosReturn::whereIn('status', ['Pending Approval', 'Rejected'])->get();
        foreach ($posReturns as $pr) {
            if ($pr->status === 'Pending Approval') $docService->notifyApprovalNeeded($pr, $pr->reference ?? 'POS Return', $pr->setting_id);
            if ($pr->status === 'Rejected') $docService->notifyRevisionNeeded($pr, $pr->reference ?? 'POS Return', $pr->setting_id);
        }

        $saleReturnSettlements = \Modules\SalesReturn\Entities\SaleReturnItemSettlement::whereIn('status', ['SUBMITTED'])->get();
        foreach ($saleReturnSettlements as $srs) {
            if ($srs->status === 'SUBMITTED' && $srs->saleReturn) {
                $docService->notifyApprovalNeeded($srs->saleReturn, $srs->saleReturn->reference ?? 'Penyelesaian Retur Penjualan', $srs->saleReturn->setting_id, null, 'settlement');
            }
        }

        $purchaseReturnSettlements = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::whereIn('status', ['SUBMITTED'])->get();
        foreach ($purchaseReturnSettlements as $prs) {
            if ($prs->status === 'SUBMITTED' && $prs->purchaseReturn) {
                $docService->notifyApprovalNeeded($prs->purchaseReturn, $prs->purchaseReturn->reference ?? 'Penyelesaian Retur Pembelian', $prs->purchaseReturn->setting_id, null, 'settlement');
            }
        }
    }
}

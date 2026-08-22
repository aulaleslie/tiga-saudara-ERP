<?php

namespace App\Services\Notification;

use Illuminate\Database\Eloquent\Model;
use App\Models\Notification;

class DocumentNotificationService
{
    public function __construct(
        private NotificationService $notificationService,
        private PermissionResolver $permissionResolver
    ) {}

    protected function getConfig(Model $document, string $workflow = 'default'): ?array
    {
        $class = get_class($document);
        $key = $workflow === 'default' ? $class : "{$class}:{$workflow}";

        $map = [
            \Modules\Purchase\Entities\Purchase::class => [
                'approval_permission' => 'purchases.approval',
                'edit_permission' => 'purchases.update',
                'title_prefix' => 'Pembelian',
                'route_prefix' => 'purchases',
            ],
            \Modules\Adjustment\Entities\Adjustment::class => [
                'approval_permission' => 'adjustments.approval',
                'edit_permission' => 'adjustments.edit',
                'title_prefix' => 'Penyesuaian Stok',
                'route_prefix' => 'adjustments',
            ],
            \Modules\Expense\Entities\Expense::class => [
                'approval_permission' => 'expenses.approval',
                'edit_permission' => 'expenses.edit',
                'title_prefix' => 'Pengeluaran',
                'route_prefix' => 'expenses',
            ],
            \Modules\Purchase\Entities\ReceivedNote::class => [
                'approval_permission' => 'purchases.receive.approval',
                'edit_permission' => 'purchases.receive',
                'title_prefix' => 'Penerimaan Pembelian',
                'route_prefix' => 'purchases.receiving',
            ],
            \Modules\Sale\Entities\Sale::class => [
                'approval_permission' => 'sales.approval',
                'edit_permission' => 'sales.edit',
                'title_prefix' => 'Penjualan',
                'route_prefix' => 'sales',
            ],
            \Modules\Sale\Entities\Dispatch::class => [
                'approval_permission' => 'salesDispatches.approval',
                'edit_permission' => 'sales.dispatch',
                'title_prefix' => 'Pengiriman Penjualan',
                'route_prefix' => 'sale-dispatches',
            ],
            \Modules\PurchasesReturn\Entities\PurchaseReturn::class => [
                'approval_permission' => 'purchaseReturns.approval',
                'edit_permission' => 'purchaseReturns.edit',
                'title_prefix' => 'Retur Pembelian',
                'route_prefix' => 'purchase-returns',
            ],
            \Modules\Consignment\Entities\ConsignmentReceival::class => [
                'approval_permission' => 'consignments.approve',
                'edit_permission' => 'consignments.edit',
                'title_prefix' => 'Dokumen Konsinyasi',
                'route_prefix' => 'consignments.receivals',
            ],
            \Modules\Consignment\Entities\ConsignmentReceiving::class => [
                'approval_permission' => 'consignments.receive.approve',
                'edit_permission' => 'consignments.receive',
                'title_prefix' => 'Penerimaan Fisik Konsinyasi',
                'route_prefix' => 'consignments.receivings',
            ],
            \Modules\PurchasesReturn\Entities\PurchaseReturn::class . ':dispatch' => [
                'approval_permission' => 'purchaseReturns.dispatchApproval',
                'edit_permission' => 'purchaseReturns.dispatchRequest',
                'title_prefix' => 'Pengiriman Retur Pembelian',
                'route_prefix' => 'purchase-returns',
            ],
            \Modules\PurchasesReturn\Entities\PurchaseReturn::class . ':settlement' => [
                'approval_permission' => 'purchaseReturnSettlements.approve',
                'edit_permission' => 'purchaseReturnSettlements.submit',
                'title_prefix' => 'Penyelesaian Retur Pembelian',
                'route_prefix' => 'purchase-returns',
            ],
            \Modules\SalesReturn\Entities\SaleReturn::class => [
                'approval_permission' => 'saleReturns.approve',
                'edit_permission' => 'saleReturns.edit',
                'title_prefix' => 'Retur Penjualan',
                'route_prefix' => 'sale-returns',
            ],
            \Modules\SalesReturn\Entities\SaleReturn::class . ':dispatch' => [
                'approval_permission' => 'saleReturnSettlements.dispatchApproval',
                'edit_permission' => 'saleReturnSettlements.dispatchRequest',
                'title_prefix' => 'Pengiriman Retur Penjualan',
                'route_prefix' => 'sale-returns',
            ],
            \Modules\SalesReturn\Entities\SaleReturn::class . ':settlement' => [
                'approval_permission' => 'saleReturnSettlements.approve',
                'edit_permission' => 'saleReturnSettlements.submit',
                'title_prefix' => 'Penyelesaian Retur Penjualan',
                'route_prefix' => 'sale-returns',
            ],
            \Modules\Pos\Entities\PosReturn::class => [
                'approval_permission' => 'pos.returns.approve',
                'edit_permission' => 'pos.returns.edit',
                'title_prefix' => 'Retur POS',
                'route_prefix' => 'pos.returns',
            ],
        ];

        return $map[$key] ?? null;
    }

    public function notifyApprovalNeeded(Model $document, string $reference, int $settingId, ?int $locationId = null, string $workflow = 'default'): void
    {
        $config = $this->getConfig($document, $workflow);
        if (!$config) return;

        $recipients = $this->permissionResolver->getApprovalRecipients($settingId, $config['approval_permission']);
        $userIds = $recipients->pluck('id');

        $sourceType = get_class($document);
        $sourceId = $document->id;
        $title = "Persetujuan {$config['title_prefix']} Dibutuhkan";
        $message = "Dokumen {$reference} membutuhkan persetujuan Anda.";
        $category = $workflow === 'default' ? 'approval' : "approval:{$workflow}";

        // Attempt generic route, fallback to empty
        $actionUrl = '#';
        if (\Illuminate\Support\Facades\Route::has("{$config['route_prefix']}.show")) {
            $actionUrl = route("{$config['route_prefix']}.show", $sourceId);
        } elseif (\Illuminate\Support\Facades\Route::has("{$config['route_prefix']}.index")) {
            $actionUrl = route("{$config['route_prefix']}.index");
        }

        foreach ($userIds as $userId) {
            $fingerprint = "{$category}:" . class_basename($sourceType) . ":{$sourceId}:user:{$userId}";

            $this->notificationService->write([
                'user_id' => $userId,
                'setting_id' => $settingId,
                'location_id' => $locationId,
                'category' => $category,
                'type' => 'document_approval',
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'fingerprint' => $fingerprint,
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'metadata' => [
                    'reference' => $reference,
                    'status' => 'pending'
                ],
            ]);
        }
    }

    public function resolveApproval(Model $document, string $workflow = 'default'): void
    {
        $category = $workflow === 'default' ? 'approval' : "approval:{$workflow}";
        $this->notificationService->resolveBySource($category, get_class($document), $document->id);
    }

    public function notifyRevisionNeeded(Model $document, string $reference, int $settingId, string $rejectionReason = '', ?int $locationId = null, string $workflow = 'default'): void
    {
        $config = $this->getConfig($document, $workflow);
        if (!$config) return;

        $recipients = $this->permissionResolver->getRevisionRecipients($settingId, $config['edit_permission']);
        $userIds = $recipients->pluck('id');

        $sourceType = get_class($document);
        $sourceId = $document->id;
        $title = "Revisi {$config['title_prefix']} Dibutuhkan";
        $message = "Dokumen {$reference} ditolak atau butuh revisi. Alasan: " . ($rejectionReason ?: '-');
        $category = $workflow === 'default' ? 'revision' : "revision:{$workflow}";

        // Attempt generic route, fallback to empty
        $actionUrl = '#';
        if (\Illuminate\Support\Facades\Route::has("{$config['route_prefix']}.edit")) {
            $actionUrl = route("{$config['route_prefix']}.edit", $sourceId);
        } elseif (\Illuminate\Support\Facades\Route::has("{$config['route_prefix']}.index")) {
            $actionUrl = route("{$config['route_prefix']}.index");
        }

        foreach ($userIds as $userId) {
            $fingerprint = "{$category}:" . class_basename($sourceType) . ":{$sourceId}:user:{$userId}";

            $this->notificationService->write([
                'user_id' => $userId,
                'setting_id' => $settingId,
                'location_id' => $locationId,
                'category' => $category,
                'type' => 'document_revision',
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'fingerprint' => $fingerprint,
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'metadata' => [
                    'reference' => $reference,
                    'status' => 'rejected',
                    'reason' => $rejectionReason
                ],
            ]);
        }
    }

    public function resolveRevision(Model $document, string $workflow = 'default'): void
    {
        $category = $workflow === 'default' ? 'revision' : "revision:{$workflow}";
        $this->notificationService->resolveBySource($category, get_class($document), $document->id);
    }
}

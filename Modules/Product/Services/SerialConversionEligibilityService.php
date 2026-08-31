<?php

namespace Modules\Product\Services;

use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\Setting\Entities\Tax;

class SerialConversionEligibilityService
{
    /**
     * Check whether a product is eligible for conversion to serial-tracked.
     */
    public function checkEligibility(Product $product): SerialConversionEligibilityResult
    {
        $blockingReasons = [];

        // 1. Must be active & stock managed
        if (! $product->is_active) {
            $blockingReasons[] = 'Produk tidak aktif.';
        }

        if (! $product->stock_managed) {
            $blockingReasons[] = 'Produk tidak dikelola stoknya (non-stock-managed).';
        }

        // 2. Cannot already require serial numbers
        if ($product->serial_number_required) {
            $blockingReasons[] = 'Produk sudah dikonfigurasi menggunakan nomor seri.';
        }

        // 3. Absence of existing serial records for product
        $hasExistingSerials = ProductSerialNumber::where('product_id', $product->id)->exists();
        if ($hasExistingSerials) {
            $blockingReasons[] = 'Produk sudah memiliki data nomor seri terdaftar.';
        }

        // 4. Inspect product stocks across all locations
        $stocks = ProductStock::where('product_id', $product->id)->with(['location'])->get();
        $totalQuantity = 0;

        if ($stocks->isEmpty()) {
            $blockingReasons[] = 'Produk tidak memiliki data stok di lokasi manapun.';
        } else {
            foreach ($stocks as $stock) {
                $normalSum = (float) $stock->quantity_non_tax + (float) $stock->quantity_tax;
                $brokenSum = (float) $stock->broken_quantity_non_tax + (float) $stock->broken_quantity_tax;
                $expectedTotalQuantity = $normalSum + $brokenSum;

                // Non-negative bucket values
                if (
                    $stock->quantity < 0 || $stock->quantity_non_tax < 0 || $stock->quantity_tax < 0 ||
                    $stock->broken_quantity < 0 || $stock->broken_quantity_non_tax < 0 || $stock->broken_quantity_tax < 0
                ) {
                    $blockingReasons[] = 'Terdapat jumlah stok bernilai negatif pada lokasi ' . ($stock->location?->name ?? "ID {$stock->location_id}") . '.';
                }

                // Bucket internal consistency
                if (abs((float) $stock->quantity - $expectedTotalQuantity) > 0.0001) {
                    $blockingReasons[] = 'Jumlah stok total tidak konsisten dengan rincian PPN/Non-PPN dan stok rusak pada lokasi ' . ($stock->location?->name ?? "ID {$stock->location_id}") . '.';
                }

                if (abs((float) $stock->broken_quantity - $brokenSum) > 0.0001) {
                    $blockingReasons[] = 'Jumlah stok rusak total tidak konsisten dengan rincian PPN/Non-PPN pada lokasi ' . ($stock->location?->name ?? "ID {$stock->location_id}") . '.';
                }

                // Whole number check
                foreach ([
                    'quantity' => $stock->quantity,
                    'quantity_non_tax' => $stock->quantity_non_tax,
                    'quantity_tax' => $stock->quantity_tax,
                    'broken_quantity' => $stock->broken_quantity,
                    'broken_quantity_non_tax' => $stock->broken_quantity_non_tax,
                    'broken_quantity_tax' => $stock->broken_quantity_tax,
                ] as $field => $val) {
                    if (fmod((float) $val, 1.0) !== 0.0) {
                        $blockingReasons[] = 'Jumlah stok harus berupa bilangan bulat utuh (ditemukan pecahan pada ' . $field . ').';
                        break;
                    }
                }

                $totalQuantity += (float) $stock->quantity;
            }

            if ($totalQuantity <= 0) {
                $blockingReasons[] = 'Produk tidak memiliki total stok fisik yang positif.';
            }
        }

        // 5. Check PPN stock vs default tax availability
        $hasPpnStock = $stocks->contains(fn ($s) => (float) $s->quantity_tax > 0 || (float) $s->broken_quantity_tax > 0);
        if ($hasPpnStock) {
            $defaultTax = Tax::where('is_active', true)->where('is_default', true)->first();
            if (! $defaultTax) {
                $blockingReasons[] = 'Stok PPN ditemukan tetapi pajak standar (default tax) tidak aktif atau tidak ditemukan.';
            }
        }

        // 6. Active stock-moving dependencies check
        $structuredBlockers = [];
        $user = auth()->user();

        // Separate non-document reasons before adding document fallbacks
        $nonDocumentReasons = array_values(array_unique($blockingReasons));

        // Helper to safely format document blocker
        $addDocumentBlocker = function (
            string $type,
            int|string $documentId,
            string $documentNumber,
            string $status,
            string $reason,
            ?string $routeName,
            array $routeParams,
            ?string $permissionName,
            string $fallbackReasonText
        ) use (&$blockingReasons, &$structuredBlockers, $user) {
            $canView = false;
            $url = null;

            if ($permissionName === null || ($user && $user->can($permissionName))) {
                if ($routeName && \Illuminate\Support\Facades\Route::has($routeName)) {
                    $url = route($routeName, $routeParams);
                    $canView = true;
                }
            }

            $structuredBlockers[] = [
                'type' => $type,
                'document_id' => $documentId,
                'document_number' => $documentNumber,
                'status' => $status,
                'reason' => $reason,
                'url' => $url,
                'can_view' => $canView,
            ];

            $blockingReasons[] = $fallbackReasonText;
        };

        // - Pending Received Notes (status PENDING)
        $pendingReceivings = ReceivedNote::select(['id', 'po_id', 'external_delivery_number', 'internal_invoice_number', 'status'])
            ->where('status', ReceivedNote::STATUS_PENDING)
            ->whereHas('purchase.purchaseDetails', function ($q) use ($product) {
                $q->where('product_id', $product->id);
            })->get();

        foreach ($pendingReceivings as $rn) {
            $docNum = $rn->external_delivery_number
                ?: ($rn->internal_invoice_number ?: "Penerimaan #{$rn->id}");
            $purchaseId = $rn->po_id;
            $addDocumentBlocker(
                type: 'received_note',
                documentId: $purchaseId,
                documentNumber: $docNum,
                status: (string) $rn->status,
                reason: 'Dokumen penerimaan barang masih pending.',
                routeName: 'purchases.receivings',
                routeParams: [$purchaseId],
                permissionName: 'purchases.receive.access',
                fallbackReasonText: 'Terdapat dokumen Penerimaan Barang (Received Note) berstatus PENDING untuk produk ini.'
            );
        }

        // - Active Purchase Returns
        if (class_exists(PurchaseReturn::class)) {
            $activeReturnStatuses = [
                PurchaseReturn::STATUS_DRAFT,
                PurchaseReturn::STATUS_PENDING_APPROVAL,
                PurchaseReturn::STATUS_AWAITING_DISPATCH,
                PurchaseReturn::STATUS_DISPATCH_PENDING_APPROVAL,
                PurchaseReturn::STATUS_IN_RETURN,
                PurchaseReturn::STATUS_SETTLEMENT_CONFIRMATION_PENDING,
                PurchaseReturn::STATUS_WAITING_REPLACEMENT_GOODS,
                PurchaseReturn::STATUS_PARTIAL_SETTLEMENT,
            ];

            $activePurchaseReturns = PurchaseReturn::select(['id', 'reference', 'status'])
                ->whereIn('status', $activeReturnStatuses)
                ->whereHas('purchaseReturnDetails', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->get();

            foreach ($activePurchaseReturns as $pr) {
                $docNum = $pr->reference ?? "ID #{$pr->id}";
                $addDocumentBlocker(
                    type: 'purchase_return',
                    documentId: $pr->id,
                    documentNumber: $docNum,
                    status: (string) $pr->status,
                    reason: 'Dokumen retur pembelian masih aktif.',
                    routeName: 'purchase-returns.show',
                    routeParams: [$pr->id],
                    permissionName: 'purchaseReturns.show',
                    fallbackReasonText: 'Terdapat dokumen Retur Pembelian yang belum selesai untuk produk ini.'
                );
            }
        }

        // - Active Transfers
        if (class_exists(\Modules\Adjustment\Entities\Transfer::class)) {
            $activeTransferStatuses = [
                \Modules\Adjustment\Entities\Transfer::STATUS_DRAFT,
                \Modules\Adjustment\Entities\Transfer::STATUS_PENDING,
                \Modules\Adjustment\Entities\Transfer::STATUS_APPROVED,
                \Modules\Adjustment\Entities\Transfer::STATUS_DISPATCHED,
                \Modules\Adjustment\Entities\Transfer::STATUS_AWAITING_RETURN,
                \Modules\Adjustment\Entities\Transfer::STATUS_RETURN_DISPATCHED,
            ];

            $activeTransfers = \Modules\Adjustment\Entities\Transfer::select(['id', 'document_number', 'status'])
                ->whereIn('status', $activeTransferStatuses)
                ->whereHas('products', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->get();

            foreach ($activeTransfers as $tr) {
                $docNum = $tr->document_number ?? "ID #{$tr->id}";
                $addDocumentBlocker(
                    type: 'transfer',
                    documentId: $tr->id,
                    documentNumber: $docNum,
                    status: (string) $tr->status,
                    reason: 'Dokumen transfer stok masih aktif.',
                    routeName: 'transfers.show',
                    routeParams: [$tr->id],
                    permissionName: 'stockTransfers.show',
                    fallbackReasonText: 'Terdapat dokumen Transfer Stok yang sedang aktif/berjalan untuk produk ini.'
                );
            }
        }

        // - Active Consignment Receivings
        if (class_exists(\Modules\Consignment\Entities\ConsignmentReceiving::class)) {
            $activeConsignments = \Modules\Consignment\Entities\ConsignmentReceiving::select(['id', 'receiving_number', 'status'])
                ->where('status', \Modules\Consignment\Entities\ConsignmentReceiving::STATUS_PENDING)
                ->whereHas('details', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->get();

            foreach ($activeConsignments as $cr) {
                $docNum = $cr->receiving_number ?? "ID #{$cr->id}";
                $addDocumentBlocker(
                    type: 'consignment_receiving',
                    documentId: $cr->id,
                    documentNumber: $docNum,
                    status: (string) $cr->status,
                    reason: 'Dokumen penerimaan konsinyasi berstatus PENDING.',
                    routeName: 'consignments.receivings.show',
                    routeParams: [$cr->id],
                    permissionName: 'consignments.access',
                    fallbackReasonText: 'Terdapat dokumen Penerimaan Konsinyasi berstatus PENDING untuk produk ini.'
                );
            }
        }

        // - Active Sales and Dispatches
        if (class_exists(\Modules\Sale\Entities\Sale::class)) {
            $activeSales = \Modules\Sale\Entities\Sale::select(['id', 'reference', 'status'])
                ->whereIn('status', [
                    \Modules\Sale\Entities\Sale::STATUS_DRAFTED,
                    \Modules\Sale\Entities\Sale::STATUS_WAITING_APPROVAL,
                    \Modules\Sale\Entities\Sale::STATUS_APPROVED,
                    \Modules\Sale\Entities\Sale::STATUS_DISPATCHED_PARTIALLY,
                ])->whereHas('saleDetails', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->get();

            foreach ($activeSales as $sl) {
                $docNum = $sl->reference ?? "ID #{$sl->id}";
                $addDocumentBlocker(
                    type: 'sale',
                    documentId: $sl->id,
                    documentNumber: $docNum,
                    status: (string) $sl->status,
                    reason: 'Dokumen penjualan masih aktif dan dapat mengubah stok produk.',
                    routeName: 'sales.show',
                    routeParams: [$sl->id],
                    permissionName: 'sales.show',
                    fallbackReasonText: 'Terdapat dokumen Penjualan atau Pengiriman yang belum selesai untuk produk ini.'
                );
            }
        }

        // - Active Adjustments
        if (class_exists(\Modules\Adjustment\Entities\Adjustment::class)) {
            $activeAdjustments = \Modules\Adjustment\Entities\Adjustment::select(['id', 'reference', 'status'])
                ->whereIn('status', ['pending', 'PENDING', 'draft', 'DRAFT'])
                ->whereHas('adjustedProducts', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->get();

            foreach ($activeAdjustments as $adj) {
                $docNum = $adj->reference ?? "ID #{$adj->id}";
                $addDocumentBlocker(
                    type: 'adjustment',
                    documentId: $adj->id,
                    documentNumber: $docNum,
                    status: (string) $adj->status,
                    reason: 'Dokumen penyesuaian stok berstatus PENDING atau DRAFT.',
                    routeName: 'adjustments.show',
                    routeParams: [$adj->id],
                    permissionName: 'adjustments.show',
                    fallbackReasonText: 'Terdapat dokumen Penyesuaian Stok (Adjustment) berstatus PENDING/DRAFT untuk produk ini.'
                );
            }
        }

        // - Active Sales Returns (covering full lifecycle: header status, approval status, and active item settlements)
        if (class_exists(\Modules\SalesReturn\Entities\SaleReturn::class)) {
            $activeSaleReturns = \Modules\SalesReturn\Entities\SaleReturn::select(['id', 'reference', 'status'])
                ->where(function ($q) {
                    $q->whereIn('status', ['Pending', 'PENDING', 'Awaiting Settlement', 'AWAITING_SETTLEMENT', 'Draft', 'DRAFT'])
                        ->orWhereIn('approval_status', ['Pending', 'PENDING'])
                        ->orWhereHas('settlementItems', function ($sq) {
                            $sq->whereIn('status', [
                                \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_DRAFT,
                                \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_SUBMITTED,
                                \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_APPROVED,
                                \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH,
                                \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED,
                            ]);
                        });
                })
                ->whereHas('saleReturnDetails', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->get();

            foreach ($activeSaleReturns as $sr) {
                $docNum = $sr->reference ?? "ID #{$sr->id}";
                $addDocumentBlocker(
                    type: 'sale_return',
                    documentId: $sr->id,
                    documentNumber: $docNum,
                    status: (string) $sr->status,
                    reason: 'Dokumen retur penjualan belum selesai.',
                    routeName: 'sale-returns.show',
                    routeParams: [$sr->id],
                    permissionName: 'saleReturns.show',
                    fallbackReasonText: 'Terdapat dokumen Retur Penjualan yang belum selesai untuk produk ini.'
                );
            }
        }

        // Remove duplicate blocking reasons if any
        $blockingReasons = array_values(array_unique($blockingReasons));

        return new SerialConversionEligibilityResult(
            isEligible: empty($blockingReasons),
            blockingReasons: $blockingReasons,
            product: $product,
            structuredBlockers: $structuredBlockers,
            nonDocumentReasons: $nonDocumentReasons
        );
    }
}

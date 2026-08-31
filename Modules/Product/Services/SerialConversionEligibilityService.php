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
        // - Pending Received Notes (status PENDING)
        $hasPendingReceiving = ReceivedNote::where('status', ReceivedNote::STATUS_PENDING)
            ->whereHas('purchase.purchaseDetails', function ($q) use ($product) {
                $q->where('product_id', $product->id);
            })->exists();

        if ($hasPendingReceiving) {
            $blockingReasons[] = 'Terdapat dokumen Penerimaan Barang (Received Note) berstatus PENDING untuk produk ini.';
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

            $hasActivePurchaseReturn = PurchaseReturn::whereIn('status', $activeReturnStatuses)
                ->whereHas('purchaseReturnDetails', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->exists();

            if ($hasActivePurchaseReturn) {
                $blockingReasons[] = 'Terdapat dokumen Retur Pembelian yang belum selesai untuk produk ini.';
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

            $hasActiveTransfer = \Modules\Adjustment\Entities\Transfer::whereIn('status', $activeTransferStatuses)
                ->whereHas('products', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->exists();

            if ($hasActiveTransfer) {
                $blockingReasons[] = 'Terdapat dokumen Transfer Stok yang sedang aktif/berjalan untuk produk ini.';
            }
        }

        // - Active Consignment Receivings
        if (class_exists(\Modules\Consignment\Entities\ConsignmentReceiving::class)) {
            $hasActiveConsignment = \Modules\Consignment\Entities\ConsignmentReceiving::where('status', \Modules\Consignment\Entities\ConsignmentReceiving::STATUS_PENDING)
                ->whereHas('details', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->exists();

            if ($hasActiveConsignment) {
                $blockingReasons[] = 'Terdapat dokumen Penerimaan Konsinyasi berstatus PENDING untuk produk ini.';
            }
        }

        // - Active Sales and Dispatches
        if (class_exists(\Modules\Sale\Entities\Sale::class)) {
            $hasActiveSale = \Modules\Sale\Entities\Sale::whereIn('status', [
                \Modules\Sale\Entities\Sale::STATUS_DRAFTED,
                \Modules\Sale\Entities\Sale::STATUS_WAITING_APPROVAL,
                \Modules\Sale\Entities\Sale::STATUS_APPROVED,
                \Modules\Sale\Entities\Sale::STATUS_DISPATCHED_PARTIALLY,
            ])->whereHas('saleDetails', function ($q) use ($product) {
                $q->where('product_id', $product->id);
            })->exists();

            if ($hasActiveSale) {
                $blockingReasons[] = 'Terdapat dokumen Penjualan atau Pengiriman yang belum selesai untuk produk ini.';
            }
        }

        // - Active Adjustments
        if (class_exists(\Modules\Adjustment\Entities\Adjustment::class)) {
            $hasActiveAdjustment = \Modules\Adjustment\Entities\Adjustment::whereIn('status', ['pending', 'PENDING', 'draft', 'DRAFT'])
                ->whereHas('adjustedProducts', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->exists();

            if ($hasActiveAdjustment) {
                $blockingReasons[] = 'Terdapat dokumen Penyesuaian Stok (Adjustment) berstatus PENDING/DRAFT untuk produk ini.';
            }
        }

        // - Active Sales Returns (covering full lifecycle: header status, approval status, and active item settlements)
        if (class_exists(\Modules\SalesReturn\Entities\SaleReturn::class)) {
            $hasActiveSaleReturn = \Modules\SalesReturn\Entities\SaleReturn::where(function ($q) {
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
            })->exists();

            if ($hasActiveSaleReturn) {
                $blockingReasons[] = 'Terdapat dokumen Retur Penjualan yang belum selesai untuk produk ini.';
            }
        }

        // Remove duplicate blocking reasons if any
        $blockingReasons = array_values(array_unique($blockingReasons));

        return new SerialConversionEligibilityResult(
            isEligible: empty($blockingReasons),
            blockingReasons: $blockingReasons,
            product: $product
        );
    }
}

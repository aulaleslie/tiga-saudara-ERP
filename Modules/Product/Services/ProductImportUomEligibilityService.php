<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosTransaction;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Unit;

class ProductImportUomEligibilityService
{
    /**
     * Evaluate the product against all eligibility rules for import-origin UOM conversion.
     */
    public function checkEligibility(Product $product, Unit $targetUnit, float $factor): ProductUomEligibilityResult
    {
        $blockingReasons = [];

        // 1. Basic validation
        if ($factor <= 0) {
            $blockingReasons[] = 'Faktor konversi harus berupa angka positif.';
        }

        if ($product->base_unit_id === $targetUnit->id) {
            $blockingReasons[] = 'Satuan target harus berbeda dari satuan dasar produk saat ini.';
        }

        // 2. Unhandled complexity checks:
        // - Conversions exist
        $hasConversions = ProductUnitConversion::where('product_id', $product->id)->exists();
        if ($hasConversions) {
            $blockingReasons[] = 'Produk memiliki data konversi satuan (product_unit_conversions). Konversi existing belum didukung oleh jalur ini.';
        }

        // - Actual (non-zero) stock footprint spans more than 1 setting.
        // Note: product_prices rows commonly exist for every setting as seeded/default
        // cost placeholders even where the product has never actually been stocked or
        // purchased in that setting, so price-row presence alone is not a reliable
        // signal of real multi-setting footprint. Only settings with non-zero stock count.
        $stockSettingIds = ProductStock::where('product_id', $product->id)
            ->where('quantity', '!=', 0)
            ->join('locations', 'product_stocks.location_id', '=', 'locations.id')
            ->pluck('locations.setting_id')
            ->filter()
            ->unique()
            ->values();

        if ($stockSettingIds->count() > 1) {
            $settingsList = $stockSettingIds->join(', ');
            $blockingReasons[] = "Produk memiliki stok aktual di lebih dari satu cabang/setting (ID: {$settingsList}).";
        }

        // 3. Broken-stock check:
        // Any product_stocks row with non-zero broken quantity buckets
        $brokenStocks = ProductStock::where('product_id', $product->id)
            ->where(function ($q) {
                $q->where('broken_quantity', '!=', 0)
                    ->orWhere('broken_quantity_tax', '!=', 0)
                    ->orWhere('broken_quantity_non_tax', '!=', 0);
            })
            ->get();

        if ($brokenStocks->isNotEmpty()) {
            $blockingReasons[] = 'Produk memiliki stok rusak (broken stock > 0). Koreksi UOM diblokir.';
        }

        // 4. Fulfillment and ledger history checks:
        // Only ADJ transaction type is permitted for import-origin quick conversion.
        // Any other transaction type (DISPATCH, BUY, SELL, TRF, SALE_RETURN_*, PURCHASE_RETURN_*, etc.) proves
        // fulfillment, receipts, transfers, or returns have occurred and blocks execution.
        $nonAdjTransactions = Transaction::where('product_id', $product->id)
            ->where('type', '!=', 'ADJ')
            ->select('type')
            ->distinct()
            ->pluck('type');

        if ($nonAdjTransactions->isNotEmpty()) {
            if ($nonAdjTransactions->contains('BUY')) {
                $blockingReasons[] = 'Produk memiliki riwayat transaksi BUY. Gunakan fitur Normalisasi UOM Penerimaan.';
            } else {
                $typesList = $nonAdjTransactions->join(', ');
                $blockingReasons[] = "Produk memiliki riwayat transaksi selain penyesuaian/import (ADJ): [{$typesList}]. Koreksi UOM diblokir.";
            }
        }

        // - Any Sale referencing product with status IN ('DISPATCHED','DISPATCHED PARTIALLY','RETURNED','RETURNED PARTIALLY') or paid_amount > 0
        $blockingSales = Sale::whereHas('saleDetails', function ($q) use ($product) {
            $q->where('product_id', $product->id);
        })
            ->where(function ($q) {
                $q->whereIn('status', [
                    Sale::STATUS_DISPATCHED,
                    Sale::STATUS_DISPATCHED_PARTIALLY,
                    Sale::STATUS_RETURNED,
                    Sale::STATUS_RETURNED_PARTIALLY,
                    'DISPATCHED',
                    'DISPATCHED PARTIALLY',
                    'RETURNED',
                    'RETURNED PARTIALLY',
                ])
                    ->orWhere('paid_amount', '>', 0);
            })
            ->get();

        if ($blockingSales->isNotEmpty()) {
            $blockingSalesRefs = $blockingSales->pluck('reference')->take(5)->join(', ');
            $blockingReasons[] = "Produk terikat dengan penjualan yang sudah terkirim (penuh/sebagian), retur, atau memiliki pembayaran (paid_amount > 0): {$blockingSalesRefs}.";
        }

        // 5. Transaction-ledger self-consistency check:
        // Global and per-location after_quantity / after_quantity_at_location vs live product_quantity / product_stocks.quantity
        $ledgerConsistencyErrors = $this->verifyLedgerSelfConsistency($product);
        if (!empty($ledgerConsistencyErrors)) {
            $blockingReasons = array_merge($blockingReasons, $ledgerConsistencyErrors);
        }

        // 6. Discover removable documents
        $removableDocuments = $this->discoverRemovableDocuments($product);

        // 7. Generate before-state snapshot & projected after-state for preview
        $preview = null;
        if (empty($blockingReasons)) {
            $preview = $this->buildPreviewData($product, $targetUnit, $factor);
        }

        $isEligible = empty($blockingReasons);

        return new ProductUomEligibilityResult(
            eligible: $isEligible,
            blockingReasons: $blockingReasons,
            removableDocuments: $removableDocuments,
            preview: $preview
        );
    }

    /**
     * Check that the most recent transaction for the product at every location, and globally,
     * matches current live product_stocks and product_quantity.
     *
     * @return array<string> List of inconsistency error messages if any.
     */
    public function verifyLedgerSelfConsistency(Product $product): array
    {
        $errors = [];

        // Global transaction ledger check
        $lastGlobalTx = Transaction::where('product_id', $product->id)
            ->orderBy('id', 'desc')
            ->first();

        $liveGlobalQty = (float) $product->product_quantity;

        if ($lastGlobalTx !== null) {
            $recordedAfterGlobal = (float) $lastGlobalTx->after_quantity;
            if (abs($liveGlobalQty - $recordedAfterGlobal) > 0.0001) {
                $errors[] = "Inkonsistensi ledger global: live product_quantity ({$liveGlobalQty}) tidak sama dengan after_quantity transaksi terakhir #{$lastGlobalTx->id} ({$recordedAfterGlobal}).";
            }
        } elseif ($liveGlobalQty != 0.0) {
            $errors[] = "Inkonsistensi ledger global: live product_quantity ({$liveGlobalQty}) bukan 0 tetapi tidak ada transaksi tercatat di ledger.";
        }

        // Per-location transaction ledger check
        $productStocks = ProductStock::where('product_id', $product->id)->get();
        foreach ($productStocks as $stock) {
            $liveLocQty = (float) $stock->quantity;
            $lastLocTx = Transaction::where('product_id', $product->id)
                ->where('location_id', $stock->location_id)
                ->orderBy('id', 'desc')
                ->first();

            if ($lastLocTx !== null) {
                $recordedAfterLoc = (float) $lastLocTx->after_quantity_at_location;
                if (abs($liveLocQty - $recordedAfterLoc) > 0.0001) {
                    $errors[] = "Inkonsistensi ledger lokasi #{$stock->location_id}: live quantity ({$liveLocQty}) tidak sama dengan after_quantity_at_location transaksi terakhir #{$lastLocTx->id} ({$recordedAfterLoc}).";
                }
            } elseif ($liveLocQty != 0.0) {
                $errors[] = "Inkonsistensi ledger lokasi #{$stock->location_id}: live quantity ({$liveLocQty}) bukan 0 tetapi tidak ada transaksi tercatat di lokasi tersebut.";
            }
        }

        return $errors;
    }

    /**
     * Discover removable documents referencing this product:
     * - POS transactions with status DRAFT / LOADED
     * - Sales with status not dispatched/returned, paid_amount = 0
     *
     * @return array<array{document_type: string, id: int, reference: string, status: string, payment_amount: float, owner_or_customer: string|null, created_at: string|null}>
     */
    public function discoverRemovableDocuments(Product $product): array
    {
        $removables = [];

        // 1. POS Transactions
        $posTransactions = PosTransaction::whereHas('lines', function ($q) use ($product) {
            $q->where('product_id', $product->id);
        })
            ->whereIn('status', [PosTransaction::STATUS_DRAFT, PosTransaction::STATUS_LOADED])
            ->with(['owner', 'creator'])
            ->get();

        foreach ($posTransactions as $pos) {
            $ownerName = $pos->owner?->name ?? $pos->creator?->name ?? 'Unknown';
            $removables[] = [
                'document_type' => 'POS',
                'id' => $pos->id,
                'reference' => $pos->code ?? "POS-{$pos->id}",
                'status' => $pos->status,
                'payment_amount' => 0.0,
                'owner_or_customer' => $ownerName,
                'created_at' => $pos->created_at?->toIso8601String(),
            ];
        }

        // 2. Sales
        $sales = Sale::whereHas('saleDetails', function ($q) use ($product) {
            $q->where('product_id', $product->id);
        })
            ->whereNotIn('status', [
                Sale::STATUS_DISPATCHED,
                Sale::STATUS_DISPATCHED_PARTIALLY,
                Sale::STATUS_RETURNED,
                Sale::STATUS_RETURNED_PARTIALLY,
                'DISPATCHED',
                'DISPATCHED PARTIALLY',
                'RETURNED',
                'RETURNED PARTIALLY',
            ])
            ->where(function ($q) {
                $q->whereNull('paid_amount')->orWhere('paid_amount', '<=', 0);
            })
            ->with('customer')
            ->get();

        foreach ($sales as $sale) {
            $customerName = $sale->customer?->customer_name ?? 'Walk-in Customer';
            $removables[] = [
                'document_type' => 'SALE',
                'id' => $sale->id,
                'reference' => $sale->reference ?? "SALE-{$sale->id}",
                'status' => (string) $sale->status,
                'payment_amount' => (float) ($sale->paid_amount ?? 0),
                'owner_or_customer' => $customerName,
                'created_at' => $sale->created_at?->toIso8601String(),
            ];
        }

        return $removables;
    }

    /**
     * Build preview information for the before/after state.
     */
    private function buildPreviewData(Product $product, Unit $targetUnit, float $factor): array
    {
        $currentBaseUnit = $product->baseUnit ?? Unit::find($product->base_unit_id);
        $liveGlobalQty = (float) $product->product_quantity;
        $projectedGlobalQty = $liveGlobalQty * $factor;

        $stocksData = [];
        $stocks = ProductStock::where('product_id', $product->id)->with('location')->get();
        foreach ($stocks as $st) {
            $qty = (float) $st->quantity;
            $qtyTax = (float) $st->quantity_tax;
            $qtyNonTax = (float) $st->quantity_non_tax;

            $stocksData[] = [
                'location_id' => $st->location_id,
                'location_name' => $st->location?->name ?? "Location #{$st->location_id}",
                'quantity' => $qty,
                'quantity_tax' => $qtyTax,
                'quantity_non_tax' => $qtyNonTax,
                'projected_quantity' => $qty * $factor,
                'projected_quantity_tax' => $qtyTax * $factor,
                'projected_quantity_non_tax' => $qtyNonTax * $factor,
            ];
        }

        $pricesData = [];
        $roundingNotes = [];
        $priceFields = ['average_purchase_price', 'last_purchase_price', 'sale_price', 'tier_1_price', 'tier_2_price'];
        $prices = ProductPrice::where('product_id', $product->id)->with('setting')->get();
        foreach ($prices as $p) {
            $businessName = $p->setting?->company_name ?? "Setting #{$p->setting_id}";
            $row = ['setting_id' => $p->setting_id, 'business_name' => $businessName];
            foreach ($priceFields as $field) {
                $oldValue = $p->{$field} !== null ? (float) $p->{$field} : null;
                $projValue = null;
                if ($oldValue !== null) {
                    $rawProj = $oldValue / $factor;
                    $projValue = round($rawProj, 2);
                    if (abs($rawProj - $projValue) > 0.000001) {
                        $roundingNotes[] = "{$businessName} {$field} rebased from {$oldValue} to exact " . number_format($rawProj, 6) . ", display-rounded to {$projValue}.";
                    }
                }
                $row[$field] = $oldValue;
                $row["projected_{$field}"] = $projValue;
            }
            $pricesData[] = $row;
        }

        $purchaseDetailsData = [];
        $purchaseDetails = PurchaseDetail::where('product_id', $product->id)->with('purchase')->get();
        foreach ($purchaseDetails as $pd) {
            $oldQty = (float) $pd->quantity;
            $oldUnitPrice = (float) $pd->unit_price;
            $rawUnitPrice = $oldUnitPrice / $factor;
            $projUnitPrice = round($rawUnitPrice, 2);
            $purchaseReference = $pd->purchase?->reference ?? "Purchase #{$pd->purchase_id}";
            if (abs($rawUnitPrice - $projUnitPrice) > 0.000001) {
                $roundingNotes[] = "PurchaseDetail #{$pd->id} ({$purchaseReference}) unit_price rebased from {$oldUnitPrice} to exact " . number_format($rawUnitPrice, 6) . ", display-rounded to {$projUnitPrice}.";
            }

            $purchaseDetailsData[] = [
                'id' => $pd->id,
                'purchase_id' => $pd->purchase_id,
                'purchase_reference' => $purchaseReference,
                'quantity' => $oldQty,
                'unit_price' => $oldUnitPrice,
                'sub_total' => (float) $pd->sub_total,
                'projected_quantity' => $oldQty * $factor,
                'projected_unit_price' => $projUnitPrice,
            ];
        }

        return [
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'current_base_unit_id' => $product->base_unit_id,
            'current_base_unit_name' => $currentBaseUnit?->name ?? (string) $product->base_unit_id,
            'target_unit_id' => $targetUnit->id,
            'target_unit_name' => $targetUnit->name,
            'conversion_factor' => $factor,
            'product_quantity' => $liveGlobalQty,
            'projected_product_quantity' => $projectedGlobalQty,
            'stocks' => $stocksData,
            'purchase_details' => $purchaseDetailsData,
            'prices' => $pricesData,
            'current_barcode' => $product->barcode,
            'rounding_notes' => !empty($roundingNotes) ? implode("\n", $roundingNotes) : null,
        ];
    }
}

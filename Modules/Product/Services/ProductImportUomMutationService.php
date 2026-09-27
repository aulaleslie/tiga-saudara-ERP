<?php

namespace Modules\Product\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Product\Entities\BarcodeIdentity;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUomCorrectionAudit;
use Modules\Product\Entities\ProductUomCorrectionRemovedDocument;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Unit;

class ProductImportUomMutationService
{
    public function __construct(
        private ProductImportUomEligibilityService $eligibilityService
    ) {
    }

    /**
     * Perform the UOM correction inside a transaction with lock.
     *
     * @param Product $product
     * @param Unit $targetUnit
     * @param float $factor
     * @param string $reason
     * @param int|null $actorUserId
     * @return ProductUomCorrectionAudit
     * @throws Exception
     */
    public function execute(Product $product, Unit $targetUnit, float $factor, string $reason, ?int $actorUserId = null): ProductUomCorrectionAudit
    {
        if (empty(trim($reason))) {
            throw new Exception('Alasan (reason) wajib diisi untuk melakukan koreksi UOM.');
        }

        return DB::transaction(function () use ($product, $targetUnit, $factor, $reason, $actorUserId) {
            // Row-level lock on the product
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            // Re-validate eligibility under lock
            $eligibility = $this->eligibilityService->checkEligibility($lockedProduct, $targetUnit, $factor);
            if (!$eligibility->isEligible()) {
                throw new Exception('Produk tidak memenuhi syarat koreksi: ' . implode('; ', $eligibility->blockingReasons));
            }

            $preview = $eligibility->preview;
            $oldUnitId = $lockedProduct->base_unit_id;
            $newUnitId = $targetUnit->id;

            // Snapshot before states
            $quantitiesBefore = [
                'product_quantity' => (float) $lockedProduct->product_quantity,
                'stocks' => ProductStock::where('product_id', $lockedProduct->id)
                    ->get(['id', 'location_id', 'quantity', 'quantity_tax', 'quantity_non_tax'])
                    ->toArray(),
            ];

            $costBasisBefore = ProductPrice::where('product_id', $lockedProduct->id)
                ->get(['id', 'setting_id', 'average_purchase_price', 'last_purchase_price', 'sale_price', 'tier_1_price', 'tier_2_price'])
                ->toArray();

            $purchaseDetailsBefore = PurchaseDetail::where('product_id', $lockedProduct->id)
                ->get(['id', 'purchase_id', 'quantity', 'unit_price', 'price', 'sub_total'])
                ->toArray();

            $oldBarcode = $lockedProduct->barcode;

            // 1. Quantity Rebase
            // 1.1 Multiply products.product_quantity
            $newProductQty = (float) $lockedProduct->product_quantity * $factor;
            $lockedProduct->product_quantity = $newProductQty;
            $lockedProduct->unit_id = $newUnitId;
            $lockedProduct->base_unit_id = $newUnitId;

            // 1.1b Clear the barcode: this correction does not migrate the barcode to
            // the retained old-base unit (unlike the receiving-normalization tool),
            // it simply empties it, per explicit operator instruction. The owning
            // barcode_identities registry row is removed in the same transaction so
            // no dangling registration is left pointing at a barcode the product no
            // longer carries.
            if (!empty($oldBarcode)) {
                $lockedProduct->barcode = null;
            }
            $lockedProduct->save();

            if (!empty($oldBarcode)) {
                BarcodeIdentity::where('product_id', $lockedProduct->id)
                    ->where('value', $oldBarcode)
                    ->delete();
            }

            // 1.2 Multiply each product_stocks quantity bucket
            $stocks = ProductStock::where('product_id', $lockedProduct->id)->lockForUpdate()->get();
            foreach ($stocks as $stock) {
                $stock->quantity = (float) $stock->quantity * $factor;
                $stock->quantity_tax = (float) $stock->quantity_tax * $factor;
                $stock->quantity_non_tax = (float) $stock->quantity_non_tax * $factor;
                $stock->save();
            }

            // 1.3 Multiply originating adjustment transactions' quantity fields
            // All existing ADJ transactions for this product are rebased so the ledger remains consistent
            $transactions = Transaction::where('product_id', $lockedProduct->id)->lockForUpdate()->get();
            foreach ($transactions as $tx) {
                if ($tx->quantity !== null) {
                    $tx->quantity = (float) $tx->quantity * $factor;
                }
                if ($tx->current_quantity !== null) {
                    $tx->current_quantity = (float) $tx->current_quantity * $factor;
                }
                if ($tx->previous_quantity !== null) {
                    $tx->previous_quantity = (float) $tx->previous_quantity * $factor;
                }
                if ($tx->after_quantity !== null) {
                    $tx->after_quantity = (float) $tx->after_quantity * $factor;
                }
                if ($tx->previous_quantity_at_location !== null) {
                    $tx->previous_quantity_at_location = (float) $tx->previous_quantity_at_location * $factor;
                }
                if ($tx->after_quantity_at_location !== null) {
                    $tx->after_quantity_at_location = (float) $tx->after_quantity_at_location * $factor;
                }
                if ($tx->quantity_tax !== null) {
                    $tx->quantity_tax = (float) $tx->quantity_tax * $factor;
                }
                if ($tx->quantity_non_tax !== null) {
                    $tx->quantity_non_tax = (float) $tx->quantity_non_tax * $factor;
                }
                $tx->save();
            }

            // 1.4 Rebase historical purchase_details rows for this product.
            // These are never used as a source of truth for live stock math (their
            // quantities do not reconcile with product_stocks for import-origin
            // products), but they must still be denominated in the new unit going
            // forward: quantity * factor, unit_price / factor, so quantity * unit_price
            // (and therefore sub_total, which is left untouched) stays invariant.
            $purchaseDetails = PurchaseDetail::where('product_id', $lockedProduct->id)->with('purchase')->lockForUpdate()->get();
            $roundingNotesList = [];
            foreach ($purchaseDetails as $pd) {
                $oldQuantity = (float) $pd->quantity;
                $oldUnitPrice = (float) $pd->unit_price;
                $purchaseReference = $pd->purchase?->reference ?? "Purchase #{$pd->purchase_id}";

                $rawUnitPrice = $oldUnitPrice / $factor;
                $newUnitPrice = round($rawUnitPrice, 2);
                if (abs($rawUnitPrice - $newUnitPrice) > 0.000001) {
                    $roundingNotesList[] = "PurchaseDetail #{$pd->id} ({$purchaseReference}) unit_price rebased from {$oldUnitPrice} to exact " . number_format($rawUnitPrice, 6) . ", display-rounded to {$newUnitPrice}.";
                }

                $pd->quantity = $oldQuantity * $factor;
                $pd->unit_price = $newUnitPrice;
                $pd->price = $newUnitPrice;
                $pd->save();
            }

            // 2. Cost-basis Rebase
            // Divide purchase-side (average_purchase_price, last_purchase_price) and
            // sale-side (sale_price, tier_1_price, tier_2_price) prices by factor, per
            // the user's explicit instruction that both sides must be rebased so unit
            // economics remain correct in the new base unit (e.g. Rp/BKS -> Rp/PCS).
            $prices = ProductPrice::where('product_id', $lockedProduct->id)->with('setting')->lockForUpdate()->get();
            foreach ($prices as $price) {
                $businessName = $price->setting?->company_name ?? "Setting #{$price->setting_id}";
                foreach ([
                    'average_purchase_price',
                    'last_purchase_price',
                    'sale_price',
                    'tier_1_price',
                    'tier_2_price',
                ] as $field) {
                    if ($price->{$field} === null) {
                        continue;
                    }

                    $oldValue = (float) $price->{$field};
                    $rawValue = $oldValue / $factor;
                    $roundedValue = round($rawValue, 2);
                    if (abs($rawValue - $roundedValue) > 0.000001) {
                        $roundingNotesList[] = "{$businessName} {$field} rebased from {$oldValue} to exact " . number_format($rawValue, 6) . ", display-rounded to {$roundedValue}.";
                    }
                    $price->{$field} = $roundedValue;
                }
                $price->save();
            }

            // Snapshot after states
            $quantitiesAfter = [
                'product_quantity' => (float) $lockedProduct->product_quantity,
                'stocks' => ProductStock::where('product_id', $lockedProduct->id)
                    ->get(['id', 'location_id', 'quantity', 'quantity_tax', 'quantity_non_tax'])
                    ->toArray(),
            ];

            $costBasisAfter = ProductPrice::where('product_id', $lockedProduct->id)
                ->get(['id', 'setting_id', 'average_purchase_price', 'last_purchase_price', 'sale_price', 'tier_1_price', 'tier_2_price'])
                ->toArray();

            $purchaseDetailsAfter = PurchaseDetail::where('product_id', $lockedProduct->id)
                ->get(['id', 'purchase_id', 'quantity', 'unit_price', 'price', 'sub_total'])
                ->toArray();

            // 3. Document removal
            $removableDocs = $eligibility->removableDocuments;
            $posIdsToDelete = [];
            $saleIdsToDelete = [];

            foreach ($removableDocs as $doc) {
                if ($doc['document_type'] === 'POS') {
                    $posIdsToDelete[] = $doc['id'];
                } elseif ($doc['document_type'] === 'SALE') {
                    $saleIdsToDelete[] = $doc['id'];
                }
            }

            if (!empty($posIdsToDelete)) {
                PosTransactionLine::whereIn('pos_transaction_id', $posIdsToDelete)->delete();
                PosTransaction::whereIn('id', $posIdsToDelete)->delete();
            }

            if (!empty($saleIdsToDelete)) {
                SaleDetails::whereIn('sale_id', $saleIdsToDelete)->delete();
                Sale::whereIn('id', $saleIdsToDelete)->delete();
            }

            // 4. Create Audit Record
            $roundingNotes = !empty($roundingNotesList) ? implode("\n", $roundingNotesList) : null;
            if (!empty($oldBarcode)) {
                $barcodeNote = "Barcode '{$oldBarcode}' dikosongkan dan registrasi barcode_identities terkait dihapus.";
                $roundingNotes = $roundingNotes ? $roundingNotes . "\n" . $barcodeNote : $barcodeNote;
            }
            $audit = ProductUomCorrectionAudit::create([
                'product_id' => $lockedProduct->id,
                'old_unit_id' => $oldUnitId,
                'new_unit_id' => $newUnitId,
                'conversion_factor' => $factor,
                'quantities_before' => $quantitiesBefore,
                'quantities_after' => $quantitiesAfter,
                'cost_basis_before' => $costBasisBefore,
                'cost_basis_after' => $costBasisAfter,
                'purchase_details_before' => $purchaseDetailsBefore,
                'purchase_details_after' => $purchaseDetailsAfter,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'rounding_notes' => $roundingNotes,
                'created_at' => now(),
            ]);

            // Save removed documents audit entries
            foreach ($removableDocs as $doc) {
                ProductUomCorrectionRemovedDocument::create([
                    'audit_id' => $audit->id,
                    'document_type' => $doc['document_type'],
                    'document_id' => $doc['id'],
                    'reference' => $doc['reference'],
                    'status' => $doc['status'],
                    'payment_amount' => $doc['payment_amount'],
                    'owner_or_customer' => $doc['owner_or_customer'],
                    'document_created_at' => !empty($doc['created_at']) ? $doc['created_at'] : null,
                    'created_at' => now(),
                ]);
            }

            return $audit->load(['removedDocuments', 'oldUnit', 'newUnit', 'product']);
        });
    }
}

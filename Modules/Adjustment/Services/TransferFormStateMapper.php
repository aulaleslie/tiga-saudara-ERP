<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\DTOs\TransferFormLineState;
use Modules\Adjustment\DTOs\TransferFormState;
use Modules\Adjustment\Entities\Transfer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;

class TransferFormStateMapper
{
    /**
     * A historical transfer is mixed-condition (unreadable as one explicit
     * mode) when it has no stock_condition and its product rows mix good and
     * broken buckets, whether within one line or across lines. Such records
     * remain viewable but are not treated as valid new/editable drafts until
     * an operator explicitly picks a mode, which clears the incompatible rows.
     */
    public function isMixedConditionHistory(Transfer $transfer): bool
    {
        if ($transfer->hasExplicitCondition()) {
            return false;
        }

        $transfer->loadMissing('products');

        $hasGood = false;
        $hasBroken = false;

        foreach ($transfer->products as $transferProduct) {
            if ($transferProduct->quantity_tax > 0 || $transferProduct->quantity_non_tax > 0) {
                $hasGood = true;
            }
            if ($transferProduct->quantity_broken_tax > 0 || $transferProduct->quantity_broken_non_tax > 0) {
                $hasBroken = true;
            }
        }

        return $hasGood && $hasBroken;
    }

    /**
     * Maps an existing Transfer into the array structure expected by the Livewire form.
     */
    public function mapToLivewireRows(Transfer $transfer): array
    {
        $transfer->loadMissing('products.product');

        $canViewSystemStock = TransferStockVisibility::canView();

        $rows = [];
        foreach ($transfer->products as $transferProduct) {
            $product = $transferProduct->product;

            $baseRow = [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => $product->serial_number_required,
                'scan_quantity_multiplier' => 1,
            ];

            // Protected system stock is only ever added to the row for a
            // privileged user; a blind editor's hydrated row omits the
            // `stock` key entirely rather than receiving zeroed/placeholder
            // values (task 2.2/3.2's "omitted, not masked" requirement).
            if ($canViewSystemStock) {
                $stock = ProductStock::where('product_id', $product->id)
                    ->where('location_id', $transfer->origin_location_id)
                    ->first();

                $baseRow['stock'] = [
                    'total'                   => $stock?->quantity                 ?? 0,
                    'quantity_tax'            => $stock?->quantity_tax             ?? 0,
                    'quantity_non_tax'        => $stock?->quantity_non_tax         ?? 0,
                    'broken_quantity_tax'     => $stock?->broken_quantity_tax      ?? 0,
                    'broken_quantity_non_tax' => $stock?->broken_quantity_non_tax  ?? 0,
                ];
            }

            // Normal mode
            if ($transferProduct->quantity_tax > 0 || $transferProduct->quantity_non_tax > 0) {
                $normalRow = $baseRow;
                $normalRow['is_broken_mode'] = false;
                $normalRow['requested_quantity'] = $transferProduct->quantity_tax + $transferProduct->quantity_non_tax;

                if ($canViewSystemStock) {
                    $normalRow['quantity_tax'] = $transferProduct->quantity_tax;
                    $normalRow['quantity_non_tax'] = $transferProduct->quantity_non_tax;
                    $normalRow['broken_quantity_tax'] = 0;
                    $normalRow['broken_quantity_non_tax'] = 0;
                }

                $normalRow['serial_numbers'] = $this->projectSerialIdentities(
                    collect($transferProduct->serial_numbers ?? [])->filter(fn($s) => empty($s['is_broken'])),
                    $canViewSystemStock
                );

                $rows[] = $normalRow;
            }

            // Broken mode
            if ($transferProduct->quantity_broken_tax > 0 || $transferProduct->quantity_broken_non_tax > 0) {
                $brokenRow = $baseRow;
                $brokenRow['is_broken_mode'] = true;
                $brokenRow['requested_quantity'] = $transferProduct->quantity_broken_tax + $transferProduct->quantity_broken_non_tax;

                if ($canViewSystemStock) {
                    $brokenRow['quantity_tax'] = 0;
                    $brokenRow['quantity_non_tax'] = 0;
                    $brokenRow['broken_quantity_tax'] = $transferProduct->quantity_broken_tax;
                    $brokenRow['broken_quantity_non_tax'] = $transferProduct->quantity_broken_non_tax;
                }

                $brokenRow['serial_numbers'] = $this->projectSerialIdentities(
                    collect($transferProduct->serial_numbers ?? [])->filter(fn($s) => !empty($s['is_broken'])),
                    $canViewSystemStock
                );

                $rows[] = $brokenRow;
            }

            // Fallback for empty transfer product
            if ($transferProduct->quantity_tax == 0 && $transferProduct->quantity_non_tax == 0 &&
                $transferProduct->quantity_broken_tax == 0 && $transferProduct->quantity_broken_non_tax == 0) {
                $normalRow = $baseRow;
                $normalRow['is_broken_mode'] = false;
                $normalRow['requested_quantity'] = 0;

                if ($canViewSystemStock) {
                    $normalRow['quantity_tax'] = 0;
                    $normalRow['quantity_non_tax'] = 0;
                    $normalRow['broken_quantity_tax'] = 0;
                    $normalRow['broken_quantity_non_tax'] = 0;
                }

                $normalRow['serial_numbers'] = [];
                $rows[] = $normalRow;
            }
        }

        return $rows;
    }

    /**
     * Projects a persisted transfer line's serial snapshot down to identity
     * only (id + serial_number) for a blind editor, matching the shape a
     * blind operator's own new selection would carry -- tax_id, taxable,
     * and is_broken provenance are protected system information and are
     * only included for a privileged user.
     */
    private function projectSerialIdentities(\Illuminate\Support\Collection $serials, bool $canViewSystemStock): array
    {
        if ($canViewSystemStock) {
            return $serials->values()->all();
        }

        return $serials
            ->map(fn($s) => [
                'id'            => $s['id'] ?? null,
                'serial_number' => $s['serial_number'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Convert Livewire form rows to TransferFormState.
     * This builds normalized line states that preserve bucket selections.
     *
     * @param array $rows Array of rows from Livewire form
     * @param int $originLocationId
     * @param int $destinationLocationId
     * @return TransferFormState
     */
    public function mapToTransferFormState(array $rows, int $originLocationId, ?int $destinationLocationId, ?string $stockCondition = null): TransferFormState
    {
        $state = new TransferFormState($originLocationId, $destinationLocationId, $stockCondition);
        
        foreach ($rows as $row) {
            $productId = $row['id'] ?? $row['product_id'] ?? null;
            if (!$productId) {
                continue;
            }

            $product = Product::find($productId);
            if (!$product) {
                continue;
            }

            $hasBucketState = array_key_exists('quantity_tax', $row)
                || array_key_exists('quantity_non_tax', $row)
                || array_key_exists('broken_quantity_tax', $row)
                || array_key_exists('broken_quantity_non_tax', $row);

            $isBrokenMode = (bool) ($row['is_broken_mode'] ?? false);
            $serials = $row['serial_numbers'] ?? [];

            if (!$hasBucketState) {
                // Blind row: no bucket breakdown was ever exposed to the
                // client (task 3.2), so only the operator's single
                // requested_quantity total and the transfer-wide mode are
                // available here. The authoritative non-tax-first
                // allocation is computed server-side from this total in
                // TransferDraftService::buildProductsData -- this mapper
                // never needs to know or trust a bucket split for a blind
                // submission.
                $requestedTotal = (int) ($row['requested_quantity'] ?? 0);

                if ($requestedTotal > 0 || !empty($serials)) {
                    $line = new TransferFormLineState(
                        $productId,
                        $product->product_name,
                        $product->product_code,
                        $product->barcode,
                        !empty($row['serial_number_required']),
                        $isBrokenMode,
                        !empty($serials) ? count($serials) : $requestedTotal
                    );

                    $line->selectedSerials = $serials;

                    $state->addLine($line);
                }

                continue;
            }

            // Determine which mode(s) this row is for based on bucket quantities
            $quantityTax = (int)($row['quantity_tax'] ?? 0);
            $quantityNonTax = (int)($row['quantity_non_tax'] ?? 0);
            $brokenQuantityTax = (int)($row['broken_quantity_tax'] ?? 0);
            $brokenQuantityNonTax = (int)($row['broken_quantity_non_tax'] ?? 0);

            // Normal mode: non-broken items
            if ($quantityTax > 0 || $quantityNonTax > 0) {
                $normalTotal = $quantityTax + $quantityNonTax;
                $line = new TransferFormLineState(
                    $productId,
                    $product->product_name,
                    $product->product_code,
                    $product->barcode,
                    !empty($row['serial_number_required']),
                    false, // normal mode
                    $normalTotal
                );

                // Preserve serial selections if available
                $line->selectedSerials = $row['serial_numbers'] ?? [];

                $state->addLine($line);
            }

            // Broken mode: broken items
            if ($brokenQuantityTax > 0 || $brokenQuantityNonTax > 0) {
                $brokenTotal = $brokenQuantityTax + $brokenQuantityNonTax;
                $line = new TransferFormLineState(
                    $productId,
                    $product->product_name,
                    $product->product_code,
                    $product->barcode,
                    !empty($row['serial_number_required']),
                    true, // broken mode
                    $brokenTotal
                );

                // For broken mode, might also have serials
                $line->selectedSerials = $row['serial_numbers'] ?? [];

                $state->addLine($line);
            }
        }
        
        return $state;
    }
}

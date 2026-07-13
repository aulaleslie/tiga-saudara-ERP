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
     * Maps an existing Transfer into the array structure expected by the Livewire form.
     */
    public function mapToLivewireRows(Transfer $transfer): array
    {
        $transfer->loadMissing('products.product');

        $rows = [];
        foreach ($transfer->products as $transferProduct) {
            $product = $transferProduct->product;

            $stock = ProductStock::where('product_id', $product->id)
                ->where('location_id', $transfer->origin_location_id)
                ->first();

            $stockData = [
                'total'                   => $stock?->quantity                 ?? 0,
                'quantity_tax'            => $stock?->quantity_tax             ?? 0,
                'quantity_non_tax'        => $stock?->quantity_non_tax         ?? 0,
                'broken_quantity_tax'     => $stock?->broken_quantity_tax      ?? 0,
                'broken_quantity_non_tax' => $stock?->broken_quantity_non_tax  ?? 0,
            ];

            $baseRow = [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => $product->serial_number_required,
                'stock' => $stockData,
                'scan_quantity_multiplier' => 1,
            ];

            // Normal mode
            if ($transferProduct->quantity_tax > 0 || $transferProduct->quantity_non_tax > 0) {
                $normalRow = $baseRow;
                $normalRow['is_broken_mode'] = false;
                $normalRow['requested_quantity'] = $transferProduct->quantity_tax + $transferProduct->quantity_non_tax;
                $normalRow['quantity_tax'] = $transferProduct->quantity_tax;
                $normalRow['quantity_non_tax'] = $transferProduct->quantity_non_tax;
                $normalRow['broken_quantity_tax'] = 0;

                $normalRow['serial_numbers'] = collect($transferProduct->serial_numbers ?? [])
                    ->filter(fn($s) => empty($s['is_broken']))
                    ->values()
                    ->all();

                $rows[] = $normalRow;
            }

            // Broken mode
            if ($transferProduct->quantity_broken_tax > 0 || $transferProduct->quantity_broken_non_tax > 0) {
                $brokenRow = $baseRow;
                $brokenRow['is_broken_mode'] = true;
                $brokenRow['requested_quantity'] = $transferProduct->quantity_broken_tax + $transferProduct->quantity_broken_non_tax;
                $brokenRow['quantity_tax'] = 0;
                $brokenRow['quantity_non_tax'] = 0;
                $brokenRow['broken_quantity_tax'] = $transferProduct->quantity_broken_tax;
                $brokenRow['broken_quantity_non_tax'] = $transferProduct->quantity_broken_non_tax;

                $brokenRow['serial_numbers'] = collect($transferProduct->serial_numbers ?? [])
                    ->filter(fn($s) => !empty($s['is_broken']))
                    ->values()
                    ->all();

                $rows[] = $brokenRow;
            }

            // Fallback for empty transfer product
            if ($transferProduct->quantity_tax == 0 && $transferProduct->quantity_non_tax == 0 &&
                $transferProduct->quantity_broken_tax == 0 && $transferProduct->quantity_broken_non_tax == 0) {
                $normalRow = $baseRow;
                $normalRow['is_broken_mode'] = false;
                $normalRow['requested_quantity'] = 0;
                $normalRow['quantity_tax'] = 0;
                $normalRow['quantity_non_tax'] = 0;
                $normalRow['broken_quantity_tax'] = 0;
                $normalRow['broken_quantity_non_tax'] = 0;
                $normalRow['serial_numbers'] = [];
                $rows[] = $normalRow;
            }
        }

        return $rows;
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
    public function mapToTransferFormState(array $rows, int $originLocationId, int $destinationLocationId): TransferFormState
    {
        $state = new TransferFormState($originLocationId, $destinationLocationId);
        
        foreach ($rows as $row) {
            $productId = $row['id'] ?? $row['product_id'] ?? null;
            if (!$productId) {
                continue;
            }

            $product = Product::find($productId);
            if (!$product) {
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

<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Product\Entities\ProductStock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferAllocationService
{
    /**
     * Authoritatively allocate stock for a transfer, ignoring client-provided buckets.
     * This locks the necessary stock rows, computes the non-tax-first allocation,
     * updates the TransferProduct records, and returns the computed state.
     */
    public function allocate(Transfer $transfer): void
    {
        $transfer->loadMissing('products.product');

        foreach ($transfer->products as $transferProduct) {
            $product = $transferProduct->product;
            
            // Lock the stock row
            $stock = ProductStock::where('product_id', $product->id)
                ->where('location_id', $transfer->origin_location_id)
                ->lockForUpdate()
                ->first();

            if (!$stock) {
                throw new RuntimeException("Data stok tidak ditemukan untuk produk {$product->product_name} di lokasi asal.");
            }

            // Handle serialized vs non-serialized
            if ($product->serial_number_required) {
                $this->allocateSerialized($transferProduct, $transfer->origin_location_id);
            } else {
                $this->allocateNonSerialized($transferProduct, $stock);
            }
        }
    }

    private function allocateSerialized(TransferProduct $transferProduct, int $locationId): void
    {
        $serials = $transferProduct->serial_numbers ?? [];
        $tax = 0;
        $nonTax = 0;
        $brokenTax = 0;
        $brokenNonTax = 0;

        foreach ($serials as $serialData) {
            $serialId = $serialData['id'] ?? null;
            if (!$serialId) {
                continue;
            }

            // Lock the serial row
            $serial = \Modules\Product\Entities\ProductSerialNumber::where('id', $serialId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            if (!$serial) {
                throw new RuntimeException("Serial number tidak ditemukan atau tidak tersedia di lokasi ini.");
            }

            $isTax = (bool) $serial->tax_id;
            $isBroken = (bool) $serial->is_broken;

            if ($isBroken) {
                if ($isTax) $brokenTax++;
                else $brokenNonTax++;
            } else {
                if ($isTax) $tax++;
                else $nonTax++;
            }
        }

        $transferProduct->update([
            'quantity' => $tax + $nonTax + $brokenTax + $brokenNonTax,
            'quantity_tax' => $tax,
            'quantity_non_tax' => $nonTax,
            'quantity_broken_tax' => $brokenTax,
            'quantity_broken_non_tax' => $brokenNonTax,
        ]);
    }

    private function allocateNonSerialized(TransferProduct $transferProduct, ProductStock $stock): void
    {
        $requestedQuantity = (int) $transferProduct->quantity;
        
        // We only use standard quantities for non-serialized in transfers right now.
        // Broken mode is not explicitly tracked as a separate request quantity on transferProduct?
        // Wait, TransferProduct has `quantity_broken_tax` and `quantity_broken_non_tax`, but the requested total is just `quantity`.
        // If the user requested broken items, it would be in `quantity_broken_tax` + `quantity_broken_non_tax`.
        // Let's assume if there is broken quantity requested, we allocate broken first.
        $requestedBroken = (int) ($transferProduct->quantity_broken_tax + $transferProduct->quantity_broken_non_tax);
        $requestedNormal = $requestedQuantity - $requestedBroken;
        
        $allocatedTax = 0;
        $allocatedNonTax = 0;
        $allocatedBrokenTax = 0;
        $allocatedBrokenNonTax = 0;

        if ($requestedNormal > 0) {
            $availableNonTax = (int) $stock->quantity_non_tax;
            $availableTax = (int) $stock->quantity_tax;

            $allocNonTax = min($requestedNormal, $availableNonTax);
            $rem = $requestedNormal - $allocNonTax;
            $allocTax = min($rem, $availableTax);

            if ($allocNonTax + $allocTax < $requestedNormal) {
                throw new RuntimeException("Stok tidak mencukupi untuk dialokasikan ke produk ID {$stock->product_id}.");
            }
            
            $allocatedNonTax = $allocNonTax;
            $allocatedTax = $allocTax;
        }
        
        if ($requestedBroken > 0) {
            $availableBrokenNonTax = (int) $stock->broken_quantity_non_tax;
            $availableBrokenTax = (int) $stock->broken_quantity_tax;
            
            $allocBrokenNonTax = min($requestedBroken, $availableBrokenNonTax);
            $rem = $requestedBroken - $allocBrokenNonTax;
            $allocBrokenTax = min($rem, $availableBrokenTax);
            
            if ($allocBrokenNonTax + $allocBrokenTax < $requestedBroken) {
                throw new RuntimeException("Stok rusak tidak mencukupi untuk dialokasikan ke produk ID {$stock->product_id}.");
            }
            
            $allocatedBrokenNonTax = $allocBrokenNonTax;
            $allocatedBrokenTax = $allocBrokenTax;
        }

        $transferProduct->update([
            'quantity_tax' => $allocatedTax,
            'quantity_non_tax' => $allocatedNonTax,
            'quantity_broken_tax' => $allocatedBrokenTax,
            'quantity_broken_non_tax' => $allocatedBrokenNonTax,
        ]);
    }
}

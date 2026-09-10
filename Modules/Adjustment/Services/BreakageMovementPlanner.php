<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Collection;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;

/**
 * Computes the breakage good-to-broken movement for a pending Adjustment
 * (type 'breakage') against CURRENT stock/serial state: per product, the
 * current good/broken buckets, the requested movement, the projected
 * post-approval result, and any drift/conflict that would block approval.
 *
 * This is an informational preview only (design.md "Preview current
 * consequences but revalidate under approval locks") -- it never locks rows
 * and is never authoritative for mutation. BreakageApprovalService
 * recomputes everything itself under locks and never trusts this planner's
 * output.
 */
class BreakageMovementPlanner
{
    public function __construct(
        private BreakageSerialPolicy $serialPolicy,
    ) {
    }

    /**
     * @return array{
     *   location_id: int|null,
     *   location_name: string|null,
     *   setting_id: int|null,
     *   is_pkp: bool|null,
     *   products: array,
     *   conflicts: string[],
     *   approvable: bool,
     * }
     */
    public function plan(Adjustment $adjustment): array
    {
        $location = $adjustment->location_id ? Location::with('setting')->find($adjustment->location_id) : null;

        if (!$location || !$location->setting) {
            return [
                'location_id' => $adjustment->location_id,
                'location_name' => $location?->name,
                'setting_id' => null,
                'is_pkp' => null,
                'products' => [],
                'conflicts' => ['Lokasi atau pengaturan pemilik lokasi tidak ditemukan.'],
                'approvable' => false,
            ];
        }

        $isPkp = (bool) $location->setting->is_pkp;

        $adjustment->loadMissing('adjustedProducts.product.baseUnit');

        $conflicts = [];
        $products = [];

        foreach ($adjustment->adjustedProducts as $adjustedProduct) {
            $product = $adjustedProduct->product;
            if (!$product) {
                // A row referencing a deleted/missing product must block the
                // preview rather than being silently omitted -- otherwise a
                // pending document could appear fully approvable while
                // BreakageApprovalService later rejects it for the same row.
                $conflicts[] = sprintf(
                    'Produk dengan ID %d pada dokumen ini tidak ditemukan.',
                    $adjustedProduct->product_id
                );
                continue;
            }

            $result = $this->planProduct($adjustedProduct, $product, $location, $isPkp);
            $conflicts = array_merge($conflicts, $result['conflicts']);
            $products[] = $result;
        }

        return [
            'location_id' => (int) $location->id,
            'location_name' => (string) $location->name,
            'setting_id' => (int) $location->setting_id,
            'is_pkp' => $isPkp,
            'products' => $products,
            'conflicts' => array_values(array_unique($conflicts)),
            'approvable' => empty($conflicts),
        ];
    }

    private function planProduct($adjustedProduct, Product $product, Location $location, bool $isPkp): array
    {
        $isSerialized = (bool) $product->serial_number_required;

        $stock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->first();

        $currentGoodTax = (int) round((float) ($stock?->quantity_tax ?? 0));
        $currentGoodNonTax = (int) round((float) ($stock?->quantity_non_tax ?? 0));
        $currentBadTax = (int) round((float) ($stock?->broken_quantity_tax ?? 0));
        $currentBadNonTax = (int) round((float) ($stock?->broken_quantity_non_tax ?? 0));

        $currentGood = $isPkp ? $currentGoodTax : $currentGoodNonTax;
        $currentBad = $isPkp ? $currentBadTax : $currentBadNonTax;
        $unexpectedBucket = $isPkp ? $currentGoodNonTax : $currentGoodTax;

        $conflicts = [];
        $base = [
            'product_id' => (int) $product->id,
            'product_name' => (string) $product->product_name,
            'product_code' => (string) $product->product_code,
            'base_unit' => (string) ($product->baseUnit?->unit_name ?? $product->baseUnit?->name ?? ''),
            'is_serialized' => $isSerialized,
            'current' => [
                'good' => $currentGood,
                'bad' => $currentBad,
            ],
        ];

        if ($isSerialized) {
            $serialIds = array_map('intval', (array) (json_decode((string) $adjustedProduct->serial_numbers, true) ?? []));

            $classification = $this->serialPolicy->classify(
                product: $product,
                location: $location,
                isPkp: $isPkp,
                serialIds: $serialIds,
            );

            $conflicts = array_merge($conflicts, $classification['conflicts']);
            $movement = count($classification['eligible']);

            $projectedGood = max(0, $currentGood - $movement);
            $projectedBad = $currentBad + $movement;

            return array_merge($base, [
                'movement' => $movement,
                'projected' => [
                    'good' => $projectedGood,
                    'bad' => $projectedBad,
                ],
                'serials' => $classification['eligible'],
                'serial_conflicts' => $classification['serial_conflicts'],
                'unexpected_bucket_quantity' => $unexpectedBucket,
                'conflicts' => $conflicts,
            ]);
        }

        $requestedTax = (int) ($adjustedProduct->quantity_tax ?? 0);
        $requestedNonTax = (int) ($adjustedProduct->quantity_non_tax ?? 0);
        $movement = $isPkp ? $requestedTax : $requestedNonTax;
        $otherBucketRequested = $isPkp ? $requestedNonTax : $requestedTax;

        if ($otherBucketRequested > 0) {
            $conflicts[] = sprintf(
                'Produk %s: permintaan berada pada kelompok pajak yang tidak sesuai dengan pengaturan PKP lokasi ini.',
                $product->product_name
            );
        }

        if ($movement <= 0) {
            $conflicts[] = sprintf('Produk %s: kuantitas breakage harus lebih dari 0.', $product->product_name);
        }

        if ($movement > $currentGood) {
            $conflicts[] = sprintf(
                'Produk %s: stok baik tersedia (%d) tidak mencukupi untuk permintaan breakage (%d).',
                $product->product_name,
                $currentGood,
                $movement
            );
        }

        $projectedGood = max(0, $currentGood - $movement);
        $projectedBad = $currentBad + $movement;

        return array_merge($base, [
            'movement' => $movement,
            'projected' => [
                'good' => $projectedGood,
                'bad' => $projectedBad,
            ],
            'serials' => [],
            'serial_conflicts' => [],
            'unexpected_bucket_quantity' => $unexpectedBucket,
            'conflicts' => $conflicts,
        ]);
    }
}

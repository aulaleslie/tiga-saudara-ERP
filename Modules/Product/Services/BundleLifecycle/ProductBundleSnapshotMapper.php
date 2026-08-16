<?php

namespace Modules\Product\Services\BundleLifecycle;

class ProductBundleSnapshotMapper
{
    /**
     * Map a raw bundle parent and components array / model data into a canonical bundle snapshot array.
     *
     * @param array<string, mixed> $bundleLine Raw line array containing bundle keys or options
     * @return array{
     *     bundle_id: int,
     *     bundle_name: ?string,
     *     bundle_price: float,
     *     bundle_sale_price: float,
     *     bundle_items: array<int, array{
     *         bundle_item_id: ?int,
     *         product_id: int,
     *         product_name: ?string,
     *         quantity_per_bundle: float,
     *         quantity: float,
     *         informational_item_price: float,
     *         stock_managed: bool,
     *         serial_number_required: bool
     *     }>
     * }|null
     */
    public function toCanonicalBundleSnapshot(array $bundleLine): ?array
    {
        $options = is_array($bundleLine['options'] ?? null) ? $bundleLine['options'] : [];
        $bundleId = $bundleLine['bundle_id'] ?? ($options['bundle_id'] ?? null);

        if ($bundleId === null || (is_numeric($bundleId) && (int) $bundleId <= 0)) {
            return null;
        }

        $bundleId = (int) $bundleId;

        $bundleName = $bundleLine['bundle_name']
            ?? ($options['bundle_name']
            ?? ($bundleLine['name']
            ?? ($bundleLine['product_name'] ?? null)));
        $bundleName = $bundleName !== null ? trim((string) $bundleName) : null;
        if ($bundleName === '') {
            $bundleName = null;
        }

        $bundlePrice = isset($bundleLine['bundle_price'])
            ? (float) $bundleLine['bundle_price']
            : (isset($options['bundle_price']) ? (float) $options['bundle_price'] : 0.0);

        $bundleSalePrice = isset($bundleLine['unit_price'])
            ? (float) $bundleLine['unit_price']
            : (isset($bundleLine['price'])
                ? (float) $bundleLine['price']
                : (isset($options['unit_price']) ? (float) $options['unit_price'] : 0.0));

        $parentQty = max(1.0, (float) ($bundleLine['qty'] ?? ($bundleLine['quantity'] ?? 1.0)));

        $rawComponents = $bundleLine['bundle_items']
            ?? ($options['bundle_items']
            ?? ($bundleLine['items'] ?? []));

        $canonicalComponents = $this->canonicalizeComponents((array) $rawComponents, $parentQty);

        return [
            'bundle_id' => $bundleId,
            'bundle_name' => $bundleName,
            'bundle_price' => round($bundlePrice, 2),
            'bundle_sale_price' => round($bundleSalePrice, 2),
            'bundle_items' => $canonicalComponents,
        ];
    }

    /**
     * Canonicalize an array of bundle component items deterministically.
     *
     * @param array<int|string, mixed> $rawComponents
     * @param float $parentQty
     * @return array<int, array{
     *     bundle_item_id: ?int,
     *     product_id: int,
     *     product_name: ?string,
     *     quantity_per_bundle: float,
     *     quantity: float,
     *     informational_item_price: float,
     *     stock_managed: bool,
     *     serial_number_required: bool
     * }>
     */
    public function canonicalizeComponents(array $rawComponents, float $parentQty = 1.0): array
    {
        $normalized = [];

        foreach ($rawComponents as $comp) {
            if (! is_array($comp) && ! is_object($comp)) {
                continue;
            }
            $compArray = (array) $comp;

            $productId = (int) ($compArray['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $bundleItemId = isset($compArray['bundle_item_id']) && is_numeric($compArray['bundle_item_id']) && (int) $compArray['bundle_item_id'] > 0
                ? (int) $compArray['bundle_item_id']
                : (isset($compArray['id']) && is_numeric($compArray['id']) && (int) $compArray['id'] > 0 ? (int) $compArray['id'] : null);

            $productName = $compArray['product_name']
                ?? ($compArray['name'] ?? null);
            $productName = $productName !== null ? trim((string) $productName) : null;
            if ($productName === '') {
                $productName = null;
            }

            // Resolve quantity per bundle with alias handling
            $qtyPerBundle = null;
            if (isset($compArray['quantity_per_bundle']) && is_numeric($compArray['quantity_per_bundle'])) {
                $qtyPerBundle = (float) $compArray['quantity_per_bundle'];
                // If quantity was explicitly mutated in compArray, sync with quantity
                if (isset($compArray['quantity']) && is_numeric($compArray['quantity'])) {
                    $explicitQty = (float) $compArray['quantity'];
                    // If quantity changed relative to parentQty or is different, reflect in qtyPerBundle if parentQty is 1
                    if ($parentQty <= 1.0) {
                        $qtyPerBundle = $explicitQty;
                    }
                }
            } elseif (isset($compArray['quantity']) && is_numeric($compArray['quantity'])) {
                // If quantity is passed without quantity_per_bundle, check if it's total quantity or qty per bundle
                // In POS cart, bundle_items.quantity is quantity per bundle (or total when parent qty is 1)
                $qtyPerBundle = (float) $compArray['quantity'];
            } elseif (isset($compArray['qty']) && is_numeric($compArray['qty'])) {
                $qtyPerBundle = (float) $compArray['qty'];
            } else {
                $qtyPerBundle = 1.0;
            }

            $qtyPerBundle = round(max(0.0, $qtyPerBundle), 4);
            $totalQuantity = round($qtyPerBundle * max(1.0, $parentQty), 4);

            $informationalPrice = 0.0;
            if (isset($compArray['informational_item_price']) && is_numeric($compArray['informational_item_price'])) {
                $informationalPrice = (float) $compArray['informational_item_price'];
            } elseif (isset($compArray['price']) && is_numeric($compArray['price'])) {
                $informationalPrice = (float) $compArray['price'];
            }

            $stockManaged = isset($compArray['stock_managed'])
                ? (bool) $compArray['stock_managed']
                : true;

            $serialRequired = isset($compArray['serial_number_required'])
                ? (bool) $compArray['serial_number_required']
                : false;

            $assignedSerials = isset($compArray['assigned_serials']) && is_array($compArray['assigned_serials'])
                ? array_values(array_map('strval', $compArray['assigned_serials']))
                : [];

            $normalized[] = [
                'bundle_item_id' => $bundleItemId,
                'product_id' => $productId,
                'product_name' => $productName,
                'quantity_per_bundle' => $qtyPerBundle,
                'quantity' => $totalQuantity,
                'informational_item_price' => round($informationalPrice, 2),
                'stock_managed' => $stockManaged,
                'serial_number_required' => $serialRequired,
                'assigned_serials' => $assignedSerials,
            ];
        }

        // Sort deterministically by product_id ASC, then bundle_item_id ASC
        usort($normalized, function (array $a, array $b): int {
            if ($a['product_id'] !== $b['product_id']) {
                return $a['product_id'] <=> $b['product_id'];
            }
            $itemIdA = $a['bundle_item_id'] ?? 0;
            $itemIdB = $b['bundle_item_id'] ?? 0;
            return $itemIdA <=> $itemIdB;
        });

        return array_values($normalized);
    }
}

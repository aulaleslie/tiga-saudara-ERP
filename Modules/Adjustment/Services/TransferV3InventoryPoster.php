<?php

namespace Modules\Adjustment\Services;

use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use RuntimeException;

/**
 * Applies exact good/broken tax/non-tax bucket deltas to a locked location
 * stock row and its locked product, and appends the matching inventory
 * transaction. Mirrors the bucket rules of the v2 dispatch/receipt
 * executors (which remain untouched) so version 3 stays interoperable with
 * the rest of the inventory ledger.
 *
 * Buckets: ['non_tax' => int, 'tax' => int, 'broken_non_tax' => int, 'broken_tax' => int]
 */
class TransferV3InventoryPoster
{
    public const EMPTY_BUCKETS = ['non_tax' => 0, 'tax' => 0, 'broken_non_tax' => 0, 'broken_tax' => 0];

    /**
     * Non-tax-first allocation within the selected condition, from the
     * stock row's current (already partially consumed) balance.
     */
    public static function allocateNonTaxFirst(ProductStock $stock, int $quantity, bool $isBroken): array
    {
        $buckets = self::EMPTY_BUCKETS;

        [$nonTaxKey, $taxKey, $nonTaxColumn, $taxColumn] = $isBroken
            ? ['broken_non_tax', 'broken_tax', 'broken_quantity_non_tax', 'broken_quantity_tax']
            : ['non_tax', 'tax', 'quantity_non_tax', 'quantity_tax'];

        $buckets[$nonTaxKey] = min($quantity, max(0, (int) $stock->{$nonTaxColumn}));
        $buckets[$taxKey] = min($quantity - $buckets[$nonTaxKey], max(0, (int) $stock->{$taxColumn}));

        if (array_sum($buckets) < $quantity) {
            throw new RuntimeException('Stok di lokasi sumber tidak mencukupi untuk alokasi ini.');
        }

        return $buckets;
    }

    /**
     * @return array{previous_stock: array, current_stock: array, previous_product: array, current_product: array}
     */
    public static function apply(Product $product, ProductStock $stock, array $buckets, int $sign): array
    {
        $buckets = array_merge(self::EMPTY_BUCKETS, $buckets);
        $total = array_sum($buckets);
        $brokenTotal = $buckets['broken_tax'] + $buckets['broken_non_tax'];

        $previous = [
            'quantity'         => (int) ($stock->quantity ?? 0),
            'broken'           => (int) ($stock->broken_quantity ?? 0),
            'quantity_tax'     => (int) ($stock->quantity_tax ?? 0),
            'quantity_non_tax' => (int) ($stock->quantity_non_tax ?? 0),
            'broken_tax'       => (int) ($stock->broken_quantity_tax ?? 0),
            'broken_non_tax'   => (int) ($stock->broken_quantity_non_tax ?? 0),
        ];
        $previousProduct = [
            'quantity' => (int) ($product->product_quantity ?? 0),
            'broken'   => (int) ($product->broken_quantity ?? 0),
        ];

        $next = [
            'quantity_tax'     => $previous['quantity_tax'] + $sign * $buckets['tax'],
            'quantity_non_tax' => $previous['quantity_non_tax'] + $sign * $buckets['non_tax'],
            'broken_tax'       => $previous['broken_tax'] + $sign * $buckets['broken_tax'],
            'broken_non_tax'   => $previous['broken_non_tax'] + $sign * $buckets['broken_non_tax'],
        ];

        foreach ($next as $value) {
            if ($value < 0) {
                throw new RuntimeException('Stok di lokasi sumber tidak mencukupi untuk alokasi ini.');
            }
        }

        $nextProductQuantity = $previousProduct['quantity'] + $sign * $total;
        $nextProductBroken = $previousProduct['broken'] + $sign * $brokenTotal;

        if ($nextProductQuantity < 0 || $nextProductBroken < 0) {
            throw new RuntimeException('Jumlah stok global produk tidak mencukupi.');
        }

        $stock->quantity_tax = $next['quantity_tax'];
        $stock->quantity_non_tax = $next['quantity_non_tax'];
        $stock->broken_quantity_tax = $next['broken_tax'];
        $stock->broken_quantity_non_tax = $next['broken_non_tax'];
        $stock->quantity = max(0, array_sum($next));
        $stock->broken_quantity = max(0, $next['broken_tax'] + $next['broken_non_tax']);
        $stock->save();

        $product->product_quantity = $nextProductQuantity;
        $product->broken_quantity = $nextProductBroken;
        $product->save();

        return [
            'previous_stock'   => $previous,
            'current_stock'    => [
                'quantity'         => (int) $stock->quantity,
                'broken'           => (int) $stock->broken_quantity,
                'quantity_tax'     => (int) $stock->quantity_tax,
                'quantity_non_tax' => (int) $stock->quantity_non_tax,
                'broken_tax'       => (int) $stock->broken_quantity_tax,
                'broken_non_tax'   => (int) $stock->broken_quantity_non_tax,
            ],
            'previous_product' => $previousProduct,
            'current_product'  => [
                'quantity' => (int) $product->product_quantity,
                'broken'   => (int) $product->broken_quantity,
            ],
        ];
    }

    public static function recordTransaction(int $productId, int $settingId, int $locationId, int $signedQuantity, array $snapshot, int $userId, string $reason): Transaction
    {
        return Transaction::create([
            'product_id'                    => $productId,
            'setting_id'                    => $settingId,
            'type'                          => 'TRF',
            'quantity'                      => $signedQuantity,
            'current_quantity'              => $snapshot['current_stock']['quantity'],
            'broken_quantity'               => $snapshot['current_stock']['broken'],
            'previous_quantity'             => $snapshot['previous_product']['quantity'],
            'previous_quantity_at_location' => $snapshot['previous_stock']['quantity'],
            'after_quantity'                => $snapshot['current_product']['quantity'],
            'after_quantity_at_location'    => $snapshot['current_stock']['quantity'],
            'quantity_tax'                  => $snapshot['current_stock']['quantity_tax'],
            'quantity_non_tax'              => $snapshot['current_stock']['quantity_non_tax'],
            'broken_quantity_tax'           => $snapshot['current_stock']['broken_tax'],
            'broken_quantity_non_tax'       => $snapshot['current_stock']['broken_non_tax'],
            'location_id'                   => $locationId,
            'user_id'                       => $userId,
            'reason'                        => $reason,
        ]);
    }

    public static function eligibleQuantity(?ProductStock $stock, bool $isBroken): int
    {
        if (! $stock) {
            return 0;
        }

        return $isBroken
            ? max(0, (int) $stock->broken_quantity_tax) + max(0, (int) $stock->broken_quantity_non_tax)
            : max(0, (int) $stock->quantity_tax) + max(0, (int) $stock->quantity_non_tax);
    }
}

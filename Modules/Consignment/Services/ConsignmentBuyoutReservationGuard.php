<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentBuyoutReservation;
use Modules\Consignment\Entities\ConsignmentInventoryCoordination;

/**
 * The single availability authority every stock consumer (Sales dispatch, POS, transfers, stock opname,
 * serial operations, returns) calls so approved buyout reservations cannot be consumed.
 *
 * Usage inside the consumer's transaction, in this order:
 *   1. lockCoordination(settingId, productIds)          -- before any document/stock/serial lock
 *   2. ... the consumer's own document + stock + serial locks ...
 *   3. assertQuantityAvailable()/assertSerialsUnclaimed() -- authoritative, under those locks
 *
 * When no buyout reservation exists (the overwhelming majority of calls) each check is one indexed read.
 */
class ConsignmentBuyoutReservationGuard
{
    public static function lockCoordination(int $settingId, array $productIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', array_filter($productIds))));
        if (! $productIds) {
            return;
        }
        ConsignmentInventoryCoordination::lockMany(array_map(fn ($p) => [$settingId, $p], $productIds));
    }

    /**
     * Coordination lock for products consumed at specific locations; the owning business is the location's
     * business. Strict: an unknown location/product aborts the operation before any mutation.
     *
     * @param array<int, array{0:int,1:int}> $productLocationPairs [productId, locationId]
     */
    public static function lockCoordinationForLocations(array $productLocationPairs): void
    {
        $locIds = array_values(array_unique(array_map(fn ($p) => (int) $p[1], $productLocationPairs)));
        $settings = DB::table('locations')->whereIn('id', $locIds)->pluck('setting_id', 'id');
        $pairs = [];
        foreach ($productLocationPairs as [$productId, $locationId]) {
            if (! $settings->has((int) $locationId)) {
                throw new \DomainException("Lokasi #{$locationId} tidak ditemukan.");
            }
            $pairs[] = [(int) $settings[(int) $locationId], (int) $productId];
        }
        ConsignmentInventoryCoordination::lockMany($pairs);
    }

    /**
     * Make a validation read return the LATEST committed rows inside a transaction.
     *
     * MySQL's default REPEATABLE READ fixes a consistent-read snapshot at the transaction's first plain SELECT. A
     * participant that read anything before it was granted the coordination lock would otherwise validate against rows
     * from before the competing operation committed (verified on MySQL 8.0.44: a purchase-return dispatch and a buyout
     * approval both succeeded over the same goods). Locking reads always see the latest committed data. Under the
     * coordination row lock these shared locks only serialize participants that are already serialized.
     */
    public static function latest($query)
    {
        if (DB::transactionLevel() > 0 && DB::getDriverName() === 'mysql') {
            $query->sharedLock();
        }

        return $query;
    }

    /** Reserved quantity in one tax bucket at a location (null bucket = every bucket). */
    public static function reservedQuantity(int $productId, int $locationId, ?bool $taxed = null, ?int $exceptBuyoutId = null): float
    {
        $q = ConsignmentBuyoutReservation::query()
            ->where('resource_type', 'PHYSICAL_BUCKET')
            ->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE)
            ->where('product_id', $productId)->where('location_id', $locationId);
        if ($exceptBuyoutId) {
            $q->where('consignment_buyout_id', '!=', $exceptBuyoutId);
        }
        if ($taxed !== null) {
            // BaseModel upper-cases strings; reservations store TAXED / NON_TAXED.
            $taxed ? $q->where('tax_bucket', 'TAXED') : $q->where('tax_bucket', '!=', 'TAXED');
        }

        return (float) self::latest($q)->sum('base_quantity');
    }

    /**
     * Assert that consuming $quantity from a bucket leaves every active reservation intact.
     *
     * @param float $bucketQuantity the (locked) current quantity in the bucket being consumed
     */
    public static function assertQuantityAvailable(int $productId, int $locationId, bool $taxed, float $bucketQuantity, float $quantity, string $label = ''): void
    {
        $reserved = self::reservedQuantity($productId, $locationId, $taxed);
        if ($reserved > 0 && round($bucketQuantity - $reserved, 3) + 0.0004 < $quantity) {
            throw new \DomainException(trim("Stok {$label} sebagian dicadangkan untuk pembelian putus konsinyasi "
                . '(tersedia ' . max(0, round($bucketQuantity - $reserved, 3)) . ', diminta ' . $quantity . ').'));
        }
    }

    /**
     * An unsaved copy of a locked stock row whose good buckets exclude active buyout reservations.
     * Consumers that pick buckets themselves (transfers, adjustments) plan against this copy and
     * apply the result to the real row.
     */
    public static function effectiveStock(\Modules\Product\Entities\ProductStock $stock): \Modules\Product\Entities\ProductStock
    {
        $copy = $stock->replicate();
        $copy->id = $stock->id;
        $tax = self::reservedQuantity((int) $stock->product_id, (int) $stock->location_id, true);
        $nonTax = self::reservedQuantity((int) $stock->product_id, (int) $stock->location_id, false);
        if ($tax > 0 || $nonTax > 0) {
            $copy->quantity_tax = max(0, round((float) $stock->quantity_tax - $tax, 3));
            $copy->quantity_non_tax = max(0, round((float) $stock->quantity_non_tax - $nonTax, 3));
            $copy->quantity = max(0, round((float) $stock->quantity - $tax - $nonTax, 3));
        }

        return $copy;
    }

    /**
     * Absolute-count writers (legacy adjustments) may not leave a bucket below what buyouts reserve in it.
     */
    public static function assertBucketsRetainReserved(int $productId, int $locationId, float $newTax, float $newNonTax, string $label = ''): void
    {
        $tax = self::reservedQuantity($productId, $locationId, true);
        $nonTax = self::reservedQuantity($productId, $locationId, false);
        if (($tax > 0 && $newTax + 0.0004 < $tax) || ($nonTax > 0 && $newNonTax + 0.0004 < $nonTax)) {
            throw new \DomainException(trim("Jumlah baru {$label} lebih kecil dari stok yang dicadangkan untuk pembelian putus konsinyasi."));
        }
    }

    /** @param int[] $serialIds */
    public static function claimedSerialIds(array $serialIds): array
    {
        if (! $serialIds) {
            return [];
        }

        return self::latest(ConsignmentActiveSerialClaim::whereIn('product_serial_number_id', $serialIds))
            ->pluck('product_serial_number_id')->map(fn ($x) => (int) $x)->all();
    }

    /** @param int[] $serialIds */
    public static function assertSerialsUnclaimed(array $serialIds, string $label = ''): void
    {
        $claimed = self::claimedSerialIds($serialIds);
        if ($claimed) {
            throw new \DomainException(trim("Serial {$label} sedang diklaim oleh dokumen konsinyasi dan tidak dapat digunakan (ID: " . implode(',', $claimed) . ').'));
        }
    }

    public static function inTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }
}

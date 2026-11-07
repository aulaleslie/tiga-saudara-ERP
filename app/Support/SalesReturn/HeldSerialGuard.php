<?php

namespace App\Support\SalesReturn;

use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductSerialNumber;

/**
 * Held custody is invisible to every stock consumer. The serial's own state (status
 * RETURN_IN_PROCESS, in-return-process flag, retained dispatch link) already hides it from the
 * common scopes; this guard is the backstop for code that bypasses them: any Eloquent change
 * to a held serial that is not performed by the custody service is refused.
 */
class HeldSerialGuard
{
    private static int $bypass = 0;

    /** Open batch scopes (see withBatchedChecks); the verified set only ever lives inside one. */
    private static int $batchScopes = 0;

    /** @var array<int, true> serial ids proven NOT held, under row locks, by prefetchClear() */
    private static array $verifiedClear = [];

    /** Runs the callback as the custody service (the only code allowed to move a held serial). */
    public static function asCustodyService(callable $callback): mixed
    {
        self::$bypass++;
        // Custody may change inside the callback: whatever was proven clear earlier is no longer proof.
        self::$verifiedClear = [];

        try {
            return $callback();
        } finally {
            self::$bypass--;
        }
    }

    /**
     * Scope inside which prefetchClear() results may be reused. The verified set is discarded when the
     * outermost scope CALLBACK exits (return or exception), NOT through transaction commit/rollback hooks.
     * Prerequisite: the caller must open the transaction inside the scope and hold row locks on the serial
     * rows it will write (StockOpnameApprovalService locks them before prefetching); do not keep a scope
     * open across transactions or use it for serials the caller has not locked.
     */
    public static function withBatchedChecks(callable $callback): mixed
    {
        self::$batchScopes++;

        try {
            return $callback();
        } finally {
            if (--self::$batchScopes === 0) {
                self::$verifiedClear = [];
            }
        }
    }

    /**
     * Inside a transaction and a batch scope: reads the custody rows of all given serials in ONE
     * locking query and remembers which are not held, so assertMutable() skips its per-serial query for
     * them. Held serials are never remembered and still go through the authoritative per-serial check.
     *
     * @param  array<int, int|string>  $ids
     */
    public static function prefetchClear(array $ids): void
    {
        if (self::$batchScopes === 0 || DB::transactionLevel() === 0) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return;
        }

        $held = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('sale_return_custodies')
                ->whereIn('product_serial_number_id', $chunk)
                ->whereRaw('quantity_received - quantity_released - quantity_dispatched > 0')
                ->lockForUpdate()
                ->pluck('product_serial_number_id') as $heldId) {
                $held[(int) $heldId] = true;
            }
        }

        foreach ($ids as $id) {
            if (! isset($held[$id])) {
                self::$verifiedClear[$id] = true;
            }
        }
    }

    public static function isBypassing(): bool
    {
        return self::$bypass > 0;
    }

    public static function isHeld(ProductSerialNumber|int $serial): bool
    {
        $id = $serial instanceof ProductSerialNumber ? (int) $serial->getKey() : $serial;

        return DB::table('sale_return_custodies')
            ->where('product_serial_number_id', $id)
            ->whereRaw('quantity_received - quantity_released - quantity_dispatched > 0')
            ->exists();
    }

    /** @param  array<int, int|string>  $ids */
    public static function anyHeld(array $ids): bool
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return false;
        }

        return DB::table('sale_return_custodies')
            ->whereIn('product_serial_number_id', $ids)
            ->whereRaw('quantity_received - quantity_released - quantity_dispatched > 0')
            ->exists();
    }

    /**
     * Called from the model's updating/deleting hooks.
     *
     * Decided from the AUTHORITATIVE custody row, never from the attributes this instance happened
     * to load: a model read before receiving still has status ACTIVE/SOLD in memory. Inside a
     * transaction the custody rows are read FOR UPDATE, so a concurrent receive/release cannot
     * change the answer between this check and the write.
     */
    public static function assertMutable(ProductSerialNumber $serial, array $guardedColumns = []): void
    {
        if (self::isBypassing() || ! $serial->exists) {
            return;
        }

        if ($guardedColumns !== [] && ! $serial->isDirty($guardedColumns)) {
            return;
        }

        if (self::$batchScopes > 0 && DB::transactionLevel() > 0 && isset(self::$verifiedClear[(int) $serial->getKey()])) {
            return;
        }

        $query = DB::table('sale_return_custodies')
            ->where('product_serial_number_id', (int) $serial->getKey())
            ->whereRaw('quantity_received - quantity_released - quantity_dispatched > 0');

        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        if ($query->exists()) {
            throw new HeldSerialException(
                "Nomor seri {$serial->serial_number} sedang ditahan dalam custody retur penjualan dan tidak dapat diubah, dijual, ditransfer, disesuaikan atau dipakai ulang sebelum inspeksi melepasnya."
            );
        }
    }
}

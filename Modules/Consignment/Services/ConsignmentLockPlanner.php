<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentInventoryCoordination;

/**
 * Complete-resource lock-and-retry protocol.
 *
 * 1. DISCOVER the (business, product) set read-only, outside any lock.
 * 2. Open a transaction, take the coordination locks for that whole set in sorted order, then run the work.
 * 3. The work re-derives its real resource set under its own document locks and calls $cover($actualPairs). If
 *    the set grew (a concurrent change added a product), the planner rolls the ENTIRE transaction back - no partial
 *    write survives - and retries with the full, sorted union, so no earlier-sorted key is ever acquired late.
 */
class ConsignmentLockPlanner
{
    /** Test seam: called with (attempt, sortedPairs) after discovery and before the transaction starts. */
    public static ?\Closure $afterDiscovery = null;

    /** Test seam: called with (attempt, sortedPairs) after the coordination locks were taken. */
    public static ?\Closure $afterLock = null;

    public const MAX_ATTEMPTS = 4;

    /**
     * @param \Closure():array<int, array{0:int,1:int}> $discover read-only discovery of (setting, product) pairs
     * @param \Closure(\Closure, int):mixed $work receives ($cover, $attempt); must call $cover($actualPairs)
     * @param \Closure|null $beforeLocks run inside the transaction BEFORE the coordination locks (e.g. Purchase-first)
     */
    public static function run(\Closure $discover, \Closure $work, ?\Closure $beforeLocks = null, int $maxAttempts = self::MAX_ATTEMPTS): mixed
    {
        $extra = [];
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $pairs = self::normalize(array_merge($discover(), $extra));
            if (self::$afterDiscovery) {
                (self::$afterDiscovery)($attempt, $pairs);
            }

            try {
                return DB::transaction(function () use ($pairs, $work, $beforeLocks, $attempt) {
                    if ($beforeLocks) {
                        $beforeLocks();
                    }
                    ConsignmentInventoryCoordination::lockMany($pairs);
                    if (self::$afterLock) {
                        (self::$afterLock)($attempt, $pairs);
                    }

                    return $work(fn (array $actual) => self::assertCovered($pairs, $actual), $attempt);
                });
            } catch (ConsignmentResourceSetChanged $e) {
                $extra = $e->requiredPairs;   // the whole transaction already rolled back
            }
        }

        throw new \RuntimeException('The resource set kept changing while locking; please retry.');
    }

    /** @param array<int, array{0:int,1:int}> $locked @param array<int, array{0:int,1:int}> $actual */
    public static function assertCovered(array $locked, array $actual): void
    {
        $have = array_flip(array_map(fn ($p) => $p[0] . ':' . $p[1], $locked));
        foreach ($actual as $p) {
            if (! isset($have[(int) $p[0] . ':' . (int) $p[1]])) {
                throw new ConsignmentResourceSetChanged(self::normalize(array_merge($locked, $actual)));
            }
        }
    }

    /** @return array<int, array{0:int,1:int}> unique, sorted */
    public static function normalize(array $pairs): array
    {
        $u = [];
        foreach ($pairs as $p) {
            $u[(int) $p[0] . ':' . (int) $p[1]] = [(int) $p[0], (int) $p[1]];
        }
        usort($u, fn ($a, $b) => $a <=> $b);

        return $u;
    }
}

<?php

namespace Modules\Pos\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Modules\Pos\Services\Exceptions\PosCartMutationException;
use Throwable;

/**
 * Serializes every writer to one POS cart, keyed by setting + POS session.
 *
 * The guard set is defined mechanically: any operation that persists, clears,
 * replaces, or hydrates the cart must run inside this lock — not only writes
 * considered relevant to approval validity. Override execution compensates by
 * restoring an exact pre-operation snapshot, so an unguarded concurrent writer
 * would have its write silently erased.
 *
 * Deployment note: the lock inherits the configured cache store. The `file`
 * store's lock is per-server, so a multi-server POS deployment must use a
 * driver with distributed atomic locks (Redis or database).
 */
class PosCartMutationLock
{
    /**
     * Seconds a caller waits to acquire before the attempt is treated as
     * contended and reported as retryable.
     */
    public const DEFAULT_WAIT_SECONDS = 5;

    /**
     * Seconds the lock is held before the cache store auto-releases it.
     *
     * This MUST exceed the longest guarded operation. A TTL that expires while
     * a callback is still running would let a second writer in and silently
     * void the compensation guarantee — the lock would report success while no
     * longer providing exclusion. It is sized against PHP's max_execution_time
     * so ownership cannot lapse before the request itself dies, and acts purely
     * as a crash valve; normal release is guaranteed by the `finally` below.
     */
    public const DEFAULT_TTL_SECONDS = 300;

    /**
     * Lease used under `php artisan test`. Long enough to outlast any guarded
     * operation in a test, short enough that a lock leaked by an interrupted
     * run expires instead of stranding later tests.
     */
    public const TEST_TTL_SECONDS = 30;

    /**
     * Run $callback while holding this cart's mutation lock.
     *
     * Release is guaranteed via finally, including when $callback throws, so a
     * failed mutation never wedges the cart. Re-entrant calls are supported
     * request-wide: nested acquisitions of the same cart key reuse the held
     * lock, so composite operations spanning several collaborating services do
     * not deadlock against themselves.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws PosCartMutationException when the lock cannot be acquired in time.
     */
    public function withLock(int $settingId, int $sessionId, callable $callback): mixed
    {
        $key = $this->key($settingId, $sessionId);

        // Only the scope that actually owns the lock may bypass acquisition.
        if (($this->heldDepth[$key] ?? 0) > 0) {
            $this->heldDepth[$key]++;

            try {
                return $callback();
            } finally {
                $this->heldDepth[$key]--;
            }
        }

        $lock = $this->acquire($key);

        $this->heldDepth[$key] = 1;

        try {
            return $callback();
        } finally {
            unset($this->heldDepth[$key]);

            try {
                $lock->release();
            } catch (Throwable) {
                // Release is best-effort: the store TTL reclaims the lock.
                // Never let a release failure mask the original outcome.
            }
        }
    }

    /**
     * Re-entrance depth per cart key for the scope that owns this instance.
     *
     * Instance state, never static: under a long-lived worker (Octane, queue
     * workers) process-wide state would let one execution context observe
     * another's held key and bypass the real cache lock, silently losing
     * exclusion. The lock is bound with Laravel's `scoped` lifecycle so every
     * collaborator inside one request/job shares this instance — which is what
     * makes cross-service re-entrance work — while a new scope starts empty and
     * cannot inherit ownership.
     *
     * Depth rather than a boolean so nested acquisitions unwind correctly.
     *
     * @var array<string, int>
     */
    private array $heldDepth = [];

    private function acquire(string $key): Lock
    {
        // A 300-second TTL is right for production but would strand a whole
        // test run if a killed process left a lock behind, so tests use a
        // short-lived lease. The exclusion semantics are identical.
        $ttl = app()->runningUnitTests()
            ? self::TEST_TTL_SECONDS
            : self::DEFAULT_TTL_SECONDS;

        $lock = Cache::lock($key, $ttl);

        try {
            $acquired = $lock->block(self::DEFAULT_WAIT_SECONDS);
        } catch (Throwable) {
            // Illuminate throws LockTimeoutException on contention; some stores
            // surface other failures. Both mean "did not acquire".
            $acquired = false;
        }

        if (! $acquired) {
            throw new PosCartMutationException(
                'CART_BUSY',
                'Keranjang sedang diproses. Silakan coba lagi.',
                409
            );
        }

        return $lock;
    }

    private function key(int $settingId, int $sessionId): string
    {
        return 'pos.cart.mutation.setting.' . $settingId . '.session.' . $sessionId;
    }
}

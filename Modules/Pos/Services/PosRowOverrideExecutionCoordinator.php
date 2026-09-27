<?php

namespace Modules\Pos\Services;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosActionApprovalToken;
use Modules\Pos\Services\Exceptions\PosCartCompensationFailedException;
use Throwable;

/**
 * Runs one POS row override — unit price or row total — so that the cart
 * mutation, the token consumption, and the audit record either all take effect
 * or none of them do.
 *
 * Both authorization paths use this coordinator. Direct execution is not
 * exempt: it can also persist a cart and then fail to write its audit, leaving
 * a changed cart with no record of who changed it.
 *
 * Ordering is fixed:
 *
 *   acquire cart lock
 *     -> authorize / re-read authoritative cart
 *     -> begin DB transaction
 *     -> lock token and approval request      (supervised only)
 *     -> revalidate complete approval context (supervised only)
 *     -> calculate proposed mutation
 *     -> persist cart
 *     -> conditionally consume token          (supervised only)
 *     -> write successful audit
 *     -> commit
 *
 * On failure after cart persistence:
 *
 *     roll back DB transaction -> restore exact pre-operation cart -> release lock
 *
 * The cart lives in the PHP session and cannot enlist in the database
 * transaction, so restoration is explicit. It is exact rather than a partial
 * field revert, which is sound only because the cart mutation lock is held for
 * the whole operation: no competing writer can enter and have its write erased
 * by the restore.
 */
class PosRowOverrideExecutionCoordinator
{
    /**
     * Bounded restoration attempts before compensation is declared failed.
     */
    private const COMPENSATION_ATTEMPTS = 3;

    public function __construct(
        private readonly PosCartMutationLock $cartMutationLock,
        private readonly PosCartSessionStore $cartSessionStore,
        private readonly PosApprovalTokenService $approvalTokenService
    ) {
    }

    /**
     * Execute a directly-authorized row override.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $mutate
     *         Receives the authoritative cart, returns the mutated cart.
     *         Must not persist anything itself.
     * @param  Closure(array<string, mixed>): void  $audit
     *         Writes the successful-execution audit record.
     * @return array<string, mixed> The persisted cart.
     */
    public function executeDirect(
        int $settingId,
        int $sessionId,
        Closure $mutate,
        Closure $audit
    ): array {
        return $this->cartMutationLock->withLock($settingId, $sessionId, function () use (
            $settingId,
            $sessionId,
            $mutate,
            $audit
        ): array {
            $originalCart = $this->cartSessionStore->getCart($settingId, $sessionId);

            $cartPersisted = false;

            DB::beginTransaction();

            try {
                // Calculate the complete mutation before any persistence, so a
                // calculation failure leaves the cart untouched.
                $mutatedCart = $mutate($originalCart);

                $this->cartSessionStore->putCart($settingId, $sessionId, $mutatedCart);
                $cartPersisted = true;

                // putCart() stamps a fresh generation, so the in-memory array is
                // already stale. Everything downstream must see what was stored.
                $persistedCart = $this->cartSessionStore->getCart($settingId, $sessionId);

                $audit($persistedCart);

                DB::commit();

                return $persistedCart;
            } catch (Throwable $exception) {
                $this->unwindAfterFailure(
                    $settingId,
                    $sessionId,
                    'DIRECT_ROW_OVERRIDE',
                    $cartPersisted,
                    $originalCart,
                    $exception
                );
            }
        });
    }

    /**
     * Execute a supervisor-approved row override against a one-time token.
     *
     * @param  Closure(PosActionApprovalRequest, array<string, mixed>): void  $revalidate
     *         Throws if the approval context does not match the current cart.
     * @param  Closure(PosActionApprovalRequest, array<string, mixed>): array<string, mixed>  $mutate
     *         Receives the approval and the authoritative cart, returns the mutated cart.
     * @param  Closure(PosActionApprovalRequest, array<string, mixed>): void  $audit
     * @return array<string, mixed> The persisted cart.
     */
    public function executeApproved(
        int $settingId,
        int $sessionId,
        string $approvalToken,
        int $actingUserId,
        array $consumeContext,
        Closure $revalidate,
        Closure $mutate,
        Closure $audit
    ): array {
        return $this->cartMutationLock->withLock($settingId, $sessionId, function () use (
            $settingId,
            $sessionId,
            $approvalToken,
            $actingUserId,
            $consumeContext,
            $revalidate,
            $mutate,
            $audit
        ): array {
            $originalCart = $this->cartSessionStore->getCart($settingId, $sessionId);

            $cartPersisted = false;

            DB::beginTransaction();

            try {
                // Serialize competing executions of this token on its row.
                $lockedToken = $this->approvalTokenService->lockTokenForUpdate($approvalToken);

                if (! $lockedToken) {
                    throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
                }

                $request = $this->assertTokenUsable($lockedToken);

                // Re-read the cart inside the protected path so validation and
                // mutation act on the state the transaction will commit against.
                $authoritativeCart = $this->cartSessionStore->getCart($settingId, $sessionId);

                $revalidate($request, $authoritativeCart);

                // Full mutation computed before anything is written.
                $mutatedCart = $mutate($request, $authoritativeCart);

                $this->cartSessionStore->putCart($settingId, $sessionId, $mutatedCart);
                $cartPersisted = true;

                // Use the stored representation from here on: putCart() stamped
                // a new generation the in-memory array does not carry.
                $persistedCart = $this->cartSessionStore->getCart($settingId, $sessionId);

                $this->approvalTokenService->consumeToken($lockedToken, $actingUserId, $consumeContext);

                $audit($request, $persistedCart);

                DB::commit();

                return $persistedCart;
            } catch (Throwable $exception) {
                $this->unwindAfterFailure(
                    $settingId,
                    $sessionId,
                    'APPROVED_ROW_OVERRIDE',
                    $cartPersisted,
                    $originalCart,
                    $exception
                );
            }
        });
    }

    /**
     * Revalidate a locked token's own status before trusting it.
     */
    private function assertTokenUsable(PosActionApprovalToken $token): PosActionApprovalRequest
    {
        if ($token->consumed_at !== null) {
            throw new DomainException('TOKEN_ALREADY_USED');
        }

        if ($token->expires_at === null || $token->expires_at->isPast()) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        $request = $token->approvalRequest;

        if (! $request || $request->status !== PosActionApprovalRequest::STATUS_APPROVED) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        return $request;
    }

    /**
     * Unwind a failed execution: roll back the database, then restore the cart.
     *
     * Restoration is guaranteed independently of rollback. Calling rollback
     * first and restoration second in the same straight line would skip
     * restoration entirely if rollback itself threw, leaving the mutated cart
     * behind — so rollback runs in its own try and restoration runs in the
     * matching finally.
     *
     * @param  array<string, mixed>  $originalCart
     * @return never
     */
    private function unwindAfterFailure(
        int $settingId,
        int $sessionId,
        string $action,
        bool $cartPersisted,
        array $originalCart,
        Throwable $originalException
    ): never {
        $compensationFailure = null;

        try {
            DB::rollBack();
        } catch (Throwable $rollbackFailure) {
            Log::critical('POS row override rollback failed.', [
                'setting_id' => $settingId,
                'pos_session_id' => $sessionId,
                'action' => $action,
                'rollback_error' => $rollbackFailure->getMessage(),
                'original_error' => $originalException->getMessage(),
            ]);
        } finally {
            if ($cartPersisted) {
                $compensationFailure = $this->restoreCart(
                    $settingId,
                    $sessionId,
                    $originalCart,
                    $action,
                    $originalException
                );
            }
        }

        if ($compensationFailure !== null) {
            throw $compensationFailure;
        }

        throw $originalException;
    }

    /**
     * Restore the pre-operation cart, verifying the result.
     *
     * Runs while the cart mutation lock is still held, so it cannot overwrite a
     * concurrent writer's change — no concurrent writer can exist.
     *
     * "Exact" restoration means exact business content. The generation counter
     * is monotonic and deliberately advances: forcing it backward would let a
     * stale compare-and-set match a cart it never observed.
     *
     * Failure is never swallowed. A cart left holding an override that was
     * neither consumed nor audited is precisely the state the invariant forbids,
     * so an unconfirmed restoration is escalated.
     *
     * @param  array<string, mixed>  $originalCart
     * @return PosCartCompensationFailedException|null Null when restoration is confirmed.
     */
    private function restoreCart(
        int $settingId,
        int $sessionId,
        array $originalCart,
        string $action,
        Throwable $originalException
    ): ?PosCartCompensationFailedException {
        $lastFailure = null;

        for ($attempt = 1; $attempt <= self::COMPENSATION_ATTEMPTS; $attempt++) {
            try {
                $this->cartSessionStore->putCart($settingId, $sessionId, $originalCart);

                if ($this->cartContentMatches($settingId, $sessionId, $originalCart)) {
                    return null;
                }

                $lastFailure = null;
            } catch (Throwable $restorationFailure) {
                $lastFailure = $restorationFailure;
            }
        }

        Log::critical('POS cart compensation failed; cart may still hold an unaudited override.', [
            'setting_id' => $settingId,
            'pos_session_id' => $sessionId,
            'action' => $action,
            'attempts' => self::COMPENSATION_ATTEMPTS,
            'original_error' => $originalException->getMessage(),
            'restoration_error' => $lastFailure?->getMessage(),
        ]);

        return new PosCartCompensationFailedException(
            $settingId,
            $sessionId,
            $action,
            $lastFailure,
            $originalException
        );
    }

    /**
     * Confirm the stored cart carries the original business content.
     *
     * Compares everything except the monotonic generation, which is expected to
     * have advanced.
     *
     * @param  array<string, mixed>  $expectedCart
     */
    private function cartContentMatches(int $settingId, int $sessionId, array $expectedCart): bool
    {
        try {
            $stored = $this->cartSessionStore->getCart($settingId, $sessionId);
        } catch (Throwable) {
            return false;
        }

        unset($stored['revision'], $expectedCart['revision']);

        return $stored == $expectedCart;
    }
}

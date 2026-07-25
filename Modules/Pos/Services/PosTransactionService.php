<?php

namespace Modules\Pos\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\Exceptions\PosTransactionConflictException;
use Modules\Pos\Services\Exceptions\PosTransactionValidationException;

class PosTransactionService
{
    public function __construct(
        private readonly PosCartSessionStore $cartSessionStore,
        private readonly PosCartService $cartService,
        private readonly PosTransactionSnapshotMapper $mapper,
        private readonly PosTransactionCodeGenerator $codeGenerator,
        private readonly PosTransactionPolicyService $policyService,
        private readonly PosCartActionAuthorizationService $actionAuthorizationService
    ) {}

    /**
     * Save the current session cart as a DRAFT transaction and clear the cart.
     *
     * @throws PosTransactionValidationException('CART_EMPTY', ...)
     * @throws PosTransactionValidationException('TRANSACTION_NOT_FOUND', ...)
     * @throws PosTransactionValidationException('TRANSACTION_NOT_SAVEABLE', ...)
     * @throws PosTransactionConflictException('EDIT_FORBIDDEN', ...)
     */
    public function saveAndNew(
        int $settingId,
        PosSession $activeSession,
        User $user
    ): PosTransaction {
        $cart = $this->cartSessionStore->getCart($settingId, $activeSession->id);

        // Validate cart is not empty
        if (empty($cart['lines'])) {
            throw new PosTransactionValidationException(
                'CART_EMPTY',
                'Keranjang kosong, tidak ada yang disimpan.'
            );
        }

        $activeTransactionId = (int) ($cart['active_transaction_id'] ?? 0);

        $transaction = DB::transaction(function () use ($settingId, $activeSession, $user, $cart, $activeTransactionId) {
            $snapshot = $this->cartService->getSnapshot($settingId, $activeSession->id);
            $snapshotTotals = $this->mapper->buildSnapshotTotals($snapshot['totals'] ?? []);

            if ($activeTransactionId > 0) {
                $transaction = PosTransaction::query()
                    ->where('setting_id', $settingId)
                    ->whereKey($activeTransactionId)
                    ->lockForUpdate()
                    ->first();

                if (! $transaction) {
                    throw new PosTransactionValidationException(
                        'TRANSACTION_NOT_FOUND',
                        'Transaksi aktif tidak ditemukan.'
                    );
                }

                $this->policyService->assertCanSaveDraft($user, $transaction);

                if (in_array($transaction->status, [
                    PosTransaction::STATUS_COMPLETED,
                    PosTransaction::STATUS_CANCELLED,
                ], true)) {
                    throw new PosTransactionValidationException(
                        'TRANSACTION_NOT_SAVEABLE',
                        'Transaksi ini tidak dapat disimpan ulang.'
                    );
                }

                $transaction->update([
                    'status' => PosTransaction::STATUS_DRAFT,
                    'last_saved_by' => $user->id,
                    'customer_id' => $cart['selected_customer_id'] ?? null,
                    'source_pos_session_id' => $activeSession->id,
                    'snapshot_totals' => $snapshotTotals,
                    'note' => $cart['note'] ?? null,
                ]);
            } else {
                $code = $this->codeGenerator->generate($settingId);

                $transaction = PosTransaction::create([
                    'setting_id' => $settingId,
                    'code' => $code,
                    'status' => PosTransaction::STATUS_DRAFT,
                    'created_by' => $user->id,
                    'owner_user_id' => $user->id,
                    'last_saved_by' => $user->id,
                    'customer_id' => $cart['selected_customer_id'] ?? null,
                    'source_pos_session_id' => $activeSession->id,
                    'snapshot_totals' => $snapshotTotals,
                    'note' => $cart['note'] ?? null,
                ]);
            }

            // Persist lines and serials
            $this->mapper->persistLines($transaction, $cart['lines']);

            $transaction->refresh();
            $transaction->update([
                'snapshot_hash' => $this->mapper->buildSnapshotHash($transaction),
            ]);

            // Clear the session cart
            $emptyCart = $this->cartSessionStore->emptyCart($settingId, $activeSession->id);
            $this->cartSessionStore->putCart($settingId, $activeSession->id, $emptyCart);

            return $transaction->fresh();
        });

        return $transaction;
    }

    /**
     * Return a paginated, filtered list of transactions for the setting.
     *
     * @param  array<string, mixed>  $filters  with keys: status[], owner_user_id, q, date_from, date_to
     */
    public function list(
        int $settingId,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = PosTransaction::query()
            ->where('setting_id', $settingId)
            ->with(['owner', 'customer']);

        // Filter by status
        if (!empty($filters['status']) && is_array($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }

        // Filter by owner
        if (!empty($filters['owner_user_id'])) {
            $query->where('owner_user_id', $filters['owner_user_id']);
        }

        // Search by code
        if (!empty($filters['q'])) {
            $query->where('code', 'like', '%' . $filters['q'] . '%');
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', (string) $filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', (string) $filters['date_to'])->endOfDay());
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Load a DRAFT transaction into the session cart.
     * Transitions status DRAFT -> LOADED (or keeps LOADED).
     * Stores active_transaction_id in cart session.
     *
     * @throws PosTransactionConflictException('CART_NOT_EMPTY', ...)
     * @throws PosTransactionConflictException('EDIT_FORBIDDEN', ...)
     * @throws PosTransactionValidationException('TRANSACTION_NOT_LOADABLE', ...)
     */
    public function loadToCart(
        int $settingId,
        int $sessionId,
        PosTransaction $transaction,
        User $user
    ): array {
        // Check cart is empty
        $currentCart = $this->cartSessionStore->getCart($settingId, $sessionId);
        $this->policyService->assertCartEmpty($currentCart['lines']);

        // Check user has permission to edit this transaction
        $this->policyService->assertCanLoadDraft($user, $transaction);

        // Only DRAFT transactions can be loaded to prevent concurrent loading across terminals.
        if ($transaction->status !== PosTransaction::STATUS_DRAFT) {
            throw new PosTransactionValidationException(
                'TRANSACTION_NOT_LOADABLE',
                'Hanya transaksi draf yang dapat dimuat.'
            );
        }

        // Load into cart and update status
        $hydratedCart = DB::transaction(function () use ($transaction, $settingId, $sessionId) {
            $transaction = PosTransaction::query()
                ->where('setting_id', $settingId)
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                throw new PosTransactionValidationException(
                    'TRANSACTION_NOT_FOUND',
                    'Transaksi aktif tidak ditemukan.'
                );
            }

            // Atomic status check after lock to prevent race conditions
            if ($transaction->status !== PosTransaction::STATUS_DRAFT) {
                throw new PosTransactionConflictException(
                    'TRANSACTION_ALREADY_LOADED',
                    'Transaksi ini sedang aktif atau telah dimuat di terminal lain dan tidak dapat diproses bersamaan.'
                );
            }

            $storedHash = (string) ($transaction->snapshot_hash ?? '');
            $currentHash = $this->mapper->buildSnapshotHash($transaction);

            if ($storedHash === '' || ! hash_equals($storedHash, $currentHash)) {
                throw new PosTransactionConflictException(
                    'SNAPSHOT_DRIFT',
                    'Data transaksi berubah dan perlu disimpan ulang.'
                );
            }

            $transaction->update(['status' => PosTransaction::STATUS_LOADED]);

            // Hydrate cart from transaction
            $hydratedCart = $this->mapper->hydrateCart($transaction);

            // Store in session
            $this->cartSessionStore->putCart($settingId, $sessionId, $hydratedCart);

            return $hydratedCart;
        });

        $transaction->refresh();

        // Return fresh snapshot
        return $this->cartService->getSnapshot($settingId, $sessionId);
    }

    /**
     * Cancel a DRAFT or LOADED transaction.
     *
     * @throws PosTransactionConflictException('EDIT_FORBIDDEN', ...)
     * @throws PosTransactionValidationException('TRANSACTION_NOT_CANCELLABLE', ...)
     */
    public function cancel(
        PosTransaction $transaction,
        User $user,
        ?string $approvalToken = null
    ): PosTransaction {
        if ($transaction->status !== PosTransaction::STATUS_DRAFT) {
            throw new PosTransactionValidationException(
                'TRANSACTION_NOT_CANCELLABLE',
                'Hanya transaksi draft yang dapat dibatalkan.'
            );
        }

        $this->actionAuthorizationService->authorize(
            $user,
            PosActionApprovalRequest::ACTION_TRANSACTION_CANCEL,
            $approvalToken
        );

        $transaction->update([
            'status' => PosTransaction::STATUS_CANCELLED,
        ]);

        return $transaction;
    }

    /**
     * Mark a transaction as COMPLETED and link the checkout.
     * Called by FinalizePosCheckoutService after successful posting.
     */
    public function markCompleted(
        PosTransaction $transaction,
        int $checkoutId
    ): void {
        $transaction->update([
            'status' => PosTransaction::STATUS_COMPLETED,
            'completed_checkout_id' => $checkoutId,
        ]);
    }

    /**
     * Revert a LOADED transaction back to DRAFT status.
     * Called when a loaded transaction is cleared from the cart (unloaded).
     */
    public function unload(int $settingId, int $transactionId): void
    {
        $transaction = PosTransaction::query()
            ->where('setting_id', $settingId)
            ->whereKey($transactionId)
            ->first();

        if ($transaction && $transaction->status === PosTransaction::STATUS_LOADED) {
            $transaction->update(['status' => PosTransaction::STATUS_DRAFT]);
        }
    }

    /**
     * Ensure checkout completion is always represented in POS transaction history.
     * If cart comes from loaded draft, update that transaction; otherwise create a completed transaction.
     *
     * @param  array<string, mixed>  $cartSnapshot
     */
    public function completeFromCartSnapshot(
        int $settingId,
        PosSession $activeSession,
        int $actorUserId,
        array $cartSnapshot,
        int $checkoutId,
        array $allocations = [],
        ?string $presetCode = null
    ): PosTransaction {
        $activeTransactionId = (int) ($cartSnapshot['active_transaction_id'] ?? 0);
        $snapshotLines = (array) ($cartSnapshot['lines'] ?? []);
        $resolvedCustomerId = (int) ($cartSnapshot['customer']['resolved_customer_id'] ?? 0);
        $selectedCustomerId = (int) ($cartSnapshot['customer']['selected_customer']['id'] ?? 0);
        $customerId = $resolvedCustomerId > 0
            ? $resolvedCustomerId
            : ($selectedCustomerId > 0 ? $selectedCustomerId : null);

        return DB::transaction(function () use (
            $settingId,
            $activeSession,
            $actorUserId,
            $checkoutId,
            $activeTransactionId,
            $customerId,
            $cartSnapshot,
            $snapshotLines
        ) {
            $transaction = null;
            if ($activeTransactionId > 0) {
                $transaction = PosTransaction::query()
                    ->where('setting_id', $settingId)
                    ->whereKey($activeTransactionId)
                    ->lockForUpdate()
                    ->first();
            }

            $totals = $this->mapper->buildSnapshotTotals($cartSnapshot['totals'] ?? []);

            if (! $transaction) {
                $transaction = PosTransaction::query()->create([
                    'setting_id' => $settingId,
                    'code' => $presetCode ?? $this->codeGenerator->generate($settingId),
                    'status' => PosTransaction::STATUS_DRAFT,
                    'created_by' => $actorUserId,
                    'owner_user_id' => $actorUserId,
                    'last_saved_by' => $actorUserId,
                    'customer_id' => $customerId,
                    'source_pos_session_id' => $activeSession->id,
                    'snapshot_totals' => $totals,
                    'note' => $cartSnapshot['note'] ?? null,
                ]);
            } else {
                $transaction->update([
                    'last_saved_by' => $actorUserId,
                    'customer_id' => $customerId,
                    'source_pos_session_id' => $activeSession->id,
                    'snapshot_totals' => $totals,
                    'note' => $cartSnapshot['note'] ?? null,
                ]);
            }

            $this->mapper->persistLines($transaction, $snapshotLines);
            $transaction->refresh();
            $transaction->update([
                'snapshot_hash' => $this->mapper->buildSnapshotHash($transaction),
                'status' => PosTransaction::STATUS_COMPLETED,
                'completed_checkout_id' => $checkoutId,
            ]);

            return $transaction->fresh();
        });
    }

    /**
     * Resolve the POS transaction code for a checkout based on the cart snapshot.
     * If the cart is loaded from a draft in the current setting, returns its existing code.
     * Otherwise, generates a new code.
     */
    public function resolveCodeFromCartSnapshot(int $settingId, array $cartSnapshot): string
    {
        $activeTransactionId = (int) ($cartSnapshot['active_transaction_id'] ?? 0);

        if ($activeTransactionId > 0) {
            $existingCode = PosTransaction::query()
                ->where('setting_id', $settingId)
                ->whereKey($activeTransactionId)
                ->value('code');

            if ($existingCode) {
                return (string) $existingCode;
            }
        }

        return $this->codeGenerator->generate($settingId);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSnapshotLines(array $lines): array
    {
        $normalized = [];
        $fallbackLineId = 1;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lineId = (int) ($line['line_id'] ?? 0);
            if ($lineId <= 0) {
                $lineId = $fallbackLineId;
            }

            $normalized[$lineId] = $line;
            $fallbackLineId = max($fallbackLineId + 1, $lineId + 1);
        }

        return $normalized;
    }
}

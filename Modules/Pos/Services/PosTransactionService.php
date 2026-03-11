<?php

namespace Modules\Pos\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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
        private readonly PosTransactionPolicyService $policyService
    ) {}

    /**
     * Save the current session cart as a DRAFT transaction and clear the cart.
     *
     * @throws PosTransactionValidationException('CART_EMPTY', ...)
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

        $transaction = DB::transaction(function () use ($settingId, $activeSession, $user, $cart) {
            // Generate unique code
            $code = $this->codeGenerator->generate($settingId);

            // Get current snapshot to store totals
            $snapshot = $this->cartService->getSnapshot($settingId, $activeSession->id);

            // Create transaction
            $transaction = PosTransaction::create([
                'setting_id' => $settingId,
                'code' => $code,
                'status' => PosTransaction::STATUS_DRAFT,
                'created_by' => $user->id,
                'owner_user_id' => $user->id,
                'last_saved_by' => $user->id,
                'customer_id' => $cart['selected_customer_id'] ?? null,
                'source_pos_session_id' => $activeSession->id,
                'snapshot_totals' => $this->mapper->buildSnapshotTotals($snapshot['totals'] ?? []),
            ]);

            // Persist lines and serials
            $this->mapper->persistLines($transaction, $cart['lines']);

            // Clear the session cart
            $emptyCart = $this->cartSessionStore->emptyCart($settingId, $activeSession->id);
            $this->cartSessionStore->putCart($settingId, $activeSession->id, $emptyCart);

            return $transaction;
        });

        return $transaction;
    }

    /**
     * Return a paginated, filtered list of transactions for the setting.
     *
     * @param  array<string, mixed>  $filters  with keys: status[], owner_user_id, q (code search)
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

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Load a DRAFT transaction into the session cart.
     * Transitions status DRAFT -> LOADED.
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
        $this->policyService->assertCanEdit($user, $transaction);

        // Verify transaction is in DRAFT status
        if ($transaction->status !== PosTransaction::STATUS_DRAFT) {
            throw new PosTransactionValidationException(
                'TRANSACTION_NOT_LOADABLE',
                'Hanya transaksi dengan status DRAFT yang dapat dimuat.'
            );
        }

        // Load into cart and update status
        $hydratedCart = DB::transaction(function () use ($transaction, $settingId, $sessionId) {
            // Update transaction status to LOADED
            $transaction->update(['status' => PosTransaction::STATUS_LOADED]);

            // Hydrate cart from transaction
            $hydratedCart = $this->mapper->hydrateCart($transaction);

            // Store in session
            $this->cartSessionStore->putCart($settingId, $sessionId, $hydratedCart);

            return $hydratedCart;
        });

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
        User $user
    ): PosTransaction {
        // Check user has permission
        $this->policyService->assertCanEdit($user, $transaction);

        // Cannot cancel COMPLETED transactions
        if ($transaction->status === PosTransaction::STATUS_COMPLETED) {
            throw new PosTransactionValidationException(
                'TRANSACTION_NOT_CANCELLABLE',
                'Transaksi yang sudah selesai tidak dapat dibatalkan.'
            );
        }

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
}

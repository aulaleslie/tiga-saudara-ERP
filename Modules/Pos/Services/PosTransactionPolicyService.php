<?php

namespace Modules\Pos\Services;

use App\Models\User;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\Exceptions\PosTransactionConflictException;

class PosTransactionPolicyService
{
    /**
     * Assert the user may save changes back into a mutable draft.
     *
     * @throws PosTransactionConflictException('EDIT_FORBIDDEN', ...)
     */
    public function assertCanSaveDraft(User $user, PosTransaction $transaction): void
    {
        if (! $user->can('pos.transactions.save')) {
            throw new PosTransactionConflictException(
                'EDIT_FORBIDDEN',
                'Anda tidak memiliki izin untuk mengedit transaksi ini.'
            );
        }
    }

    /**
     * Assert the user may load a mutable draft for continuation.
     *
     * @throws PosTransactionConflictException('EDIT_FORBIDDEN', ...)
     */
    public function assertCanLoadDraft(User $user, PosTransaction $transaction): void
    {
        if (! $user->can('pos.transactions.load')) {
            throw new PosTransactionConflictException(
                'EDIT_FORBIDDEN',
                'Anda tidak memiliki izin untuk memuat transaksi ini.'
            );
        }
    }

    /**
     * Assert the cart is empty before loading a draft.
     *
     * @param  array<int, array<string, mixed>>  $cartLines
     * @throws PosTransactionConflictException('CART_NOT_EMPTY', ...)
     */
    public function assertCartEmpty(array $cartLines): void
    {
        if (count($cartLines) > 0) {
            throw new PosTransactionConflictException(
                'CART_NOT_EMPTY',
                'Keranjang harus kosong sebelum memuat transaksi tersimpan.'
            );
        }
    }
}

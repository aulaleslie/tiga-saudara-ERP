<?php

namespace Modules\Pos\Services;

use App\Models\User;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\Exceptions\PosTransactionConflictException;

class PosTransactionPolicyService
{
    /**
     * Assert the user may load/edit/cancel the transaction.
     * Creator OR holder of pos.transactions.edit.any may proceed.
     *
     * @throws PosTransactionConflictException('EDIT_FORBIDDEN', ...)
     */
    public function assertCanEdit(User $user, PosTransaction $transaction): void
    {
        $isCreator = $transaction->owner_user_id === $user->id;
        $hasEditAnyPermission = $user->can('pos.transactions.edit.any');

        if (!$isCreator && !$hasEditAnyPermission) {
            throw new PosTransactionConflictException(
                'EDIT_FORBIDDEN',
                'Anda tidak memiliki izin untuk mengedit transaksi ini.'
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

<?php

namespace Modules\Pos\Services;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;

class PosReturnLookupService
{
    /**
     * Lookup POS transaction by code or receipt number.
     *
     * @param string $identifier
     * @return array|null
     */
    public function lookup(string $identifier): ?array
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $settingId = settings()->id;

        $transaction = PosTransaction::query()
            ->with(['completedCheckout' => function ($query) {
                $query->select('id', 'pos_transaction_id', 'setting_id', 'receipt_number', 'status');
            }])
            ->where('setting_id', $settingId)
            ->where('status', PosTransaction::STATUS_COMPLETED)
            ->where(function ($query) use ($identifier, $settingId) {
                $query->where('code', $identifier)
                    ->orWhereHas('completedCheckout', function ($checkoutQuery) use ($identifier, $settingId) {
                        $checkoutQuery->where('setting_id', $settingId)
                            ->where('receipt_number', $identifier)
                            ->where('status', PosCheckout::STATUS_POSTED);
                    });
            })
            ->orderBy('id', 'desc')
            ->first();

        if (! $transaction) {
            return null;
        }

        $checkout = $transaction->completedCheckout;

        if (! $checkout || $checkout->status !== PosCheckout::STATUS_POSTED) {
            return null;
        }

        return [
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
        ];
    }
}

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
        if (empty($identifier)) {
            return null;
        }

        $settingId = settings()->id;

        // Try lookup by transaction code
        $transaction = PosTransaction::where('setting_id', $settingId)
            ->where('code', $identifier)
            ->where('status', PosTransaction::STATUS_COMPLETED)
            ->first();

        if ($transaction) {
            $checkout = $transaction->completedCheckout;
            if ($checkout && $checkout->status === PosCheckout::STATUS_POSTED) {
                return [
                    'pos_transaction_id' => $transaction->id,
                    'pos_checkout_id' => $checkout->id,
                    'transaction_code' => $transaction->code,
                    'receipt_number' => $checkout->receipt_number,
                ];
            }
        }

        // Try lookup by receipt number
        $checkout = PosCheckout::where('setting_id', $settingId)
            ->where('receipt_number', $identifier)
            ->where('status', PosCheckout::STATUS_POSTED)
            ->first();

        if ($checkout) {
            $transaction = $checkout->transaction;
            if ($transaction && $transaction->status === PosTransaction::STATUS_COMPLETED) {
                return [
                    'pos_transaction_id' => $transaction->id,
                    'pos_checkout_id' => $checkout->id,
                    'transaction_code' => $transaction->code,
                    'receipt_number' => $checkout->receipt_number,
                ];
            }
        }

        return null;
    }
}

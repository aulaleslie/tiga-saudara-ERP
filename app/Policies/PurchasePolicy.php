<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Modules\Purchase\Entities\Purchase;

class PurchasePolicy
{
    public function correct(User $user, Purchase $purchase): bool
    {
        // Verify purchase is in an eligible status for correction
        if (!in_array($purchase->status, [
            Purchase::STATUS_RECEIVED,
            Purchase::STATUS_RECEIVED_PARTIALLY,
        ])) {
            return false;
        }

        // Verify purchase belongs to user's current setting (tenant scoped)
        $currentSettingId = session('setting_id');
        if ($purchase->setting_id !== $currentSettingId) {
            return false;
        }

        // Super Admin bypass (no need to explicitly grant permission)
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        // Check if user has the correction permission
        return $user->hasPermissionTo('purchases.received.correct');
    }
}

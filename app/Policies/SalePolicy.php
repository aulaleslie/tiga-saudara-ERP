<?php

namespace App\Policies;

use App\Models\User;
use Modules\Sale\Entities\Sale;

class SalePolicy
{
    public function overrideReportingDate(User $user, Sale $sale): bool
    {
        // Verify sale is in an eligible post-approval status
        if (!in_array($sale->status, [
            Sale::STATUS_APPROVED,
            Sale::STATUS_DISPATCHED_PARTIALLY,
            Sale::STATUS_DISPATCHED,
            Sale::STATUS_RETURNED_PARTIALLY,
            Sale::STATUS_RETURNED,
        ])) {
            return false;
        }

        // Verify sale belongs to user's current setting (tenant scoped)
        $currentSettingId = session('setting_id');
        if ($sale->setting_id !== $currentSettingId) {
            return false;
        }

        // Check if user has the reporting-date override permission
        return $user->hasPermissionTo('sales.reporting-date.override');
    }

    public function overrideDueDate(User $user, Sale $sale): bool
    {
        // Verify sale is in an eligible post-approval status
        if (!in_array($sale->status, [
            Sale::STATUS_APPROVED,
            Sale::STATUS_DISPATCHED_PARTIALLY,
            Sale::STATUS_DISPATCHED,
            Sale::STATUS_RETURNED_PARTIALLY,
            Sale::STATUS_RETURNED,
        ])) {
            return false;
        }

        // Verify sale belongs to user's current setting (tenant scoped)
        $currentSettingId = session('setting_id');
        if ($sale->setting_id !== $currentSettingId) {
            return false;
        }

        // Check if user has the due-date override permission
        return $user->hasPermissionTo('sales.due-date.override');
    }
}

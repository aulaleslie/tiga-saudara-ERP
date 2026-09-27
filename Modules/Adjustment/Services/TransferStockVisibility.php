<?php

namespace Modules\Adjustment\Services;

use App\Models\User;

/**
 * Reusable visibility decision boundary for the transfer-stock-visibility
 * feature (see openspec/changes/transfer-stock-visibility-boundary).
 *
 * Wraps the single `stockTransfers.view-system-stock` permission check used
 * consistently across the transfer controller, Livewire components, and
 * Blade views to decide whether a user receives the privileged (system
 * stock, allocation buckets, serial provenance, movement expectations) or
 * blind (operator-intent-only) transfer projection.
 *
 * Deliberately does not reproduce Super Admin role-name checks: the
 * application-wide Gate::before rule in AuthServiceProvider already grants
 * every ability -- including this one -- to Super Admin, so `$user->can(...)`
 * alone is sufficient and correct.
 */
class TransferStockVisibility
{
    public const PERMISSION = 'stockTransfers.view-system-stock';

    /**
     * Whether the given user (or the currently authenticated user when
     * omitted) may see system-derived transfer stock information.
     */
    public static function canView(?User $user = null): bool
    {
        $user = $user ?? auth()->user();

        if (! $user) {
            return false;
        }

        return (bool) $user->can(self::PERMISSION);
    }
}

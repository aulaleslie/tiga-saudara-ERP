<?php

namespace Modules\Adjustment\Services;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Adjustment\Entities\Transfer;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Permission matrix and version guards for Stock Transfer workflow version 3.
 *
 * Every v3 action is authorized by the actor's permission in the current
 * active business (resolved by CheckUserRoleForSetting into the user's role),
 * never by membership of the source/destination businesses of the document.
 * The application-wide Super Admin Gate::before bypass stays authoritative.
 */
class TransferV3Access
{
    public const ACCESS         = 'stockTransfers.access';
    public const SHOW           = 'stockTransfers.show';
    public const CREATE         = 'stockTransfers.create';
    public const EDIT           = 'stockTransfers.edit';
    public const APPROVAL       = 'stockTransfers.approval';
    public const RECEIVE        = 'stockTransfers.receive';
    public const CANCEL_DISPATCH = 'stockTransfers.cancel-dispatch';
    public const VIEW_HISTORY   = 'stockTransfers.view-history';

    public static function allows(string $permission, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();

        return $user !== null && Gate::forUser($user)->allows($permission);
    }

    /** Approval authority grants route configuration and source totals. */
    public static function canConfigureAllocations(?User $user = null): bool
    {
        return self::allows(self::APPROVAL, $user);
    }

    /** Detailed tax/bucket diagnostics need approval AND legacy stock visibility. */
    public static function canViewBucketDiagnostics(?User $user = null): bool
    {
        return self::allows(self::APPROVAL, $user) && self::allows(TransferStockVisibility::PERMISSION, $user);
    }

    /** Event timeline needs document-view authority AND the history permission. */
    public static function canViewHistory(?User $user = null): bool
    {
        return self::allows(self::SHOW, $user) && self::allows(self::VIEW_HISTORY, $user);
    }

    public static function authorize(string $permission): void
    {
        if (! self::allows($permission)) {
            throw new HttpException(403, 'This action is unauthorized.');
        }
    }

    public static function creationEnabled(): bool
    {
        return (bool) config('stock_transfers.v3_creation_enabled', false);
    }

    /** Version-specific v3 actions reject legacy documents. */
    public static function assertV3(Transfer $transfer): void
    {
        if (! $transfer->isV3()) {
            throw new HttpException(404, 'This action is only available for workflow version 3 transfers.');
        }
    }

    /** Legacy actions reject version 3 documents without effects. */
    public static function assertLegacy(Transfer $transfer): void
    {
        if ($transfer->isV3()) {
            throw new HttpException(404, 'This action is not available for workflow version 3 transfers.');
        }
    }
}

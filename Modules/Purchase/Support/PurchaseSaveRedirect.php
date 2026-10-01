<?php

namespace Modules\Purchase\Support;

use Illuminate\Support\Facades\Gate;
use Modules\Purchase\Entities\Purchase;

/**
 * Where to land after a Purchase is successfully created or updated, never on a 403/404:
 *
 * 1. the Purchase detail, when the user has purchases.show and the Purchase is in the active setting
 *    (the detail page 404s outside it, and cross-business create/edit can save into another business);
 * 2. otherwise the Purchase list, when the user has purchases.access (the list's own gate);
 * 3. otherwise home, which only requires an authenticated user.
 *
 * purchases.show / purchases.access are assignable independently of purchases.create / purchases.update.
 */
class PurchaseSaveRedirect
{
    public static function url(Purchase $purchase): string
    {
        $activeSettingId = session('setting_id');
        $inActiveSetting = is_null($activeSettingId) || (int) $purchase->setting_id === (int) $activeSettingId;

        if (Gate::allows('purchases.show') && $inActiveSetting) {
            return route('purchases.show', $purchase);
        }

        return Gate::allows('purchases.access') ? route('purchases.index') : route('home');
    }
}

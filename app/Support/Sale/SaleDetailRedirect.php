<?php

namespace App\Support\Sale;

use Illuminate\Support\Facades\Gate;
use Modules\Sale\Entities\Sale;

/**
 * Where to land after a sale was successfully created or edited: its detail page, but only when the
 * current user could actually open it (same rules as SaleController::show). Otherwise (another business
 * than the active one, or no detail permission) the list, so a successful save never ends on a 403/404.
 */
class SaleDetailRedirect
{
    public static function url(Sale $sale): string
    {
        return self::canOpen($sale) ? route('sales.show', $sale->id) : route('sales.index');
    }

    public static function canOpen(Sale $sale): bool
    {
        if (Gate::denies('sales.show')) {
            return false;
        }

        if (Gate::allows('globalSalesSearch.access')) {
            return true;
        }

        $current = session('setting_id');

        return is_null($current) || (int) $sale->setting_id === (int) $current;
    }
}

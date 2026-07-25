<?php

namespace Modules\Pos\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\Pos\Entities\PosCheckout;
use Modules\Sale\Entities\Sale;

class PosCheckoutSaleController extends Controller
{
    public function show(Request $request, int $checkoutId, int $saleId)
    {
        // 6.2 Authorize POS checkout viewing
        $user = auth()->user();
        if (! $user || ! $user->can('pos.access')) {
            abort(403, 'Unauthorized.');
        }

        // 6.2 Assert the requested sale_id is present in that checkout's pos_checkout_sales rows
        $checkout = PosCheckout::findOrFail($checkoutId);
        $salePivot = \DB::table('pos_checkout_sales')
            ->where('pos_checkout_id', $checkoutId)
            ->where('sale_id', $saleId)
            ->first();

        if (! $salePivot) {
            abort(404, 'Sale not found in this checkout.');
        }

        // 6.3 Load the Sale without any current-setting scoping, so cross-setting Sales resolve
        $sale = Sale::withoutGlobalScope('setting')
            ->with(['customer', 'saleDetails.product'])
            ->findOrFail($saleId);

        return view('pos::checkouts.sale-readonly', compact('checkout', 'sale'));
    }
}

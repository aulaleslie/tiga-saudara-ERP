<?php

namespace Modules\Pos\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\Entities\Setting;

class PosTransactionsEnabledMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $settingId = (int) session('setting_id');

        if ($settingId <= 0) {
            abort(403, 'Setting context is required.');
        }

        $isEnabled = (bool) Setting::query()
            ->whereKey($settingId)
            ->value('pos_transactions_enabled');

        if ($isEnabled) {
            return $next($request);
        }

        $message = 'Fitur transaksi POS belum diaktifkan untuk bisnis ini.';

        if ($request->expectsJson()) {
            return response()->json([
                'code' => 'POS_TRANSACTIONS_DISABLED',
                'message' => $message,
            ], 403);
        }

        if (Gate::allows('pos.sell')) {
            return redirect()
                ->route('pos.sell')
                ->with('warning', $message);
        }

        abort(403, $message);
    }
}

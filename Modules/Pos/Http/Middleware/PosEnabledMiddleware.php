<?php

namespace Modules\Pos\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\Entities\Setting;

class PosEnabledMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $settingId = (int) session('setting_id');

        if ($settingId <= 0) {
            \Illuminate\Support\Facades\Log::channel('single')->error('POS Enabled Middleware: Setting context missing', [
                'path' => $request->path(),
                'setting_id' => $settingId,
                'session_data' => session()->all(),
            ]);
            abort(403, 'Setting context is required.');
        }

        $isEnabled = Setting::query()
            ->whereKey($settingId)
            ->value('pos_enabled');

        if ((bool) $isEnabled) {
            return $next($request);
        }

        if (Gate::allows('sales.access')) {
            return redirect()
                ->route('sales.index')
                ->with('warning', 'POS is disabled for the current business.');
        }

        abort(403, 'POS is disabled for the current business.');
    }
}

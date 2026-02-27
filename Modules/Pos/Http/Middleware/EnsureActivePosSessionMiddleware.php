<?php

namespace Modules\Pos\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Pos\Services\PosSessionLifecycleService;

class EnsureActivePosSessionMiddleware
{
    public function __construct(private readonly PosSessionLifecycleService $sessionLifecycleService)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $settingId = (int) session('setting_id');

        if ($settingId <= 0) {
            abort(403, 'Setting context is required.');
        }

        $user = $request->user();

        if (! $user) {
            abort(403, 'Authentication is required.');
        }

        $activeSession = $this->sessionLifecycleService->getActiveSessionForCashier($settingId, (int) $user->id);

        if (! $activeSession) {
            return redirect()
                ->route('pos.sessions.create')
                ->with('warning', 'Active POS session is required before accessing POS sell screen.');
        }

        $request->attributes->set('pos_session_id', (int) $activeSession->id);
        $request->attributes->set('pos_active_session', $activeSession);

        return $next($request);
    }
}

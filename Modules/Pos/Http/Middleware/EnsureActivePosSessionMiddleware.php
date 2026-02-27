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
            abort(403, 'Active POS session is required.');
        }

        $request->attributes->set('pos_session_id', $activeSession->id);

        return $next($request);
    }
}

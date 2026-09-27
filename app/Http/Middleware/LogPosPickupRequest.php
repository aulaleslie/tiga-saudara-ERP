<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class LogPosPickupRequest
{
    public function handle(Request $request, Closure $next)
    {
        if (strpos($request->path(), 'pos/sessions') !== false && $request->path() !== strval(intval($request->path()))) {
            $user = Auth::user();
            Log::channel('single')->info('POS Pickup Request', [
                'path' => $request->path(),
                'method' => $request->method(),
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'user_roles' => $user?->getRoleNames()->toArray(),
                'session_id' => session('setting_id'),
                'has_auth' => Auth::check(),
                'request_data' => $request->all(),
            ]);
        }

        return $next($request);
    }
}

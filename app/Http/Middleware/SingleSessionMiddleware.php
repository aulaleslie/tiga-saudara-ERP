<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SingleSessionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {
        if (Auth::check()) {
            $userId = Auth::id();
            $currentSessionId = session()->getId();

            // Retrieve the stored session ID
            $storedSessionId = Cache::get('user_session_' . $userId);

            if ($storedSessionId && $storedSessionId !== $currentSessionId) {
                // Invalidate the previous session by regenerating its ID
                $this->invalidateSession($storedSessionId);
            }

            // Store the new session ID
            Cache::put('user_session_' . $userId, $currentSessionId, 7200); // Store for 2 hours
        }

        return $next($request);
    }

    protected function invalidateSession($sessionId): void
    {
        // Logic to invalidate the previous session
        Session::getHandler()->destroy($sessionId);
    }
}

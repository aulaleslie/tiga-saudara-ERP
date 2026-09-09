<?php

namespace App\Http\Middleware;

use App\Services\SessionIncidentDiagnosticsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SingleSessionMiddleware
{
    public function __construct(
        protected SessionIncidentDiagnosticsService $diagnostics
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle($request, Closure $next)
    {
        if (Auth::check()) {
            $userId = Auth::id();
            $currentSessionId = session()->getId();

            // Retrieve the stored session ID
            $storedSessionId = Cache::get('user_session_' . $userId);

            if ($storedSessionId && $storedSessionId !== $currentSessionId) {
                $this->recordConflict($userId, $storedSessionId, $currentSessionId);

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

    /**
     * Emit conflict and invalidation diagnostics before the previous session
     * is destroyed. Kept out of the normal request path when fingerprints
     * match, so this never becomes a routine high-volume log line.
     */
    protected function recordConflict(int $userId, string $storedSessionId, string $currentSessionId): void
    {
        $invalidatedFingerprint = $this->diagnostics->fingerprint($storedSessionId);
        $currentFingerprint = $this->diagnostics->fingerprint($currentSessionId);

        $this->diagnostics->record('single_session_observed', [
            'user_id' => $userId,
            'invalidated_session_fingerprint' => $invalidatedFingerprint,
            'current_session_fingerprint' => $currentFingerprint,
        ], 'debug');

        $this->diagnostics->record('single_session_invalidated', [
            'user_id' => $userId,
            'invalidated_session_fingerprint' => $invalidatedFingerprint,
            'current_session_fingerprint' => $currentFingerprint,
        ], 'warning');
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\SessionIncidentDiagnosticsService;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
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
            $session = $request->hasSession() ? $request->session() : session();

            $currentLoginToken = $session->get('login_token');
            $currentSessionId = $session->getId();

            $this->enforceSingleSession($userId, $currentSessionId, $currentLoginToken);
        }

        return $next($request);
    }

    /**
     * Guard the single session check-destroy-store sequence with cache lock
     * to prevent concurrency races.
     */
    protected function enforceSingleSession(int $userId, string $currentSessionId, ?string $currentLoginToken): void
    {
        try {
            Cache::lock('user_session_lock_' . $userId, 5)->block(2, function () use ($userId, $currentSessionId, $currentLoginToken) {
                $this->processSessionEnforcement($userId, $currentSessionId, $currentLoginToken);
            });
        } catch (LockTimeoutException $e) {
            $this->processSessionEnforcement($userId, $currentSessionId, $currentLoginToken);
        } catch (\Throwable $e) {
            // If the cache driver does not support atomic locks or an error occurs, execute safely directly
            $this->processSessionEnforcement($userId, $currentSessionId, $currentLoginToken);
        }
    }

    /**
     * Process session comparison and invalidation if genuine conflict detected.
     */
    protected function processSessionEnforcement(int $userId, string $currentSessionId, ?string $currentLoginToken): void
    {
        $stored = Cache::get('user_session_' . $userId);
        $storedSessionId = null;
        $storedLoginToken = null;

        if (is_array($stored)) {
            $storedSessionId = $stored['session_id'] ?? null;
            $storedLoginToken = $stored['login_token'] ?? null;
        } elseif (is_string($stored) && $stored !== '') {
            $storedSessionId = $stored;
            $storedLoginToken = null;
        }

        $isConflict = false;

        if ($storedSessionId && $storedSessionId !== $currentSessionId) {
            if ($storedLoginToken !== null && $currentLoginToken !== null) {
                // If login tokens are both present, conflict exists ONLY if login tokens differ
                if ($storedLoginToken !== $currentLoginToken) {
                    $isConflict = true;
                }
            } elseif (is_string($stored)) {
                // Legacy cache fallback
                $isConflict = true;
            }
        }

        if ($isConflict) {
            $this->recordConflict(
                $userId,
                $storedSessionId,
                $currentSessionId,
                $storedLoginToken,
                $currentLoginToken
            );

            $this->invalidateSession($storedSessionId);
        }

        // Store active session and login identity for 2 hours
        $cachePayload = [
            'session_id' => $currentSessionId,
        ];

        if ($currentLoginToken !== null) {
            $cachePayload['login_token'] = $currentLoginToken;
        }

        Cache::put('user_session_' . $userId, $cachePayload, 7200);
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
    protected function recordConflict(
        int $userId,
        string $storedSessionId,
        string $currentSessionId,
        ?string $storedLoginToken = null,
        ?string $currentLoginToken = null
    ): void {
        $invalidatedFingerprint = $this->diagnostics->fingerprint($storedSessionId);
        $currentFingerprint = $this->diagnostics->fingerprint($currentSessionId);

        $context = [
            'user_id' => $userId,
            'invalidated_session_fingerprint' => $invalidatedFingerprint,
            'current_session_fingerprint' => $currentFingerprint,
        ];

        if ($storedLoginToken !== null) {
            $context['invalidated_login_token_fingerprint'] = $this->diagnostics->fingerprint($storedLoginToken);
        }

        if ($currentLoginToken !== null) {
            $context['current_login_token_fingerprint'] = $this->diagnostics->fingerprint($currentLoginToken);
        }

        $this->diagnostics->record('single_session_observed', $context, 'debug');

        $this->diagnostics->record('single_session_invalidated', $context, 'warning');
    }
}


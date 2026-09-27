<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SessionIncidentDiagnosticsService
{
    /**
     * Bump when the fingerprint derivation changes so correlated events can
     * be distinguished from ones produced under a different scheme.
     */
    public const FINGERPRINT_VERSION = 'v1';

    /**
     * Route name fragments that identify transaction-sensitive pages where a
     * retried mutation could duplicate financial/inventory effects.
     */
    protected const TRANSACTION_ROUTE_MARKERS = [
        'purchase',
        'receiving',
        'payment',
        'sale',
        'return',
        'stock',
        'pos',
    ];

    protected const MAX_CONTEXT_LENGTH = 100;

    /**
     * Generate a short, copyable, support-facing incident ID.
     */
    public function generateIncidentId(): string
    {
        return 'ERP-' . strtoupper(Str::random(10));
    }

    /**
     * Derive a one-way, keyed fingerprint for a session ID, CSRF token, or
     * other secret-adjacent value. Never reversible, never logged raw.
     */
    public function fingerprint(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = config('app.key');

        return self::FINGERPRINT_VERSION . ':' . substr(
            hash_hmac('sha256', $value, (string) $key),
            0,
            16
        );
    }

    /**
     * Whether the given route name is classified as transaction-sensitive.
     */
    public function isTransactionSensitiveRoute(?string $routeName): bool
    {
        if (!$routeName) {
            return false;
        }

        $routeName = Str::lower($routeName);

        foreach (self::TRANSACTION_ROUTE_MARKERS as $marker) {
            if (Str::contains($routeName, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize and length-limit a single piece of client- or server-supplied
     * diagnostic context so it stays safe to log and display.
     */
    public function normalizeContextValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return Str::limit($value, self::MAX_CONTEXT_LENGTH, '');
    }

    /**
     * Build the allowlisted environment/deployment context shared by every
     * diagnostic event.
     */
    public function environmentContext(): array
    {
        return [
            'deployment_version' => config('app.deployment_version', 'unknown'),
            'node_id' => config('app.node_id', 'unknown'),
            'session_driver' => config('session.driver'),
            'cache_driver' => config('cache.default'),
        ];
    }

    /**
     * Build the allowlisted request-derived context for a diagnostic event.
     * Deliberately excludes request bodies, cookies, headers, and Livewire
     * payloads beyond the specific fields listed here.
     */
    public function requestContext(Request $request): array
    {
        return [
            'route_name' => $this->normalizeContextValue(optional($request->route())->getName()),
            'method' => $this->normalizeContextValue($request->method()),
            'is_livewire' => $this->isLivewireRequest($request),
            'is_ajax' => $request->ajax() || $request->expectsJson(),
            'session_cookie_present' => $request->hasCookie(config('session.cookie')),
            'business_id' => $request->hasSession()
                ? $this->normalizeContextValue($request->session()->get('setting_id'))
                : null,
            'transaction_sensitive' => $this->isTransactionSensitiveRoute(optional($request->route())->getName()),
        ];
    }

    /**
     * Extract the allowlisted client-supplied page context headers, if any
     * were attached by the shared Livewire request hook. Values are treated
     * as untrusted troubleshooting hints, never authorization data.
     */
    public function clientPageContext(Request $request): array
    {
        return array_filter([
            'page_view_id' => $this->normalizeContextValue($request->header('X-ERP-Page-View-Id')),
            'tab_id' => $this->normalizeContextValue($request->header('X-ERP-Tab-Id')),
            'page_rendered_at' => $this->normalizeContextValue($request->header('X-ERP-Page-Rendered-At')),
            'page_business_id' => $this->normalizeContextValue($request->header('X-ERP-Page-Business-Id')),
            'page_route_name' => $this->normalizeContextValue($request->header('X-ERP-Page-Route')),
        ], static fn ($value) => $value !== null);
    }

    protected function isLivewireRequest(Request $request): bool
    {
        return $request->hasHeader('X-Livewire') || Str::startsWith($request->path(), 'livewire/');
    }

    /**
     * Build the CSRF-specific diagnostic evidence needed to distinguish a
     * missing submitted token, a missing/expired session token, and a
     * present-but-mismatched pair. Only presence flags, fingerprints, and a
     * boolean comparison are recorded — never the raw token values.
     */
    public function csrfDiagnosticContext(Request $request): array
    {
        $submittedToken = $this->extractSubmittedToken($request);
        $sessionToken = $request->hasSession() ? $request->session()->token() : null;

        return [
            'submitted_token_present' => $submittedToken !== null && $submittedToken !== '',
            'session_token_present' => $sessionToken !== null && $sessionToken !== '',
            'submitted_token_fingerprint' => $this->fingerprint($submittedToken),
            'session_token_fingerprint' => $this->fingerprint($sessionToken),
            'tokens_match' => is_string($submittedToken) && is_string($sessionToken) && $submittedToken !== ''
                ? hash_equals($sessionToken, $submittedToken)
                : false,
        ];
    }

    /**
     * Mirror Laravel's VerifyCsrfToken token extraction (input, X-CSRF-TOKEN
     * header, or the raw X-XSRF-TOKEN cookie header) without decrypting the
     * encrypted XSRF cookie, since only presence/fingerprint is needed here.
     */
    protected function extractSubmittedToken(Request $request): ?string
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

        if (!$token) {
            $token = $request->header('X-XSRF-TOKEN');
        }

        return $token ?: null;
    }

    /**
     * Emit a structured diagnostic event. Failures are swallowed so that
     * instrumentation never breaks the primary response path.
     */
    public function record(string $event, array $context = [], string $level = 'info'): void
    {
        try {
            Log::log($level, $event, array_merge([
                'event' => $event,
                'timestamp' => now()->toIso8601String(),
            ], $this->environmentContext(), $context));
        } catch (\Throwable $e) {
            // Diagnostic emission must never break the primary response path.
        }
    }
}

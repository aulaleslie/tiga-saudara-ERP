<?php

namespace App\Services\Notification;

class NotificationActionUrlNormalizer
{
    /**
     * Normalize an action URL so it is always a safe, origin-relative path.
     *
     * Rules:
     * - Returns null for null, empty, or whitespace-only inputs.
     * - Rejects protocol-relative URLs (starts with '//').
     * - If given an origin-relative path starting with '/' (and not '//'):
     *   validates that parse_url does not find a scheme or host, and preserves query/fragment.
     * - If given an absolute URL (with scheme & host):
     *   it must match one of the $allowedOrigins (case-insensitive host/scheme/port match).
     *   If matched, extracts the path, query, and fragment as an origin-relative path.
     *   If not matched, returns null.
     * - If given a relative path without leading slash (e.g. 'purchases/1'), ensures leading slash '/purchases/1'.
     * - Any backslash prefix (e.g. '\/') or malformed URL that could cause open redirect is rejected.
     *
     * @param string|null $url
     * @param array<int, string> $allowedOrigins List of allowed absolute origins e.g. ['http://192.168.1.100', 'https://erp.example.com']
     * @return string|null Normalized origin-relative path (e.g. '/purchases/1?tab=items#section') or null if invalid/rejected
     */
    public static function normalize(?string $url, array $allowedOrigins = []): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '' || $trimmed === '#') {
            return $trimmed === '#' ? '#' : null;
        }

        // Reject protocol-relative URLs e.g. "//evil.com" or "\evil.com"
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '\\') || str_starts_with($trimmed, '/\\')) {
            return null;
        }

        $parsed = parse_url($trimmed);
        if ($parsed === false) {
            return null;
        }

        // Check if absolute URL (contains scheme or host)
        if (isset($parsed['scheme']) || isset($parsed['host'])) {
            if (empty($allowedOrigins)) {
                return null;
            }

            $matchesAllowedOrigin = false;
            foreach ($allowedOrigins as $origin) {
                if (self::originMatches($parsed, $origin)) {
                    $matchesAllowedOrigin = true;
                    break;
                }
            }

            if (!$matchesAllowedOrigin) {
                return null;
            }

            // Extract origin-relative components
            $path = $parsed['path'] ?? '/';
            if (!str_starts_with($path, '/')) {
                $path = '/' . $path;
            }

            return self::buildRelativePath($path, $parsed['query'] ?? null, $parsed['fragment'] ?? null);
        }

        // Relative URL
        $path = $parsed['path'] ?? '';
        if ($path === '' && (isset($parsed['query']) || isset($parsed['fragment']))) {
            $path = '/';
        } elseif (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        // Security check: ensure path does not start with '//' or contain control characters
        if (str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return null;
        }

        return self::buildRelativePath($path, $parsed['query'] ?? null, $parsed['fragment'] ?? null);
    }

    /**
     * Check if a parsed URL matches an allowed origin.
     */
    public static function originMatches(array $parsedUrl, string $allowedOrigin): bool
    {
        $allowedOrigin = trim($allowedOrigin);
        if ($allowedOrigin === '') {
            return false;
        }

        $parsedOrigin = parse_url($allowedOrigin);
        if ($parsedOrigin === false || !isset($parsedOrigin['host'])) {
            return false;
        }

        $urlScheme = strtolower($parsedUrl['scheme'] ?? 'http');
        $originScheme = strtolower($parsedOrigin['scheme'] ?? 'http');
        if ($urlScheme !== $originScheme) {
            return false;
        }

        $urlHost = strtolower($parsedUrl['host'] ?? '');
        $originHost = strtolower($parsedOrigin['host'] ?? '');
        if ($urlHost !== $originHost) {
            return false;
        }

        $urlPort = $parsedUrl['port'] ?? ($urlScheme === 'https' ? 443 : 80);
        $originPort = $parsedOrigin['port'] ?? ($originScheme === 'https' ? 443 : 80);

        return $urlPort === $originPort;
    }

    /**
     * Build relative path with query and fragment.
     */
    protected static function buildRelativePath(string $path, ?string $query, ?string $fragment): string
    {
        $result = $path;
        if ($query !== null && $query !== '') {
            $result .= '?' . $query;
        }
        if ($fragment !== null && $fragment !== '') {
            $result .= '#' . $fragment;
        }
        return $result;
    }
}

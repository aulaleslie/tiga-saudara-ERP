<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IdempotencyService
{
    private const CACHE_PREFIX = 'idempotency';
    private const TTL_MINUTES = 5;

    public static function tokenFromRequest(Request $request): string
    {
        return $request->old('idempotency_token') ?? (string) Str::uuid();
    }

    public static function claim(?string $token, string $routeName, $userId = null): bool
    {
        if (empty($token)) {
            return false;
        }

        $key = sprintf('%s:%s:%s:%s', self::CACHE_PREFIX, $userId ?? 'guest', $routeName, $token);

        // Store with in-progress marker
        return Cache::add($key, 'IN_PROGRESS', now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * Completes an in-progress idempotency claim after successful database commit.
     */
    public static function complete(?string $token, string $routeName, $userId = null, mixed $result = 'COMPLETED'): void
    {
        if (empty($token)) {
            return;
        }

        $key = sprintf('%s:%s:%s:%s', self::CACHE_PREFIX, $userId ?? 'guest', $routeName, $token);
        Cache::put($key, $result, now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * Releases an in-progress idempotency claim when an operation fails before commit or on rollback.
     */
    public static function release(?string $token, string $routeName, $userId = null): void
    {
        if (empty($token)) {
            return;
        }

        $key = sprintf('%s:%s:%s:%s', self::CACHE_PREFIX, $userId ?? 'guest', $routeName, $token);
        Cache::forget($key);
    }
}

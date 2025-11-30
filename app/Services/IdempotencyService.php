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

        return Cache::add($key, now()->toIso8601String(), now()->addMinutes(self::TTL_MINUTES));
    }
}

<?php

namespace Tests\Unit\Services;

use App\Services\IdempotencyService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IdempotencyServiceLifecycleTest extends TestCase
{
    public function test_claim_complete_and_release_lifecycle(): void
    {
        $token = 'test-token-' . uniqid();
        $route = 'purchases.store';
        $userId = 42;

        // 1. First claim succeeds
        $this->assertTrue(IdempotencyService::claim($token, $route, $userId));

        // 2. Immediate second claim fails (duplicate)
        $this->assertFalse(IdempotencyService::claim($token, $route, $userId));

        // 3. Release on error clears lock and allows retry
        IdempotencyService::release($token, $route, $userId);
        $this->assertTrue(IdempotencyService::claim($token, $route, $userId));

        // 4. Complete marks state as finished and retains duplicate protection
        IdempotencyService::complete($token, $route, $userId, 'COMPLETED');
        $this->assertFalse(IdempotencyService::claim($token, $route, $userId));
    }
}

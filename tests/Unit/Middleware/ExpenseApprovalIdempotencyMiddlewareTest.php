<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExpenseApprovalIdempotencyMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function createApprovalRequest(string $expenseId, int $userId, array $payload = ['_token' => 'sample_token']): Request
    {
        $request = Request::create('/expenses/' . $expenseId . '/approve', 'POST', $payload);
        $route = new Route('POST', 'expenses/{expense}/approve', ['as' => 'expenses.approve']);
        $route->bind($request);
        $route->setParameter('expense', $expenseId);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => (object) ['id' => $userId]);

        return $request;
    }

    public function test_two_expense_ids_can_claim_separately(): void
    {
        $middleware = new IdempotencyMiddleware();
        $userId = 99;

        $request1 = $this->createApprovalRequest('101', $userId);
        $request2 = $this->createApprovalRequest('102', $userId);

        $response1 = $middleware->handle($request1, fn () => new Response('ok1'));
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals('ok1', $response1->getContent());

        // Second expense with different ID by same user should NOT collide
        $response2 = $middleware->handle($request2, fn () => new Response('ok2'));
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals('ok2', $response2->getContent());
    }

    public function test_repeated_same_expense_id_claim_is_rejected(): void
    {
        $middleware = new IdempotencyMiddleware();
        $userId = 99;

        $request1 = $this->createApprovalRequest('101', $userId);
        $request2 = $this->createApprovalRequest('101', $userId);

        $response1 = $middleware->handle($request1, fn () => new Response('ok1'));
        $this->assertEquals(200, $response1->getStatusCode());

        // Repeated request for same expense ID should be rejected
        $response2 = $middleware->handle($request2, fn () => new Response('ok2'));
        $this->assertInstanceOf(RedirectResponse::class, $response2);
        $this->assertTrue(session()->has('errors'));
        $errors = session('errors');
        $this->assertTrue($errors->has('idempotency'));
    }

    public function test_other_routes_preserve_fallback_key_behavior(): void
    {
        $middleware = new IdempotencyMiddleware();
        $userId = 99;

        $request1 = Request::create('/expenses', 'POST', ['_token' => 'tok', 'amount' => 5000]);
        $route1 = new Route('POST', 'expenses', ['as' => 'expenses.store']);
        $route1->bind($request1);
        $request1->setRouteResolver(fn () => $route1);
        $request1->setUserResolver(fn () => (object) ['id' => $userId]);

        $request2 = Request::create('/expenses', 'POST', ['_token' => 'tok', 'amount' => 5000]);
        $route2 = new Route('POST', 'expenses', ['as' => 'expenses.store']);
        $route2->bind($request2);
        $request2->setRouteResolver(fn () => $route2);
        $request2->setUserResolver(fn () => (object) ['id' => $userId]);

        $response1 = $middleware->handle($request1, fn () => new Response('ok'));
        $this->assertEquals(200, $response1->getStatusCode());

        // Repeated identical store request on another route is rejected under default behavior
        $response2 = $middleware->handle($request2, fn () => new Response('ok'));
        $this->assertInstanceOf(RedirectResponse::class, $response2);
        $this->assertTrue(session('errors')->has('idempotency'));
    }

    public function test_explicit_token_is_scoped_by_expense_id_for_approval_route(): void
    {
        $userId = 99;

        $request = $this->createApprovalRequest('101', $userId, [
            '_token' => 'csrf_token',
            'idempotency_token' => 'custom-unique-uuid-1',
        ]);

        $resolved = IdempotencyMiddleware::resolveToken($request);
        $this->assertEquals('custom-unique-uuid-1:101', $resolved);
    }

    public function test_same_explicit_token_across_different_expenses_does_not_collide(): void
    {
        $middleware = new IdempotencyMiddleware();
        $userId = 99;

        $request1 = $this->createApprovalRequest('101', $userId, [
            'idempotency_token' => 'shared-client-token',
        ]);
        $request2 = $this->createApprovalRequest('102', $userId, [
            'idempotency_token' => 'shared-client-token',
        ]);
        $request1Duplicate = $this->createApprovalRequest('101', $userId, [
            'idempotency_token' => 'shared-client-token',
        ]);

        $response1 = $middleware->handle($request1, fn () => new Response('ok1'));
        $this->assertEquals(200, $response1->getStatusCode());

        // Reusing same explicit token on another expense must NOT collide
        $response2 = $middleware->handle($request2, fn () => new Response('ok2'));
        $this->assertEquals(200, $response2->getStatusCode());

        // Repeated request on same expense with same explicit token must be rejected
        $responseDuplicate = $middleware->handle($request1Duplicate, fn () => new Response('ok-dup'));
        $this->assertInstanceOf(RedirectResponse::class, $responseDuplicate);
        $this->assertTrue(session('errors')->has('idempotency'));
    }

    public function test_claim_is_released_on_403_response_for_expenses_approve(): void
    {
        $middleware = new IdempotencyMiddleware();
        $userId = 99;

        $request = $this->createApprovalRequest('101', $userId);

        // First attempt returns 403 (e.g. permission or ownership check failed)
        $response1 = $middleware->handle($request, fn () => new Response('forbidden', 403));
        $this->assertEquals(403, $response1->getStatusCode());

        // Because claim was released on 403, immediate retry with same parameters succeeds
        $response2 = $middleware->handle($request, fn () => new Response('retry-ok', 200));
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals('retry-ok', $response2->getContent());
    }
}

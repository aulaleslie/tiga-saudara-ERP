<?php

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\SingleSessionMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SingleSessionMultiTabTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create();
    }

    public function test_login_event_assigns_login_token_in_session()
    {
        $user = User::factory()->create([
            'is_active' => 1,
            'password' => bcrypt('password123'),
        ]);
        $user->settings()->attach($this->setting->id, ['role_id' => 1]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertRedirect();
        $this->assertTrue(session()->has('login_token'));
        $this->assertNotEmpty(session()->get('login_token'));
    }

    public function test_two_untokened_sessions_racing_without_cache_do_not_falsely_invalidate()
    {
        $user = User::factory()->create();
        Auth::login($user);

        $sess1Id = str_repeat('1', 40);
        $sess2Id = str_repeat('2', 40);

        // Seed two sessions with NO login_token at all (e.g. pre-deploy or un-migrated tabs)
        $handler = Session::getHandler();
        $handler->write($sess1Id, serialize(['user_id' => $user->id]));
        $handler->write($sess2Id, serialize(['user_id' => $user->id]));

        // Ensure cache is completely empty
        Cache::forget('user_session_' . $user->id);

        Log::spy();

        $middleware = app(SingleSessionMiddleware::class);

        // Request 1 from tab 1 (no login_token in session)
        $req1 = Request::create('/dashboard', 'GET');
        $sess1 = new \Illuminate\Session\Store('test', $handler, $sess1Id);
        $sess1->start();
        $req1->setLaravelSession($sess1);

        $middleware->handle($req1, fn ($req) => response('ok'));

        // Request 2 from tab 2 (no login_token in session)
        $req2 = Request::create('/purchases/create', 'GET');
        $sess2 = new \Illuminate\Session\Store('test', $handler, $sess2Id);
        $sess2->start();
        $req2->setLaravelSession($sess2);

        $middleware->handle($req2, fn ($req) => response('ok'));

        // Neither session should be destroyed
        $this->assertNotEmpty($handler->read($sess1Id), 'Session 1 was falsely destroyed when un-tokened sessions raced');
        $this->assertNotEmpty($handler->read($sess2Id), 'Session 2 was falsely destroyed when un-tokened sessions raced');

        // No invalidation event should be emitted
        Log::shouldNotHaveReceived('log', function ($args) {
            return ($args[1] ?? null) === 'single_session_invalidated';
        });
    }

    public function test_same_login_multi_tab_requests_do_not_destroy_session()
    {
        $user = User::factory()->create();
        Auth::login($user);

        $loginToken = (string) Str::uuid();
        $tab1SessionId = str_repeat('1', 40);
        $tab2SessionId = str_repeat('2', 40);

        // Seed session 1 data into handler
        $handler = Session::getHandler();
        $handler->write($tab1SessionId, serialize(['user_id' => $user->id, 'login_token' => $loginToken]));

        // Prime the cache with login 1's identity
        Cache::put('user_session_' . $user->id, [
            'login_token' => $loginToken,
            'session_id' => $tab1SessionId,
        ], 7200);

        Log::spy();

        // Tab 2 makes a request sharing the same login_token
        $requestTab2 = Request::create('/dashboard', 'GET');
        $session2 = $this->app['session']->driver();
        $session2->setId($tab2SessionId);
        $session2->start();
        $session2->put('login_token', $loginToken);
        $requestTab2->setLaravelSession($session2);

        $middleware = app(SingleSessionMiddleware::class);
        $response = $middleware->handle($requestTab2, fn ($req) => response('ok'));

        $this->assertSame('ok', $response->getContent());

        // Assert no single_session_invalidated log was emitted
        Log::shouldNotHaveReceived('log', function ($args) {
            return ($args[1] ?? null) === 'single_session_invalidated';
        });

        // Assert session 1 was NOT destroyed in the session handler
        $readData = $handler->read($tab1SessionId);
        $this->assertNotEmpty($readData, 'Tab 1 session was unexpectedly destroyed by Tab 2 request');

        // Check updated cache still retains the login token
        $cached = Cache::get('user_session_' . $user->id);
        $this->assertIsArray($cached);
        $this->assertSame($loginToken, $cached['login_token']);
        $this->assertSame($tab2SessionId, $cached['session_id']);
    }

    public function test_genuine_second_login_invalidates_prior_session()
    {
        $user = User::factory()->create();
        Auth::login($user);

        $loginToken1 = (string) Str::uuid();
        $loginToken2 = (string) Str::uuid();
        $device1SessionId = str_repeat('a', 40);
        $device2SessionId = str_repeat('b', 40);

        // Seed session 1 from initial login into handler
        $handler = Session::getHandler();
        $handler->write($device1SessionId, serialize(['user_id' => $user->id, 'login_token' => $loginToken1]));

        // Stored in cache
        Cache::put('user_session_' . $user->id, [
            'login_token' => $loginToken1,
            'session_id' => $device1SessionId,
        ], 7200);

        Log::spy();

        // Session 2 from a fresh second login (different login_token)
        $requestDevice2 = Request::create('/dashboard', 'GET');
        $session2 = $this->app['session']->driver();
        $session2->setId($device2SessionId);
        $session2->start();
        $session2->put('login_token', $loginToken2);
        $requestDevice2->setLaravelSession($session2);

        $middleware = app(SingleSessionMiddleware::class);
        $response = $middleware->handle($requestDevice2, fn ($req) => response('ok'));

        $this->assertSame('ok', $response->getContent());

        // Assert single_session_invalidated was emitted
        Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) use ($user) {
            return $message === 'single_session_invalidated'
                && $level === 'warning'
                && $context['user_id'] === $user->id
                && !empty($context['invalidated_session_fingerprint'])
                && !empty($context['current_session_fingerprint']);
        })->once();

        // Assert device 1 session was destroyed
        $readData = $handler->read($device1SessionId);
        $this->assertEmpty($readData, 'Prior session should have been destroyed by a genuine second login');

        // Check updated cache has new login token
        $cached = Cache::get('user_session_' . $user->id);
        $this->assertIsArray($cached);
        $this->assertSame($loginToken2, $cached['login_token']);
        $this->assertSame($device2SessionId, $cached['session_id']);
    }

    public function test_legacy_string_cache_format_invalidates_gracefully()
    {
        $user = User::factory()->create();
        Auth::login($user);

        $legacySessionId = str_repeat('c', 40);
        $newSessionId = str_repeat('d', 40);

        $handler = Session::getHandler();
        $handler->write($legacySessionId, 'legacy-data');

        // Prime cache with legacy string format
        Cache::put('user_session_' . $user->id, $legacySessionId, 7200);

        Log::spy();

        $loginToken = (string) Str::uuid();

        $request = Request::create('/dashboard', 'GET');
        $session = $this->app['session']->driver();
        $session->setId($newSessionId);
        $session->start();
        $session->put('login_token', $loginToken);
        $request->setLaravelSession($session);

        $middleware = app(SingleSessionMiddleware::class);
        $response = $middleware->handle($request, fn ($req) => response('ok'));

        $this->assertSame('ok', $response->getContent());

        // Assert legacy session was invalidated
        $readData = $handler->read($legacySessionId);
        $this->assertEmpty($readData);

        // Cache is now upgraded to array format
        $cached = Cache::get('user_session_' . $user->id);
        $this->assertIsArray($cached);
        $this->assertSame($loginToken, $cached['login_token']);
        $this->assertSame($newSessionId, $cached['session_id']);
    }

    public function test_rapid_concurrent_requests_with_same_login_token_do_not_invalidate()
    {
        $user = User::factory()->create();
        Auth::login($user);

        $loginToken = (string) Str::uuid();
        $sessAId = str_repeat('e', 40);
        $sessBId = str_repeat('f', 40);

        $handler = Session::getHandler();
        $handler->write($sessAId, serialize(['login_token' => $loginToken]));
        $handler->write($sessBId, serialize(['login_token' => $loginToken]));

        Log::spy();

        $middleware = app(SingleSessionMiddleware::class);

        // Request A
        $reqA = Request::create('/dashboard', 'GET');
        $sessA = $this->app['session']->driver();
        $sessA->setId($sessAId);
        $sessA->start();
        $sessA->put('login_token', $loginToken);
        $reqA->setLaravelSession($sessA);

        $middleware->handle($reqA, fn ($req) => response('ok'));

        // Request B (same login token, alternating)
        $reqB = Request::create('/purchases/create', 'GET');
        $sessB = $this->app['session']->driver();
        $sessB->setId($sessBId);
        $sessB->start();
        $sessB->put('login_token', $loginToken);
        $reqB->setLaravelSession($sessB);

        $middleware->handle($reqB, fn ($req) => response('ok'));

        // Request A again
        $middleware->handle($reqA, fn ($req) => response('ok'));

        // Neither session should be destroyed
        $this->assertNotEmpty($handler->read($sessAId));
        $this->assertNotEmpty($handler->read($sessBId));

        Log::shouldNotHaveReceived('log', function ($args) {
            return ($args[1] ?? null) === 'single_session_invalidated';
        });
    }
}

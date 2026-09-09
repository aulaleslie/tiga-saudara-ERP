<?php

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\SingleSessionMiddleware;
use App\Models\User;
use App\Services\SessionIncidentDiagnosticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SingleSessionMiddlewareDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_conflicting_session_emits_observed_and_invalidated_events_before_destroying_the_old_session()
    {
        $user = User::factory()->create();
        Auth::login($user);

        Cache::put('user_session_' . $user->id, 'previous-session-id', 7200);

        Log::spy();

        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->getSession()->setId('current-session-id');
        $request->getSession()->start();

        $middleware = app(SingleSessionMiddleware::class);
        $middleware->handle($request, fn ($req) => response('ok'));

        Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) use ($user) {
            return $message === 'single_session_invalidated'
                && $level === 'warning'
                && $context['user_id'] === $user->id
                && !empty($context['invalidated_session_fingerprint'])
                && !empty($context['current_session_fingerprint']);
        })->once();

        Log::shouldHaveReceived('log')->withArgs(function ($level, $message) {
            return $message === 'single_session_observed' && $level === 'debug';
        })->once();
    }

    public function test_diagnostic_events_never_contain_the_raw_session_ids()
    {
        $user = User::factory()->create();
        Auth::login($user);

        Cache::put('user_session_' . $user->id, 'previous-raw-session-id', 7200);

        Log::spy();

        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->getSession()->setId('current-raw-session-id');
        $request->getSession()->start();

        $middleware = app(SingleSessionMiddleware::class);
        $middleware->handle($request, fn ($req) => response('ok'));

        Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
            if ($message !== 'single_session_invalidated') {
                return true;
            }

            $encoded = json_encode($context);

            return !str_contains($encoded, 'previous-raw-session-id')
                && !str_contains($encoded, 'current-raw-session-id');
        });
    }

    public function test_no_conflict_does_not_emit_invalidation_event()
    {
        $user = User::factory()->create();
        Auth::login($user);

        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->getSession()->setId('only-session-id');
        $request->getSession()->start();

        Cache::forget('user_session_' . $user->id);

        Log::spy();

        $middleware = app(SingleSessionMiddleware::class);
        $middleware->handle($request, fn ($req) => response('ok'));

        Log::shouldNotHaveReceived('log', function ($args) {
            return ($args[1] ?? null) === 'single_session_invalidated';
        });
    }

    public function test_conflict_handling_survives_diagnostic_logging_failure()
    {
        $user = User::factory()->create();
        Auth::login($user);

        Cache::put('user_session_' . $user->id, 'previous-session-id', 7200);

        Log::shouldReceive('log')->andThrow(new \RuntimeException('log sink unavailable'));

        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->getSession()->setId('current-session-id');
        $request->getSession()->start();

        $middleware = app(SingleSessionMiddleware::class);
        $response = $middleware->handle($request, fn ($req) => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\IdempotencyService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IdempotencyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('post') && ! $request->isMethod('put')) {
            return $next($request);
        }

        $token = $request->header('X-Idempotency-Token') ?? $request->input('idempotency_token');
        $routeName = $request->route()?->getName() ?? $request->path();
        $userId = optional($request->user())->id;

        if (! IdempotencyService::claim($token, $routeName, $userId)) {
            return $this->reject($request);
        }

        return $next($request);
    }

    protected function reject(Request $request): RedirectResponse
    {
        return redirect()->back()->withInput()->withErrors([
            'idempotency' => 'Permintaan yang sama sudah diproses. Silakan tunggu sebelum mencoba lagi.',
        ]);
    }
}

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

        $routeName = $request->route()?->getName() ?? $request->path();
        $userId = optional($request->user())->id;
        $token = self::resolveToken($request);

        if (! IdempotencyService::claim($token, $routeName, $userId)) {
            return $this->reject($request);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            if ($routeName === 'expenses.approve') {
                IdempotencyService::release($token, $routeName, $userId);
            }
            throw $e;
        }

        if ($routeName === 'expenses.approve') {
            if (isset($response->exception) || $response->getStatusCode() >= 400) {
                IdempotencyService::release($token, $routeName, $userId);
            }
        }

        return $response;
    }

    public static function resolveToken(Request $request): string
    {
        $token = $request->header('X-Idempotency-Token') ?? $request->input('idempotency_token');
        $routeName = $request->route()?->getName() ?? $request->path();
        $userId = optional($request->user())->id;

        if ($routeName === 'expenses.approve') {
            $expenseId = self::resolveExpenseId($request);
            if (! empty($token)) {
                return (string) $token . ':' . ($expenseId ?? '');
            }

            $signature = hash('sha256', $routeName . '|' . ($userId ?? 'guest') . '|' . ($expenseId ?? '') . '|' . json_encode($request->all()));
            return 'auto-' . $signature;
        }

        if (! empty($token)) {
            return (string) $token;
        }

        $signature = hash('sha256', $routeName . '|' . ($userId ?? 'guest') . '|' . json_encode($request->all()));
        return 'auto-' . $signature;
    }

    public static function resolveExpenseId(Request $request): ?string
    {
        $expense = $request->route('expense');
        if (is_object($expense)) {
            return (string) ($expense->getKey() ?? $expense->id ?? '');
        }
        if (! empty($expense)) {
            return (string) $expense;
        }
        if (preg_match('#(?:^|/)expenses/([^/]+)/approve#', $request->path(), $matches)) {
            return (string) $matches[1];
        }
        return null;
    }

    protected function reject(Request $request): RedirectResponse
    {
        return redirect()->back()->withInput()->withErrors([
            'idempotency' => 'Permintaan yang sama sudah diproses. Silakan tunggu sebelum mencoba lagi.',
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\PosSession;
use App\Support\PosSessionManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActivePosSession
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var PosSessionManager $manager */
        $manager = app(PosSessionManager::class);
        $session = $manager->current();

        if (! $session) {
            return redirect()->route('app.pos.session')
                ->withErrors(['posSession' => 'Mulai sesi POS sebelum melanjutkan.']);
        }

        if ($session->status === PosSession::STATUS_PAUSED) {
            return redirect()->route('app.pos.session')
                ->withErrors(['posSession' => 'Sesi POS dijeda. Lanjutkan kembali sebelum menggunakan POS.']);
        }

        $request->attributes->set('pos_session', $session);

        return $next($request);
    }
}

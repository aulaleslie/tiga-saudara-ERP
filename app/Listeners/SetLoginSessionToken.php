<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class SetLoginSessionToken
{
    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        if (Session::isStarted() || request()->hasSession()) {
            session()->put('login_token', (string) Str::uuid());
        }
    }
}

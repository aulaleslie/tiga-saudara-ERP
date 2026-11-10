<?php

namespace Modules\Consignment\Tests\Feature\Concurrency;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RaceWorkerBootstrap
{
    public static function login(int $actorId): ?User
    {
        Auth::loginUsingId($actorId);

        return Auth::user();
    }
}

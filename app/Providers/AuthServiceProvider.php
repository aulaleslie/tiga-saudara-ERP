<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Gate::before(function ($user, $ability) {
            $isSuperAdmin = $user->hasRole('Super Admin');
            if ($isSuperAdmin || strpos($ability, 'pos.safeDrops') !== false) {
                \Illuminate\Support\Facades\Log::channel('single')->info('Gate::before check', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'ability' => $ability,
                    'is_super_admin' => $isSuperAdmin,
                    'user_roles' => $user->getRoleNames()->toArray(),
                    'result' => $isSuperAdmin ? 'ALLOWED (Super Admin)' : 'NEUTRAL',
                ]);
            }
            return $isSuperAdmin ? true : null;
        });
    }
}

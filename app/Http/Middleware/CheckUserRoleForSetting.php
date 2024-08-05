<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class CheckUserRoleForSetting
{
    public function handle($request, Closure $next)
    {
        if (Auth::check()) {
            $user = Auth::user();

            // Check if the user has the Super Admin role
            if ($user->hasRole('Super Admin')) {
                // Skip dynamic role assignment for Super Admin
                return $next($request);
            }

            // Assign roles dynamically based on the current setting
            $role = $user->getCurrentSettingRole();
            if ($role && !$user->hasRole($role->name)) {
                session(['temporary_role' => $role->name]);
                $user->syncRoles([$role->name]);
            }
        }

        return $next($request);
    }

    public function terminate($request, $response)
    {
        if (Auth::check()) {
            $user = Auth::user();
            $originalRoles = session('original_roles', []);

            if (!empty($originalRoles)) {
                $user->syncRoles($originalRoles);
            }
        }
    }
}

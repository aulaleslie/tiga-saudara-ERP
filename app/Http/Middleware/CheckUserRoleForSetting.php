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

            // Ensure setting_id is set for all users (including Super Admin)
            if (!session()->has('setting_id')) {
                // For Super Admin, use the first available setting or a provided setting_id in request
                if ($user->hasRole('Super Admin')) {
                    // Try to get setting_id from request if provided
                    $settingId = $request->input('setting_id') ?: $request->query('setting_id');
                    if (!$settingId) {
                        // Fall back to first available setting from any settings table
                        $settingId = $user->settings()->orderBy('settings.id')->value('settings.id');
                    }
                    if ($settingId) {
                        session(['setting_id' => (int) $settingId]);
                        \Illuminate\Support\Facades\Log::channel('single')->info('CheckUserRoleForSetting: Super Admin setting assigned', [
                            'user_id' => $user->id,
                            'user_email' => $user->email,
                            'setting_id' => (int) $settingId,
                        ]);
                    } else {
                        \Illuminate\Support\Facades\Log::channel('single')->warning('CheckUserRoleForSetting: Super Admin has no settings', [
                            'user_id' => $user->id,
                            'user_email' => $user->email,
                        ]);
                    }
                } else {
                    $settingId = $user->settings()->orderBy('settings.id')->value('settings.id');
                    if ($settingId) {
                        session(['setting_id' => $settingId]);
                    }
                }
            }

            // Check if the user has the Super Admin role
            if ($user->hasRole('Super Admin')) {
                // Skip dynamic role assignment for Super Admin
                return $next($request);
            }

            // Assign roles dynamically based on the current setting
            $role = $user->getCurrentSettingRole();
            if ($role) {
                $currentRoles = $user->getRoleNames();
                if ($currentRoles->count() !== 1 || ! $currentRoles->contains($role->name)) {
                    $user->syncRoles([$role->name]);
                }
            } elseif ($user->roles->isNotEmpty()) {
                $user->syncRoles([]);
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

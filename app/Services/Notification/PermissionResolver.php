<?php

namespace App\Services\Notification;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

class PermissionResolver
{
    /**
     * Check if a user has a specific permission in a specific setting.
     * Super Admins always return true.
     */
    public function hasPermissionInSetting(User $user, int $settingId, string $permission): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        $setting = $user->settings()->where('setting_id', $settingId)->first();

        if (!$setting || !$setting->pivot || !$setting->pivot->role_id) {
            return false;
        }

        /** @var \Spatie\Permission\Models\Role|null $role */
        $role = Role::find($setting->pivot->role_id);

        if (!$role) {
            return false;
        }

        return $role->hasPermissionTo($permission);
    }

    /**
     * Resolve users who should receive a notification based on setting and permission.
     * This includes all Super Admins and active users with a role in the setting that has the permission.
     *
     * @return Collection<int, User>
     */
    public function resolveRecipients(int $settingId, string $permission): Collection
    {
        // Get role IDs that have this permission
        $roleIdsWithPermission = Role::whereHas('permissions', function ($query) use ($permission) {
            $query->where('name', $permission);
        })->pluck('id');

        return User::isActive()
            ->where(function ($query) use ($settingId, $roleIdsWithPermission) {
                // User has Super Admin role
                $query->whereHas('roles', function ($q) {
                    $q->where('name', 'Super Admin');
                })
                // OR user is assigned a role in this setting that has the permission
                ->orWhereHas('settings', function ($q) use ($settingId, $roleIdsWithPermission) {
                    $q->where('setting_id', $settingId)
                      ->whereIn('user_setting.role_id', $roleIdsWithPermission);
                });
            })
            ->get();
    }

    /**
     * Get recipients for low-stock notifications in a specific setting.
     */
    public function getLowStockRecipients(int $settingId): Collection
    {
        return $this->resolveRecipients($settingId, 'notifications.lowStock');
    }

    /**
     * Get recipients for approval-needed notifications in a specific setting.
     */
    public function getApprovalRecipients(int $settingId, string $approvalPermission): Collection
    {
        return $this->resolveRecipients($settingId, $approvalPermission);
    }

    /**
     * Get recipients for revision/correction-needed notifications in a specific setting.
     */
    public function getRevisionRecipients(int $settingId, string $editPermission): Collection
    {
        return $this->resolveRecipients($settingId, $editPermission);
    }
}

<?php

namespace Modules\User\Helpers;

use Spatie\Permission\Models\Role;

/**
 * PermissionHelper
 *
 * Provides utility functions for working with permissions in the application.
 * Used primarily in role management views and controllers.
 */
class PermissionHelper
{
    /**
     * Get permission groups formatted for role management forms.
     *
     * Returns the grouped permission structure from the configuration,
     * optionally filtered to show which permissions are already assigned
     * to a specific role (for pre-checking in edit forms).
     *
     * @param Role|null $role Optional role to pre-check existing permissions
     * @return array Groups of permissions with format:
     *               [
     *                   'Group Name' => [
     *                       'permission.key' => [
     *                           'label' => 'Human Label',
     *                           'checked' => true/false (if role provided)
     *                       ]
     *                   ]
     *               ]
     */
    public static function getGroupsForForm(?Role $role = null): array
    {
        $permissionsConfig = config('permissions');

        $rolePermissionNames = $role ? $role->permissions->pluck('name')->toArray() : [];

        $groups = [];

        foreach ($permissionsConfig as $groupName => $groupPermissions) {
            $groups[$groupName] = [];

            foreach ($groupPermissions as $permissionKey => $label) {
                $groups[$groupName][$permissionKey] = [
                    'label' => $label,
                    'checked' => in_array($permissionKey, $rolePermissionNames),
                ];
            }
        }

        return $groups;
    }

    /**
     * Get all permission groups without any additional data.
     *
     * @return array Raw configuration groups
     */
    public static function getGroups(): array
    {
        return config('permissions');
    }

    /**
     * Get all permissions flattened into a simple key => label array.
     *
     * @return array Flat array of all permissions with labels
     */
    public static function getAllPermissions(): array
    {
        $config = config('permissions');
        $permissions = [];

        foreach ($config as $groupPermissions) {
            $permissions = array_merge($permissions, $groupPermissions);
        }

        return $permissions;
    }

    /**
     * Get all permission keys (identifiers).
     *
     * @return array Array of permission keys
     */
    public static function getAllPermissionKeys(): array
    {
        return array_keys(self::getAllPermissions());
    }

    /**
     * Check if a permission key exists in the configuration.
     *
     * @param string $permissionKey
     * @return bool
     */
    public static function isValidPermission(string $permissionKey): bool
    {
        return in_array($permissionKey, self::getAllPermissionKeys());
    }

    /**
     * Get the label for a specific permission.
     *
     * @param string $permissionKey
     * @return string|null The label or null if permission doesn't exist
     */
    public static function getPermissionLabel(string $permissionKey): ?string
    {
        $allPermissions = self::getAllPermissions();
        return $allPermissions[$permissionKey] ?? null;
    }

    /**
     * Get group name for a specific permission.
     *
     * @param string $permissionKey
     * @return string|null The group name or null if not found
     */
    public static function getPermissionGroup(string $permissionKey): ?string
    {
        $config = config('permissions');

        foreach ($config as $groupName => $groupPermissions) {
            if (isset($groupPermissions[$permissionKey])) {
                return $groupName;
            }
        }

        return null;
    }
}

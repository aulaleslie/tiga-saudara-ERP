<?php

namespace Modules\Product\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class FeedPermissionResolver
{
    /**
     * Cache roles and permissions loaded in bulk for a request.
     * key: role_id, value: array of permission names
     */
    private array $rolePermissionsCache = [];

    /**
     * Derive feed visibility masks for a user across all their assigned settings.
     *
     * Returns an array keyed by setting_id:
     * [
     *     setting_id => [
     *         'can_purchase_price' => bool,
     *         'can_sales_prices' => bool,
     *         'can_bundle_event' => bool,
     *     ]
     * ]
     *
     * For Super Admin, returns masks with all true for all existing setting IDs or requested settings.
     */
    public function getSettingVisibilityMasks(User $user): array
    {
        if ($user->hasRole('Super Admin')) {
            $allSettings = \Modules\Setting\Entities\Setting::pluck('id');
            $masks = [];
            foreach ($allSettings as $sId) {
                $masks[$sId] = [
                    'can_purchase_price' => true,
                    'can_sales_prices' => true,
                    'can_bundle_event' => true,
                ];
            }
            return $masks;
        }

        $userSettings = DB::table('user_setting')
            ->where('user_id', $user->id)
            ->whereNotNull('role_id')
            ->select('setting_id', 'role_id')
            ->get();

        if ($userSettings->isEmpty()) {
            return [];
        }

        $roleIds = $userSettings->pluck('role_id')->unique()->filter()->values()->all();
        $this->ensureRolesLoaded($roleIds);

        $masks = [];
        foreach ($userSettings as $us) {
            $settingId = (int) $us->setting_id;
            $roleId = (int) $us->role_id;
            $perms = $this->rolePermissionsCache[$roleId] ?? [];

            $canPurchase = in_array('purchases.create', $perms, true);
            $canSalesDirect = in_array('sales.create', $perms, true);
            $canPosCombo = in_array('pos.access', $perms, true) && in_array('pos.sessions.open', $perms, true);

            $canSales = $canSalesDirect || $canPosCombo;
            $canBundle = $canSales;

            if ($canPurchase || $canSales || $canBundle) {
                $masks[$settingId] = [
                    'can_purchase_price' => $canPurchase,
                    'can_sales_prices' => $canSales,
                    'can_bundle_event' => $canBundle,
                ];
            }
        }

        return $masks;
    }

    private function ensureRolesLoaded(array $roleIds): void
    {
        $missingRoleIds = array_diff($roleIds, array_keys($this->rolePermissionsCache));
        if (empty($missingRoleIds)) {
            return;
        }

        $roles = Role::whereIn('id', $missingRoleIds)->with('permissions:id,name')->get();
        foreach ($roles as $role) {
            $this->rolePermissionsCache[$role->id] = $role->permissions->pluck('name')->all();
        }
    }
}

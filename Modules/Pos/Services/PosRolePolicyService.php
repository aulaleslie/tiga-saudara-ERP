<?php

namespace Modules\Pos\Services;

use App\Models\User;

class PosRolePolicyService
{
    public const ROLE_FLOOR_STAFF = 'floor_staff';

    public const ROLE_CASHIER_STAFF = 'cashier_staff';

    public const ROLE_STORE_MANAGER = 'store_manager';

    public const ROLE_UNKNOWN = 'unknown';

    /**
     * Detect the current POS operating role from role naming + permission fallback.
     */
    public function detectRole(User $user): string
    {
        $roleName = strtolower((string) $user->getRoleNames()->first());

        if ($roleName !== '') {
            if (str_contains($roleName, 'floor')) {
                return self::ROLE_FLOOR_STAFF;
            }

            if (str_contains($roleName, 'cashier') || str_contains($roleName, 'kasir')) {
                return self::ROLE_CASHIER_STAFF;
            }

            if (str_contains($roleName, 'manager')) {
                return self::ROLE_STORE_MANAGER;
            }
        }

        if ($user->can('pos.supervisor.approval') && $user->can('pos.overrides.price')) {
            return self::ROLE_STORE_MANAGER;
        }

        if ($user->can('pos.sell') && $user->can('pos.sessions.open')) {
            return self::ROLE_CASHIER_STAFF;
        }

        return self::ROLE_UNKNOWN;
    }

    /**
     * Cashier role must choose a terminal; floor/manager may open without terminal selection.
     */
    public function requiresTerminalSelection(User $user): bool
    {
        return $this->detectRole($user) === self::ROLE_CASHIER_STAFF;
    }

    /**
     * Floor staff is handoff-oriented and cannot finalize payment.
     */
    public function canCheckout(User $user): bool
    {
        if (! $user->can('pos.sell')) {
            return false;
        }

        if ($user->can('pos.checkout.payment')) {
            return true;
        }

        return $this->detectRole($user) !== self::ROLE_FLOOR_STAFF;
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilityFlags(User $user): array
    {
        return [
            'role' => $this->detectRole($user),
            'requires_terminal_selection' => $this->requiresTerminalSelection($user),
            'can_checkout' => $this->canCheckout($user),
            'can_open_approval_queue' => $user->can('pos.supervisor.approval'),
            'direct_permissions' => [
                'cart_clear' => $user->can('pos.cart.clear'),
                'line_remove' => $user->can('pos.cart.line.remove'),
                'qty_reduce' => $user->can('pos.cart.line.reduce'),
                'price_override' => $user->can('pos.overrides.price'),
            ],
        ];
    }
}

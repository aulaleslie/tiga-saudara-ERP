<?php

namespace Modules\Pos\Services;

use App\Models\User;
use Modules\Pos\Entities\PosSession;

class PosRolePolicyService
{
    public const PROFILE_HELPER = 'helper_handoff';

    public const PROFILE_CASHIER = 'cashier_checkout';

    public const PROFILE_MANAGER = 'manager_supervisor';

    public const PROFILE_GENERIC = 'generic_pos';

    /**
     * Determine a descriptive POS profile from explicit permissions only.
     * This value is informational and must not be used as an auth fallback.
     */
    public function detectRole(User $user): string
    {
        if ($this->hasAllPermissions($user, [
            'pos.checkout.payment',
            'pos.sessions.view',
            'pos.sessions.close-admin',
            'pos.transactions.edit.any',
        ])) {
            return self::PROFILE_MANAGER;
        }

        if ($this->hasAllPermissions($user, [
            'pos.checkout.payment',
            'pos.transactions.save',
            'pos.transactions.load',
        ])) {
            return self::PROFILE_CASHIER;
        }

        if ($this->hasAllPermissions($user, [
            'pos.sell',
            'pos.transactions.save',
            'pos.transactions.load',
        ])) {
            return self::PROFILE_HELPER;
        }

        return self::PROFILE_GENERIC;
    }

    public function hasCheckoutAuthority(User $user): bool
    {
        return $user->can('pos.checkout.payment');
    }

    public function canCheckout(User $user, ?PosSession $activeSession = null): bool
    {
        if (! $this->hasCheckoutAuthority($user)) {
            return false;
        }

        if ($activeSession === null) {
            return true;
        }

        if ($activeSession->terminal_id !== null) {
            return true;
        }

        return $this->isManagerCheckoutRole($user);
    }

    public function isManagerCheckoutRole(User $user): bool
    {
        return $this->hasAllPermissions($user, [
            'pos.checkout.payment',
            'pos.sessions.view',
            'pos.sessions.close-admin',
            'pos.transactions.edit.any',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilityFlags(User $user, ?PosSession $activeSession = null): array
    {
        $hasCheckoutAuthority = $this->hasCheckoutAuthority($user);
        $hasAssignedTerminal = $activeSession?->terminal_id !== null;
        $isManagerCheckoutRole = $this->isManagerCheckoutRole($user);
        $canCheckout = $this->canCheckout($user, $activeSession);

        return [
            'role' => $this->detectRole($user),
            'can_checkout' => $hasCheckoutAuthority,
            'has_checkout_authority' => $hasCheckoutAuthority,
            'has_assigned_terminal' => $hasAssignedTerminal,
            'is_manager_checkout_role' => $isManagerCheckoutRole,
            'can_select_terminal_on_open' => $hasCheckoutAuthority,
            'can_use_payment_flow' => $canCheckout,
            'can_search_payment_methods' => $canCheckout,
            'can_open_approval_queue' => $user->can('pos.supervisor.approval'),
            'can_view_sessions' => $user->can('pos.sessions.view'),
            'can_admin_close_sessions' => $user->can('pos.sessions.close-admin'),
            'can_manage_terminals' => $user->can('pos.terminals.access'),
            'can_view_transactions' => $user->can('pos.transactions.view'),
            'can_save_draft' => $user->can('pos.transactions.save'),
            'can_load_draft' => $user->can('pos.transactions.load'),
            'can_reduce_quantity' => $user->can('pos.cart.line.reduce'),
            'direct_permissions' => [
                'cart_clear' => $user->can('pos.cart.clear'),
                'line_remove' => $user->can('pos.cart.line.remove'),
                'qty_reduce' => $user->can('pos.cart.line.reduce'),
                'price_override' => $user->can('pos.overrides.price'),
                'total_price_override' => $user->can('pos.overrides.total-price'),
            ],
        ];
    }

    private function hasAllPermissions(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }
}

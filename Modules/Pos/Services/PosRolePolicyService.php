<?php

namespace Modules\Pos\Services;

use App\Models\User;

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
        if ($user->can('pos.supervisor.approval')) {
            return self::PROFILE_MANAGER;
        }

        if ($user->can('pos.checkout.payment')) {
            return self::PROFILE_CASHIER;
        }

        if ($user->can('pos.sell') && $user->can('pos.transactions.save')) {
            return self::PROFILE_HELPER;
        }

        return self::PROFILE_GENERIC;
    }

    public function canCheckout(User $user): bool
    {
        return $user->can('pos.checkout.payment');
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilityFlags(User $user): array
    {
        $canCheckout = $this->canCheckout($user);

        return [
            'role' => $this->detectRole($user),
            'can_checkout' => $canCheckout,
            'can_use_payment_flow' => $canCheckout,
            'can_search_payment_methods' => $canCheckout,
            'can_open_approval_queue' => $user->can('pos.supervisor.approval'),
            'can_reduce_quantity' => $user->can('pos.cart.line.reduce'),
            'direct_permissions' => [
                'cart_clear' => $user->can('pos.cart.clear'),
                'line_remove' => $user->can('pos.cart.line.remove'),
                'qty_reduce' => $user->can('pos.cart.line.reduce'),
                'price_override' => $user->can('pos.overrides.price'),
            ],
        ];
    }
}

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
        if ($this->isManagerCheckoutRole($user)) {
            return self::PROFILE_MANAGER;
        }

        if ($this->hasCheckoutAuthority($user) && $this->hasAllPermissions($user, [
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

    public function hasPaymentCheckoutAuthority(User $user): bool
    {
        return $user->can('pos.checkout.payment');
    }

    public function hasDirectDebtCheckoutAuthority(User $user): bool
    {
        return $user->can('pos.checkout.debt');
    }

    public function hasRequestDebtCheckoutAuthority(User $user): bool
    {
        return $user->can('pos.checkout.debt.request');
    }

    public function hasDebtCheckoutAuthority(User $user): bool
    {
        return $this->hasDirectDebtCheckoutAuthority($user)
            || $this->hasRequestDebtCheckoutAuthority($user);
    }

    public function requiresDebtApproval(User $user): bool
    {
        return ! $this->hasDirectDebtCheckoutAuthority($user)
            && $this->hasRequestDebtCheckoutAuthority($user);
    }

    public function hasCheckoutAuthority(User $user): bool
    {
        return $this->hasPaymentCheckoutAuthority($user)
            || $this->hasDebtCheckoutAuthority($user);
    }

    public function canSwitchCheckoutFlow(User $user): bool
    {
        return $this->hasPaymentCheckoutAuthority($user)
            && $this->hasDebtCheckoutAuthority($user);
    }

    public function hasFlowAuthority(User $user, string $flow): bool
    {
        $normalized = $this->normalizeCheckoutFlow($flow);

        if ($normalized === 'kasbon') {
            return $this->hasDebtCheckoutAuthority($user);
        }

        if ($normalized === 'full_payment') {
            return $this->hasPaymentCheckoutAuthority($user);
        }

        return false;
    }

    public function normalizeCheckoutFlow(?string $flow): ?string
    {
        if ($flow === null) {
            return null;
        }

        $normalized = strtolower(trim($flow));

        if ($normalized === 'kasbon' || $normalized === 'debt') {
            return 'kasbon';
        }

        if ($normalized === 'full_payment' || $normalized === 'lunas') {
            return 'full_payment';
        }

        return null;
    }

    /**
     * Resolve checkout flow from an array containing optional 'flow' and/or 'is_debt' indicators.
     *
     * @param  array<string, mixed>  $data
     * @return array{flow: ?string, contradictory: bool}
     */
    public function resolveFlowFromIndicators(array $data): array
    {
        $hasRawExplicitFlow = isset($data['flow']) && $data['flow'] !== '';
        $explicitFlow = $hasRawExplicitFlow
            ? $this->normalizeCheckoutFlow((string) $data['flow'])
            : null;

        $hasIsDebt = array_key_exists('is_debt', $data) && $data['is_debt'] !== null && $data['is_debt'] !== '';
        $isDebtBool = $hasIsDebt ? filter_var($data['is_debt'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $legacyFlow = $isDebtBool !== null ? ($isDebtBool ? 'kasbon' : 'full_payment') : null;

        if (($hasRawExplicitFlow && $explicitFlow === null) || ($hasIsDebt && $isDebtBool === null)) {
            return [
                'flow' => null,
                'contradictory' => true,
            ];
        }

        if ($explicitFlow !== null && $legacyFlow !== null && $explicitFlow !== $legacyFlow) {
            return [
                'flow' => null,
                'contradictory' => true,
            ];
        }

        return [
            'flow' => $explicitFlow ?? $legacyFlow,
            'contradictory' => false,
        ];
    }

    /**
     * Resolve the effective flow and explicitness of a staged session payment chain.
     *
     * @param  array<string, mixed>  $chain
     * @return array{flow: string, has_explicit_flow: bool, contradictory: bool}
     */
    public function resolvePaymentChainFlow(array $chain): array
    {
        $resolved = $this->resolveFlowFromIndicators($chain);
        $hasExplicit = $resolved['contradictory']
            || $resolved['flow'] !== null
            || ! empty($chain['payments']);

        $fallbackFlow = 'full_payment';
        if ($resolved['contradictory']) {
            $rawExplicit = isset($chain['flow']) && $chain['flow'] !== ''
                ? $this->normalizeCheckoutFlow((string) $chain['flow'])
                : null;
            $fallbackFlow = $rawExplicit ?? (! empty($chain['is_debt']) ? 'kasbon' : 'full_payment');
        }

        return [
            'flow' => $resolved['flow'] ?? $fallbackFlow,
            'has_explicit_flow' => $hasExplicit,
            'contradictory' => $resolved['contradictory'],
        ];
    }

    public function missingPermissionForFlow(string $flow): string
    {
        return $this->normalizeCheckoutFlow($flow) === 'kasbon'
            ? 'pos.checkout.debt / pos.checkout.debt.request'
            : 'pos.checkout.payment';
    }

    public function flowPermissionDeniedMessage(string $flow, bool $isStagedRecovery = false): string
    {
        if ($this->normalizeCheckoutFlow($flow) === 'kasbon') {
            return $isStagedRecovery
                ? 'Alur Kasbon pada transaksi bertahap ini tidak dapat dilanjutkan karena membutuhkan izin pos.checkout.debt atau pos.checkout.debt.request. Data pembayaran yang sudah diproses tetap tersimpan.'
                : 'Anda tidak memiliki izin untuk mengakses alur Kasbon (membutuhkan pos.checkout.debt atau pos.checkout.debt.request).';
        }

        return $isStagedRecovery
            ? 'Alur Bayar Lunas pada transaksi bertahap ini tidak dapat dilanjutkan karena membutuhkan izin pos.checkout.payment. Data pembayaran yang sudah diproses tetap tersimpan.'
            : 'Anda tidak memiliki izin untuk mengakses alur Bayar Lunas (membutuhkan pos.checkout.payment).';
    }

    public function flowStateMismatchMessage(): string
    {
        return 'Alur pembayaran pada permintaan tidak konsisten dengan status pembayaran bertahap.';
    }

    public function canUseFlow(User $user, string $flow, ?PosSession $activeSession = null): bool
    {
        return $this->canCheckout($user, $activeSession)
            && $this->hasFlowAuthority($user, $flow);
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
        $hasPaymentAuthority = $this->hasPaymentCheckoutAuthority($user);
        $hasDirectDebtAuthority = $this->hasDirectDebtCheckoutAuthority($user);
        $hasRequestDebtAuthority = $this->hasRequestDebtCheckoutAuthority($user);
        $hasDebtAuthority = $this->hasDebtCheckoutAuthority($user);
        $requiresDebtApproval = $this->requiresDebtApproval($user);
        $hasCheckoutAuthority = $this->hasCheckoutAuthority($user);
        $canSwitchCheckoutFlow = $this->canSwitchCheckoutFlow($user);
        $hasAssignedTerminal = $activeSession?->terminal_id !== null;
        $isManagerCheckoutRole = $this->isManagerCheckoutRole($user);
        $canCheckout = $this->canCheckout($user, $activeSession);

        return [
            'role' => $this->detectRole($user),
            'can_checkout' => $hasCheckoutAuthority,
            'has_checkout_authority' => $hasCheckoutAuthority,
            'has_payment_checkout_authority' => $hasPaymentAuthority,
            'has_direct_debt_checkout_authority' => $hasDirectDebtAuthority,
            'has_request_debt_checkout_authority' => $hasRequestDebtAuthority,
            'has_debt_checkout_authority' => $hasDebtAuthority,
            'requires_debt_approval' => $requiresDebtApproval,
            'can_checkout_lunas' => $canCheckout && $hasPaymentAuthority,
            'can_checkout_kasbon' => $canCheckout && $hasDebtAuthority,
            'can_switch_checkout_flow' => $canSwitchCheckoutFlow,
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
            'can_print_current_transaction' => $user->can('pos.transactions.save')
                && $user->can('pos.transactions.load')
                && $user->can('pos.transactions.print-current'),
            'can_reduce_quantity' => $user->can('pos.cart.line.reduce'),
            'direct_permissions' => [
                'cart_clear' => $user->can('pos.cart.clear'),
                'line_remove' => $user->can('pos.cart.line.remove'),
                'qty_reduce' => $user->can('pos.cart.line.reduce'),
                'price_override' => $user->can('pos.overrides.price'),
                'total_price_override' => $user->can('pos.overrides.total-price'),
                'checkout_payment' => $hasPaymentAuthority,
                'checkout_debt' => $hasDirectDebtAuthority,
                'checkout_debt_request' => $hasRequestDebtAuthority,
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

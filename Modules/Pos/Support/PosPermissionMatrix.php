<?php

namespace Modules\Pos\Support;

final class PosPermissionMatrix
{
    /**
     * @return array<string, string>
     */
    public static function deprecatedPermissions(): array
    {
        return [
            'pos.sessions.require-terminal' => 'Terminal requirement is no longer enforced as a standalone permission. Session-open policy now handles terminal selection rules.',
            'pos.monitor.access' => 'Live monitor access is not exposed by supported runtime routes. Keep only for transitional migrations until a supported monitor surface exists.',
            'pos.approval.requests.view' => 'Approval queue visibility is represented by pos.supervisor.approval in supported runtime behavior.',
            'pos.settings.edit' => 'POS setting administration is not exposed as a supported runtime permission in the current POS surface.',
        ];
    }

    /**
     * @return array<string, array{label:string, description:string, permissions:array<int, string>}>
     */
    public static function supportedBundles(): array
    {
        return [
            'owner' => [
                'label' => 'Owner',
                'description' => 'Mapped to Super Admin gate bypass. No separate owner-only permission bundle is maintained.',
                'permissions' => [],
            ],
            'manager' => [
                'label' => 'Manager',
                'description' => 'Full POS oversight bundle with checkout authority, draft takeover, reports, reconciliation, approval queue, and administrative session controls.',
                'permissions' => [
                    'pos.access',
                    'pos.sell',
                    'pos.sessions.open',
                    'pos.sessions.close',
                    'pos.sessions.close-admin',
                    'pos.sessions.approve-variance',
                    'pos.sessions.view',
                    'pos.checkout.payment',
                    'pos.safeDrops.create',
                    'pos.safeDrops.approve',
                    'pos.reports.access',
                    'pos.reconciliation.access',
                    'pos.receipts.reprint',
                    'pos.terminals.access',
                    'pos.terminals.edit',
                    'pos.supervisor.approval',
                    'pos.transactions.view',
                    'pos.transactions.save',
                    'pos.transactions.load',
                    'pos.transactions.edit.any',
                ],
            ],
            'cashier' => [
                'label' => 'Cashier',
                'description' => 'Checkout-authorized sell bundle for normal shell, handoff, and own-session operation without manager oversight screens.',
                'permissions' => [
                    'pos.access',
                    'pos.sell',
                    'pos.sessions.open',
                    'pos.sessions.close',
                    'pos.checkout.payment',
                    'pos.safeDrops.create',
                    'pos.transactions.view',
                    'pos.transactions.save',
                    'pos.transactions.load',
                ],
            ],
            'floor_staff' => [
                'label' => 'Floor Staff',
                'description' => 'Shell and handoff bundle for cart preparation, save/load draft continuation, and own-session operation without payment authority.',
                'permissions' => [
                    'pos.access',
                    'pos.sell',
                    'pos.sessions.open',
                    'pos.sessions.close',
                    'pos.transactions.view',
                    'pos.transactions.save',
                    'pos.transactions.load',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{label:string, permissions:array<int, string>}>
     */
    public static function capabilityClusters(): array
    {
        return [
            'core_shell' => [
                'label' => 'Core Shell Access',
                'permissions' => ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.sessions.close'],
            ],
            'draft_handoff' => [
                'label' => 'Draft Handoff',
                'permissions' => ['pos.transactions.view', 'pos.transactions.save', 'pos.transactions.load'],
            ],
            'checkout' => [
                'label' => 'Checkout',
                'permissions' => ['pos.checkout.payment', 'pos.receipts.reprint'],
            ],
            'oversight' => [
                'label' => 'Oversight',
                'permissions' => [
                    'pos.sessions.view',
                    'pos.sessions.close-admin',
                    'pos.sessions.approve-variance',
                    'pos.supervisor.approval',
                    'pos.safeDrops.approve',
                    'pos.reports.access',
                    'pos.reconciliation.access',
                    'pos.transactions.edit.any',
                ],
            ],
            'administration' => [
                'label' => 'Administration',
                'permissions' => ['pos.terminals.access', 'pos.terminals.edit'],
            ],
            'exceptions' => [
                'label' => 'Grouped Exceptions',
                'permissions' => [
                    'pos.safeDrops.create',
                    'pos.cart.clear',
                    'pos.cart.line.remove',
                    'pos.cart.line.reduce',
                    'pos.overrides.price',
                    'pos.overrides.discount',
                    'pos.void',
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function hiddenPermissionKeys(): array
    {
        return array_keys(self::deprecatedPermissions());
    }

    public static function isDeprecated(string $permission): bool
    {
        return array_key_exists($permission, self::deprecatedPermissions());
    }
}

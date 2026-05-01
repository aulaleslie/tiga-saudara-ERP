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
                'label' => 'Pemilik',
                'description' => 'Dipetakan langsung ke akses Super Admin. Tidak ada paket hak akses terpisah yang dikelola untuk pemilik.',
                'permissions' => [],
            ],
            'manager' => [
                'label' => 'Manajer',
                'description' => 'Paket pengawasan POS lengkap dengan otoritas checkout, ambil alih draf, laporan, rekonsiliasi, antrean persetujuan, dan kontrol sesi administratif.',
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
                    'pos.returns.view',
                    'pos.returns.create',
                    'pos.returns.edit',
                    'pos.returns.delete',
                    'pos.returns.approve',
                    'pos.returns.receive',
                    'pos.returns.settle',
                    'pos.returns.dispatch',
                ],
            ],
            'cashier' => [
                'label' => 'Kasir',
                'description' => 'Paket penjualan dengan otoritas checkout untuk operasional normal, serah terima, dan pengelolaan sesi sendiri tanpa layar pengawasan manajer.',
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
                    'pos.returns.view',
                    'pos.returns.create',
                ],
            ],
            'floor_staff' => [
                'label' => 'Staf Lapangan',
                'description' => 'Paket untuk persiapan keranjang dan serah terima draf, mendukung simpan/muat draf, dan operasional sesi sendiri tanpa otoritas pembayaran.',
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
                'label' => 'Akses Inti POS',
                'permissions' => ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.sessions.close'],
            ],
            'draft_handoff' => [
                'label' => 'Serah Terima Draf',
                'permissions' => ['pos.transactions.view', 'pos.transactions.save', 'pos.transactions.load'],
            ],
            'checkout' => [
                'label' => 'Pembayaran & Checkout',
                'permissions' => ['pos.checkout.payment', 'pos.receipts.reprint'],
            ],
            'oversight' => [
                'label' => 'Pengawasan & Manajerial',
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
                'label' => 'Administrasi Terminal',
                'permissions' => ['pos.terminals.access', 'pos.terminals.edit'],
            ],
            'exceptions' => [
                'label' => 'Pengecualian Hak Akses',
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
            'returns' => [
                'label' => 'Retur POS',
                'permissions' => [
                    'pos.returns.view',
                    'pos.returns.create',
                    'pos.returns.edit',
                    'pos.returns.delete',
                    'pos.returns.approve',
                    'pos.returns.receive',
                    'pos.returns.settle',
                    'pos.returns.dispatch',
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

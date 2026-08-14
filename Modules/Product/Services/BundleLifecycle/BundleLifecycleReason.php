<?php

namespace Modules\Product\Services\BundleLifecycle;

class BundleLifecycleReason
{
    public const SETTING_MISMATCH = 'SETTING_MISMATCH';
    public const DISABLED = 'DISABLED';
    public const NOT_STARTED = 'NOT_STARTED';
    public const EXPIRED = 'EXPIRED';
    public const DEFINITION_DELETED = 'DEFINITION_DELETED';
    public const EMPTY_COMPOSITION = 'EMPTY_COMPOSITION';
    public const INACTIVE_COMPONENT = 'INACTIVE_COMPONENT';
    public const MISSING_COMPONENT = 'MISSING_COMPONENT';
    public const COMPONENT_REMOVED = 'COMPONENT_REMOVED';

    /**
     * Map reason codes to human readable Indonesian labels.
     */
    public static function label(string $code): string
    {
        return match ($code) {
            self::SETTING_MISMATCH => 'Paket tidak terdaftar untuk unit bisnis ini',
            self::DISABLED => 'Paket sedang dinonaktifkan',
            self::NOT_STARTED => 'Periode aktif paket belum dimulai',
            self::EXPIRED => 'Masa berlaku paket telah berakhir',
            self::DEFINITION_DELETED => 'Definisi paket sudah tidak ditemukan',
            self::EMPTY_COMPOSITION => 'Komposisi item paket kosong atau tidak valid',
            self::INACTIVE_COMPONENT => 'Komponen produk paket tidak aktif',
            self::MISSING_COMPONENT => 'Komponen produk paket tidak ditemukan',
            self::COMPONENT_REMOVED => 'Komponen produk telah dihapus dari komposisi paket',
            default => 'Paket tidak memenuhi syarat operasional',
        };
    }
}

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
    public const COMPONENT_ADDED = 'COMPONENT_ADDED';
    public const QUANTITY_CHANGED = 'QUANTITY_CHANGED';
    public const INFORMATIONAL_ALLOCATION_CHANGED = 'INFORMATIONAL_ALLOCATION_CHANGED';
    public const STOCK_MANAGED_CHANGED = 'STOCK_MANAGED_CHANGED';
    public const SERIAL_REQUIRED_CHANGED = 'SERIAL_REQUIRED_CHANGED';

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
            self::COMPONENT_ADDED => 'Komponen baru telah ditambahkan ke komposisi paket',
            self::QUANTITY_CHANGED => 'Kuantitas komponen paket telah berubah',
            self::INFORMATIONAL_ALLOCATION_CHANGED => 'Alokasi nilai informasi komponen paket telah berubah',
            self::STOCK_MANAGED_CHANGED => 'Status pengelolaan stok produk paket telah berubah',
            self::SERIAL_REQUIRED_CHANGED => 'Status kewajiban nomor seri produk paket telah berubah',
            default => 'Paket tidak memenuhi syarat operasional',
        };
    }
}

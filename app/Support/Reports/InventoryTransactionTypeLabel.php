<?php

namespace App\Support\Reports;

class InventoryTransactionTypeLabel
{
    public const CONTEXT_DEFAULT = 'default';
    public const CONTEXT_MUTATION = 'mutation';
    public const CONTEXT_DETAIL = 'detail';
    public const CONTEXT_VALUATION = 'valuation';

    /**
     * Resolve Indonesian label for an inventory transaction type.
     *
     * @param string $type
     * @param float|null $delta Direction indicator for types like TRF and ADJ
     * @param string|null $context Context for per-report legacy wording compatibility
     * @return string
     */
    public static function label(string $type, ?float $delta = null, ?string $context = null): string
    {
        $normalizedType = strtoupper(trim($type));

        if ($context === self::CONTEXT_DETAIL) {
            return match ($normalizedType) {
                'BUY' => 'Pembelian',
                'SELL' => 'Penjualan',
                'DISPATCH' => 'Pengiriman',
                'ADJ' => 'Penyesuaian',
                'TRF' => 'Transfer',
                'OPENING' => 'Saldo Awal',
                default => self::defaultLabel($normalizedType, $delta),
            };
        }

        if ($context === self::CONTEXT_VALUATION) {
            return match ($normalizedType) {
                'BUY' => 'Pembelian',
                'SELL' => 'Penjualan',
                'DISPATCH' => 'Penjualan',
                'ADJ' => 'Penyesuaian',
                'TRF' => 'Transfer',
                'OPENING' => 'Saldo Awal',
                'CONSIGNMENT_RECEIPT' => 'Penerimaan Titipan Konsinyasi',
                'CONSIGNMENT_RECEIPT_REVERSAL' => 'Pembatalan Titipan Konsinyasi',
                default => self::defaultLabel($normalizedType, $delta),
            };
        }

        return self::defaultLabel($normalizedType, $delta);
    }

    /**
     * Default canonical Indonesian label for inventory transaction types.
     */
    public static function defaultLabel(string $type, ?float $delta = null): string
    {
        return match ($type) {
            'BUY' => 'Penerimaan Pembelian',
            'PURCHASE_RECEIVING_CANCELLED' => 'Pembatalan Penerimaan Pembelian',
            'SELL', 'DISPATCH' => 'Pengiriman Penjualan',
            'DISPATCH_RETURN' => 'Pengiriman Barang Pengganti Retur',
            'TRF' => $delta !== null ? ($delta >= 0 ? 'Transfer Masuk' : 'Transfer Keluar') : 'Transfer',
            'ADJ' => $delta !== null ? ($delta >= 0 ? 'Penyesuaian Tambah' : 'Penyesuaian Kurang') : 'Penyesuaian',
            'INIT' => 'Inisialisasi Stok',
            'OPENING' => 'Saldo Awal',
            'SALE_RETURN_GOOD_TAX' => 'Retur Penjualan Barang Baik (Pajak)',
            'SALE_RETURN_GOOD_NON_TAX' => 'Retur Penjualan Barang Baik (Non Pajak)',
            'SALE_RETURN_RELEASE_TAX' => 'Pelepasan Retur Penjualan (Pajak)',
            'SALE_RETURN_RELEASE_NON_TAX' => 'Pelepasan Retur Penjualan (Non Pajak)',
            'PURCHASE_RETURN_GOOD_TAX' => 'Retur Pembelian Barang Baik (Pajak)',
            'PURCHASE_RETURN_GOOD_NON_TAX' => 'Retur Pembelian Barang Baik (Non Pajak)',
            'PURCHASE_RETURN_BROKEN_TAX' => 'Retur Pembelian Barang Rusak (Pajak)',
            'PURCHASE_RETURN_BROKEN_NON_TAX' => 'Retur Pembelian Barang Rusak (Non Pajak)',
            'RETURN_BROKEN' => 'Retur Barang Rusak',
            'RETURN_REPAIR' => 'Penerimaan Perbaikan Retur',
            'CONSIGNMENT_RECEIPT' => 'Penerimaan Titipan Konsinyasi',
            'CONSIGNMENT_RECEIPT_REVERSAL' => 'Pembatalan Titipan Konsinyasi',
            default => 'Mutasi Stok',
        };
    }
}

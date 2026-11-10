<?php

namespace Tests\Unit\Support;

use App\Support\Reports\InventoryTransactionTypeLabel;
use PHPUnit\Framework\TestCase;

class InventoryTransactionTypeLabelTest extends TestCase
{
    public function test_all_expected_transaction_types_have_indonesian_labels(): void
    {
        $expectedTypes = [
            'BUY' => 'Penerimaan Pembelian',
            'PURCHASE_RECEIVING_CANCELLED' => 'Pembatalan Penerimaan Pembelian',
            'SELL' => 'Pengiriman Penjualan',
            'DISPATCH' => 'Pengiriman Penjualan',
            'DISPATCH_RETURN' => 'Pengiriman Barang Pengganti Retur',
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
        ];

        foreach ($expectedTypes as $type => $expectedLabel) {
            $actual = InventoryTransactionTypeLabel::label($type);
            $this->assertEquals($expectedLabel, $actual, "Failed resolving label for type: {$type}");
            $this->assertNotEquals($type, $actual, "Raw constant returned for type: {$type}");
        }

        // Direction-dependent types
        $this->assertEquals('Transfer Masuk', InventoryTransactionTypeLabel::label('TRF', 5));
        $this->assertEquals('Transfer Keluar', InventoryTransactionTypeLabel::label('TRF', -5));
        $this->assertEquals('Penyesuaian Tambah', InventoryTransactionTypeLabel::label('ADJ', 3));
        $this->assertEquals('Penyesuaian Kurang', InventoryTransactionTypeLabel::label('ADJ', -3));
    }

    public function test_unknown_type_falls_back_to_humanized_generic(): void
    {
        $actual = InventoryTransactionTypeLabel::label('UNKNOWN_CUSTOM_TYPE');
        $this->assertEquals('Mutasi Stok', $actual);
        $this->assertNotEquals('UNKNOWN_CUSTOM_TYPE', $actual);

        $emptyActual = InventoryTransactionTypeLabel::label('');
        $this->assertEquals('Mutasi Stok', $emptyActual);
    }

    public function test_context_overrides_preserve_legacy_report_wording(): void
    {
        // Detail context
        $this->assertEquals('Pembelian', InventoryTransactionTypeLabel::label('BUY', null, InventoryTransactionTypeLabel::CONTEXT_DETAIL));
        $this->assertEquals('Penjualan', InventoryTransactionTypeLabel::label('SELL', null, InventoryTransactionTypeLabel::CONTEXT_DETAIL));
        $this->assertEquals('Pengiriman', InventoryTransactionTypeLabel::label('DISPATCH', null, InventoryTransactionTypeLabel::CONTEXT_DETAIL));
        $this->assertEquals('Transfer', InventoryTransactionTypeLabel::label('TRF', null, InventoryTransactionTypeLabel::CONTEXT_DETAIL));
        $this->assertEquals('Penyesuaian', InventoryTransactionTypeLabel::label('ADJ', null, InventoryTransactionTypeLabel::CONTEXT_DETAIL));
        $this->assertEquals('Saldo Awal', InventoryTransactionTypeLabel::label('OPENING', null, InventoryTransactionTypeLabel::CONTEXT_DETAIL));

        // Valuation context
        $this->assertEquals('Penjualan', InventoryTransactionTypeLabel::label('DISPATCH', null, InventoryTransactionTypeLabel::CONTEXT_VALUATION));
        $this->assertEquals('Penerimaan Titipan Konsinyasi', InventoryTransactionTypeLabel::label('CONSIGNMENT_RECEIPT', null, InventoryTransactionTypeLabel::CONTEXT_VALUATION));
    }
}

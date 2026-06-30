<?php

namespace App\Services\Reports;

class OperationalGeneralLedgerBucketConfig
{
    public const CASH_BANK = 'cash_bank';
    public const ACCOUNTS_RECEIVABLE = 'accounts_receivable';
    public const ACCOUNTS_PAYABLE = 'accounts_payable';
    public const OPERATIONAL_REVENUE = 'operational_revenue';
    public const OPERATIONAL_COST = 'operational_cost';
    public const RETURNS_AND_ADJUSTMENTS = 'returns_and_adjustments';
    public const INVENTORY = 'inventory';
    public const TAX_PAYABLE = 'tax_payable';
    public const SHIPPING_REVENUE = 'shipping_revenue';

    public static function getLabels(): array
    {
        return [
            self::CASH_BANK => 'Kas & Bank dari Transaksi',
            self::ACCOUNTS_RECEIVABLE => 'Piutang Usaha',
            self::ACCOUNTS_PAYABLE => 'Hutang Usaha',
            self::OPERATIONAL_REVENUE => 'Pendapatan Operasional',
            self::OPERATIONAL_COST => 'Beban Pokok & Biaya Operasional',
            self::RETURNS_AND_ADJUSTMENTS => 'Retur / Koreksi',
            self::INVENTORY => 'Persediaan (Estimasi)',
            self::TAX_PAYABLE => 'Hutang Pajak',
            self::SHIPPING_REVENUE => 'Pendapatan Pengiriman',
        ];
    }
}

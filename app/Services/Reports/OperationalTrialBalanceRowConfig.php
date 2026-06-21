<?php

namespace App\Services\Reports;

class OperationalTrialBalanceRowConfig
{
    public const CATEGORY_ASSET = 'Aset';
    public const CATEGORY_LIABILITY = 'Liabilitas';
    public const CATEGORY_INCOME = 'Pendapatan';
    public const CATEGORY_EXPENSE = 'Beban';
    public const CATEGORY_EQUITY = 'Modal';

    public const NORMAL_DEBIT = 'debit';
    public const NORMAL_CREDIT = 'credit';

    /**
     * Map bucket keys to Trial Balance row metadata.
     * Includes Category, Label, Normal Balance, and Synthetic Account Code.
     * 
     * @return array<string, array{category: string, label: string, normal_balance: string, code: string}>
     */
    public static function getRowMetadata(): array
    {
        return [
            OperationalGeneralLedgerBucketConfig::CASH_BANK => [
                'category' => self::CATEGORY_ASSET,
                'label' => 'Kas & Bank dari Transaksi',
                'normal_balance' => self::NORMAL_DEBIT,
                'code' => 'OP-100',
            ],
            OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE => [
                'category' => self::CATEGORY_ASSET,
                'label' => 'Piutang Usaha',
                'normal_balance' => self::NORMAL_DEBIT,
                'code' => 'OP-110',
            ],
            OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE => [
                'category' => self::CATEGORY_LIABILITY,
                'label' => 'Hutang Usaha',
                'normal_balance' => self::NORMAL_CREDIT,
                'code' => 'OP-200',
            ],
            OperationalGeneralLedgerBucketConfig::OPERATIONAL_REVENUE => [
                'category' => self::CATEGORY_INCOME,
                'label' => 'Pendapatan Operasional',
                'normal_balance' => self::NORMAL_CREDIT,
                'code' => 'OP-400',
            ],
            OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST => [
                'category' => self::CATEGORY_EXPENSE,
                'label' => 'Pembelian / Biaya Operasional',
                'normal_balance' => self::NORMAL_DEBIT,
                'code' => 'OP-500',
            ],
            'virtual_sales_returns' => [
                'category' => self::CATEGORY_INCOME,
                'label' => 'Retur Penjualan',
                'normal_balance' => self::NORMAL_DEBIT, // Sales is Credit, Returns is Debit
                'code' => 'OP-410',
            ],
            'virtual_purchase_returns' => [
                'category' => self::CATEGORY_EXPENSE,
                'label' => 'Retur Pembelian',
                'normal_balance' => self::NORMAL_CREDIT, // Purchases is Debit, Returns is Credit
                'code' => 'OP-510',
            ],
        ];
    }
}

<?php

namespace App\Services\Reports;

use Modules\Setting\Entities\Setting;
use Carbon\Carbon;

class OperationalTrialBalanceReportService
{
    private OperationalMovementEventService $movementService;

    public function __construct(OperationalMovementEventService $movementService)
    {
        $this->movementService = $movementService;
    }

    public function generate(int $settingId, string $startDate, string $endDate): OperationalTrialBalanceReport
    {
        $setting = Setting::with('currency')->find($settingId);
        $currencyCode = $setting && $setting->currency ? ($setting->currency->code ?? $setting->currency->currency_name ?? 'IDR') : 'IDR';
        
        $sourceNote = '* Laporan ini dihitung dari nilai dokumen operasional (penjualan, pembelian, pembayaran, retur, pengeluaran) dan tidak menggunakan pencatatan jurnal akuntansi ganda.';

        $start = Carbon::parse($startDate)->startOfDay()->format('Y-m-d');
        $end = Carbon::parse($endDate)->endOfDay()->format('Y-m-d');

        if ($start > $end) {
            return new OperationalTrialBalanceReport(
                $currencyCode,
                $startDate,
                $endDate,
                $sourceNote,
                [],
                0, 0, 0, 0, 0, 0
            );
        }

        $events = $this->movementService->getMovementEvents($settingId, $end);
        $metadata = OperationalTrialBalanceRowConfig::getRowMetadata();

        $rowsByBucket = [];
        foreach ($metadata as $bucket => $meta) {
            $rowsByBucket[$bucket] = [
                'meta' => $meta,
                'openingNet' => 0.0,
                'periodDebit' => 0.0,
                'periodCredit' => 0.0,
                'hasActivity' => false,
            ];
        }

        foreach ($events as $event) {
            $bucket = $event['bucket'];
            
            if ($bucket === OperationalGeneralLedgerBucketConfig::RETURNS_AND_ADJUSTMENTS) {
                if ($event['sourceType'] === 'Retur Penjualan') {
                    $bucket = 'virtual_sales_returns';
                } else {
                    $bucket = 'virtual_purchase_returns';
                }
            }

            if (!isset($rowsByBucket[$bucket])) {
                continue;
            }

            $date = substr($event['dt'], 0, 10);
            $isBefore = $date < $start;
            $isInside = $date >= $start && $date <= $end;

            $normalBalance = $rowsByBucket[$bucket]['meta']['normal_balance'];
            
            $netEffect = 0.0;
            if ($normalBalance === OperationalTrialBalanceRowConfig::NORMAL_DEBIT) {
                $netEffect = $event['debit'] - $event['credit'];
            } else {
                $netEffect = $event['credit'] - $event['debit'];
            }

            if ($isBefore) {
                $rowsByBucket[$bucket]['openingNet'] += $netEffect;
            } elseif ($isInside) {
                $rowsByBucket[$bucket]['periodDebit'] += $event['debit'];
                $rowsByBucket[$bucket]['periodCredit'] += $event['credit'];
                $rowsByBucket[$bucket]['hasActivity'] = true;
            }
        }

        // Group rows into categories
        $categoriesMap = [];

        foreach ($rowsByBucket as $bucket => $data) {
            $meta = $data['meta'];
            $openingNet = $data['openingNet'];
            $periodDebit = $data['periodDebit'];
            $periodCredit = $data['periodCredit'];
            
            // Period net effect based on normal balance
            $periodNet = 0.0;
            if ($meta['normal_balance'] === OperationalTrialBalanceRowConfig::NORMAL_DEBIT) {
                $periodNet = $periodDebit - $periodCredit;
            } else {
                $periodNet = $periodCredit - $periodDebit;
            }
            
            $endingNet = $openingNet + $periodNet;

            if (abs($openingNet) < 0.001 && abs($endingNet) < 0.001 && !$data['hasActivity']) {
                continue; // Skip empty rows
            }

            // Split nets into Dr / Cr
            $openingDebit = 0.0;
            $openingCredit = 0.0;
            if ($openingNet > 0) {
                if ($meta['normal_balance'] === OperationalTrialBalanceRowConfig::NORMAL_DEBIT) {
                    $openingDebit = $openingNet;
                } else {
                    $openingCredit = $openingNet;
                }
            } elseif ($openingNet < 0) {
                if ($meta['normal_balance'] === OperationalTrialBalanceRowConfig::NORMAL_DEBIT) {
                    $openingCredit = abs($openingNet);
                } else {
                    $openingDebit = abs($openingNet);
                }
            }

            $endingDebit = 0.0;
            $endingCredit = 0.0;
            if ($endingNet > 0) {
                if ($meta['normal_balance'] === OperationalTrialBalanceRowConfig::NORMAL_DEBIT) {
                    $endingDebit = $endingNet;
                } else {
                    $endingCredit = $endingNet;
                }
            } elseif ($endingNet < 0) {
                if ($meta['normal_balance'] === OperationalTrialBalanceRowConfig::NORMAL_DEBIT) {
                    $endingCredit = abs($endingNet);
                } else {
                    $endingDebit = abs($endingNet);
                }
            }

            $row = new OperationalTrialBalanceRow(
                $meta['code'],
                $meta['label'],
                $meta['normal_balance'],
                $openingDebit,
                $openingCredit,
                $periodDebit,
                $periodCredit,
                $endingDebit,
                $endingCredit
            );

            $catName = $meta['category'];
            if (!isset($categoriesMap[$catName])) {
                $categoriesMap[$catName] = [];
            }
            $categoriesMap[$catName][] = $row;
        }

        // Prepare Category Objects
        // Sort categories logically: Asset, Liability, Equity, Income, Expense
        $categoryOrder = [
            OperationalTrialBalanceRowConfig::CATEGORY_ASSET => 1,
            OperationalTrialBalanceRowConfig::CATEGORY_LIABILITY => 2,
            OperationalTrialBalanceRowConfig::CATEGORY_EQUITY => 3,
            OperationalTrialBalanceRowConfig::CATEGORY_INCOME => 4,
            OperationalTrialBalanceRowConfig::CATEGORY_EXPENSE => 5,
        ];

        uksort($categoriesMap, function ($a, $b) use ($categoryOrder) {
            $orderA = $categoryOrder[$a] ?? 99;
            $orderB = $categoryOrder[$b] ?? 99;
            return $orderA <=> $orderB;
        });

        $categories = [];
        $grandTotalOpeningDebit = 0.0;
        $grandTotalOpeningCredit = 0.0;
        $grandTotalPeriodDebit = 0.0;
        $grandTotalPeriodCredit = 0.0;
        $grandTotalEndingDebit = 0.0;
        $grandTotalEndingCredit = 0.0;

        foreach ($categoriesMap as $catName => $rows) {
            $catOpenDr = 0.0;
            $catOpenCr = 0.0;
            $catPeriodDr = 0.0;
            $catPeriodCr = 0.0;
            $catEndDr = 0.0;
            $catEndCr = 0.0;

            foreach ($rows as $row) {
                $catOpenDr += $row->openingDebit;
                $catOpenCr += $row->openingCredit;
                $catPeriodDr += $row->periodDebit;
                $catPeriodCr += $row->periodCredit;
                $catEndDr += $row->endingDebit;
                $catEndCr += $row->endingCredit;
            }

            $grandTotalOpeningDebit += $catOpenDr;
            $grandTotalOpeningCredit += $catOpenCr;
            $grandTotalPeriodDebit += $catPeriodDr;
            $grandTotalPeriodCredit += $catPeriodCr;
            $grandTotalEndingDebit += $catEndDr;
            $grandTotalEndingCredit += $catEndCr;

            $categories[] = new OperationalTrialBalanceCategory(
                $catName,
                $rows,
                $catOpenDr,
                $catOpenCr,
                $catPeriodDr,
                $catPeriodCr,
                $catEndDr,
                $catEndCr
            );
        }

        return new OperationalTrialBalanceReport(
            $currencyCode,
            $startDate,
            $endDate,
            $sourceNote,
            $categories,
            $grandTotalOpeningDebit,
            $grandTotalOpeningCredit,
            $grandTotalPeriodDebit,
            $grandTotalPeriodCredit,
            $grandTotalEndingDebit,
            $grandTotalEndingCredit
        );
    }
}

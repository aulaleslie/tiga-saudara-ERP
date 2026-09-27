<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Expense\Entities\Expense;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Setting\Entities\Setting;
use Carbon\Carbon;

class OperationalGeneralLedgerReportService
{
    public function getSummary(int|array $settingScope, OperationalGeneralLedgerReportFilterData $filter): OperationalGeneralLedgerReport
    {
        return $this->buildReport($settingScope, $filter, false);
    }

    public function generate(int|array $settingScope, OperationalGeneralLedgerReportFilterData $filter): OperationalGeneralLedgerReport
    {
        // Preserves existing full-detail export path
        return $this->buildReport($settingScope, $filter, true);
    }

    public function getBucketDetail(int|array $settingScope, OperationalGeneralLedgerReportFilterData $filter, string $bucketKey): ?OperationalGeneralLedgerBucket
    {
        $report = $this->buildReport($settingScope, $filter, true, [$bucketKey]);
        foreach ($report->buckets as $bucket) {
            if ($bucket->key === $bucketKey) {
                return $bucket;
            }
        }
        
        return null;
    }

    private function buildReport(
        int|array $settingScope, 
        OperationalGeneralLedgerReportFilterData $filter, 
        bool $loadDetails, 
        ?array $specificBucketsToLoadDetail = null
    ): OperationalGeneralLedgerReport {
        $settingIds = is_array($settingScope) ? $settingScope : [$settingScope];
        $firstSettingId = $settingIds[0] ?? session('setting_id');
        $setting = Setting::with('currency')->find($firstSettingId);
        $currencyCode = $setting && $setting->currency ? ($setting->currency->code ?? $setting->currency->currency_name ?? 'IDR') : 'IDR';

        $startDate = $filter->startDate;
        $endDate = $filter->endDate;
        $bucketKeys = $filter->bucketKeys;

        $sourceNote = '* Laporan ini dihitung dari nilai dokumen operasional (penjualan, pembelian, pembayaran) dan tidak menggunakan pencatatan jurnal akuntansi ganda.';

        if ($startDate > $endDate) {
            return new OperationalGeneralLedgerReport(
                $currencyCode,
                $startDate,
                $endDate,
                $sourceNote,
                []
            );
        }

        $startDateStr = $startDate instanceof \DateTimeInterface ? $startDate->format('Y-m-d') : (string)$startDate;
        $endDateStr = $endDate instanceof \DateTimeInterface ? $endDate->format('Y-m-d') : (string)$endDate;

        $movementService = app(OperationalMovementEventService::class);
        $openingBalances = $movementService->getOpeningBalances($settingScope, $startDateStr);
        
        $events = [];
        $endingBalances = [];

        // If we don't need ANY details, we can skip getPeriodMovements entirely
        if (!$loadDetails) {
            $endDateNextDay = Carbon::parse($endDateStr)->addDay()->format('Y-m-d');
            $endingBalances = $movementService->getOpeningBalances($settingScope, $endDateNextDay);
        } else {
            $events = $movementService->getPeriodMovements($settingScope, $startDateStr, $endDateStr);
        }

        // --- Process Events into Buckets ---
        $bucketLabels = OperationalGeneralLedgerBucketConfig::getLabels();
        $buckets = [];

        foreach ($bucketKeys as $key) {
            if (!isset($bucketLabels[$key])) {
                continue;
            }
            
            $beginningDebit = $openingBalances[$key]['debit'] ?? 0.0;
            $beginningCredit = $openingBalances[$key]['credit'] ?? 0.0;
            $beginningBalance = $this->getNetEffect($key, $beginningDebit, $beginningCredit);
            
            $periodDebit = 0;
            $periodCredit = 0;
            $runningBalance = $beginningBalance;
            $rows = [];
            
            $shouldLoadDetailForThisBucket = $loadDetails && ($specificBucketsToLoadDetail === null || in_array($key, $specificBucketsToLoadDetail));
            $hasActivity = false;

            if (!$loadDetails) {
                // Calculate period movement from opening balances delta
                $endDebit = $endingBalances[$key]['debit'] ?? 0.0;
                $endCredit = $endingBalances[$key]['credit'] ?? 0.0;
                
                $periodDebit = max(0, $endDebit - $beginningDebit);
                $periodCredit = max(0, $endCredit - $beginningCredit);
                $hasActivity = ($periodDebit > 0 || $periodCredit > 0);
            } else {
                // Filter events for this bucket
                $bucketEvents = array_filter($events, fn($e) => $e['bucket'] === $key);
                $hasActivity = count($bucketEvents) > 0;
                
                // Sort by date/time ascending, tie-breaker by reference
                usort($bucketEvents, function($a, $b) {
                    if ($a['dt'] === $b['dt']) {
                        return strcmp($a['reference'], $b['reference']);
                    }
                    return $a['dt'] <=> $b['dt'];
                });
                
                foreach ($bucketEvents as $e) {
                    $periodDebit += $e['debit'];
                    $periodCredit += $e['credit'];
                    
                    $netEffect = $this->getNetEffect($key, $e['debit'], $e['credit']);
                    $runningBalance += $netEffect;
                    
                    if ($shouldLoadDetailForThisBucket) {
                        $rows[] = new OperationalGeneralLedgerMovementRow(
                            substr($e['dt'], 0, 10),
                            $e['sourceType'],
                            $e['reference'],
                            $e['description'],
                            $e['debit'],
                            $e['credit'],
                            $runningBalance,
                            $e['tag']
                        );
                    }
                }
            }
            
            $endingBalance = $beginningBalance + $this->getNetEffect($key, $periodDebit, $periodCredit);
            
            // Only include if there is activity or a non-zero balance
            if (abs($beginningBalance) > 0.001 || abs($endingBalance) > 0.001 || $hasActivity) {
                $buckets[] = new OperationalGeneralLedgerBucket(
                    $key,
                    $bucketLabels[$key],
                    $beginningBalance,
                    $periodDebit,
                    $periodCredit,
                    $endingBalance,
                    $rows
                );
            }
        }

        return new OperationalGeneralLedgerReport(
            $currencyCode,
            $startDate,
            $endDate,
            $sourceNote,
            $buckets
        );
    }
    

    private function getNetEffect(string $bucket, float $debit, float $credit): float
    {
        // Normal balance debit: Cash, AR, Cost
        // Normal balance credit: AP, Revenue, Returns
        $debitNormal = [
            OperationalGeneralLedgerBucketConfig::CASH_BANK,
            OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE,
            OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST,
            OperationalGeneralLedgerBucketConfig::INVENTORY,
        ];
        
        if (in_array($bucket, $debitNormal)) {
            return $debit - $credit;
        } else {
            return $credit - $debit;
        }
    }
}

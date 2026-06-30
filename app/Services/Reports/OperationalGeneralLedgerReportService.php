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
    public function generate(int $settingId, OperationalGeneralLedgerReportFilterData $filter): OperationalGeneralLedgerReport
    {
        $setting = Setting::with('currency')->find($settingId);
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

        $movementService = app(OperationalMovementEventService::class);
        $events = $movementService->getMovementEvents($settingId, $endDate instanceof \DateTimeInterface ? $endDate->format('Y-m-d') : (string)$endDate);

        // --- Process Events into Buckets ---
        $bucketLabels = OperationalGeneralLedgerBucketConfig::getLabels();
        $buckets = [];

        foreach ($bucketKeys as $key) {
            if (!isset($bucketLabels[$key])) {
                continue;
            }
            
            // Filter events for this bucket
            $bucketEvents = array_filter($events, fn($e) => $e['bucket'] === $key);
            
            // Sort by date/time ascending, tie-breaker by reference
            usort($bucketEvents, function($a, $b) {
                if ($a['dt'] === $b['dt']) {
                    return strcmp($a['reference'], $b['reference']);
                }
                return $a['dt'] <=> $b['dt'];
            });
            
            $beginningBalance = 0;
            $periodDebit = 0;
            $periodCredit = 0;
            $runningBalance = 0;
            $rows = [];
            
            foreach ($bucketEvents as $e) {
                $isBefore = substr($e['dt'], 0, 10) < $startDate;
                $isInside = substr($e['dt'], 0, 10) >= $startDate && substr($e['dt'], 0, 10) <= $endDate;
                
                $netEffect = $this->getNetEffect($key, $e['debit'], $e['credit']);
                
                if ($isBefore) {
                    $beginningBalance += $netEffect;
                } elseif ($isInside) {
                    $periodDebit += $e['debit'];
                    $periodCredit += $e['credit'];
                }
            }
            
            $runningBalance = $beginningBalance;
            
            foreach ($bucketEvents as $e) {
                $isInside = substr($e['dt'], 0, 10) >= $startDate && substr($e['dt'], 0, 10) <= $endDate;
                if ($isInside) {
                    $netEffect = $this->getNetEffect($key, $e['debit'], $e['credit']);
                    $runningBalance += $netEffect;
                    
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
            
            $endingBalance = $beginningBalance + $this->getNetEffect($key, $periodDebit, $periodCredit);
            
            // Only include if there is activity or a non-zero balance
            if (abs($beginningBalance) > 0.001 || abs($endingBalance) > 0.001 || count($rows) > 0) {
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

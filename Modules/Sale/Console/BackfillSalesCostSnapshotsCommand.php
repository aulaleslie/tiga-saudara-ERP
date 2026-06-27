<?php

namespace Modules\Sale\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Sale\Support\BackfillCostCalculator;
use Carbon\Carbon;

class BackfillSalesCostSnapshotsCommand extends Command
{
    protected $signature = 'sales:backfill-cost-snapshots 
                            {--write : Write the computed snapshots to the database}
                            {--force : Recompute and overwrite existing snapshots}
                            {--product= : Limit to a specific product ID}
                            {--setting= : Limit to a specific setting ID}
                            {--start= : Start date (YYYY-MM-DD)}
                            {--end= : End date (YYYY-MM-DD)}';

    protected $description = 'Backfill historical sales cost snapshots using effective-date purchase replay';

    protected $summary = [
        'scanned' => 0,
        'fillable' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'skipped' => 0,
        'missing_product_price' => 0,
        'negative_stock' => 0,
        'archived_skipped' => 0,
        'future_purchase_fallback' => 0,
        'no_purchase_fallback' => 0,
        'non_stock_zero' => 0,
        'missing_receipt_data' => 0,
        'suspicious_unit_cost' => 0,
    ];

    protected $suspiciousWarnings = [];
    protected $updateBatch = [];
    protected $batchSize = 500;

    public function handle()
    {
        $isDryRun = !$this->option('write');
        $force = $this->option('force');
        $productId = $this->option('product');
        $settingId = $this->option('setting');
        $startDate = $this->option('start');
        $endDate = $this->option('end');

        if ($isDryRun) {
            $this->info("Starting backfill in DRY RUN mode. No data will be written.");
        } else {
            $this->warn("Starting backfill in WRITE mode. Data will be modified.");
        }

        $query = Product::withoutEagerLoads()->select('id', 'product_code', 'stock_managed');
        if ($productId) {
            $query->where('id', $productId);
        }

        $count = $query->count();
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunk(100, function ($products) use ($bar, $settingId, $isDryRun, $startDate, $endDate, $force) {
            foreach ($products as $product) {
                $this->processProduct($product, $settingId, $startDate, $endDate, $isDryRun, $force);
                $bar->advance();
            }
        });

        $this->flushUpdates(); // Ensure remaining batch is written

        $bar->finish();
        $this->line('');
        $this->info("Backfill completed.");

        $this->table(
            ['Metric', 'Count'],
            collect($this->summary)->map(fn($val, $key) => [$key, $val])->toArray()
        );

        if (!empty($this->suspiciousWarnings)) {
            $this->warn("Suspicious unit costs encountered:");
            $this->table(
                ['Product ID', 'Code', 'Detail ID', 'Date', 'Run Qty', 'Run Value', 'Computed Cost'],
                $this->suspiciousWarnings
            );
        }
    }

    protected function processProduct(Product $product, $settingId, $startDate, $endDate, $isDryRun, $force)
    {
        // For each product, we get all timeline events: Purchases, PurchaseReturns, Sales
        // Group them by setting because purchases usually happen per setting, though average is global.
        // Wait, D2 says "product_prices.average_purchase_price values for the same product_id must be synchronized to the same value"
        // And D3 says "The backfill command builds a global product timeline from eligible purchase and sale events."
        // Let's build a global timeline.

        $events = collect();

        // 1. Purchase Details
        $purchases = PurchaseDetail::withoutEagerLoads()
            ->with(['purchase' => function ($q) use ($settingId) {
                $q->withoutEagerLoads()->select('id', 'date', 'status');
                if ($settingId) $q->where('setting_id', $settingId);
            }, 'receivedNoteDetails' => function ($q) {
                $q->withoutEagerLoads()->select('id', 'po_detail_id', 'received_note_id', 'quantity_received');
            }, 'receivedNoteDetails.receivedNote' => function ($q) {
                $q->withoutEagerLoads()->select('id', 'date', 'status', 'approved_at');
            }])
            ->select('id', 'purchase_id', 'product_id', 'quantity', 'sub_total', 'product_tax_amount', 'product_discount_amount')
            ->where('product_id', $product->id)
            ->whereHas('purchase', function ($q) use ($settingId, $endDate) {
                $q->whereIn('status', ['Completed', 'COMPLETED', 'RECEIVED', 'RECEIVED PARTIALLY', 'RETURNED PARTIALLY', 'RETURNED']);
                if ($settingId) $q->where('setting_id', $settingId);
                if ($endDate) $q->whereDate('date', '<=', $endDate);
            })->get();

        $purchaseEvents = $this->buildPurchaseEvents($purchases, true);
        foreach ($purchaseEvents as $event) {
            $events->push($event);
        }

        // 2. Purchase Return Details
        $purchaseReturns = PurchaseReturnDetail::withoutEagerLoads()
            ->with(['purchaseReturn' => function ($q) use ($settingId) {
                $q->withoutEagerLoads()->select('id', 'date', 'status');
                if ($settingId) $q->where('setting_id', $settingId);
            }])
            ->select('id', 'purchase_return_id', 'product_id', 'quantity')
            ->where('product_id', $product->id)
            ->whereHas('purchaseReturn', function ($q) use ($settingId, $endDate) {
                $q->whereIn('status', ['Completed', 'COMPLETED']);
                if ($settingId) $q->where('setting_id', $settingId);
                if ($endDate) $q->whereDate('date', '<=', $endDate);
            })->get();

        foreach ($purchaseReturns as $prd) {
            $date = Carbon::parse($prd->purchaseReturn->date)->format('Y-m-d H:i:s');
            $events->push([
                'type' => 'purchase_return',
                'order' => 2,
                'id' => $prd->id,
                'date' => $date,
                'quantity' => (float) $prd->quantity,
                'model' => $prd,
            ]);
        }

        // 3. Sale Details
        // Date filters for writes are checked later during processing, not in the query, to ensure opening state is built correctly
        $salesQuery = SaleDetails::withoutEagerLoads()
            ->with(['sale' => function ($q) use ($settingId) {
                $q->withoutEagerLoads()->select('id', 'date', 'status');
                if ($settingId) $q->where('setting_id', $settingId);
            }])
            ->select('id', 'sale_id', 'product_id', 'quantity', 'cost_snapshot_source', 'cost_unit_snapshot', 'cost_total_snapshot', 'cost_snapshot_at')
            ->where('product_id', $product->id)
            ->whereHas('sale', function ($q) use ($settingId, $endDate) {
                $q->whereIn('status', ['Completed', 'COMPLETED', 'DISPATCHED', 'RETURNED PARTIALLY', 'RETURNED']);
                if ($settingId) $q->where('setting_id', $settingId);
                if ($endDate) $q->whereDate('date', '<=', $endDate);
            });

        $sales = $salesQuery->get();
        foreach ($sales as $sd) {
            $date = Carbon::parse($sd->sale->date)->format('Y-m-d H:i:s');
            $events->push([
                'type' => 'sale',
                'order' => 3,
                'id' => $sd->id,
                'date' => $date,
                'quantity' => (float) $sd->quantity,
                'model' => $sd,
            ]);
        }

        $events = $events->sort(function ($a, $b) {
            if ($a['date'] === $b['date']) {
                if ($a['order'] === $b['order']) {
                    return $a['id'] <=> $b['id'];
                }
                return $a['order'] <=> $b['order'];
            }
            return $a['date'] <=> $b['date'];
        })->values();

        // Compute running average
        $runningQty = 0;
        $runningValue = 0;
        $currentAverage = 0;

        // Determine earliest future purchase average for fallback
        $earliestPurchaseAverage = null;
        foreach ($events as $event) {
            if ($event['type'] === 'purchase' && $event['quantity'] > 0) {
                $earliestPurchaseAverage = $event['cost'] / $event['quantity'];
                break;
            }
        }

        if ($earliestPurchaseAverage === null && $endDate) {
            $futurePurchases = PurchaseDetail::withoutEagerLoads()
                ->with(['purchase' => function ($q) use ($settingId) {
                    $q->withoutEagerLoads()->select('id', 'date', 'status');
                    if ($settingId) $q->where('setting_id', $settingId);
                }, 'receivedNoteDetails' => function ($q) {
                    $q->withoutEagerLoads()->select('id', 'po_detail_id', 'received_note_id', 'quantity_received');
                }, 'receivedNoteDetails.receivedNote' => function ($q) {
                    $q->withoutEagerLoads()->select('id', 'date', 'status', 'approved_at');
                }])
                ->select('purchase_details.id', 'purchase_details.purchase_id', 'purchase_details.product_id', 'purchase_details.quantity', 'purchase_details.sub_total', 'purchase_details.product_tax_amount', 'purchase_details.product_discount_amount')
                ->join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
                ->where('purchase_details.product_id', $product->id)
                ->whereIn('purchases.status', ['Completed', 'COMPLETED', 'RECEIVED', 'RECEIVED PARTIALLY', 'RETURNED PARTIALLY', 'RETURNED'])
                ->when($settingId, function ($q) use ($settingId) {
                    $q->where('purchases.setting_id', $settingId);
                })
                ->whereDate('purchases.date', '>', $endDate)
                ->orderBy('purchases.date', 'asc')
                ->orderBy('purchase_details.id', 'asc')
                ->get();

            $futureEvents = $this->buildPurchaseEvents($futurePurchases, false);
            $futureEvents = $futureEvents->sort(function ($a, $b) {
                if ($a['date'] === $b['date']) {
                    return $a['id'] <=> $b['id'];
                }
                return $a['date'] <=> $b['date'];
            })->values();

            foreach ($futureEvents as $event) {
                if ($event['quantity'] > 0) {
                    $earliestPurchaseAverage = $event['cost'] / $event['quantity'];
                    break;
                }
            }
        }

        foreach ($events as $event) {
            if ($event['type'] === 'purchase') {
                $state = BackfillCostCalculator::applyPurchase($event['quantity'], $event['cost'], $runningQty, $runningValue, $currentAverage);
                $runningQty = $state['runningQty'];
                $runningValue = $state['runningValue'];
                $currentAverage = $state['currentAverage'];
            } elseif ($event['type'] === 'purchase_return') {
                $state = BackfillCostCalculator::applyConsumption($event['quantity'], $runningQty, $runningValue, $currentAverage);
                $runningQty = $state['runningQty'];
                $runningValue = $state['runningValue'];
                $currentAverage = $state['currentAverage'];
                if ($runningQty < 0) {
                    $this->summary['negative_stock']++;
                }
            } elseif ($event['type'] === 'sale') {
                $sd = $event['model'];
                
                $inScope = true;
                $eventDate = Carbon::parse($sd->sale->date)->startOfDay();
                if ($startDate && $eventDate->lt(Carbon::parse($startDate)->startOfDay())) {
                    $inScope = false;
                }
                if ($endDate && $eventDate->gt(Carbon::parse($endDate)->startOfDay())) {
                    $inScope = false;
                }

                if ($inScope) {
                    $this->summary['scanned']++;
                }

                // Skip ONLY if snapshot is from backfill AND not force flag
                // BUT: always consume stock from timeline to maintain running average integrity
                $shouldSkipSnapshot = !$force && $sd->cost_snapshot_source && str_starts_with($sd->cost_snapshot_source, 'BACKFILL_');

                if ($inScope && $shouldSkipSnapshot) {
                    $this->summary['skipped']++;
                } elseif ($inScope) {
                    $this->summary['fillable']++;

                    // Calculate snapshot BEFORE consuming stock, using pre-sale moving average
                    $unitCost = 0;
                    $source = '';

                    if (!$product->stock_managed) {
                        $unitCost = 0;
                        $source = 'NON_STOCK_ZERO';
                        $this->summary['non_stock_zero']++;
                    } else {
                        if ($runningQty > 0 || $currentAverage > 0) {
                            $unitCost = $currentAverage;
                            $source = 'BACKFILL_RUNNING_AVERAGE';
                        } elseif ($earliestPurchaseAverage !== null) {
                            $unitCost = $earliestPurchaseAverage;
                            $source = 'BACKFILL_FUTURE_PURCHASE';
                            $this->summary['future_purchase_fallback']++;
                        } else {
                            $unitCost = 0;
                            $source = 'BACKFILL_ZERO_FALLBACK';
                            $this->summary['no_purchase_fallback']++;
                        }
                    }

                    $maxCost = (float) config('sale.suspicious_unit_cost_max', 100000000);
                    $isSuspicious = $unitCost < 0 || !is_finite($unitCost) || $unitCost > $maxCost;

                    if ($isSuspicious) {
                        $this->summary['suspicious_unit_cost']++;
                        $this->suspiciousWarnings[] = [
                            $product->id,
                            $product->product_code,
                            $sd->id,
                            $sd->sale->date,
                            $runningQty,
                            $runningValue,
                            $unitCost,
                        ];
                    } else {
                        $totalCost = $unitCost * $sd->quantity;

                        if (!$isDryRun) {
                            $this->updateBatch[] = [
                                'id' => $sd->id,
                                'cost_unit_snapshot' => round($unitCost, 6),
                                'cost_total_snapshot' => round($totalCost, 2),
                                'cost_snapshot_source' => $source,
                                'cost_snapshot_at' => now()->format('Y-m-d H:i:s'),
                            ];
                            $this->summary['updated']++;

                            if (count($this->updateBatch) >= $this->batchSize) {
                                $this->flushUpdates();
                            }
                        }
                    }
                }

                // NOW consume stock from running inventory after snapshot is taken
                // Always reduce value at current average, even if negative stock results
                $state = BackfillCostCalculator::applyConsumption($event['quantity'], $runningQty, $runningValue, $currentAverage);
                $runningQty = $state['runningQty'];
                $runningValue = $state['runningValue'];
                $currentAverage = $state['currentAverage'];
                if ($runningQty < 0) {
                    $this->summary['negative_stock']++;
                }
            }
        }
    }

    protected function flushUpdates()
    {
        if (empty($this->updateBatch)) {
            return;
        }

        DB::transaction(function () {
            foreach ($this->updateBatch as $update) {
                DB::table('sale_details')
                    ->where('id', $update['id'])
                    ->update([
                        'cost_unit_snapshot' => $update['cost_unit_snapshot'],
                        'cost_total_snapshot' => $update['cost_total_snapshot'],
                        'cost_snapshot_source' => $update['cost_snapshot_source'],
                        'cost_snapshot_at' => $update['cost_snapshot_at'],
                        'updated_at' => $update['cost_snapshot_at'],
                    ]);
            }
        });

        $this->updateBatch = [];
    }

    protected function buildPurchaseEvents($purchases, $recordWarnings = true)
    {
        $events = collect();

        foreach ($purchases as $pd) {
            $subTotal = (float) $pd->sub_total;
            $tax = (float) $pd->product_tax_amount;
            $discount = (float) $pd->product_discount_amount;
            $lineDpp = BackfillCostCalculator::calculatePurchaseDpp($subTotal, $tax, $discount);
            $orderedQty = (float) $pd->quantity;

            $approvedReceipts = [];
            if ($pd->receivedNoteDetails && $pd->receivedNoteDetails->count() > 0) {
                foreach ($pd->receivedNoteDetails as $rnd) {
                    if ($rnd->receivedNote && $rnd->receivedNote->status === 'APPROVED') {
                        $approvedReceipts[] = [
                            'quantity' => (float) $rnd->quantity_received,
                            'date' => $rnd->receivedNote->approved_at ?? $rnd->receivedNote->date,
                        ];
                    }
                }
            }

            if ($pd->purchase->status === 'RECEIVED PARTIALLY' && empty($approvedReceipts)) {
                if ($recordWarnings) {
                    $this->summary['missing_receipt_data']++;
                }
                continue;
            }

            if (!empty($approvedReceipts)) {
                usort($approvedReceipts, fn($a, $b) => $a['date'] <=> $b['date']);
                foreach ($approvedReceipts as $receipt) {
                    $receiptQty = $receipt['quantity'];
                    $receiptCost = BackfillCostCalculator::calculateProratedReceiptCost($orderedQty, $receiptQty, $lineDpp);

                    $events->push([
                        'type' => 'purchase',
                        'order' => 1,
                        'id' => $pd->id,
                        'date' => Carbon::parse($receipt['date'])->format('Y-m-d H:i:s'),
                        'quantity' => $receiptQty,
                        'cost' => $receiptCost,
                        'model' => $pd,
                    ]);
                }
            } else {
                $events->push([
                    'type' => 'purchase',
                    'order' => 1,
                    'id' => $pd->id,
                    'date' => Carbon::parse($pd->purchase->date)->format('Y-m-d H:i:s'),
                    'quantity' => $orderedQty,
                    'cost' => $lineDpp,
                    'model' => $pd,
                ]);
            }
        }

        return $events;
    }
}

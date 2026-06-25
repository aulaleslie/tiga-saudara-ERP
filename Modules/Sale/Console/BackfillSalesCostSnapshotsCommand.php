<?php

namespace Modules\Sale\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturnDetail;
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
    ];

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

        $query = Product::query();
        if ($productId) {
            $query->where('id', $productId);
        }

        $products = $query->get();
        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($products as $product) {
            $this->processProduct($product, $settingId, $startDate, $endDate, $isDryRun, $force);
            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->info("Backfill completed.");

        $this->table(
            ['Metric', 'Count'],
            collect($this->summary)->map(fn($val, $key) => [$key, $val])->toArray()
        );
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
        $purchases = PurchaseDetail::with(['purchase', 'receivedNoteDetails.receivedNote'])
            ->where('product_id', $product->id)
            ->whereHas('purchase', function ($q) use ($settingId) {
                $q->whereIn('status', ['Completed', 'COMPLETED', 'RECEIVED', 'RECEIVED PARTIALLY', 'RETURNED PARTIALLY', 'RETURNED']);
                if ($settingId) $q->where('setting_id', $settingId);
            })->get();

        foreach ($purchases as $pd) {
            // DPP calculation: sub_total (already discounted in import/cart) minus tax only
            $subTotal = (float) $pd->sub_total;
            $tax = (float) $pd->product_tax_amount;
            $lineDpp = $subTotal - $tax;
            $orderedQty = (float) $pd->quantity;

            // Collect all approved receipts with their quantities and dates
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

            // For RECEIVED PARTIALLY with no approved receipts, skip with warning instead of using full quantity
            if ($pd->purchase->status === 'RECEIVED PARTIALLY' && empty($approvedReceipts)) {
                $this->summary['missing_receipt_data']++;
                continue;
            }

            // If we have approved receipts, create one event per receipt with prorated cost
            if (!empty($approvedReceipts)) {
                $totalReceivedQty = array_sum(array_column($approvedReceipts, 'quantity'));

                // Sort receipts by date to maintain timeline order
                usort($approvedReceipts, fn($a, $b) => $a['date'] <=> $b['date']);

                foreach ($approvedReceipts as $receipt) {
                    $receiptQty = $receipt['quantity'];
                    // Prorate total DPP cost based on this receipt's quantity relative to ordered quantity
                    $receiptCost = $orderedQty > 0 ? $lineDpp * ($receiptQty / $orderedQty) : 0;

                    $events->push([
                        'type' => 'purchase',
                        'date' => Carbon::parse($receipt['date'])->format('Y-m-d H:i:s'),
                        'quantity' => $receiptQty,
                        'cost' => $receiptCost,
                        'model' => $pd,
                    ]);
                }
            } else {
                // No receipts: use ordered quantity for non-RECEIVED PARTIALLY statuses
                $events->push([
                    'type' => 'purchase',
                    'date' => Carbon::parse($pd->purchase->date)->format('Y-m-d H:i:s'),
                    'quantity' => $orderedQty,
                    'cost' => $lineDpp,
                    'model' => $pd,
                ]);
            }
        }

        // 2. Purchase Return Details
        $purchaseReturns = PurchaseReturnDetail::with(['purchaseReturn'])
            ->where('product_id', $product->id)
            ->whereHas('purchaseReturn', function ($q) use ($settingId) {
                $q->whereIn('status', ['Completed', 'COMPLETED']);
                if ($settingId) $q->where('setting_id', $settingId);
            })->get();

        foreach ($purchaseReturns as $prd) {
            $date = Carbon::parse($prd->purchaseReturn->date)->format('Y-m-d H:i:s');
            $events->push([
                'type' => 'purchase_return',
                'date' => $date,
                'quantity' => (float) $prd->quantity,
                'model' => $prd,
            ]);
        }

        // 3. Sale Details
        $salesQuery = SaleDetails::with(['sale'])
            ->where('product_id', $product->id)
            ->whereHas('sale', function ($q) use ($settingId, $startDate, $endDate) {
                $q->whereIn('status', ['Completed', 'COMPLETED', 'DISPATCHED', 'RETURNED PARTIALLY', 'RETURNED']);
                if ($settingId) $q->where('setting_id', $settingId);
                if ($startDate) $q->whereDate('date', '>=', $startDate);
                if ($endDate) $q->whereDate('date', '<=', $endDate);
            });

        $sales = $salesQuery->get();
        foreach ($sales as $sd) {
            $date = Carbon::parse($sd->sale->date)->format('Y-m-d H:i:s');
            $events->push([
                'type' => 'sale',
                'date' => $date,
                'quantity' => (float) $sd->quantity,
                'model' => $sd,
            ]);
        }

        $events = $events->sortBy('date')->values();

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

        foreach ($events as $event) {
            if ($event['type'] === 'purchase') {
                $runningQty += $event['quantity'];
                $runningValue += $event['cost'];
                if ($runningQty > 0) {
                    $currentAverage = $runningValue / $runningQty;
                }
            } elseif ($event['type'] === 'purchase_return') {
                $runningQty -= $event['quantity'];
                $runningValue -= $currentAverage * $event['quantity'];
                if ($runningQty < 0) {
                    $this->summary['negative_stock']++;
                }
            } elseif ($event['type'] === 'sale') {
                $sd = $event['model'];
                $this->summary['scanned']++;

                // Skip ONLY if snapshot is from backfill AND not force flag
                // BUT: always consume stock from timeline to maintain running average integrity
                $shouldSkipSnapshot = !$force && $sd->cost_snapshot_source && str_starts_with($sd->cost_snapshot_source, 'BACKFILL_');

                if ($shouldSkipSnapshot) {
                    $this->summary['skipped']++;
                } else {
                    $this->summary['fillable']++;

                    // Calculate snapshot BEFORE consuming stock, using pre-sale moving average
                    $unitCost = 0;
                    $source = '';

                    if (!$product->stock_managed) {
                        $unitCost = 0;
                        $source = 'NON_STOCK_ZERO';
                        $this->summary['non_stock_zero']++;
                    } else {
                        if ($runningQty > 0) {
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

                    $totalCost = $unitCost * $sd->quantity;

                    if (!$isDryRun) {
                        $sd->cost_unit_snapshot = round($unitCost, 6);
                        $sd->cost_total_snapshot = round($totalCost, 2);
                        $sd->cost_snapshot_source = $source;
                        $sd->cost_snapshot_at = now();
                        $sd->save();
                        $this->summary['updated']++;
                    }
                }

                // NOW consume stock from running inventory after snapshot is taken
                // Always reduce value at current average, even if negative stock results
                $runningValue -= $currentAverage * $event['quantity'];
                $runningQty -= $event['quantity'];
                if ($runningQty < 0) {
                    $this->summary['negative_stock']++;
                }
                if ($runningQty > 0) {
                    $currentAverage = $runningValue / $runningQty;
                } else if ($runningQty === 0) {
                    $runningValue = 0;
                    $currentAverage = 0;
                }
            }
        }
    }
}

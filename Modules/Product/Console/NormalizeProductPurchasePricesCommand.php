<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;

class NormalizeProductPurchasePricesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'product:normalize-purchase-prices {--write : Apply the changes to the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize product purchase prices from historical received purchases.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $isWrite = $this->option('write');

        $this->info("Starting product purchase price normalization in " . ($isWrite ? "WRITE" : "DRY-RUN") . " mode...");

        $consideredCount = 0;
        $skippedCount = 0;
        $createdRowsCount = 0;
        $updatedRowsCount = 0;
        $unchangedRowsCount = 0;

        $settings = \Modules\Setting\Entities\Setting::all();

        \Modules\Product\Entities\Product::where('stock_managed', true)
            ->chunkById(100, function ($products) use (
                $isWrite,
                $settings,
                &$consideredCount,
                &$skippedCount,
                &$createdRowsCount,
                &$updatedRowsCount,
                &$unchangedRowsCount
            ) {
                $productIds = $products->pluck('id')->toArray();

                $allPurchaseDetails = \Modules\Purchase\Entities\PurchaseDetail::whereIn('product_id', $productIds)
                    ->whereHas('purchase', function($q) {
                        $q->whereIn('status', [\Modules\Purchase\Entities\Purchase::STATUS_RECEIVED, \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED_PARTIALLY])
                          ->whereNull('archived_at');
                    })
                    ->with([
                        'purchase:id,date',
                        'receivedNoteDetails:id,po_detail_id,received_note_id,quantity_received',
                        'receivedNoteDetails.receivedNote:id,status,approved_at'
                    ])
                    ->select('id', 'product_id', 'purchase_id', 'quantity', 'price', 'unit_price')
                    ->get()
                    ->groupBy('product_id');

                $allExistingPrices = \Modules\Product\Entities\ProductPrice::whereIn('product_id', $productIds)
                    ->get()
                    ->groupBy('product_id');

                foreach ($products as $product) {
                    $consideredCount++;
                    
                    $purchaseDetails = $allPurchaseDetails->get($product->id, collect());

                    $totalEligibleQuantity = 0;
                    $totalCost = 0;
                    $latestEvent = null;

                    foreach ($purchaseDetails as $detail) {
                        $purchase = $detail->purchase;
                        $approvedNotes = $detail->receivedNoteDetails->filter(function($rnd) {
                            return $rnd->receivedNote && $rnd->receivedNote->status === \Modules\Purchase\Entities\ReceivedNote::STATUS_APPROVED;
                        });

                        $eligibleQuantity = 0;
                        $eventDate = null;

                        if ($approvedNotes->isNotEmpty()) {
                            $eligibleQuantity = $approvedNotes->sum('quantity_received');
                            $maxDateNote = $approvedNotes->sortByDesc(function($rnd) {
                                return $rnd->receivedNote->approved_at ? $rnd->receivedNote->approved_at->timestamp : 0;
                            })->first();
                            $eventDate = $maxDateNote->receivedNote->approved_at ?? \Carbon\Carbon::parse($purchase->date);
                        } else {
                            // If no approved received notes, fallback to purchase detail quantity
                            $eligibleQuantity = $detail->quantity;
                            $eventDate = \Carbon\Carbon::parse($purchase->date);
                        }

                        if ($eligibleQuantity > 0) {
                            $unitCost = $detail->price ?? $detail->unit_price ?? 0;
                            $totalEligibleQuantity += $eligibleQuantity;
                            $totalCost += ($unitCost * $eligibleQuantity);

                            // Determine latest event
                            $isLatest = false;
                            if (!$latestEvent) {
                                $isLatest = true;
                            } else {
                                if ($eventDate->timestamp > $latestEvent['date']->timestamp) {
                                    $isLatest = true;
                                } elseif ($eventDate->timestamp === $latestEvent['date']->timestamp) {
                                    // Tie breaker by purchase date
                                    $prevPurchaseDate = \Carbon\Carbon::parse($latestEvent['purchase']->date)->timestamp;
                                    $currPurchaseDate = \Carbon\Carbon::parse($purchase->date)->timestamp;
                                    if ($currPurchaseDate > $prevPurchaseDate) {
                                        $isLatest = true;
                                    } elseif ($currPurchaseDate === $prevPurchaseDate) {
                                        // Tie breaker by database id
                                        if ($detail->id > $latestEvent['detail_id']) {
                                            $isLatest = true;
                                        }
                                    }
                                }
                            }

                            if ($isLatest) {
                                $latestEvent = [
                                    'date' => $eventDate,
                                    'purchase' => $purchase,
                                    'detail_id' => $detail->id,
                                    'unit_cost' => $unitCost
                                ];
                            }
                        }
                    }

                    if ($totalEligibleQuantity <= 0) {
                        $skippedCount++;
                        continue;
                    }

                    $averagePurchasePrice = round($totalCost / $totalEligibleQuantity, 2);
                    $lastPurchasePrice = $latestEvent ? round($latestEvent['unit_cost'], 2) : 0;

                    // Sync to all settings
                    $existingPrices = $allExistingPrices->get($product->id, collect())->keyBy('setting_id');
                    
                    // Find a template row for missing rows
                    $templateRow = $existingPrices->filter(function($price) {
                        return (float)$price->sale_price > 0;
                    })->sortBy('id')->first();

                    foreach ($settings as $setting) {
                        $existing = $existingPrices->get($setting->id);

                        if ($existing) {
                            $needsUpdate = (float)$existing->last_purchase_price !== (float)$lastPurchasePrice ||
                                           (float)$existing->average_purchase_price !== (float)$averagePurchasePrice;
                            
                            if ($needsUpdate) {
                                if ($isWrite) {
                                    $existing->update([
                                        'last_purchase_price' => $lastPurchasePrice,
                                        'average_purchase_price' => $averagePurchasePrice,
                                    ]);
                                }
                                $updatedRowsCount++;
                            } else {
                                $unchangedRowsCount++;
                            }
                        } else {
                            if ($isWrite) {
                                $data = [
                                    'product_id' => $product->id,
                                    'setting_id' => $setting->id,
                                    'last_purchase_price' => $lastPurchasePrice,
                                    'average_purchase_price' => $averagePurchasePrice,
                                ];

                                if ($templateRow) {
                                    $data['sale_price'] = $templateRow->sale_price;
                                    $data['tier_1_price'] = (float)$templateRow->tier_1_price > 0 ? $templateRow->tier_1_price : $templateRow->sale_price;
                                    $data['tier_2_price'] = (float)$templateRow->tier_2_price > 0 ? $templateRow->tier_2_price : $templateRow->sale_price;
                                    $data['purchase_tax_id'] = $templateRow->purchase_tax_id;
                                    $data['sale_tax_id'] = $templateRow->sale_tax_id;
                                } else {
                                    $data['sale_price'] = 0;
                                    $data['tier_1_price'] = 0;
                                    $data['tier_2_price'] = 0;
                                    $data['purchase_tax_id'] = null;
                                    $data['sale_tax_id'] = null;
                                }

                                \Modules\Product\Entities\ProductPrice::create($data);
                            }
                            $createdRowsCount++;
                        }
                    }
                }
            });

        $this->info("Products Considered: {$consideredCount}");
        $this->info("Products Skipped: {$skippedCount}");
        $this->info("Rows Created: {$createdRowsCount}");
        $this->info("Rows Updated: {$updatedRowsCount}");
        $this->info("Rows Unchanged: {$unchangedRowsCount}");

        if (!$isWrite) {
            $this->info("Dry run complete. Run with --write to apply.");
        }

        return 0;
    }
}

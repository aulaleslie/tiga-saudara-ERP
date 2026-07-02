<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;

class NormalizeImportTransactionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:normalize-import-transactions {--initialize} {--write}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialization-only command to rebuild normalized import transactions.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $initialize = $this->option('initialize');
        $write = $this->option('write');
        $isDryRun = !($initialize && $write);

        if ($isDryRun) {
            $this->info("Running in dry-run mode. Use --initialize --write to truncate transactions and create new ones.");
        }

        $buyCount = 0;
        $sellCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        // Collect all movements
        $movements = collect();

        // 1. Get imported purchases
        $purchases = Purchase::with(['purchaseDetails'])
            ->whereNotNull('supplier_purchase_number')
            ->whereIn('status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])
            ->get();

        // 2. Get imported sales
        $sales = Sale::with(['saleDetails'])
            ->whereNotNull('imported_sales_reference_number')
            ->whereIn('status', [Sale::STATUS_DISPATCHED, Sale::STATUS_DISPATCHED_PARTIALLY])
            ->get();


        // Resolve setting locations
        $settingLocations = [];
        $locations = \Modules\Setting\Entities\Location::all();
        foreach ($locations as $location) {
            if (!isset($settingLocations[$location->setting_id])) {
                $settingLocations[$location->setting_id] = $location->id;
            }
        }

        // Add purchase details as movements
        foreach ($purchases as $purchase) {
            $locationId = $settingLocations[$purchase->setting_id] ?? null;
            if (!$locationId) {
                $skippedCount++;
                continue;
            }

            foreach ($purchase->purchaseDetails as $detail) {
                if ($detail->quantity <= 0) {
                    continue;
                }

                $movements->push([
                    'type' => 'BUY',
                    'priority' => 1, // BUY before SELL
                    'date' => $purchase->date ? \Carbon\Carbon::parse($purchase->date)->format('Y-m-d H:i:s') : \Carbon\Carbon::parse($purchase->created_at)->format('Y-m-d H:i:s'),
                    'document_id' => $purchase->id,
                    'detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'setting_id' => $purchase->setting_id,
                    'location_id' => $locationId,
                    'quantity' => (float)$detail->quantity,
                    'reference' => 'Imported Purchase: ' . $purchase->reference,
                    'user_id' => null,
                ]);
            }
        }

        // Add sale details as movements
        foreach ($sales as $sale) {
            // "sale setting id or resolved dispatch owner setting id"
            // For now we use the sale setting_id.
            $settingId = $sale->setting_id;
            
            // "target location id"
            // Wait, does sale have a specific target location id? Sales use the setting's first location too.
            // Oh, requirement 2.3: "Resolve the current initial-phase target location from each document setting's first configured location."
            
            $locationId = $settingLocations[$settingId] ?? null;
            if (!$locationId) {
                $skippedCount++;
                continue;
            }

            foreach ($sale->saleDetails as $detail) {
                if ($detail->quantity <= 0) {
                    continue;
                }

                $movements->push([
                    'type' => 'SELL',
                    'priority' => 2,
                    'date' => $sale->date ? \Carbon\Carbon::parse($sale->date)->format('Y-m-d H:i:s') : \Carbon\Carbon::parse($sale->created_at)->format('Y-m-d H:i:s'),
                    'document_id' => $sale->id,
                    'detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'setting_id' => $settingId,
                    'location_id' => $locationId,
                    'quantity' => -1 * (float)$detail->quantity,
                    'reference' => 'Imported Sale: ' . $sale->reference,
                    'user_id' => null,
                ]);
            }
        }

        // Sort movements: date ASC, priority ASC, document_id ASC, detail_id ASC
        $sortedMovements = $movements->sort(function ($a, $b) {
            if ($a['date'] !== $b['date']) {
                return $a['date'] <=> $b['date'];
            }
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }
            if ($a['document_id'] !== $b['document_id']) {
                return $a['document_id'] <=> $b['document_id'];
            }
            return $a['detail_id'] <=> $b['detail_id'];
        });

        // Calculate balances
        $runningLedger = []; 
        $transactionsToInsert = [];

        foreach ($sortedMovements as $movement) {
            $globalKey = $movement['product_id'] . '_' . $movement['setting_id'];
            $locKey = $movement['product_id'] . '_' . $movement['setting_id'] . '_' . $movement['location_id'];

            if (!isset($runningLedger[$globalKey])) {
                $runningLedger[$globalKey] = 0.0;
            }
            if (!isset($runningLedger[$locKey])) {
                $runningLedger[$locKey] = 0.0;
            }

            $previousGlobal = $runningLedger[$globalKey];
            $previousLoc = $runningLedger[$locKey];

            $qty = $movement['quantity'];
            $afterGlobal = $previousGlobal + $qty;
            $afterLoc = $previousLoc + $qty;

            $runningLedger[$globalKey] = $afterGlobal;
            $runningLedger[$locKey] = $afterLoc;

            $transactionsToInsert[] = [
                'product_id' => $movement['product_id'],
                'setting_id' => $movement['setting_id'],
                'location_id' => $movement['location_id'],
                'type' => $movement['type'],
                'quantity' => $qty,
                'previous_quantity' => $previousGlobal,
                'after_quantity' => $afterGlobal,
                'previous_quantity_at_location' => $previousLoc,
                'after_quantity_at_location' => $afterLoc,
                'current_quantity' => $afterGlobal,
                'quantity_non_tax' => 0,
                'quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'reason' => $movement['reference'],
                'user_id' => null, // Default to a valid user or omit if nullable
                'created_at' => $movement['date'],
                'updated_at' => $movement['date'],
            ];

            if ($movement['type'] === 'BUY') {
                $buyCount++;
            } else {
                $sellCount++;
            }
        }

        if (!$isDryRun) {
            $this->warn("DESTRUCTIVE INITIALIZATION: Truncating transactions...");
            DB::table('transactions')->truncate();

            foreach (array_chunk($transactionsToInsert, 500) as $chunk) {
                Transaction::insert($chunk);
            }
            $this->info("Transactions truncated and rebuilt successfully.");
        } else {
            $this->info("Dry-run completed.");
        }

        $this->info("BUY created: {$buyCount}");
        $this->info("SELL created: {$sellCount}");
        $this->info("Skipped: {$skippedCount}");
        $this->info("Errors: {$errorCount}");

        return 0;
    }
}

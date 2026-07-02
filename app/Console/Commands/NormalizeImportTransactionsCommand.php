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

        // Resolve setting locations
        $settingLocations = [];
        $locations = \Modules\Setting\Entities\Location::all();
        foreach ($locations as $location) {
            if (!isset($settingLocations[$location->setting_id])) {
                $settingLocations[$location->setting_id] = $location->id;
            }
        }

        $runId = uniqid();
        $stagingMovementsTable = 'tmp_movements_' . $runId;
        $stagingOrderedTable = 'tmp_movements_ordered_' . $runId;
        $stagingTransactionsTable = 'tmp_transactions_' . $runId;

        \Illuminate\Support\Facades\Schema::create($stagingMovementsTable, function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('type');
            $table->integer('priority');
            $table->dateTime('movement_date');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('detail_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('setting_id');
            $table->decimal('quantity', 15, 4);
            $table->string('reference')->nullable();
        });

        \Illuminate\Support\Facades\Schema::create($stagingOrderedTable, function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->integer('priority');
            $table->dateTime('movement_date');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('detail_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('setting_id');
            $table->decimal('quantity', 15, 4);
            $table->string('reference')->nullable();
        });

        \Illuminate\Support\Facades\Schema::create($stagingTransactionsTable, function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('type');
            $table->decimal('quantity', 15, 4);
            $table->decimal('previous_quantity', 15, 4);
            $table->decimal('after_quantity', 15, 4);
            $table->decimal('previous_quantity_at_location', 15, 4);
            $table->decimal('after_quantity_at_location', 15, 4);
            $table->decimal('current_quantity', 15, 4);
            $table->decimal('quantity_non_tax', 15, 4)->default(0);
            $table->decimal('quantity_tax', 15, 4)->default(0);
            $table->decimal('broken_quantity_non_tax', 15, 4)->default(0);
            $table->decimal('broken_quantity_tax', 15, 4)->default(0);
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        try {
            $buyQuery = DB::table('purchase_details as pd')
                ->join('purchases as p', 'pd.purchase_id', '=', 'p.id')
                ->whereNotNull('p.supplier_purchase_number')
                ->whereIn('p.status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])
                ->where('pd.quantity', '>', 0)
                ->selectRaw("
                    'BUY' as type,
                    1 as priority,
                    COALESCE(p.date, p.created_at) as movement_date,
                    p.id as document_id,
                    pd.id as detail_id,
                    pd.product_id,
                    p.setting_id,
                    pd.quantity,
                    p.reference as reference
                ");

            DB::table($stagingMovementsTable)->insertUsing(
                ['type', 'priority', 'movement_date', 'document_id', 'detail_id', 'product_id', 'setting_id', 'quantity', 'reference'],
                $buyQuery
            );

            $sellQuery = DB::table('sale_details as sd')
                ->join('sales as s', 'sd.sale_id', '=', 's.id')
                ->whereNotNull('s.imported_sales_reference_number')
                ->whereIn('s.status', [Sale::STATUS_DISPATCHED, Sale::STATUS_DISPATCHED_PARTIALLY])
                ->where('sd.quantity', '>', 0)
                ->selectRaw("
                    'SELL' as type,
                    2 as priority,
                    COALESCE(s.date, s.created_at) as movement_date,
                    s.id as document_id,
                    sd.id as detail_id,
                    sd.product_id,
                    s.setting_id,
                    (-1 * sd.quantity) as quantity,
                    s.reference as reference
                ");

            DB::table($stagingMovementsTable)->insertUsing(
                ['type', 'priority', 'movement_date', 'document_id', 'detail_id', 'product_id', 'setting_id', 'quantity', 'reference'],
                $sellQuery
            );

            $orderedSelect = DB::table($stagingMovementsTable)
                ->select(['type', 'priority', 'movement_date', 'document_id', 'detail_id', 'product_id', 'setting_id', 'quantity', 'reference'])
                ->orderBy('movement_date')
                ->orderBy('priority')
                ->orderBy('document_id')
                ->orderBy('detail_id');

            DB::table($stagingOrderedTable)->insertUsing(
                ['type', 'priority', 'movement_date', 'document_id', 'detail_id', 'product_id', 'setting_id', 'quantity', 'reference'],
                $orderedSelect
            );

            $runningLedger = []; 

            DB::table($stagingOrderedTable)->orderBy('id')->chunkById(500, function ($movements) use (
                &$runningLedger, &$buyCount, &$sellCount, &$skippedCount, 
                $settingLocations, $isDryRun, $stagingTransactionsTable
            ) {
                $transactionsToInsert = [];

                foreach ($movements as $movement) {
                    $locationId = $settingLocations[$movement->setting_id] ?? null;
                    if (!$locationId) {
                        $skippedCount++;
                        continue;
                    }

                    if ($movement->type === 'BUY') {
                        $buyCount++;
                    } else {
                        $sellCount++;
                    }

                    if ($isDryRun) {
                        continue;
                    }

                    $globalKey = $movement->product_id . '_' . $movement->setting_id;
                    $locKey = $movement->product_id . '_' . $movement->setting_id . '_' . $locationId;

                    if (!isset($runningLedger[$globalKey])) {
                        $runningLedger[$globalKey] = 0.0;
                    }
                    if (!isset($runningLedger[$locKey])) {
                        $runningLedger[$locKey] = 0.0;
                    }

                    $previousGlobal = $runningLedger[$globalKey];
                    $previousLoc = $runningLedger[$locKey];

                    $qty = (float)$movement->quantity;
                    $afterGlobal = $previousGlobal + $qty;
                    $afterLoc = $previousLoc + $qty;

                    $runningLedger[$globalKey] = $afterGlobal;
                    $runningLedger[$locKey] = $afterLoc;

                    $date = \Carbon\Carbon::parse($movement->movement_date)->format('Y-m-d H:i:s');
                    
                    $referenceLabel = $movement->type === 'BUY' 
                        ? 'Imported Purchase: ' . $movement->reference 
                        : 'Imported Sale: ' . $movement->reference;

                    $transactionsToInsert[] = [
                        'product_id' => $movement->product_id,
                        'setting_id' => $movement->setting_id,
                        'location_id' => $locationId,
                        'type' => $movement->type,
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
                        'reason' => $referenceLabel,
                        'user_id' => null, 
                        'created_at' => $date,
                        'updated_at' => $date,
                    ];
                }

                if (!$isDryRun && count($transactionsToInsert) > 0) {
                    DB::table($stagingTransactionsTable)->insert($transactionsToInsert);
                }
            });

            if (!$isDryRun) {
                $this->warn("DESTRUCTIVE INITIALIZATION: Truncating transactions...");
                DB::table('transactions')->truncate();

                $columns = [
                    'product_id', 'setting_id', 'location_id', 'type', 'quantity', 
                    'previous_quantity', 'after_quantity', 'previous_quantity_at_location', 
                    'after_quantity_at_location', 'current_quantity', 'quantity_non_tax', 
                    'quantity_tax', 'broken_quantity_non_tax', 'broken_quantity_tax', 
                    'reason', 'user_id', 'created_at', 'updated_at'
                ];

                $selectQuery = DB::table($stagingTransactionsTable)->select($columns);
                DB::table('transactions')->insertUsing($columns, $selectQuery);
            }

        } finally {
            \Illuminate\Support\Facades\Schema::dropIfExists($stagingMovementsTable);
            \Illuminate\Support\Facades\Schema::dropIfExists($stagingOrderedTable);
            \Illuminate\Support\Facades\Schema::dropIfExists($stagingTransactionsTable);
        }

        if (!$isDryRun) {
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

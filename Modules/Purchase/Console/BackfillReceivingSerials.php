<?php

namespace Modules\Purchase\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Carbon\Carbon;

class BackfillReceivingSerials extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase:backfill-receiving-serials';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill received_note_detail_serial_numbers pivot from history and legacy FK.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting backfill of receiving serials...');

        // 1. Backfill from SerialNumberHistory
        $this->info('Phase 1: Backfilling from SerialNumberHistory...');
        $histories = SerialNumberHistory::where('event_type', SerialNumberHistory::EVENT_RECEIVED)
            ->where('reference_type', ReceivedNoteDetail::class)
            ->get();

        $countHistory = 0;
        foreach ($histories as $history) {
            // Check if SN and RND exist
            $exists = DB::table('received_note_detail_serial_numbers')
                ->where('received_note_detail_id', $history->reference_id)
                ->where('product_serial_number_id', $history->product_serial_number_id)
                ->exists();

            if (!$exists) {
                // Verify IDs exist to avoid FK violation
                $rndExists = DB::table('received_note_details')->where('id', $history->reference_id)->exists();
                $snExists = DB::table('product_serial_numbers')->where('id', $history->product_serial_number_id)->exists();

                if ($rndExists && $snExists) {
                    DB::table('received_note_detail_serial_numbers')->insert([
                        'received_note_detail_id' => $history->reference_id,
                        'product_serial_number_id' => $history->product_serial_number_id,
                        'source_history_id' => $history->id,
                        'linked_at' => $history->created_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $countHistory++;
                }
            }
        }
        $this->info("Backfilled {$countHistory} entries from history.");

        // 2. Backfill from legacy FK (fallback)
        $this->info('Phase 2: Backfilling from legacy FK...');
        $serials = ProductSerialNumber::whereNotNull('received_note_detail_id')->get();
        $countLegacy = 0;

        foreach ($serials as $serial) {
            $exists = DB::table('received_note_detail_serial_numbers')
                ->where('received_note_detail_id', $serial->received_note_detail_id)
                ->where('product_serial_number_id', $serial->id)
                ->exists();

            if (!$exists) {
                // Verify RND exists
                $rndExists = DB::table('received_note_details')->where('id', $serial->received_note_detail_id)->exists();

                if ($rndExists) {
                    DB::table('received_note_detail_serial_numbers')->insert([
                        'received_note_detail_id' => $serial->received_note_detail_id,
                        'product_serial_number_id' => $serial->id,
                        'source_history_id' => null, // No specific history for this fallback
                        'linked_at' => $serial->created_at, // Approximate
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $countLegacy++;
                }
            }
        }
        $this->info("Backfilled {$countLegacy} entries from legacy FK.");

        $this->info('Backfill completed successfully.');
    }
}

<?php

namespace Modules\PurchasesReturn\Console;

use Illuminate\Console\Command;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;

class BackfillPurchaseReturnDetailPoId extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase-return:backfill-po-id {--dry-run : Only show what would be updated}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill null po_id in purchase_return_details for serial-tracked items using existing M:M lineage';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Starting backfill for purchase_return_details.po_id');

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in DRY RUN mode. No database changes will be made.');
        }

        $query = PurchaseReturnDetail::whereNull('po_id')
            ->whereNotNull('serial_number_ids')
            ->where('serial_number_ids', '!=', '[]');

        $totalCount = $query->count();
        $this->info("Found {$totalCount} details to process.");

        $updatedCount = 0;
        $skippedAmbiguousCount = 0;
        $skippedNoCandidateCount = 0;

        $details = $query->get();

        foreach ($details as $detail) {
            $serialIds = $detail->serial_number_ids ?? [];
            if (empty($serialIds)) {
                $skippedNoCandidateCount++;
                continue;
            }

            $candidatePurchaseIds = [];

            foreach ($serialIds as $serialId) {
                $serial = ProductSerialNumber::find($serialId);
                if ($serial) {
                    $purchaseId = $serial->resolveCurrentPurchaseId();
                    if ($purchaseId) {
                        $candidatePurchaseIds[] = $purchaseId;
                    }
                }
            }

            $uniquePurchaseIds = array_unique($candidatePurchaseIds);

            if (empty($uniquePurchaseIds)) {
                $this->warn("Detail ID {$detail->id}: Skipped, no candidate purchase ID found.");
                $skippedNoCandidateCount++;
            } elseif (count($uniquePurchaseIds) === 1) {
                $resolvedPurchaseId = array_values($uniquePurchaseIds)[0];
                if (!$dryRun) {
                    $detail->update(['po_id' => $resolvedPurchaseId]);
                }
                $this->info("Detail ID {$detail->id}: Resolved po_id to {$resolvedPurchaseId}.");
                $updatedCount++;
            } else {
                $this->error("Detail ID {$detail->id}: Skipped, ambiguous purchase IDs (" . implode(', ', $uniquePurchaseIds) . ").");
                $skippedAmbiguousCount++;
            }
        }

        $this->line('');
        $this->info('Backfill completed.');
        $this->info("Total Processed: {$totalCount}");
        $this->info("Updated: {$updatedCount}");
        $this->warn("Skipped (Ambiguous): {$skippedAmbiguousCount}");
        $this->warn("Skipped (No Candidate): {$skippedNoCandidateCount}");
    }
}

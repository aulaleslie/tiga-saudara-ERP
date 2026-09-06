<?php

namespace Modules\Purchase\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixManualUnitPriceDesync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase:fix-manual-unit-price-desync
                            {--dry-run : Preview changes without writing to database}
                            {--detail-id=* : Specific purchase detail ID(s) to fix}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Correct unit_price and price on purchase_details where manual unit price edit left unit_price desynced from sub_total';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $specificDetailIds = array_map('intval', (array) $this->option('detail-id'));

        $this->info($isDryRun ? 'Running in DRY-RUN mode (no changes will be written)...' : 'Running in LIVE mode...');

        $query = DB::table('purchase_details as pd')
            ->join('purchases as p', 'p.id', '=', 'pd.purchase_id')
            ->where('pd.pricing_source', 'manual_unit_price')
            ->select('pd.*', 'p.is_tax_included', 'p.reference');

        if (! empty($specificDetailIds)) {
            $query->whereIn('pd.id', $specificDetailIds);
        }

        $rows = $query->orderBy('pd.id')->get();

        $affected = [];
        $skipped = [];

        foreach ($rows as $r) {
            $qty = (float) $r->quantity;
            if ($qty <= 0) {
                continue;
            }

            $disc = (float) $r->product_discount_amount;
            $tax = (float) $r->product_tax_amount;
            $subTotal = (float) $r->sub_total;
            $oldUnitPrice = (float) $r->unit_price;

            $subTotalBeforeTax = $r->is_tax_included ? ($subTotal - $tax) : $subTotal;
            $rawExpectedUnitPrice = ($subTotalBeforeTax / $qty) + $disc;
            $correctedUnitPrice = round($rawExpectedUnitPrice, 6);
            $factor = (float) ($r->conversion_factor ?? 1.0);
            if ($factor <= 0) {
                $factor = 1.0;
            }
            $expectedEnteredUnitPrice = round($correctedUnitPrice * $factor, 2);

            $oldEnteredUnitPrice = $r->entered_unit_price !== null ? (float) $r->entered_unit_price : null;

            // Check if there is a material discrepancy beyond rounding tolerance in unit_price or entered_unit_price
            $unitPriceDesynced = abs($correctedUnitPrice - $oldUnitPrice) > 0.01;
            $enteredPriceDesynced = $oldEnteredUnitPrice !== null && abs($expectedEnteredUnitPrice - $oldEnteredUnitPrice) > 0.01;

            if (! $unitPriceDesynced && ! $enteredPriceDesynced) {
                continue;
            }

            // Verify reproduction: recomputing sub_total from correctedUnitPrice must match stored sub_total within tolerance (0.02)
            $reproducedSubTotal = $r->is_tax_included
                ? round((($correctedUnitPrice - $disc) * $qty) + $tax, 2)
                : round(($correctedUnitPrice - $disc) * $qty, 2);

            if (abs($reproducedSubTotal - $subTotal) > 0.02) {
                $skipped[] = [
                    'detail_id' => $r->id,
                    'purchase_id' => $r->purchase_id,
                    'reason' => "Reproduction subtotal ($reproducedSubTotal) differs from stored ($subTotal) by > 0.02",
                ];
                continue;
            }

            $affected[] = [
                'detail_id' => $r->id,
                'purchase_id' => $r->purchase_id,
                'reference' => $r->reference,
                'product_name' => $r->product_name,
                'qty' => $qty,
                'old_unit_price' => $oldUnitPrice,
                'new_unit_price' => $correctedUnitPrice,
                'sub_total' => $subTotal,
            ];
        }

        if (empty($affected)) {
            $this->info('No desynced manual unit price rows found.');
            return Command::SUCCESS;
        }

        $this->table(
            ['Purchase ID', 'Ref', 'Detail ID', 'Product', 'Qty', 'Old Unit Price', 'New Unit Price', 'Sub Total'],
            array_map(function ($row) {
                return [
                    $row['purchase_id'],
                    $row['reference'],
                    $row['detail_id'],
                    \Illuminate\Support\Str::limit($row['product_name'], 25),
                    $row['qty'],
                    number_format($row['old_unit_price'], 2),
                    number_format($row['new_unit_price'], 2),
                    number_format($row['sub_total'], 2),
                ];
            }, $affected)
        );

        if (! empty($skipped)) {
            $this->warn('The following rows were SKIPPED because reproduction check failed:');
            $this->table(['Detail ID', 'Purchase ID', 'Reason'], $skipped);
        }

        $this->info(sprintf('Total affected rows identified: %d', count($affected)));

        if ($isDryRun) {
            $this->info('DRY-RUN completed. No changes written.');
            return Command::SUCCESS;
        }

        $this->info('Writing changes to database inside a transaction...');

        DB::transaction(function () use ($affected) {
            foreach ($affected as $row) {
                $detail = DB::table('purchase_details')->where('id', $row['detail_id'])->first();
                $updateData = [
                    'unit_price' => number_format($row['new_unit_price'], 6, '.', ''),
                    'price' => number_format($row['new_unit_price'], 6, '.', ''),
                    'updated_at' => now(),
                ];

                // If entered_unit_price was populated, update it to match the new entered unit price
                if ($detail && $detail->entered_unit_price !== null) {
                    $factor = (float) ($detail->conversion_factor ?? 1.0);
                    if ($factor <= 0) {
                        $factor = 1.0;
                    }
                    $newEnteredUnitPrice = $row['new_unit_price'] * $factor;
                    $updateData['entered_unit_price'] = number_format($newEnteredUnitPrice, 2, '.', '');
                }

                DB::table('purchase_details')
                    ->where('id', $row['detail_id'])
                    ->update($updateData);
            }
        });

        $this->info(sprintf('Successfully updated %d purchase detail rows.', count($affected)));

        return Command::SUCCESS;
    }
}

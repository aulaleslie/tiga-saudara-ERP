<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Services\PurchaseTaxInclusionResolver;

class RepairPurchaseTaxInclusion extends Command
{
    protected $signature = 'purchases:repair-tax-inclusion
        {--apply : Persist changes instead of dry-run}
        {--purchase-id=* : Limit to specific purchase IDs}
        {--setting-id=* : Limit to specific setting IDs}
        {--chunk=500 : Chunk size for scanning}';

    protected $description = 'Detect and optionally repair inconsistent purchases.is_tax_included values from line-item tax math';

    public function handle(PurchaseTaxInclusionResolver $resolver): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) $this->option('chunk'));
        $purchaseIds = $this->sanitizeIds($this->option('purchase-id'));
        $settingIds = $this->sanitizeIds($this->option('setting-id'));

        $stats = [
            'scanned' => 0,
            'candidates' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_ambiguous' => 0,
            'skipped_no_inferable' => 0,
            'skipped_other' => 0,
        ];

        $this->line(sprintf(
            'Mode: %s | chunk=%d%s%s',
            $apply ? 'apply' : 'dry-run',
            $chunk,
            $purchaseIds ? ' | purchase_ids='.implode(',', $purchaseIds) : '',
            $settingIds ? ' | setting_ids='.implode(',', $settingIds) : ''
        ));

        $query = Purchase::withArchived()
            ->with(['purchaseDetails.tax'])
            ->orderBy('id');

        if ($purchaseIds !== []) {
            $query->whereIn('id', $purchaseIds);
        }

        if ($settingIds !== []) {
            $query->whereIn('setting_id', $settingIds);
        }

        $query->chunkById($chunk, function ($purchases) use ($resolver, $apply, &$stats) {
            foreach ($purchases as $purchase) {
                $stats['scanned']++;

                $resolution = $resolver->resolveForDuplicate($purchase);
                $stored = (bool) $purchase->is_tax_included;
                $inferred = $resolution['inferred'];
                $reason = (string) $resolution['reason'];

                if ($inferred === null) {
                    if ($reason === 'ambiguous_keep_stored') {
                        $stats['skipped_ambiguous']++;
                    } elseif ($reason === 'no_inferable_lines_keep_stored') {
                        $stats['skipped_no_inferable']++;
                    } else {
                        $stats['skipped_other']++;
                    }

                    $this->line(sprintf(
                        'SKIP  purchase#%d stored=%d inferred=null reason=%s',
                        $purchase->id,
                        $stored ? 1 : 0,
                        $reason
                    ));
                    continue;
                }

                if ($inferred === $stored) {
                    $stats['unchanged']++;
                    continue;
                }

                $stats['candidates']++;

                if (! $apply) {
                    $this->line(sprintf(
                        'CAND  purchase#%d stored=%d inferred=%d reason=%s',
                        $purchase->id,
                        $stored ? 1 : 0,
                        $inferred ? 1 : 0,
                        $reason
                    ));
                    continue;
                }

                $purchase->is_tax_included = (bool) $inferred;
                $purchase->saveQuietly();
                $stats['updated']++;

                $this->line(sprintf(
                    'FIXED purchase#%d %d=>%d',
                    $purchase->id,
                    $stored ? 1 : 0,
                    $inferred ? 1 : 0
                ));
            }
        }, 'id');

        $this->newLine();
        $this->info('Summary');
        $this->line('Scanned: '.$stats['scanned']);
        $this->line('Candidates: '.$stats['candidates']);
        $this->line('Updated: '.$stats['updated']);
        $this->line('Unchanged: '.$stats['unchanged']);
        $this->line('Skipped ambiguous: '.$stats['skipped_ambiguous']);
        $this->line('Skipped no inferable: '.$stats['skipped_no_inferable']);
        $this->line('Skipped other: '.$stats['skipped_other']);

        return self::SUCCESS;
    }

    /**
     * @param  mixed  $raw
     * @return array<int, int>
     */
    private function sanitizeIds($raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];

        return array_values(array_unique(array_filter(array_map(static function ($value) {
            if ($value === null || $value === '') {
                return null;
            }

            return max(0, (int) $value);
        }, $values))));
    }
}

<?php

namespace Modules\Pos\Console;

use Illuminate\Console\Command;
use Modules\Pos\Services\PosReturnReplacementStockRepairService;

class PosRepairReturnReplacementStockBucketsCommand extends Command
{
    protected $signature = 'pos:repair-return-replacement-stock-buckets
        {--apply : Persist the corrections instead of previewing them}
        {--product= : Restrict candidates to a single product ID}
        {--location= : Restrict candidates to a single location ID}
        {--actor= : User ID attributed to the corrective audit records}';

    protected $description = 'Dry-run-first, evidence-gated repair of stock-bucket drift caused by POS Return replacement dispatch';

    public function handle(PosReturnReplacementStockRepairService $service): int
    {
        $apply = (bool) $this->option('apply');

        $filters = [
            'product_id' => $this->option('product') !== null ? (int) $this->option('product') : null,
            'location_id' => $this->option('location') !== null ? (int) $this->option('location') : null,
        ];

        $actorId = $this->option('actor') !== null ? (int) $this->option('actor') : null;

        try {
            $results = $apply
                ? $service->apply($filters, $actorId)
                : $service->discover($filters);
        } catch (\Throwable $e) {
            $this->error('Repair failed: ' . $e->getMessage());

            return 1;
        }

        if ($results === []) {
            $this->info('No POS Return replacement stock-bucket candidates found.');

            return 0;
        }

        $this->line($apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (no data changed)');
        $this->newLine();

        foreach ($results as $candidate) {
            $this->renderCandidate($candidate, $apply);
        }

        $counts = array_count_values(array_column($results, 'classification'));

        $this->newLine();
        $this->line('Summary:');
        foreach ($counts as $classification => $count) {
            $this->line(sprintf('  %-18s %d', $classification, $count));
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Re-run with --apply to persist the corrections above.');
        }

        $hasConflict = ($counts[PosReturnReplacementStockRepairService::CLASSIFICATION_CONFLICT] ?? 0) > 0;

        return $hasConflict ? 2 : 0;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    protected function renderCandidate(array $candidate, bool $apply): void
    {
        $this->line(sprintf(
            '[%s] product %d (%s) @ location %d, owner setting %d, bucket %s',
            strtoupper($candidate['classification']),
            $candidate['product_id'],
            $candidate['product_name'] ?? '-',
            $candidate['location_id'],
            $candidate['setting_id'],
            $candidate['bucket']
        ));

        if (! empty($candidate['reason'])) {
            $this->line('  reason: ' . $candidate['reason']);
        }

        $this->table(
            ['field', 'current', 'expected'],
            array_map(
                fn (string $field) => [
                    $field,
                    $candidate['current'][$field] ?? 0,
                    $candidate['expected'][$field] ?? 0,
                ],
                ['quantity', 'quantity_tax', 'quantity_non_tax', 'broken_quantity', 'broken_quantity_tax', 'broken_quantity_non_tax']
            )
        );

        $this->line(sprintf(
            '  aggregate delta: %d, bucket delta: %d, global product quantity: %d, sellable serials: %d',
            $candidate['aggregate_delta'],
            $candidate['bucket_delta'],
            $candidate['global_quantity'],
            $candidate['sellable_serial_count']
        ));

        $this->line('  POS Returns: ' . $this->joinIds($candidate['pos_return_ids']));
        $this->line('  Sale Returns: ' . $this->joinIds($candidate['sale_return_ids']));
        $this->line('  Dispatch details: ' . $this->joinIds($candidate['dispatch_detail_ids']));
        $this->line('  Transactions: ' . $this->joinIds($candidate['transaction_ids']));
        $this->line('  Returned serials: ' . $this->joinIds($candidate['returned_serial_ids']));
        $this->line('  Replacement serials: ' . $this->joinIds($candidate['replacement_serial_ids']));

        if ($apply && ! empty($candidate['corrective_transaction_id'])) {
            $this->line('  corrective transaction: #' . $candidate['corrective_transaction_id']);
        }

        $this->newLine();
    }

    /**
     * @param  array<int, int>  $ids
     */
    protected function joinIds(array $ids): string
    {
        return $ids === [] ? '-' : implode(', ', $ids);
    }
}

<?php

namespace Modules\Pos\Console;

use Illuminate\Console\Command;
use Modules\Pos\Services\PosHistoricalBundleCorrectionService;

class PosRepairHistoricalBundleOmissionCommand extends Command
{
    protected $signature = 'pos:repair-historical-bundle-omission
        {transaction=TNC-TXN-2026-10-0638 : The transaction code to inspect or correct}
        {--apply : Persist the correction instead of dry-run preview}
        {--actor= : User ID attributed to the corrective audit records}
        {--reason= : Reason for applying the administrative correction}
        {--confirm-physical-return : Explicit confirmation that the missing component was physically returned}';

    protected $description = 'Dry-run-first, evidence-gated administrative repair of omitted bundle component for incident TNC-TXN-2026-10-0638';

    public function handle(PosHistoricalBundleCorrectionService $service): int
    {
        $transaction = (string) $this->argument('transaction');
        $apply = (bool) $this->option('apply');
        $confirmPhysicalReturn = (bool) $this->option('confirm-physical-return');

        $actorOption = $this->option('actor');
        $reasonOption = $this->option('reason');

        if ($apply) {
            if ($actorOption === null || trim((string) $actorOption) === '' || ! is_numeric($actorOption) || (int) $actorOption <= 0) {
                $this->error('Correction aborted: An authorized actor ID must be explicitly specified with --actor=<id> when applying.');

                return 1;
            }

            if ($reasonOption === null || trim((string) $reasonOption) === '') {
                $this->error('Correction aborted: A specific audit reason must be explicitly specified with --reason="<text>" when applying.');

                return 1;
            }

            if (! $confirmPhysicalReturn) {
                $this->error('Correction aborted: Physical return of the missing component must be explicitly confirmed with --confirm-physical-return.');
                $this->line('Prerequisites for apply:');
                $this->line('  1. Physical confirmation that the omitted component was returned.');
                $this->line('  2. Re-run with: php artisan pos:repair-historical-bundle-omission ' . $transaction . ' --apply --confirm-physical-return --actor=' . (int) $actorOption . ' --reason="' . addslashes((string) $reasonOption) . '"');

                return 1;
            }
        }

        $actorId = $actorOption !== null && is_numeric($actorOption) ? (int) $actorOption : null;
        $reason = $reasonOption !== null && trim((string) $reasonOption) !== '' ? trim((string) $reasonOption) : null;

        try {
            $candidate = $apply
                ? $service->apply($transaction, (int) $actorId, (string) $reason, $confirmPhysicalReturn)
                : $service->discover($transaction);
        } catch (\Throwable $e) {
            $this->error('Correction failed: ' . $e->getMessage());

            return 1;
        }

        $this->line($apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (no data changed)');
        $this->newLine();

        $this->renderCandidate($candidate, $apply);

        if (! empty($candidate['classification'])) {
            $classification = $candidate['classification'];
            if ($classification === PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT
                || $classification === PosHistoricalBundleCorrectionService::CLASSIFICATION_AMBIGUOUS) {
                return 2;
            }
        }

        if (! $apply && ($candidate['classification'] ?? '') === PosHistoricalBundleCorrectionService::CLASSIFICATION_CORRECTABLE) {
            $this->newLine();
            $this->comment('To apply this correction, re-run with:');
            $this->line(sprintf(
                '  php artisan pos:repair-historical-bundle-omission %s --apply --confirm-physical-return --actor=<user-id> --reason="<audit-reason>"',
                $transaction
            ));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    protected function renderCandidate(array $candidate, bool $apply): void
    {
        $classification = strtoupper($candidate['classification'] ?? 'UNKNOWN');
        $this->line(sprintf('Status: [%s]', $classification));

        if (! empty($candidate['reason'])) {
            $this->line('Details: ' . $candidate['reason']);
        }

        if (($candidate['classification'] ?? '') === PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT
            || ($candidate['classification'] ?? '') === PosHistoricalBundleCorrectionService::CLASSIFICATION_AMBIGUOUS) {
            return;
        }

        $this->newLine();
        $this->line('Source Evidence Lineage:');
        $this->line(sprintf('  Transaction: %s (ID: %d)', $candidate['transaction_code'] ?? '-', $candidate['pos_transaction_id'] ?? 0));
        $this->line(sprintf('  POS Return: #%d (%s)', $candidate['pos_return_id'] ?? 0, $candidate['pos_return_reference'] ?? '-'));
        $this->line(sprintf('  Parent Sale Return: #%d (Sale ID: %d)', $candidate['parent_sale_return_id'] ?? 0, $candidate['parent_sale_id'] ?? 0));
        $this->line(sprintf('  Component Sale: #%d (%s)', $candidate['component_sale_id'] ?? 0, $candidate['component_sale_reference'] ?? '-'));
        $this->line(sprintf('  Carrier Sale Detail: #%d', $candidate['component_carrier_sale_detail_id'] ?? 0));
        $this->line(sprintf('  Sale Bundle Item: #%d', $candidate['component_sale_bundle_item_id'] ?? 0));
        $this->line(sprintf('  Dispatch Detail: #%d', $candidate['component_dispatch_detail_id'] ?? 0));

        $this->newLine();
        $this->line('Missing Component Information:');
        $this->line(sprintf('  Product: #%d - %s (%s)', $candidate['product_id'] ?? 0, $candidate['product_name'] ?? '-', $candidate['product_code'] ?? '-'));
        $this->line(sprintf('  Location: #%d | Owner Setting: #%d | Stock Bucket: %s', $candidate['location_id'] ?? 0, $candidate['setting_id'] ?? 0, $candidate['stock_bucket'] ?? '-'));
        $this->line(sprintf('  Quantity: %d | Unit Price: Rp %s | Subtotal: Rp %s', $candidate['quantity'] ?? 0, number_format($candidate['unit_price'] ?? 0, 2), number_format($candidate['sub_total'] ?? 0, 2)));
        $this->line(sprintf('  Unit Cost: Rp %s | Total Cost: Rp %s', number_format($candidate['unit_cost'] ?? 0, 2), number_format($candidate['total_cost'] ?? 0, 2)));

        $this->newLine();
        $this->line('Commercial & Monetary Effects:');
        $this->line(sprintf('  Debt Reduction: Rp %s', number_format($candidate['debt_reduction'] ?? 0, 2)));
        $this->line(sprintf('  Cash Refund: Rp %s', number_format($candidate['cash_refund'] ?? 0, 2)));

        $this->newLine();
        $this->line('Stock Impact Comparison:');
        $this->table(
            ['Metric', 'Current', 'Expected After Correction'],
            [
                ['Global Product Quantity', $candidate['current_stock']['global_quantity'] ?? 0, $candidate['expected_stock']['global_quantity'] ?? 0],
                ['Location Stock Quantity', $candidate['current_stock']['location_quantity'] ?? 0, $candidate['expected_stock']['location_quantity'] ?? 0],
                ['Bucket (' . ($candidate['stock_bucket'] ?? 'quantity') . ')', $candidate['current_stock']['bucket_quantity'] ?? 0, $candidate['expected_stock']['bucket_quantity'] ?? 0],
            ]
        );

        $this->newLine();
        $this->line('Dispatch Detail Comparison:');
        $this->table(
            ['Metric', 'Current', 'Expected After Correction'],
            [
                ['Dispatched Quantity', $candidate['current_dispatch']['dispatched_quantity'] ?? 0, $candidate['expected_dispatch']['dispatched_quantity'] ?? 0],
            ]
        );

        $this->newLine();
        $this->line('Component Sale Comparison:');
        $this->table(
            ['Field', 'Current', 'Expected After Correction'],
            [
                ['Total Amount', 'Rp ' . number_format($candidate['current_sale']['total_amount'] ?? 0, 2), 'Rp ' . number_format($candidate['expected_sale']['total_amount'] ?? 0, 2)],
                ['Paid Amount', 'Rp ' . number_format($candidate['current_sale']['paid_amount'] ?? 0, 2), 'Rp ' . number_format($candidate['expected_sale']['paid_amount'] ?? 0, 2)],
                ['Due Amount', 'Rp ' . number_format($candidate['current_sale']['due_amount'] ?? 0, 2), 'Rp ' . number_format($candidate['expected_sale']['due_amount'] ?? 0, 2)],
                ['Payment Status', $candidate['current_sale']['payment_status'] ?? '-', $candidate['expected_sale']['payment_status'] ?? '-'],
            ]
        );

        if ($apply && ! empty($candidate['corrective_sale_return_id'])) {
            $this->newLine();
            $this->info(sprintf(
                'Corrective SaleReturn created: #%d (%s)',
                $candidate['corrective_sale_return_id'],
                $candidate['corrective_sale_return_reference'] ?? '-'
            ));
        }
    }
}

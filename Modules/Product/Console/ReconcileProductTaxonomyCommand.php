<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Modules\Product\Services\ProductTaxonomyReconciliationService;
use Throwable;

class ReconcileProductTaxonomyCommand extends Command
{
    protected $signature = 'product:reconcile-taxonomy
        {--execute : Execute the reviewed reconciliation plan}
        {--fingerprint= : Required SHA-256 plan fingerprint from preview when using --execute}
        {--operator= : Required operator identifier when using --execute}
        {--confirm : Explicit confirmation flag required when using --execute}
        {--json : Output machine-readable JSON}
        {--connection= : Optional database connection name}';

    protected $description = 'Preview (read-only) or execute audited deduplication of global product categories and brands.';

    public function handle(ProductTaxonomyReconciliationService $service): int
    {
        $connection = $this->option('connection') ?: null;
        $execute = (bool) $this->option('execute');
        $asJson = (bool) $this->option('json');

        if (!$execute) {
            $plan = $service->buildPlan($connection);

            if ($asJson) {
                $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $plan['valid'] ? self::SUCCESS : self::FAILURE;
            }

            $this->renderPreview($plan);

            return $plan['valid'] ? self::SUCCESS : self::FAILURE;
        }

        $fingerprint = (string) ($this->option('fingerprint') ?? '');
        $operator = (string) ($this->option('operator') ?? '');
        $confirmed = (bool) $this->option('confirm');

        if (!$confirmed || trim($fingerprint) === '' || trim($operator) === '') {
            $this->error('Execution requires --confirm, --fingerprint=<sha256>, and --operator=<name>.');
            return self::FAILURE;
        }

        try {
            $result = $service->execute($fingerprint, $operator, $connection);
        } catch (Throwable $e) {
            if ($asJson) {
                $this->line(json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($result['status'] === 'no_op') {
            $this->info('No remaining reconciliation work. Database is already canonical.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Reconciliation completed (audit #%d, fingerprint %s).',
            $result['audit_id'],
            $result['plan_fingerprint']
        ));
        $this->table(
            ['Metric', 'Count'],
            [
                ['Categories removed', $result['counts']['categories_removed']],
                ['Brands removed', $result['counts']['brands_removed']],
                ['Product category refs updated', $result['counts']['product_category_references_updated']],
                ['Category parent refs updated', $result['counts']['category_parent_references_updated']],
                ['Product brand refs updated', $result['counts']['product_brand_references_updated']],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function renderPreview(array $plan): void
    {
        $summary = $plan['summary'];

        $this->info('Product Taxonomy Reconciliation Preview (Read-Only)');
        $this->line('Plan Fingerprint: ' . $plan['plan_fingerprint']);
        $this->line('Status: ' . ($plan['valid'] ? 'READY' : 'BLOCKED'));
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Categories (before -> after)', $summary['categories_before'] . ' -> ' . $summary['categories_after']],
                ['Category duplicate groups', $summary['category_groups_merged']],
                ['Categories to remove', $summary['categories_removed']],
                ['Categories needing canonical backfill', $summary['categories_needing_backfill']],
                ['Active brands (before -> after)', $summary['brands_before_active'] . ' -> ' . $summary['brands_after_active']],
                ['Deleted brands reported', $summary['brands_deleted']],
                ['Brand duplicate groups', $summary['brand_groups_merged']],
                ['Brands to remove', $summary['brands_removed']],
                ['Brands needing canonical backfill', $summary['brands_needing_backfill']],
                ['Product category refs to remap', $summary['product_category_references_updated']],
                ['Category parent refs to remap', $summary['category_parent_references_updated']],
                ['Product brand refs to remap', $summary['product_brand_references_updated']],
            ]
        );

        if ($plan['categories']['duplicate_groups'] !== []) {
            $this->newLine();
            $this->info('Category Duplicate Groups:');
            $this->table(
                ['Identity Key', 'Survivor ID', 'Survivor Code', 'Survivor Name', 'Redundant IDs', 'Metadata Conflicts'],
                array_map(fn ($g) => [
                    $g['identity_key'],
                    $g['survivor_id'],
                    (string) ($g['survivor_code'] ?? ''),
                    $g['survivor_name'],
                    implode(', ', $g['redundant_ids']),
                    implode(', ', array_keys($g['metadata_conflicts'])),
                ], $plan['categories']['duplicate_groups'])
            );
        }

        if ($plan['brands']['duplicate_groups'] !== []) {
            $this->newLine();
            $this->info('Brand Duplicate Groups:');
            $this->table(
                ['Normalized Name', 'Survivor ID', 'Survivor Name', 'Redundant IDs', 'Metadata Conflicts'],
                array_map(fn ($g) => [
                    $g['normalized_name'],
                    $g['survivor_id'],
                    $g['survivor_name'],
                    implode(', ', $g['redundant_ids']),
                    implode(', ', array_keys($g['metadata_conflicts'])),
                ], $plan['brands']['duplicate_groups'])
            );
        }

        if ($plan['brands']['deleted_brands'] !== []) {
            $this->newLine();
            $this->warn('Soft-Deleted Brands (reported separately; not merged or restored):');
            $this->table(
                ['ID', 'Name', 'Normalized Name', 'Deleted At'],
                array_map(fn ($b) => [$b['id'], $b['name'], $b['normalized_name'], (string) $b['deleted_at']], $plan['brands']['deleted_brands'])
            );
        }

        if ($plan['blockers'] !== []) {
            $this->newLine();
            $this->error('Blockers:');
            foreach ($plan['blockers'] as $blocker) {
                $this->line('  - ' . $blocker);
            }
        }
    }
}

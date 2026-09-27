<?php

namespace App\Console\Commands;

use App\Services\Sequence\DocumentType;
use App\Services\Sequence\SequenceReconciliationService;
use Illuminate\Console\Command;

class BootstrapDocumentSequencesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sequence:bootstrap
                            {--family=all : Document family to bootstrap (purchase, sale, or all)}
                            {--dry-run : Perform dry-run reconciliation without writing to database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze historical Purchase and Sale references and bootstrap document sequence counters.';

    public function handle(SequenceReconciliationService $service): int
    {
        $familyOption = strtolower((string) $this->option('family'));
        $dryRun = (bool) $this->option('dry-run');

        $documentTypes = match ($familyOption) {
            'purchase' => [DocumentType::PURCHASE],
            'sale' => [DocumentType::SALE],
            'all' => [DocumentType::PURCHASE, DocumentType::SALE],
            default => null,
        };

        if ($documentTypes === null) {
            $this->error("Invalid --family option '{$familyOption}'. Allowed values: purchase, sale, all.");
            return self::FAILURE;
        }

        $this->info(sprintf(
            "Running sequence reconciliation for [%s] (mode: %s)...",
            implode(', ', array_map(fn($t) => $t->value, $documentTypes)),
            $dryRun ? 'DRY-RUN' : 'APPLY'
        ));

        $analysis = $service->analyze($documentTypes);

        // 1. Report Malformed References
        if (!empty($analysis['malformed_references'])) {
            $this->warn(sprintf("\nFound %d malformed / unparseable references:", count($analysis['malformed_references'])));
            $this->table(
                ['Type', 'ID', 'Setting ID', 'Reference', 'Reason'],
                array_map(fn($m) => [
                    $m['document_type'],
                    $m['id'],
                    $m['setting_id'] ?? 'NULL',
                    $m['reference'],
                    $m['reason'],
                ], array_slice($analysis['malformed_references'], 0, 50))
            );
            if (count($analysis['malformed_references']) > 50) {
                $this->line(sprintf("... and %d more malformed rows.", count($analysis['malformed_references']) - 50));
            }
        } else {
            $this->info("✓ No malformed references found.");
        }

        // 2. Report Unexpected Prefixes
        if (!empty($analysis['unexpected_prefixes'])) {
            $this->warn(sprintf("\nFound %d namespaces with unexpected prefixes (historical prefix changes):", count($analysis['unexpected_prefixes'])));
            $this->table(
                ['Type', 'Setting ID', 'Historical Prefix', 'Current Setting Prefix', 'Count'],
                array_map(fn($p) => [
                    $p['document_type'],
                    $p['setting_id'],
                    $p['prefix'],
                    $p['expected_prefix'],
                    $p['count'],
                ], $analysis['unexpected_prefixes'])
            );
        }

        // 3. Report Date Drift References
        if (!empty($analysis['date_drift_references'])) {
            $this->warn(sprintf("\nFound %d references with document date != embedded reference period:", count($analysis['date_drift_references'])));
            $this->table(
                ['Type', 'ID', 'Setting ID', 'Reference', 'Document Date', 'Embedded Period'],
                array_map(fn($d) => [
                    $d['document_type'],
                    $d['id'],
                    $d['setting_id'],
                    $d['reference'],
                    $d['document_date'] ?? 'NULL',
                    sprintf('%04d-%02d', $d['embedded_year'], $d['embedded_month']),
                ], array_slice($analysis['date_drift_references'], 0, 20))
            );
            if (count($analysis['date_drift_references']) > 20) {
                $this->line(sprintf("... and %d more date drift rows.", count($analysis['date_drift_references']) - 20));
            }
        }

        // 4. Namespace Counter Table
        $this->info(sprintf("\nDiscovered %d distinct reference namespaces:", count($analysis['namespaces'])));
        $this->table(
            ['Type', 'Setting ID', 'Prefix', 'Period', 'Hist Max', 'Current Counter', 'Target Counter', 'Count'],
            array_map(fn($ns) => [
                $ns['document_type'],
                $ns['setting_id'],
                $ns['prefix'],
                sprintf('%04d-%02d', $ns['year'], $ns['month']),
                $ns['historical_max'],
                $ns['current_counter'],
                $ns['target_counter'],
                $ns['count'],
            ], $analysis['namespaces'])
        );

        // Execute Bootstrap
        $result = $service->bootstrap($documentTypes, $dryRun);

        $this->newLine();
        $this->info(sprintf(
            "Bootstrap Summary: %d created, %d advanced, %d unchanged.",
            $result['created_count'],
            $result['advanced_count'],
            $result['unchanged_count']
        ));

        if ($dryRun) {
            $this->warn("Dry run complete. No database changes were applied.");
        } else {
            $this->info("Bootstrap complete. Sequence counters are up to date.");
        }

        return self::SUCCESS;
    }
}

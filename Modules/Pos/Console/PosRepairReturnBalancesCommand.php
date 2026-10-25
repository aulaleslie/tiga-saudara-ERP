<?php

namespace Modules\Pos\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Pos\Services\PosReturnBalanceRepairService;
use Modules\Setting\Entities\Setting;

class PosRepairReturnBalancesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pos:repair-return-balances
        {transactions?* : Optional list of explicit POS transaction IDs or codes}
        {--setting= : The required business setting ID scope}
        {--transaction=* : Repeatable explicit POS transaction ID or code to inspect or repair}
        {--apply : Persist the repair instead of dry-run preview}
        {--actor= : User ID attributing the repair action}
        {--reason= : Non-empty audit reason required when applying}
        {--expected-fingerprint= : Optional evidence fingerprint to verify before apply}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Explicit ID-selected repair tool for POS return header and balance drift';

    public function handle(PosReturnBalanceRepairService $service): int
    {
        $settingOption = $this->option('setting');
        $apply = (bool) $this->option('apply');
        $actorOption = $this->option('actor');
        $reasonOption = $this->option('reason');
        $expectedFingerprint = $this->option('expected-fingerprint');

        // Business scope validation
        if ($settingOption === null || trim((string) $settingOption) === '' || ! is_numeric($settingOption) || (int) $settingOption <= 0) {
            $this->error('A valid business setting ID must be explicitly specified with --setting=<id>.');
            return 1;
        }

        $settingId = (int) $settingOption;
        $setting = Setting::query()->find($settingId);
        if (! $setting) {
            $this->error("Business setting ID {$settingId} does not exist in database.");
            return 1;
        }

        // Transaction inputs collection
        $transactionInputs = array_merge(
            (array) $this->option('transaction'),
            (array) $this->argument('transactions')
        );
        $transactionInputs = array_values(array_filter(array_unique(array_map('trim', $transactionInputs))));

        if (empty($transactionInputs)) {
            $this->error('At least one explicit transaction ID or code must be specified via --transaction=<id>.');
            return 1;
        }

        // Apply mode validations
        $actorId = null;
        $reason = null;
        if ($apply) {
            if ($actorOption === null || trim((string) $actorOption) === '' || ! is_numeric($actorOption) || (int) $actorOption <= 0) {
                $this->error('A valid numeric User ID must be specified via --actor=<id> when applying.');
                return 1;
            }

            $actorId = (int) $actorOption;
            $actorUser = User::query()->find($actorId);
            if (! $actorUser) {
                $this->error("Actor user ID {$actorId} does not exist in database.");
                return 1;
            }

            if ($reasonOption === null || trim((string) $reasonOption) === '') {
                $this->error('A non-empty audit reason must be specified via --reason="<text>" when applying.');
                return 1;
            }

            $reason = trim((string) $reasonOption);
        }

        $this->line(sprintf(
            "POS Return Balance Repair | Business Setting: #%d (%s) | Mode: %s | Candidates: %d",
            $setting->id,
            $setting->company_name ?? 'N/A',
            $apply ? 'APPLY (Audit Persisted)' : 'DRY-RUN PREVIEW (No Changes)',
            count($transactionInputs)
        ));
        $this->newLine();

        $hasErrorOrBlocked = false;

        foreach ($transactionInputs as $trxInput) {
            $this->line(str_repeat('-', 70));
            $this->info("Evaluating POS Transaction: {$trxInput}");

            try {
                if ($apply) {
                    $result = $service->apply(
                        $trxInput,
                        $settingId,
                        $actorId,
                        $reason,
                        $expectedFingerprint
                    );
                } else {
                    $result = $service->plan($trxInput, $settingId);
                }

                $this->renderEvaluationResult($result, $apply);

                if ($result['classification'] === PosReturnBalanceRepairService::CLASSIFICATION_BLOCKED) {
                    $hasErrorOrBlocked = true;
                }
            } catch (\Throwable $e) {
                $this->error("Error processing transaction '{$trxInput}': " . $e->getMessage());
                $hasErrorOrBlocked = true;
            }
        }

        $this->line(str_repeat('=', 70));
        if ($hasErrorOrBlocked) {
            $this->warn('One or more candidates were blocked or encountered errors.');
            return 1;
        }

        $this->info($apply ? 'Repair applied successfully.' : 'Preview completed successfully.');
        return 0;
    }

    /**
     * Render the detailed before/after evaluation result table.
     *
     * @param array<string, mixed> $result
     * @param bool $isApply
     */
    protected function renderEvaluationResult(array $result, bool $isApply): void
    {
        $classification = $result['classification'] ?? 'unknown';
        $badge = match ($classification) {
            PosReturnBalanceRepairService::CLASSIFICATION_REPAIRABLE => '<fg=yellow;options=bold>[REPAIRABLE]</>',
            PosReturnBalanceRepairService::CLASSIFICATION_UNCHANGED => '<fg=green;options=bold>[UNCHANGED]</>',
            PosReturnBalanceRepairService::CLASSIFICATION_REPAIRED => '<fg=green;options=bold>[REPAIRED]</>',
            PosReturnBalanceRepairService::CLASSIFICATION_BLOCKED => '<fg=red;options=bold>[BLOCKED]</>',
            default => "[{$classification}]",
        };

        $this->line("Classification: {$badge}");
        if (! empty($result['reason'])) {
            $this->line("<fg=red>Reason: {$result['reason']}</>");
        }

        if (! empty($result['evidence_fingerprint'])) {
            $this->line("Evidence Fingerprint: {$result['evidence_fingerprint']}");
        }

        if (! empty($result['summary'])) {
            $s = $result['summary'];
            $this->table(
                ['Metric', 'Captured/Stored', 'Current Header', 'Proposed Header'],
                [
                    ['Original Charges', number_format($s['captured_original_total'] ?? 0, 2), '-', '-'],
                    ['Committed Returns', number_format($s['committed_cash_return_total'] ?? 0, 2), '-', '-'],
                    ['Stored Detail Rows', number_format($s['stored_remaining_row_total'] ?? 0, 2), '-', '-'],
                    ['Grand Total', '-', number_format($s['current_grand_total'] ?? 0, 2), number_format($s['proposed_grand_total'] ?? 0, 2)],
                    ['Paid Amount', '-', number_format($s['paid_total'] ?? 0, 2), number_format($s['paid_total'] ?? 0, 2)],
                    ['Due Balance', '-', number_format($s['current_due_total'] ?? 0, 2), number_format($s['proposed_due_total'] ?? 0, 2)],
                ]
            );
        }

        if (! empty($result['sales'])) {
            $this->line('Reachable Sales:');
            $salesRows = [];
            foreach ($result['sales'] as $salePlan) {
                $salesRows[] = [
                    $salePlan['sale_id'],
                    $salePlan['reference'],
                    $salePlan['has_drift'] ? 'YES' : 'NO',
                    number_format($salePlan['drift_amount'] ?? 0, 2),
                    number_format($salePlan['current']['total_amount'], 2),
                    number_format($salePlan['proposed']['total_amount'], 2),
                    number_format($salePlan['current']['paid_amount'], 2),
                    number_format($salePlan['current']['due_amount'], 2),
                    number_format($salePlan['proposed']['due_amount'], 2),
                ];
            }
            $this->table(
                ['Sale #', 'Reference', 'Drift?', 'Drift Amt', 'Current Tot', 'Proposed Tot', 'Paid', 'Current Due', 'Proposed Due'],
                $salesRows
            );
        }

        if (! empty($result['returns'])) {
            $this->line('Completed Cash Returns:');
            $returnRows = [];
            foreach ($result['returns'] as $ret) {
                $returnRows[] = [
                    $ret['id'],
                    $ret['reference'],
                    $ret['return_option'],
                    $ret['status'],
                    number_format($ret['total_amount'], 2),
                ];
            }
            $this->table(['Return #', 'Reference', 'Option', 'Status', 'Total Return'], $returnRows);
        }
    }
}

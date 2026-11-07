<?php

namespace Modules\Pos\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Pos\Services\PosReplacementAccountingRepairService;
use Modules\Setting\Entities\Setting;

class PosRepairReplacementAccountingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pos:repair-replacement-accounting
        {transactions?* : Optional list of explicit POS transaction IDs or codes}
        {--setting= : Required business setting ID scope}
        {--discover : Read-only bounded discovery of candidate cross-owner replacement transactions}
        {--limit=50 : Maximum number of candidates to inspect in discovery mode (1-500)}
        {--transaction=* : Repeatable explicit POS transaction ID or code to preview or repair}
        {--apply : Persist the reviewed repair instead of dry-run preview}
        {--actor= : User ID attributing the repair action (required with --apply)}
        {--reason= : Non-empty audit reason (required with --apply)}
        {--fingerprint= : Reviewed evidence fingerprint to verify before apply}
        {--expected-fingerprint= : Alias for --fingerprint}
        {--json : Output structured JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover, preview, and atomically repair historical cross-owner POS replacement accounting duplication';

    public function handle(PosReplacementAccountingRepairService $service): int
    {
        $settingOption = $this->option('setting');
        $discover = (bool) $this->option('discover');
        $apply = (bool) $this->option('apply');
        $limitOption = $this->option('limit');
        $actorOption = $this->option('actor');
        $reasonOption = $this->option('reason');
        $fingerprintOption = $this->option('fingerprint') ?: $this->option('expected-fingerprint');
        $asJson = (bool) $this->option('json');

        // 1. Validate required business scope
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

        // 2. Reject invalid mode combinations
        if ($discover && $apply) {
            $this->error('Discovery mode (--discover) is strictly read-only and cannot be combined with --apply.');
            return 1;
        }

        $rawInputs = array_merge(
            (array) $this->option('transaction'),
            (array) $this->argument('transactions')
        );
        $trimmedInputs = array_values(array_filter(array_map('trim', $rawInputs), fn ($v) => $v !== ''));

        if ($discover && ! empty($trimmedInputs)) {
            $this->error('Discovery mode (--discover) cannot be combined with explicit --transaction selections.');
            return 1;
        }

        // Reject duplicate selections
        if (count($trimmedInputs) !== count(array_unique($trimmedInputs))) {
            $this->error('Duplicate --transaction selections are not allowed in a single invocation.');
            return 1;
        }

        if ($discover) {
            $limit = is_numeric($limitOption) ? (int) $limitOption : 50;
            if ($limit < 1 || $limit > 500) {
                $this->error('Option --limit must be an integer between 1 and 500.');
                return 1;
            }

            $discovery = $service->discoverCandidates($settingId, $limit);
            if ($asJson) {
                $this->line(json_encode($discovery, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return 0;
            }

            $this->renderDiscoveryResult($setting, $discovery);
            return 0;
        }

        if (empty($trimmedInputs)) {
            $this->error('Specify --discover for read-only candidate discovery or at least one --transaction=<id> for preview/apply.');
            return 1;
        }

        $actorId = null;
        $reason = null;
        $fingerprint = $fingerprintOption !== null ? trim((string) $fingerprintOption) : '';

        if ($apply) {
            if (count($trimmedInputs) > 1 && $fingerprint !== '') {
                // Each checkout group has a distinct evidence fingerprint
                $this->error('When applying with --fingerprint, select one explicit --transaction per invocation.');
                return 1;
            }

            if ($actorOption === null || trim((string) $actorOption) === '' || ! is_numeric($actorOption) || (int) $actorOption <= 0) {
                $this->error('A valid numeric User ID must be specified via --actor=<id> when applying.');
                return 1;
            }

            $actorId = (int) $actorOption;
            if (! User::query()->whereKey($actorId)->exists()) {
                $this->error("Actor user ID {$actorId} does not exist in database.");
                return 1;
            }

            if ($reasonOption === null || trim((string) $reasonOption) === '') {
                $this->error('A non-empty audit reason must be specified via --reason="<text>" when applying.');
                return 1;
            }
            $reason = trim((string) $reasonOption);

            if ($fingerprint === '') {
                $this->error('A reviewed evidence fingerprint must be specified via --fingerprint=<sha256> when applying.');
                return 1;
            }
        }

        if (! $asJson) {
            $this->line(sprintf(
                'POS Replacement Accounting Repair | Business Setting: #%d (%s) | Mode: %s | Selected: %d',
                $setting->id,
                $setting->company_name ?? 'N/A',
                $apply ? 'APPLY (Audited)' : 'PREVIEW (Read-Only)',
                count($trimmedInputs)
            ));
            $this->newLine();
        }

        $results = [];
        $hasBlockedOrError = false;

        foreach ($trimmedInputs as $trxInput) {
            try {
                if ($apply) {
                    $res = $service->apply($trxInput, $settingId, $actorId, $reason, $fingerprint);
                } else {
                    $res = $service->plan($trxInput, $settingId);
                }

                $results[] = $res;

                if (($res['classification'] ?? '') === PosReplacementAccountingRepairService::CLASSIFICATION_BLOCKED) {
                    $hasBlockedOrError = true;
                }

                if (! $asJson) {
                    $this->renderEvaluationResult($res);
                }
            } catch (\Throwable $e) {
                $hasBlockedOrError = true;
                $errorPayload = [
                    'transaction_input' => $trxInput,
                    'classification' => PosReplacementAccountingRepairService::CLASSIFICATION_BLOCKED,
                    'error' => $e->getMessage(),
                ];
                $results[] = $errorPayload;

                if (! $asJson) {
                    $this->error("Error processing transaction '{$trxInput}': " . $e->getMessage());
                }
            }
        }

        if ($asJson) {
            $this->line(json_encode([
                'setting_id' => $settingId,
                'mode' => $apply ? 'apply' : 'preview',
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(str_repeat('=', 80));
            if ($hasBlockedOrError) {
                $this->warn('One or more selected transactions were blocked or failed validation.');
            } else {
                $this->info($apply ? 'Replacement accounting repair completed.' : 'Read-only preview completed.');
            }
        }

        return $hasBlockedOrError ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $discovery
     */
    protected function renderDiscoveryResult(Setting $setting, array $discovery): void
    {
        $this->line(sprintf(
            'POS Replacement Accounting Discovery (READ-ONLY) | Business Setting: #%d (%s) | Discovered: %d (Showing: %d)',
            $setting->id,
            $setting->company_name ?? 'N/A',
            (int) ($discovery['total_discovered'] ?? 0),
            (int) ($discovery['returned_count'] ?? 0)
        ));

        $candidates = $discovery['candidates'] ?? [];
        if (empty($candidates)) {
            $this->info('No cross-owner replacement candidates found in this business scope.');
            return;
        }

        $rows = [];
        foreach ($candidates as $cand) {
            $rows[] = [
                $cand['transaction_id'] ?? '-',
                $cand['transaction_code'] ?? '-',
                $cand['checkout_id'] ?? '-',
                strtoupper((string) ($cand['classification'] ?? 'unknown')),
                number_format((float) ($cand['current_grand_total'] ?? 0), 2),
                number_format((float) ($cand['proposed_grand_total'] ?? 0), 2),
                number_format((float) ($cand['current_effective_paid'] ?? 0), 2),
                number_format((float) ($cand['proposed_effective_paid'] ?? 0), 2),
                substr((string) ($cand['evidence_fingerprint'] ?? ''), 0, 16) . '...',
            ];
        }

        $this->table(
            ['Trx #', 'Code', 'Checkout #', 'Status', 'Curr Total', 'Prop Total', 'Curr Paid', 'Prop Paid', 'Fingerprint'],
            $rows
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function renderEvaluationResult(array $result): void
    {
        $this->line(str_repeat('-', 80));
        $classification = (string) ($result['classification'] ?? 'unknown');
        $this->line(sprintf(
            'Transaction #%s (%s) | Checkout #%s | Classification: [%s]',
            (string) ($result['transaction_id'] ?? 'N/A'),
            (string) ($result['transaction_code'] ?? 'N/A'),
            (string) ($result['checkout_id'] ?? 'N/A'),
            strtoupper($classification)
        ));

        if (! empty($result['reason'])) {
            $this->warn('Reason: ' . $result['reason']);
        }

        if (! empty($result['evidence_fingerprint'])) {
            $this->line('Evidence Fingerprint: ' . $result['evidence_fingerprint']);
        }

        if (! empty($result['summary'])) {
            $s = $result['summary'];
            $this->table(
                ['Metric', 'Current', 'Proposed', 'Duplicated Delta'],
                [
                    [
                        'Grand Total',
                        number_format((float) ($s['current_grand_total'] ?? 0), 2),
                        number_format((float) ($s['proposed_grand_total'] ?? 0), 2),
                        number_format((float) ($s['duplicated_charge_amount'] ?? 0), 2),
                    ],
                    [
                        'Effective Paid',
                        number_format((float) ($s['current_effective_paid'] ?? 0), 2),
                        number_format((float) ($s['proposed_effective_paid'] ?? 0), 2),
                        number_format((float) ($s['duplicated_paid_amount'] ?? 0), 2),
                    ],
                    [
                        'Due Amount',
                        number_format((float) ($s['current_due_total'] ?? 0), 2),
                        number_format((float) ($s['proposed_due_total'] ?? 0), 2),
                        '-',
                    ],
                ]
            );
        }

        if (! empty($result['sales'])) {
            $this->line('Reachable Sales:');
            $saleRows = [];
            foreach ($result['sales'] as $salePlan) {
                $saleRows[] = [
                    $salePlan['sale_id'],
                    $salePlan['setting_id'] . ' (' . $salePlan['setting_name'] . ')',
                    $salePlan['reference'],
                    $salePlan['has_drift'] ? 'YES' : 'NO',
                    number_format((float) ($salePlan['current']['total_amount'] ?? 0), 2),
                    number_format((float) ($salePlan['proposed']['total_amount'] ?? 0), 2),
                    number_format((float) ($salePlan['current']['effective_paid_amount'] ?? 0), 2),
                    number_format((float) ($salePlan['proposed']['effective_paid_amount'] ?? 0), 2),
                    number_format((float) ($salePlan['current']['due_amount'] ?? 0), 2),
                    number_format((float) ($salePlan['proposed']['due_amount'] ?? 0), 2),
                ];
            }
            $this->table(
                ['Sale #', 'Owner Setting', 'Reference', 'Drift?', 'Curr Total', 'Prop Total', 'Curr Eff Paid', 'Prop Eff Paid', 'Curr Due', 'Prop Due'],
                $saleRows
            );
        }

        if (! empty($result['owner_summaries'])) {
            $this->line('Per-Owner Summary:');
            $ownerRows = [];
            foreach ($result['owner_summaries'] as $owner) {
                $ownerRows[] = [
                    $owner['setting_id'],
                    $owner['setting_name'],
                    number_format((float) $owner['current_total'], 2),
                    number_format((float) $owner['proposed_total'], 2),
                    number_format((float) $owner['current_effective_paid'], 2),
                    number_format((float) $owner['proposed_effective_paid'], 2),
                    number_format((float) $owner['current_due'], 2),
                    number_format((float) $owner['proposed_due'], 2),
                ];
            }
            $this->table(
                ['Setting #', 'Owner Name', 'Curr Total', 'Prop Total', 'Curr Eff Paid', 'Prop Eff Paid', 'Curr Due', 'Prop Due'],
                $ownerRows
            );
        }

        if (! empty($result['method_summaries'])) {
            $this->line('Per-Payment-Method Summary:');
            $methodRows = [];
            foreach ($result['method_summaries'] as $m) {
                $methodRows[] = [
                    $m['payment_method'],
                    number_format((float) $m['current_collection_total'], 2),
                    number_format((float) $m['proposed_collection_total'], 2),
                    number_format((float) $m['owner_transfer_allocation_total'], 2),
                ];
            }
            $this->table(
                ['Method', 'Current Active Collections', 'Proposed Active Collections', 'Owner Transfer Allocations'],
                $methodRows
            );
        }

        if (! empty($result['expected_receipt_lines'])) {
            $this->line('Expected Receipt Lines:');
            $lineRows = [];
            foreach ($result['expected_receipt_lines'] as $l) {
                $lineRows[] = [
                    $l['pos_transaction_line_id'],
                    $l['product_name'],
                    $l['qty'],
                    number_format((float) $l['sub_total'], 2),
                ];
            }
            $this->table(['POS Line #', 'Product', 'Qty', 'Expected Subtotal'], $lineRows);
        }
    }
}

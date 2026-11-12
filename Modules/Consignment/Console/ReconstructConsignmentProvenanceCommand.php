<?php

namespace Modules\Consignment\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Consignment\Services\ConsignmentHistoricalReconstructionService;

/**
 * Manual, preview-first historical custody reconstruction (track-transferred-consignment-provenance 7.1).
 *
 *   consignment:reconstruct-provenance --product=ID [--origin=SETTING]                       (preview only, writes nothing)
 *   consignment:reconstruct-provenance --product=ID --confirm --hash=HASH --actor=USER --reason="..." [--choices='{"allocId":{"receiptId":qty}}']
 */
class ReconstructConsignmentProvenanceCommand extends Command
{
    protected $signature = 'consignment:reconstruct-provenance {--product= : Product ID} {--origin= : Original receipt business (setting) ID}
        {--confirm : Apply the previewed mapping} {--hash= : Evidence hash printed by the preview} {--actor= : Confirming user ID}
        {--reason= : Audit reason} {--choices= : JSON explicit non-serialized lot quantities per dispatch allocation}';

    protected $description = 'Preview (default) or confirm reconstruction of consignment receipt custody for pre-provenance transfers.';

    public function handle(ConsignmentHistoricalReconstructionService $svc): int
    {
        $product = (int) $this->option('product');
        if (! $product) {
            $this->error('--product is required.');

            return self::FAILURE;
        }
        $origin = $this->option('origin') ? (int) $this->option('origin') : null;
        $choices = $this->option('choices') ? (json_decode((string) $this->option('choices'), true) ?: []) : [];
        $choices = collect($choices)->mapWithKeys(fn ($v, $k) => [(int) $k => collect($v)->mapWithKeys(fn ($q, $r) => [(int) $r => $q])->all()])->all();

        $p = $svc->preview($product, $choices, $origin);
        $this->table(['Event', 'Receipt', 'Serial', 'Qty', 'From', 'To', 'At'], array_map(fn ($e) => [
            $e['kind'], $e['receipt_id'], $e['serial_id'] ?? '-', $e['quantity'], "{$e['from_state']}@{$e['from_location_id']}", "{$e['to_state']}@{$e['to_location_id']}", $e['at'],
        ], $p['events']));
        foreach ($p['blockers'] as $b) {
            $this->warn("Unresolved: {$b}");
        }
        $this->line("Evidence hash: {$p['evidence_hash']}");

        if (! $this->option('confirm')) {
            $this->info('Preview only. Nothing was written.');

            return self::SUCCESS;
        }
        $actor = User::find((int) $this->option('actor'));
        if (! $actor || ! $this->option('hash') || trim((string) $this->option('reason')) === '') {
            $this->error('--confirm needs --hash, --actor and --reason.');

            return self::FAILURE;
        }
        $mappings = $svc->confirm($product, (string) $this->option('hash'), (string) $this->option('reason'), $actor, $choices, $origin);
        $this->info(count($mappings) . ' mapping revision(s) confirmed. Run consignment:discover-sold-sources to bind remote sales.');

        return self::SUCCESS;
    }
}

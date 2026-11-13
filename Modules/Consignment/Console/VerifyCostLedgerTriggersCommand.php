<?php

namespace Modules\Consignment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deployment gate for the product cost-event ledger (migration 2026_11_10_000004).
 *
 * That migration drops trg_ppce_insert / trg_ppce_update before recreating them, so a failed run can leave
 * product_prices with zero or one trigger, and average-cost writes would then go unrecorded. deploy.bat runs
 * this command before it leaves maintenance mode and keeps the application down when it fails.
 *
 * Read-only: the functional probe runs inside a transaction that is always rolled back.
 */
class VerifyCostLedgerTriggersCommand extends Command
{
    public const REQUIRED_TRIGGERS = ['trg_ppce_insert', 'trg_ppce_update'];

    protected $signature = 'consignment:verify-cost-ledger-triggers';

    protected $description = 'Verify both product cost-ledger triggers are installed and record cost changes (read-only; exits non-zero on failure)';

    public function handle(): int
    {
        if (! Schema::hasTable('product_prices') || ! Schema::hasTable('product_price_cost_events')) {
            return $this->failClosed('product_prices or product_price_cost_events table is missing.');
        }

        $installed = $this->installedTriggers();
        sort($installed);
        $missing = array_values(array_diff(self::REQUIRED_TRIGGERS, $installed));
        $unexpected = array_values(array_filter(
            array_diff($installed, self::REQUIRED_TRIGGERS),
            fn ($name) => str_starts_with($name, 'trg_ppce_')
        ));

        if ($missing !== [] || $unexpected !== []) {
            return $this->failClosed('product_prices triggers found: [' . implode(', ', $installed) . ']; missing: ['
                . implode(', ', $missing) . ']; unexpected: [' . implode(', ', $unexpected) . '].');
        }

        $probe = $this->probe();
        if ($probe !== true) {
            return $this->failClosed($probe);
        }

        $this->info('OK: trg_ppce_insert and trg_ppce_update are installed on product_prices and record cost changes.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function installedTriggers(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'product_prices')
                ->pluck('name')->map(fn ($n) => (string) $n)->all();
        }

        return collect(DB::select(
            'SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
            ['product_prices']
        ))->map(fn ($r) => (string) $r->name)->all();
    }

    /**
     * Inside one transaction that is always rolled back: INSERT a priced row and UPDATE its average, and require each
     * statement to append exactly one ledger row carrying the matching product, setting and old/new averages.
     *
     * @return true|string
     */
    private function probe(): bool|string
    {
        $productId = DB::table('products')->orderBy('id')->value('id');
        $settingId = DB::table('settings')->orderBy('id')->value('id');
        if (! $productId || ! $settingId) {
            $this->warn('No products or settings exist, so nothing can write costs; trigger presence verified, functional probe skipped.');

            return true;
        }

        $result = 'Functional probe did not run.';
        DB::beginTransaction();
        try {
            // Prefer a (product, setting) pair without a price row; otherwise remove one temporarily (rolled back).
            $pair = DB::table('products')->crossJoin('settings')
                ->whereNotExists(fn ($q) => $q->from('product_prices')
                    ->whereColumn('product_prices.product_id', 'products.id')
                    ->whereColumn('product_prices.setting_id', 'settings.id'))
                ->orderBy('products.id')->orderBy('settings.id')
                ->first(['products.id as product_id', 'settings.id as setting_id']);
            if (! $pair) {
                $pair = DB::table('product_prices')->orderBy('id')->first(['product_id', 'setting_id']);
                DB::table('product_prices')->where(['product_id' => $pair->product_id, 'setting_id' => $pair->setting_id])->delete();
            }
            $pid = (int) $pair->product_id;
            $sid = (int) $pair->setting_id;

            $result = $this->expectLedgerRow('INSERT', $pid, $sid, null, 1.23, function () use ($pid, $sid) {
                DB::table('product_prices')->insert(['product_id' => $pid, 'setting_id' => $sid, 'average_purchase_price' => 1.23,
                    'created_at' => now(), 'updated_at' => now()]);
            });
            if ($result === true) {
                $result = $this->expectLedgerRow('UPDATE', $pid, $sid, 1.23, 4.56, function () use ($pid, $sid) {
                    DB::table('product_prices')->where(['product_id' => $pid, 'setting_id' => $sid])->update(['average_purchase_price' => 4.56]);
                });
            }
        } catch (\Throwable $e) {
            $result = 'Functional probe failed: ' . $e->getMessage();
        } finally {
            DB::rollBack();
        }

        return $result;
    }

    private function expectLedgerRow(string $kind, int $pid, int $sid, ?float $old, float $new, callable $write): bool|string
    {
        $lastId = (int) DB::table('product_price_cost_events')->max('id');
        $write();
        $rows = DB::table('product_price_cost_events')->where('id', '>', $lastId)->get();
        if ($rows->count() !== 1) {
            return "Functional probe: an average-cost {$kind} appended {$rows->count()} ledger rows (expected 1).";
        }
        $row = $rows->first();
        $ok = (int) $row->product_id === $pid && (int) $row->setting_id === $sid
            && ($old === null ? $row->old_average === null : ($row->old_average !== null && abs((float) $row->old_average - $old) < 0.001))
            && $row->new_average !== null && abs((float) $row->new_average - $new) < 0.001;

        return $ok ? true : "Functional probe: the {$kind} ledger row has wrong evidence (product {$row->product_id}, setting {$row->setting_id}, "
            . 'old ' . var_export($row->old_average, true) . ', new ' . var_export($row->new_average, true) . ').';
    }

    private function failClosed(string $reason): int
    {
        $this->error('COST-LEDGER TRIGGER VERIFICATION FAILED: ' . $reason);
        $this->line('Keep maintenance mode ON and keep every cost writer stopped (queue workers, scheduler, imports, manual SQL).');
        $this->line('Recovery: fix the cause (for MySQL error 1419 grant SUPER/SET_USER_ID or set log_bin_trust_function_creators=1),');
        $this->line('re-run "php artisan migrate --force" (migration 2026_11_10_000004 is safe to re-run), then re-run this command.');
        $this->line('See openspec/changes/buy-out-unsold-consignment-stock/uat-and-deployment.md.');

        return self::FAILURE;
    }
}

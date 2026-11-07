<?php

namespace Modules\SalesReturn\Console;

use App\Support\SalesReturn\SaleReturnAuditService;
use Illuminate\Console\Command;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;

/**
 * Read-only evidence audit of standard Sales Returns. There is deliberately NO apply / repair /
 * fix option: it only reads, takes no locks and writes nothing. Archived records are included.
 *
 *   php artisan sale-returns:audit --setting=1
 *   php artisan sale-returns:audit --setting=1 --sale=42 --json
 *   php artisan sale-returns:audit --setting=1 --return=7
 */
class SaleReturnsAuditCommand extends Command
{
    protected $signature = 'sale-returns:audit
        {--setting= : Business (setting) id to audit. REQUIRED}
        {--sale= : Limit to one source sale id}
        {--return= : Limit to one sales return id}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only audit of standard Sales Return evidence (proven / suspected / ambiguous findings). Never modifies data.';

    public function handle(SaleReturnAuditService $audit): int
    {
        // Every supplied value must be a positive integer: a malformed filter must never silently widen the scope.
        foreach (['setting', 'sale', 'return'] as $name) {
            $raw = $this->option($name);
            if ($raw !== null && (! is_string($raw) && ! is_int($raw) || ! ctype_digit((string) $raw) || (int) $raw <= 0)) {
                $this->error("--{$name} must be a positive integer id" . ($name === 'setting' ? ' (required: the audit is always scoped to one business).' : '.'));

                return self::INVALID;
            }
        }
        if ($this->option('setting') === null) {
            $this->error('--setting is required: the audit is always scoped to one business.');

            return self::INVALID;
        }

        $settingId = (int) $this->option('setting');
        $saleId = $this->option('sale') !== null ? (int) $this->option('sale') : null;
        $returnId = $this->option('return') !== null ? (int) $this->option('return') : null;

        if (! Setting::query()->whereKey($settingId)->exists()) {
            $this->error("Business (setting) {$settingId} does not exist.");

            return self::INVALID;
        }
        if ($saleId !== null && ! Sale::withArchived()->where('setting_id', $settingId)->whereKey($saleId)->exists()) {
            $this->error("Sale {$saleId} does not exist in business {$settingId}.");

            return self::INVALID;
        }
        if ($returnId !== null && ! SaleReturn::withArchived()->where('setting_id', $settingId)->whereKey($returnId)->exists()) {
            $this->error("Sales return {$returnId} does not exist in business {$settingId}.");

            return self::INVALID;
        }

        $report = $audit->audit($settingId, $saleId, $returnId);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $s = $report['summary'];
        $this->info("Sales Return audit - business {$report['scope']} (read-only; no data was changed)");
        $this->line("Returns audited: {$s['returns_audited']}   proven: {$s['proven']}   suspected: {$s['suspected']}   ambiguous: {$s['ambiguous']}");

        foreach (['proven', 'suspected', 'ambiguous'] as $class) {
            $rows = array_filter($report['findings'], fn ($f) => $f['classification'] === $class);
            if ($rows === []) {
                continue;
            }
            $this->newLine();
            $this->line(strtoupper($class) . ' (' . count($rows) . ')');
            foreach ($rows as $f) {
                $target = $f['sale_return_id'] ? "return #{$f['sale_return_id']} {$f['reference']}" : "sale #{$f['sale_id']} {$f['reference']}";
                $this->line("  [{$f['code']}] {$target} ({$f['record_policy']}): {$f['message']}");
                if ($f['evidence'] !== []) {
                    $this->line('      evidence: ' . json_encode($f['evidence'], JSON_UNESCAPED_SLASHES));
                }
            }
        }

        return self::SUCCESS;
    }
}

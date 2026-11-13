<?php

namespace Modules\Consignment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Tests\TestCase;

/**
 * Deployment gate for the cost-event ledger triggers, and the fail-closed contract of deploy.bat.
 * Runs on SQLite by default and on the isolated MySQL database via phpunit.mysql.xml.
 */
class VerifyCostLedgerTriggersCommandTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;

    private function seedPriceRow(): int
    {
        $key = ['product_id' => $this->product->id, 'setting_id' => $this->setting->id];
        DB::table('product_prices')->updateOrInsert($key, ['average_purchase_price' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_prices')->where('id', '<>', DB::table('product_prices')->where($key)->value('id'))->delete();

        return (int) DB::table('product_prices')->where($key)->value('id');
    }

    public function test_passes_when_both_triggers_exist_and_probe_is_rolled_back(): void
    {
        $this->artisan('consignment:verify-cost-ledger-triggers')->assertExitCode(0);
    }

    public function test_probe_leaves_prices_and_ledger_unchanged(): void
    {
        $this->seedFixture();
        $id = $this->seedPriceRow();
        $ledgerBefore = DB::table('product_price_cost_events')->count();

        $this->artisan('consignment:verify-cost-ledger-triggers')->assertExitCode(0);

        $this->assertEquals(10, (float) DB::table('product_prices')->where('id', $id)->value('average_purchase_price'));
        $this->assertSame($ledgerBefore, DB::table('product_price_cost_events')->count());
    }

    public function test_fails_closed_when_either_trigger_is_missing(): void
    {
        foreach ([['trg_ppce_insert'], ['trg_ppce_update'], ['trg_ppce_insert', 'trg_ppce_update']] as $triggers) {
            $this->ddl(function () use ($triggers) {
                foreach ($triggers as $trigger) {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
                }
            });
            $this->artisan('consignment:verify-cost-ledger-triggers')
                ->expectsOutputToContain('COST-LEDGER TRIGGER VERIFICATION FAILED')
                ->expectsOutputToContain('Keep maintenance mode ON')
                ->assertExitCode(1);
            $this->reinstallTriggers();
        }
        $this->artisan('consignment:verify-cost-ledger-triggers')->assertExitCode(0);
    }

    /** @return array<string, array{string, string, string}> */
    public static function brokenTriggers(): array
    {
        $sqliteInsert = 'CREATE TRIGGER trg_ppce_insert AFTER INSERT ON product_prices ';
        $sqliteUpdate = 'CREATE TRIGGER trg_ppce_update AFTER UPDATE ON product_prices ';
        $mysqlInsert = 'CREATE TRIGGER trg_ppce_insert AFTER INSERT ON product_prices FOR EACH ROW ';
        $mysqlUpdate = 'CREATE TRIGGER trg_ppce_update AFTER UPDATE ON product_prices FOR EACH ROW ';
        $wrong = "INSERT INTO product_price_cost_events (product_id, setting_id, old_average, new_average, created_at) VALUES (NEW.product_id, NEW.setting_id, NULL, 999, %s);";

        return [
            'inert insert trigger' => ['trg_ppce_insert', $sqliteInsert . 'WHEN 0 BEGIN SELECT 1; END', $mysqlInsert . 'BEGIN END', 'INSERT appended 0 ledger rows'],
            'inert update trigger' => ['trg_ppce_update', $sqliteUpdate . 'WHEN 0 BEGIN SELECT 1; END', $mysqlUpdate . 'BEGIN END', 'UPDATE appended 0 ledger rows'],
            'insert trigger records wrong average' => ['trg_ppce_insert', $sqliteInsert . 'BEGIN ' . sprintf($wrong, 'CURRENT_TIMESTAMP') . ' END', $mysqlInsert . 'BEGIN ' . sprintf($wrong, 'NOW()') . ' END', 'INSERT ledger row has wrong evidence'],
            'update trigger records wrong average' => ['trg_ppce_update', $sqliteUpdate . 'BEGIN ' . sprintf($wrong, 'CURRENT_TIMESTAMP') . ' END', $mysqlUpdate . 'BEGIN ' . sprintf($wrong, 'NOW()') . ' END', 'UPDATE ledger row has wrong evidence'],
        ];
    }

    /** @dataProvider brokenTriggers */
    public function test_fails_closed_when_a_trigger_exists_but_does_not_record_correct_evidence(string $trigger, string $sqlite, string $mysql, string $expected): void
    {
        // DDL first: on MySQL it commits implicitly, so fixtures are seeded afterwards and stay rolled back.
        $this->ddl(function () use ($trigger, $sqlite, $mysql) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            DB::unprepared(DB::getDriverName() === 'sqlite' ? $sqlite : $mysql);
        });
        $this->seedFixture();

        $this->artisan('consignment:verify-cost-ledger-triggers')
            ->expectsOutputToContain($expected)
            ->expectsOutputToContain('Keep maintenance mode ON')
            ->assertExitCode(1);
        $this->reinstallTriggers();
    }

    public function test_probe_uses_an_unpriced_pair_and_leaves_no_trace(): void
    {
        $this->seedFixture();
        DB::table('product_prices')->delete();
        $ledgerBefore = DB::table('product_price_cost_events')->count();

        $this->artisan('consignment:verify-cost-ledger-triggers')->assertExitCode(0);

        $this->assertSame(0, DB::table('product_prices')->count());
        $this->assertSame($ledgerBefore, DB::table('product_price_cost_events')->count());
    }

    public function test_deploy_script_fails_closed_and_gates_reopening_on_trigger_verification(): void
    {
        $script = str_replace("\r\n", "\n", file_get_contents(base_path('deploy.bat')));
        [$main, $handler] = explode("\n:deployment_failed\n", $script, 2);

        // Failure handler never reopens the application.
        $this->assertDoesNotMatchRegularExpression('/^\s*php artisan up\b/m', $handler);
        $this->assertStringContainsString('MAINTENANCE MODE', $handler);
        $this->assertStringContainsString('consignment:verify-cost-ledger-triggers', $handler);
        $this->assertMatchesRegularExpression('/exit \/b 1\s*$/', $handler);

        // Maintenance mode: a supported `down` is the first artisan command and aborts on failure, never reopening.
        $this->assertStringNotContainsString('--message', $script);
        [$beforeMaintenanceHandler, $maintenanceHandler] = explode("\n:maintenance_failed\n", $main, 2);
        $this->assertDoesNotMatchRegularExpression('/^\s*php artisan up\b/m', $maintenanceHandler);
        $this->assertMatchesRegularExpression('/exit \/b 1\s*$/', $maintenanceHandler);
        $artisan = array_values(preg_grep('/^\s*(call\s+)?(php artisan|composer|npm)\b/', explode("\n", $beforeMaintenanceHandler)));
        $this->assertMatchesRegularExpression('/^php artisan down( --retry=\d+)?$/', trim($artisan[0]));
        $mainLines = explode("\n", $beforeMaintenanceHandler);
        $downAt = array_key_first(preg_grep('/^php artisan down\b/', $mainLines));
        $this->assertSame('if errorlevel 1 goto :maintenance_failed', trim($mainLines[$downAt + 1]));

        // Success path: cost writers confirmed stopped before migrate, gate after migrate, gate again before the only `up`.
        $lines = explode("\n", $main);
        $find = fn (string $re) => array_keys(preg_grep($re, $lines));
        $confirm = $find('/COST_WRITERS_STOPPED%"=="YES" goto :deployment_failed/');
        $migrate = $find('/^php artisan migrate --force/');
        $verify = $find('/^php artisan consignment:verify-cost-ledger-triggers/');
        $up = $find('/^php artisan up\b/');
        $this->assertCount(1, $confirm);
        $this->assertCount(1, $migrate);
        $this->assertCount(1, $up);
        $this->assertCount(2, $verify);
        $this->assertLessThan($migrate[0], $confirm[0]);
        $this->assertGreaterThan($migrate[0], $verify[0]);
        $this->assertLessThan($up[0], $verify[1]);
        foreach ($verify as $line) {
            $this->assertSame('if errorlevel 1 goto :deployment_failed', trim($lines[$line + 1]));
        }
    }

    private function seedFixture(): void
    {
        $this->setUpBuyoutFixtures();
    }

    private function reinstallTriggers(): void
    {
        $migration = require base_path('Modules/Consignment/Database/Migrations/2026_11_10_000004_create_product_price_cost_event_ledger.php');
        $this->ddl(fn () => $migration->up());
    }

    /**
     * MySQL DDL implicitly commits. Discard the test's uncommitted rows first so nothing leaks into the isolated
     * database, then reopen a real transaction so RefreshDatabase and the probe's savepoint keep working.
     */
    private function ddl(callable $statements): void
    {
        $pdo = DB::getPdo();
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $statements();
        if ($mysql && ! $pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
    }
}

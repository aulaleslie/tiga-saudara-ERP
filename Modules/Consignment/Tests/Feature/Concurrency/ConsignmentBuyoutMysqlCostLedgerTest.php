<?php

namespace Modules\Consignment\Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Consignment\Services\ConsignmentBuyoutFinalizationService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Tests\TestCase;

/**
 * product_price_cost_events triggers on REAL MySQL 8.0.44: creation, rollback, upgrade/rollback safety, and that a failed
 * posting leaves no ledger event. Data is committed/truncated like the race tests (DDL implicitly commits on MySQL).
 *
 * @group mysql
 */
class ConsignmentBuyoutMysqlCostLedgerTest extends TestCase
{
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }
        $this->wipe();
        $this->setUpFinalizationFixtures();
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql_test') {
            $this->wipe();
        }
        parent::tearDown();
    }

    private function wipe(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ledger(): array
    {
        return DB::table('product_price_cost_events')->orderBy('id')->get(['old_average', 'new_average'])
            ->map(fn ($r) => [$r->old_average === null ? null : (float) $r->old_average, (float) $r->new_average])->all();
    }

    private function triggers(): array
    {
        return array_map(fn ($r) => $r->TRIGGER_NAME, DB::select(
            "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'product_prices' ORDER BY TRIGGER_NAME"
        ));
    }

    /** @test */
    public function mysql_creates_both_triggers_and_logs_inserts_updates_and_mass_updates_but_not_noops(): void
    {
        $this->assertSame(['trg_ppce_insert', 'trg_ppce_update'], $this->triggers());

        $id = DB::table('product_prices')->insertGetId(['product_id' => $this->product->id, 'setting_id' => $this->setting->id,
            'sale_price' => 1, 'last_purchase_price' => 1, 'average_purchase_price' => 100]);
        DB::table('product_prices')->where('id', $id)->update(['average_purchase_price' => 100, 'sale_price' => 9]); // no average change
        DB::table('product_prices')->where('id', $id)->update(['average_purchase_price' => 120]);                     // raw statement
        DB::table('product_prices')->update(['average_purchase_price' => 100]);                                       // mass update, back to 100
        $this->assertSame([[null, 100.0], [100.0, 120.0], [120.0, 100.0]], $this->ledger(), 'an average that returns to its old value still leaves its trail');
    }

    /** @test */
    public function rolled_back_transactions_leave_no_ledger_events(): void
    {
        $id = DB::table('product_prices')->insertGetId(['product_id' => $this->product->id, 'setting_id' => $this->setting->id,
            'sale_price' => 1, 'last_purchase_price' => 1, 'average_purchase_price' => 100]);
        $before = $this->ledger();

        DB::beginTransaction();
        DB::table('product_prices')->where('id', $id)->update(['average_purchase_price' => 555]);
        $this->assertCount(count($before) + 1, $this->ledger(), 'visible inside the transaction');
        DB::rollBack();

        $this->assertSame($before, $this->ledger(), 'rollback removes the ledger rows with the price change');
        $this->assertEquals(100.0, (float) DB::table('product_prices')->where('id', $id)->value('average_purchase_price'));
    }

    /** @test */
    public function a_failed_finalization_leaves_no_cost_event_for_any_stage(): void
    {
        foreach (['purchase', 'movement', 'serial', 'cost', 'audit'] as $stage) {
            $this->wipe();
            $this->setUpFinalizationFixtures();
            [$b] = $this->mixed(null, 150.0);
            $before = $this->ledger();
            $svc = new ConsignmentBuyoutFinalizationService();
            $svc->stageHook = fn (string $s) => $s === $stage ? throw new \RuntimeException("injected {$stage}") : null;
            try {
                $svc->finalize($b, $this->user);
                $this->fail("finalization survived the injected {$stage} failure");
            } catch (\RuntimeException $e) {
                $this->assertSame("injected {$stage}", $e->getMessage());
            }
            $this->assertSame($before, $this->ledger(), "stage {$stage}: no ledger event survives the rollback");
            $this->assertEquals(100.0, (float) DB::table('product_prices')->value('average_purchase_price'), "stage {$stage}");
            $this->assertSame(0, DB::table('consignment_buyout_cost_events')->count());

            // Negative control: the same buyout then posts normally and records exactly one new event.
            (new ConsignmentBuyoutFinalizationService())->finalize($b->fresh(), $this->user);
            $this->assertCount(count($before) + 1, $this->ledger(), "stage {$stage}: a successful posting adds exactly one event");
        }
    }

    /** @test */
    public function the_migration_refuses_rollback_with_evidence_is_safe_to_rerun_and_downgrades_cleanly_without_it(): void
    {
        $migration = require base_path('Modules/Consignment/Database/Migrations/2026_11_10_000004_create_product_price_cost_event_ledger.php');

        // Re-running up() on an installed ledger is a no-op (MySQL DDL is not transactional, so partial failures must be re-runnable).
        $migration->up();
        $this->assertSame(['trg_ppce_insert', 'trg_ppce_update'], $this->triggers());

        [$b] = $this->mixed(null, 150.0);
        (new ConsignmentBuyoutFinalizationService())->finalize($b, $this->user);
        $this->assertNotNull(DB::table('consignment_buyout_cost_events')->value('price_event_id'));

        try {
            $migration->down();
            $this->fail('down() dropped a ledger that buyout evidence references');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to drop', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('product_price_cost_events'));
        $this->assertSame(['trg_ppce_insert', 'trg_ppce_update'], $this->triggers());

        // Without evidence the rollback removes triggers and table, price writes keep working, and up() restores them.
        $this->wipe();
        $migration->down();
        $this->assertFalse(Schema::hasTable('product_price_cost_events'));
        $this->assertSame([], $this->triggers());
        $migration->up();
        $this->assertSame(['trg_ppce_insert', 'trg_ppce_update'], $this->triggers());
    }
}

<?php

namespace Tests\Feature\Database\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the global_pos_payment_batches / global_pos_payment_allocations migrations
 * against real MySQL, where identifier length (64 chars) and reserved index/constraint
 * name collisions are enforced. SQLite does not enforce the identifier-length limit,
 * so an unnamed foreign key that exceeds it (e.g. Laravel's auto-generated
 * "global_pos_payment_allocations_global_pos_payment_batch_id_foreign", 66 chars)
 * silently passes focused SQLite tests but fails migration on MySQL, leaving a
 * partially created table that is not recorded as migrated.
 *
 * @group mysql
 */
class GlobalPosPaymentMigrationsMysqlTest extends TestCase
{
    private const CONNECTION = 'mysql_test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => self::CONNECTION]);
    }

    public function test_tables_exist_after_migration()
    {
        $this->assertTrue(Schema::connection(self::CONNECTION)->hasTable('global_pos_payment_batches'));
        $this->assertTrue(Schema::connection(self::CONNECTION)->hasTable('global_pos_payment_allocations'));
    }

    public function test_all_constraint_names_are_within_mysql_identifier_limit()
    {
        foreach (['global_pos_payment_batches', 'global_pos_payment_allocations'] as $table) {
            $constraintNames = DB::connection(self::CONNECTION)->select(
                'SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            );

            $this->assertNotEmpty($constraintNames, "Expected at least one constraint on {$table}.");

            foreach ($constraintNames as $row) {
                $name = $row->CONSTRAINT_NAME;
                $this->assertLessThanOrEqual(
                    64,
                    strlen($name),
                    "Constraint '{$name}' on {$table} is " . strlen($name) . " characters, exceeding MySQL's 64-character identifier limit."
                );
            }
        }
    }

    public function test_all_index_names_are_within_mysql_identifier_limit()
    {
        // information_schema.TABLE_CONSTRAINTS only covers PK/FK/UNIQUE/CHECK constraints,
        // not ordinary non-unique indexes (e.g. the gppb_*_idx / gppa_*_idx indexes), so
        // those are verified separately via information_schema.STATISTICS.
        foreach (['global_pos_payment_batches', 'global_pos_payment_allocations'] as $table) {
            $indexNames = DB::connection(self::CONNECTION)->select(
                'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            );

            $this->assertNotEmpty($indexNames, "Expected at least one index on {$table}.");

            foreach ($indexNames as $row) {
                $name = $row->INDEX_NAME;
                $this->assertLessThanOrEqual(
                    64,
                    strlen($name),
                    "Index '{$name}' on {$table} is " . strlen($name) . " characters, exceeding MySQL's 64-character identifier limit."
                );
            }
        }
    }

    public function test_global_pos_payment_allocations_foreign_keys_reference_expected_tables()
    {
        $rows = DB::connection(self::CONNECTION)->select(
            "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'global_pos_payment_allocations'
               AND REFERENCED_TABLE_NAME IS NOT NULL"
        );

        $byColumn = collect($rows)->keyBy('COLUMN_NAME');

        $this->assertTrue($byColumn->has('global_pos_payment_batch_id'));
        $this->assertEquals('global_pos_payment_batches', $byColumn->get('global_pos_payment_batch_id')->REFERENCED_TABLE_NAME);
        $this->assertEquals('gppa_batch_fk', $byColumn->get('global_pos_payment_batch_id')->CONSTRAINT_NAME);

        $this->assertTrue($byColumn->has('pos_transaction_id'));
        $this->assertEquals('pos_transactions', $byColumn->get('pos_transaction_id')->REFERENCED_TABLE_NAME);
        $this->assertEquals('gppa_pos_trx_fk', $byColumn->get('pos_transaction_id')->CONSTRAINT_NAME);

        $this->assertTrue($byColumn->has('sale_id'));
        $this->assertEquals('sales', $byColumn->get('sale_id')->REFERENCED_TABLE_NAME);
        $this->assertEquals('gppa_sale_fk', $byColumn->get('sale_id')->CONSTRAINT_NAME);

        $this->assertTrue($byColumn->has('sale_payment_id'));
        $this->assertEquals('sale_payments', $byColumn->get('sale_payment_id')->REFERENCED_TABLE_NAME);
        $this->assertEquals('gppa_sale_payment_fk', $byColumn->get('sale_payment_id')->CONSTRAINT_NAME);
    }

    public function test_global_pos_payment_batches_foreign_keys_reference_expected_tables()
    {
        $rows = DB::connection(self::CONNECTION)->select(
            "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'global_pos_payment_batches'
               AND REFERENCED_TABLE_NAME IS NOT NULL"
        );

        $byColumn = collect($rows)->keyBy('COLUMN_NAME');

        $this->assertTrue($byColumn->has('customer_id'));
        $this->assertEquals('customers', $byColumn->get('customer_id')->REFERENCED_TABLE_NAME);
        $this->assertEquals('gppb_customer_fk', $byColumn->get('customer_id')->CONSTRAINT_NAME);

        $this->assertTrue($byColumn->has('user_id'));
        $this->assertEquals('users', $byColumn->get('user_id')->REFERENCED_TABLE_NAME);
        $this->assertEquals('gppb_user_fk', $byColumn->get('user_id')->CONSTRAINT_NAME);

        $this->assertTrue($byColumn->has('payment_method_id'));
        $this->assertEquals('payment_methods', $byColumn->get('payment_method_id')->REFERENCED_TABLE_NAME);
        $this->assertEquals('gppb_payment_method_fk', $byColumn->get('payment_method_id')->CONSTRAINT_NAME);
    }
}

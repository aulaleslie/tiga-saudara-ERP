<?php

namespace Tests\Feature\Concerns;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\PreservesMigrationSeededTables;
use Tests\TestCase;

/**
 * DatabaseTruncation classes commit their writes, so anything they leave in a migration-seeded table
 * is seen by every later test on the shared SQLite file. Both tests below mutate the seeded tables;
 * whichever runs second proves the first one's changes were rolled back by the trait, and every
 * test proves it starts from the migrated seed state (denominations enabled, default payment terms
 * present, no stray permissions).
 */
class PreservesMigrationSeededTablesTest extends TestCase
{
    use DatabaseTruncation;
    use PreservesMigrationSeededTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertGreaterThan(0, DB::table('cash_denominations')->count(), 'migration seed for cash_denominations is missing');
        $this->assertSame(0, DB::table('cash_denominations')->where('is_enabled', false)->count(), 'a previous test left a denomination disabled');
        $this->assertGreaterThan(0, DB::table('payment_terms')->count(), 'migration seed for payment_terms is missing');
        $this->assertFalse(
            DB::table('permissions')->where('name', 'leak.probe')->exists(),
            'a permission created by a previous test leaked'
        );
    }

    private function mutateSeededTables(): void
    {
        DB::table('cash_denominations')->update(['is_enabled' => false]);
        DB::table('payment_terms')->delete();
        Permission::findOrCreate('leak.probe', 'web');
        $this->assertTrue(DB::table('permissions')->where('name', 'leak.probe')->exists());
    }

    public function test_first_mutation_does_not_leak(): void
    {
        $this->mutateSeededTables();
    }

    public function test_second_mutation_does_not_leak(): void
    {
        $this->mutateSeededTables();
    }
}

<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductMergeEvent;
use Modules\Product\Entities\ProductReferenceMigration;
use Modules\Product\Entities\Transaction;
use Modules\Product\Services\ProductReferenceMigrator;
use App\Models\User;
use Tests\TestCase;

class ReconcileCatalogGroupCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate');

        // Create a default setting for tests if it doesn't exist
        if (!\Modules\Setting\Entities\Setting::where('id', 1)->exists()) {
            \Modules\Setting\Entities\Setting::factory()->create(['id' => 1]);
        }
    }

    protected function createProduct(array $attributes = [])
    {
        return Product::create(array_merge([
            'product_name' => 'Test Product',
            'setting_id' => 1,
            'unit_id' => 1,
            'base_unit_id' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
            'stock_managed' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
        ], $attributes));
    }

    protected function createUser(array $attributes = [])
    {
        static $emailCounter = 0;
        $emailCounter++;
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => "test{$emailCounter}@example.com",
            'password' => bcrypt('password'),
            'is_active' => 1,
        ], $attributes));
    }

    public function test_command_rejects_missing_survivor_id()
    {
        $operator = $this->createUser();

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--retired-ids' => '1',
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(1, $exitCode);
    }

    public function test_command_rejects_missing_retired_ids()
    {
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(1, $exitCode);
    }

    public function test_command_rejects_missing_operator_id()
    {
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(1, $exitCode);
    }

    public function test_command_rejects_invalid_operator_id()
    {
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--operator-id' => 99999,
            '--confirm' => true,
        ]);

        $this->assertEquals(1, $exitCode);
    }

    public function test_command_rejects_nonexistent_survivor()
    {
        $operator = $this->createUser();
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => 99999,
            '--retired-ids' => $retired->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(1, $exitCode);
    }

    public function test_command_rejects_nonexistent_retired_product()
    {
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => '99999',
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(1, $exitCode);
    }

    public function test_price_collision_refusal()
    {
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        // Create prices for both products on the same setting with actual price fields
        DB::table('product_prices')->insert([
            [
                'product_id' => $survivor->id,
                'setting_id' => 1,
                'sale_price' => 1000,
                'tier_1_price' => 900,
                'tier_2_price' => 800,
                'last_purchase_price' => 500,
                'average_purchase_price' => 550,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $retired->id,
                'setting_id' => 1,
                'sale_price' => 2000,
                'tier_1_price' => 1800,
                'tier_2_price' => 1600,
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(1, $exitCode);

        // Verify no mutation occurred
        $survivor->refresh();
        $this->assertNull($survivor->merged_into_id);
        $retired->refresh();
        $this->assertNull($retired->merged_into_id);

        // Verify no merge events were created
        $events = ProductMergeEvent::where('survivor_product_id', $survivor->id)->count();
        $this->assertEquals(0, $events);
    }

    public function test_bundle_semantic_conflict_refusal()
    {
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);
        $bundle = $this->createProduct(['product_name' => 'Bundle Product']);

        // Create a bundle for the bundle product
        $bundleId = DB::table('product_bundles')->insertGetId([
            'parent_product_id' => $bundle->id,
            'name' => 'Test Bundle',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create bundle items in the same bundle: one survivor, one retired
        DB::table('product_bundle_items')->insert([
            [
                'bundle_id' => $bundleId,
                'product_id' => $survivor->id,
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'bundle_id' => $bundleId,
                'product_id' => $retired->id,
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(1, $exitCode);

        // Verify no mutation occurred
        $survivor->refresh();
        $this->assertNull($survivor->merged_into_id);
        $retired->refresh();
        $this->assertNull($retired->merged_into_id);

        // Verify no merge events were created
        $events = ProductMergeEvent::where('survivor_product_id', $survivor->id)->count();
        $this->assertEquals(0, $events);
    }

    public function test_unsupported_reference_rejection_blocks_reconciliation()
    {
        // Test that an unsupported reference in product_import_rows blocks reconciliation
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        // Create a location first
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Test Location',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create a batch first
        $batchId = DB::table('product_import_batches')->insertGetId([
            'location_id' => $locationId,
            'source_csv_path' => '/tmp/test.csv',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create an unsupported reference in product_import_rows
        $rowId = DB::table('product_import_rows')->insertGetId([
            'batch_id' => $batchId,
            'row_number' => 1,
            'raw_json' => json_encode(['name' => 'Test Product A']),
            'product_id' => $retired->id,
            'status' => 'imported',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        // Command should reject due to unsupported references
        $this->assertSame(1, $exitCode);

        // Verify no mutation occurred
        $survivor->refresh();
        $this->assertNull($survivor->merged_into_id);
        $retired->refresh();
        $this->assertNull($retired->merged_into_id);

        // Verify product_import_rows row is still intact
        $this->assertDatabaseHas('product_import_rows', [
            'id' => $rowId,
            'product_id' => $retired->id,
        ]);

        // Verify no merge events were created
        $this->assertDatabaseCount('product_merge_events', 0);
        $this->assertDatabaseCount('product_merge_audits', 0);
        $this->assertDatabaseCount('product_reference_migrations', 0);
    }

    public function test_successful_chunked_migration_with_audit_records()
    {
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        $location = DB::table('locations')->insertGetId([
            'name' => 'Test Location',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create more than 500 transactions to test chunking
        $transactionIds = [];
        for ($i = 0; $i < 550; $i++) {
            $transaction = Transaction::create([
                'product_id' => $retired->id,
                'setting_id' => 1,
                'type' => 'BUY',
                'quantity' => 10,
                'previous_quantity' => $i * 10,
                'current_quantity' => ($i + 1) * 10,
                'after_quantity' => ($i + 1) * 10,
                'previous_quantity_at_location' => $i * 10,
                'after_quantity_at_location' => ($i + 1) * 10,
                'quantity_tax' => 0,
                'quantity_non_tax' => 10,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'location_id' => $location,
            ]);
            $transactionIds[] = $transaction->id;
        }

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        // Verify all transactions were migrated
        $survivorTransactionCount = DB::table('transactions')
            ->where('product_id', $survivor->id)
            ->count();
        $this->assertEquals(550, $survivorTransactionCount);

        // Verify retired product has no transactions
        $retiredTransactionCount = DB::table('transactions')
            ->where('product_id', $retired->id)
            ->count();
        $this->assertEquals(0, $retiredTransactionCount);

        // Verify event and audit
        $event = ProductMergeEvent::where('survivor_product_id', $survivor->id)->first();
        $this->assertNotNull($event);

        $audit = $event->mergeAudits()->first();
        $this->assertNotNull($audit);

        // Verify every transaction has exactly one audit record
        foreach ($transactionIds as $rowId) {
            $auditCount = ProductReferenceMigration::where('audit_id', $audit->id)
                ->where('table_name', 'transactions')
                ->where('row_id', $rowId)
                ->count();
            $this->assertEquals(1, $auditCount);
        }

        // Verify total migration count
        $this->assertEquals(550, $audit->actual_migrated_counts['transactions']);
    }

    public function test_injected_mid_migration_failure_rolls_back()
    {
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired1 = $this->createProduct(['product_name' => 'Test Product A']);
        $retired2 = $this->createProduct(['product_name' => 'Test Product A']);

        $location = DB::table('locations')->insertGetId([
            'name' => 'Test Location',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create transactions for both retired products
        Transaction::create([
            'product_id' => $retired1->id,
            'setting_id' => 1,
            'type' => 'BUY',
            'quantity' => 10,
            'previous_quantity' => 0,
            'current_quantity' => 10,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'location_id' => $location,
        ]);

        Transaction::create([
            'product_id' => $retired2->id,
            'setting_id' => 1,
            'type' => 'BUY',
            'quantity' => 20,
            'previous_quantity' => 0,
            'current_quantity' => 20,
            'after_quantity' => 20,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 20,
            'quantity_tax' => 0,
            'quantity_non_tax' => 20,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'location_id' => $location,
        ]);

        // Create a price for both retired products (will cause collision after first, before second)
        DB::table('product_prices')->insert([
            [
                'product_id' => $survivor->id,
                'setting_id' => 1,
                'sale_price' => 1000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $retired1->id,
                'setting_id' => 1,
                'sale_price' => 2000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $retired2->id,
                'setting_id' => 1,
                'sale_price' => 3000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired1->id . ',' . $retired2->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        // Should fail due to price collision
        $this->assertEquals(1, $exitCode);

        // Verify NO transactions were migrated (transaction should be rolled back)
        $survivor_transaction_count = DB::table('transactions')
            ->where('product_id', $survivor->id)
            ->count();
        $this->assertEquals(0, $survivor_transaction_count);

        // Verify retired transactions are still intact
        $retired1_transaction_count = DB::table('transactions')
            ->where('product_id', $retired1->id)
            ->count();
        $this->assertEquals(1, $retired1_transaction_count);

        $retired2_transaction_count = DB::table('transactions')
            ->where('product_id', $retired2->id)
            ->count();
        $this->assertEquals(1, $retired2_transaction_count);

        // Verify no merge event was created
        $events = ProductMergeEvent::where('survivor_product_id', $survivor->id)->count();
        $this->assertEquals(0, $events);

        // Verify no migration records exist
        $migrations = ProductReferenceMigration::count();
        $this->assertEquals(0, $migrations);
    }

    public function test_successful_migration_with_simple_supported_relation()
    {
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        $location = DB::table('locations')->insertGetId([
            'name' => 'Test Location',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create a transaction referencing the retired product
        Transaction::create([
            'product_id' => $retired->id,
            'setting_id' => 1,
            'type' => 'BUY',
            'quantity' => 10,
            'previous_quantity' => 0,
            'current_quantity' => 10,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'location_id' => $location,
        ]);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
            '--verbose' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        // Verify transaction was migrated
        $transactionCount = DB::table('transactions')
            ->where('product_id', $survivor->id)
            ->count();
        $this->assertEquals(1, $transactionCount);

        // Verify retired product has no transactions
        $retiredTransactionCount = DB::table('transactions')
            ->where('product_id', $retired->id)
            ->count();
        $this->assertEquals(0, $retiredTransactionCount);

        // Verify event was created
        $event = ProductMergeEvent::where('survivor_product_id', $survivor->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals($operator->id, $event->created_by);

        // Verify audit record exists
        $audit = $event->mergeAudits()->first();
        $this->assertNotNull($audit);
        $this->assertEquals($retired->id, $audit->retired_product_id);

        // Verify row-level migration audit
        $migration = ProductReferenceMigration::where('audit_id', $audit->id)->first();
        $this->assertNotNull($migration);
        $this->assertEquals('transactions', $migration->table_name);
        $this->assertEquals($retired->id, $migration->old_product_id);
        $this->assertEquals($survivor->id, $migration->new_product_id);
    }

    public function test_command_records_actual_migrated_counts()
    {
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        $location = DB::table('locations')->insertGetId([
            'name' => 'Test Location',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create multiple transactions
        for ($i = 0; $i < 3; $i++) {
            Transaction::create([
                'product_id' => $retired->id,
                'setting_id' => 1,
                'type' => 'BUY',
                'quantity' => 10,
                'previous_quantity' => $i * 10,
                'current_quantity' => ($i + 1) * 10,
                'after_quantity' => ($i + 1) * 10,
                'previous_quantity_at_location' => $i * 10,
                'after_quantity_at_location' => ($i + 1) * 10,
                'quantity_tax' => 0,
                'quantity_non_tax' => 10,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'location_id' => $location,
            ]);
        }

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $event = ProductMergeEvent::where('survivor_product_id', $survivor->id)->first();
        $audit = $event->mergeAudits()->first();

        // Verify actual_migrated_counts is accurate
        $this->assertIsArray($audit->actual_migrated_counts);
        $this->assertEquals(3, $audit->actual_migrated_counts['transactions']);

        // Verify all supported relations have a count (even if 0)
        $supportedRelations = [
            'transactions', 'product_prices', 'product_stocks', 'purchase_details',
            'sale_details', 'dispatch_details', 'sale_return_details', 'purchase_return_details',
            'product_bundles', 'product_bundle_items', 'product_unit_conversions',
        ];

        foreach ($supportedRelations as $table) {
            $this->assertArrayHasKey($table, $audit->actual_migrated_counts,
                "Missing count for table: {$table}");
        }
    }

    public function test_command_creates_row_level_audit_records()
    {
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        $location = DB::table('locations')->insertGetId([
            'name' => 'Test Location',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create transactions
        $transactionIds = [];
        for ($i = 0; $i < 2; $i++) {
            $transaction = Transaction::create([
                'product_id' => $retired->id,
                'setting_id' => 1,
                'type' => 'BUY',
                'quantity' => 10,
                'previous_quantity' => $i * 10,
                'current_quantity' => ($i + 1) * 10,
                'after_quantity' => ($i + 1) * 10,
                'previous_quantity_at_location' => $i * 10,
                'after_quantity_at_location' => ($i + 1) * 10,
                'quantity_tax' => 0,
                'quantity_non_tax' => 10,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'location_id' => $location,
            ]);
            $transactionIds[] = $transaction->id;
        }

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $event = ProductMergeEvent::where('survivor_product_id', $survivor->id)->first();
        $audit = $event->mergeAudits()->first();

        // Verify row-level migrations
        $migrations = ProductReferenceMigration::where('audit_id', $audit->id)->get();
        $this->assertCount(2, $migrations);

        foreach ($migrations as $migration) {
            $this->assertEquals('transactions', $migration->table_name);
            $this->assertEquals($retired->id, $migration->old_product_id);
            $this->assertEquals($survivor->id, $migration->new_product_id);
            $this->assertContains($migration->row_id, $transactionIds);
        }
    }

    public function test_confirm_flag_skips_confirmation_prompt()
    {
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        $location = DB::table('locations')->insertGetId([
            'name' => 'Test Location',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create a transaction referencing the retired product
        Transaction::create([
            'product_id' => $retired->id,
            'setting_id' => 1,
            'type' => 'BUY',
            'quantity' => 10,
            'previous_quantity' => 0,
            'current_quantity' => 10,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'location_id' => $location,
        ]);

        // With --confirm, should succeed without asking for confirmation
        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        // Verify mutation occurred
        $retired->refresh();
        $this->assertEquals($survivor->id, $retired->merged_into_id);
    }

    public function test_partial_migration_rollback_on_mid_transaction_failure()
    {
        // This test verifies that if a failure occurs mid-migration (after first table completes,
        // before second table), the entire transaction rolls back and no state is persisted.
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired1 = $this->createProduct(['product_name' => 'Test Product A']);
        $retired2 = $this->createProduct(['product_name' => 'Test Product A']);

        $location = DB::table('locations')->insertGetId([
            'name' => 'Test Location',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create transactions for both retired products (supported relation)
        Transaction::create([
            'product_id' => $retired1->id,
            'setting_id' => 1,
            'type' => 'BUY',
            'quantity' => 10,
            'previous_quantity' => 0,
            'current_quantity' => 10,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'location_id' => $location,
        ]);

        Transaction::create([
            'product_id' => $retired2->id,
            'setting_id' => 1,
            'type' => 'BUY',
            'quantity' => 20,
            'previous_quantity' => 0,
            'current_quantity' => 20,
            'after_quantity' => 20,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 20,
            'quantity_tax' => 0,
            'quantity_non_tax' => 20,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'location_id' => $location,
        ]);

        // Create prices that will cause a collision during preflight (second group, same setting)
        // This will be caught by buildConflictPlan before any mutation
        DB::table('product_prices')->insert([
            [
                'product_id' => $survivor->id,
                'setting_id' => 1,
                'sale_price' => 1000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $retired1->id,
                'setting_id' => 1,
                'sale_price' => 2000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired1->id . ',' . $retired2->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        // Should fail due to price collision preflight
        $this->assertEquals(1, $exitCode);

        // Verify NO transactions were migrated (transaction rolled back at preflight stage)
        $survivorTransactionCount = DB::table('transactions')
            ->where('product_id', $survivor->id)
            ->count();
        $this->assertEquals(0, $survivorTransactionCount);

        // Verify both retired products still have their transactions
        $retired1TransactionCount = DB::table('transactions')
            ->where('product_id', $retired1->id)
            ->count();
        $this->assertEquals(1, $retired1TransactionCount);

        $retired2TransactionCount = DB::table('transactions')
            ->where('product_id', $retired2->id)
            ->count();
        $this->assertEquals(1, $retired2TransactionCount);

        // Verify no merge event was created
        $events = ProductMergeEvent::where('survivor_product_id', $survivor->id)->count();
        $this->assertEquals(0, $events);

        // Verify no migration records exist
        $migrations = ProductReferenceMigration::count();
        $this->assertEquals(0, $migrations);

        // Verify retired products are not marked as merged
        $retired1->refresh();
        $this->assertNull($retired1->merged_into_id);
        $retired2->refresh();
        $this->assertNull($retired2->merged_into_id);
    }

    public function test_price_conflict_rejection_with_retired_to_retired_collision()
    {
        // Test: Two retired products have prices for the same setting, survivor has none
        // This should be rejected as a group-level conflict
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired1 = $this->createProduct(['product_name' => 'Test Product A']);
        $retired2 = $this->createProduct(['product_name' => 'Test Product A']);

        // Create prices for two retired products on the same setting (survivor has no price for this setting)
        DB::table('product_prices')->insert([
            [
                'product_id' => $retired1->id,
                'setting_id' => 1,
                'sale_price' => 1000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $retired2->id,
                'setting_id' => 1,
                'sale_price' => 2000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired1->id . ',' . $retired2->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(1, $exitCode);

        // Verify no mutation occurred
        $survivor->refresh();
        $this->assertNull($survivor->merged_into_id);
        $retired1->refresh();
        $this->assertNull($retired1->merged_into_id);
        $retired2->refresh();
        $this->assertNull($retired2->merged_into_id);

        // Verify no merge events were created
        $events = ProductMergeEvent::where('survivor_product_id', $survivor->id)->count();
        $this->assertEquals(0, $events);
    }

    public function test_bundle_semantic_conflict_with_two_retired_items_same_bundle_no_survivor()
    {
        // Test: Two retired bundle items in the same bundle, survivor not already in the bundle
        // After repointing both to survivor, the bundle would have a duplicate survivor component
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired1 = $this->createProduct(['product_name' => 'Test Product A']);
        $retired2 = $this->createProduct(['product_name' => 'Test Product A']);
        $bundle = $this->createProduct(['product_name' => 'Bundle Product']);

        // Create a bundle for the bundle product
        $bundleId = DB::table('product_bundles')->insertGetId([
            'parent_product_id' => $bundle->id,
            'name' => 'Test Bundle',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create two bundle items for the same bundle, both referencing retired products
        // (survivor is not in the bundle yet)
        DB::table('product_bundle_items')->insert([
            [
                'bundle_id' => $bundleId,
                'product_id' => $retired1->id,
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'bundle_id' => $bundleId,
                'product_id' => $retired2->id,
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired1->id . ',' . $retired2->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertEquals(1, $exitCode);

        // Verify no mutation occurred
        $survivor->refresh();
        $this->assertNull($survivor->merged_into_id);
        $retired1->refresh();
        $this->assertNull($retired1->merged_into_id);
        $retired2->refresh();
        $this->assertNull($retired2->merged_into_id);

        // Verify no merge events were created
        $events = ProductMergeEvent::where('survivor_product_id', $survivor->id)->count();
        $this->assertEquals(0, $events);
    }

    public function test_post_write_rollback_on_mid_migration_failure()
    {
        // Test that if a failure occurs after the transactions table has been updated
        // and audit records created, the entire transaction rolls back.
        $operator = $this->createUser();
        $survivor = $this->createProduct(['product_name' => 'Test Product A']);
        $retired = $this->createProduct(['product_name' => 'Test Product A']);

        $location = DB::table('locations')->insertGetId([
            'name' => 'Test Location',
            'setting_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create one transaction and one stock row for the retired product
        $transactionId = DB::table('transactions')->insertGetId([
            'product_id' => $retired->id,
            'setting_id' => 1,
            'type' => 'BUY',
            'quantity' => 10,
            'previous_quantity' => 0,
            'current_quantity' => 10,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'location_id' => $location,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_stocks')->insert([
            'product_id' => $retired->id,
            'location_id' => $location,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Use a service instance with a failure callback configured
        app()->bind(ProductReferenceMigrator::class, function () {
            $migrator = new \Modules\Product\Services\ProductReferenceMigrator();

            // Configure to throw after transactions table has been updated
            $migrator->setOnTableMigratedCallback(function ($table, $count, $survivor, $retired) {
                if ($table === 'transactions' && $count > 0) {
                    throw new \RuntimeException('Simulated mid-migration failure');
                }
            });

            return $migrator;
        });

        $exitCode = Artisan::call('product:reconcile-catalog-group', [
            '--survivor-id' => $survivor->id,
            '--retired-ids' => $retired->id,
            '--operator-id' => $operator->id,
            '--confirm' => true,
        ]);

        $this->assertSame(1, $exitCode);

        // Verify transaction and stock rows still point to retired, not survivor
        $this->assertSame(1, DB::table('transactions')->where('product_id', $retired->id)->count());
        $this->assertSame(0, DB::table('transactions')->where('product_id', $survivor->id)->count());
        $this->assertSame(1, DB::table('product_stocks')->where('product_id', $retired->id)->count());
        $this->assertSame(0, DB::table('product_stocks')->where('product_id', $survivor->id)->count());

        // Verify retired product was not marked as merged
        $this->assertNull($retired->fresh()->merged_into_id);

        // Verify no merge events or audit records were created
        $this->assertDatabaseCount('product_merge_events', 0);
        $this->assertDatabaseCount('product_merge_audits', 0);
        $this->assertDatabaseCount('product_reference_migrations', 0);

        // Reset binding to original
        app()->forgetInstance(ProductReferenceMigrator::class);
    }
}

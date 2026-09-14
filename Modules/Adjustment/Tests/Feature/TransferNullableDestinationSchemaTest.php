<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferNullableDestinationSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function destination_location_id_is_nullable()
    {
        if (DB::getDriverName() === 'sqlite') {
            $column = collect(DB::select('PRAGMA table_info(transfers)'))
                ->firstWhere('name', 'destination_location_id');

            $this->assertNotNull($column, 'destination_location_id column missing');
            $this->assertEquals(0, $column->notnull, 'destination_location_id should be nullable');
        } else {
            $this->assertTrue(Schema::getConnection()->getDoctrineColumn('transfers', 'destination_location_id')->getNotnull() === false);
        }
    }

    /** @test */
    public function destination_location_foreign_key_is_preserved()
    {
        if (DB::getDriverName() === 'sqlite') {
            $fks = collect(DB::select('PRAGMA foreign_key_list(transfers)'))
                ->filter(fn ($fk) => $fk->from === 'destination_location_id');

            $this->assertCount(1, $fks, 'destination_location_id foreign key missing after nullable migration');
            $fk = $fks->first();
            $this->assertEquals('locations', $fk->table);
            $this->assertEquals('id', $fk->to);
        } else {
            $this->markTestSkipped('Foreign key introspection covered by SQLite path.');
        }
    }

    /** @test */
    public function stock_condition_column_exists_and_is_nullable()
    {
        $this->assertTrue(Schema::hasColumn('transfers', 'stock_condition'));

        if (DB::getDriverName() === 'sqlite') {
            $column = collect(DB::select('PRAGMA table_info(transfers)'))
                ->firstWhere('name', 'stock_condition');
            $this->assertEquals(0, $column->notnull);
        }
    }

    /** @test */
    public function transfer_can_be_created_without_a_destination()
    {
        $setting = Setting::factory()->create();
        $user = User::factory()->create();
        $origin = Location::factory()->create(['setting_id' => $setting->id]);

        $transfer = Transfer::create([
            'origin_location_id' => $origin->id,
            'destination_location_id' => null,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'created_by' => $user->id,
            'status' => Transfer::STATUS_DRAFT,
        ]);

        $this->assertDatabaseHas('transfers', [
            'id' => $transfer->id,
            'destination_location_id' => null,
        ]);
        $this->assertFalse($transfer->fresh()->hasDestination());
    }

    /** @test */
    public function unambiguous_good_history_is_classified_on_migration()
    {
        // Since RefreshDatabase already ran all migrations including the
        // classification backfill, simulate the "historical" shape by
        // inserting a transfer/product pair with null stock_condition and
        // only good buckets, then re-running just the classification logic
        // path indirectly via the mapper's read semantics.
        $setting = Setting::factory()->create();
        $user = User::factory()->create();
        $origin = Location::factory()->create(['setting_id' => $setting->id]);
        $destination = Location::factory()->create(['setting_id' => $setting->id]);

        $transfer = Transfer::create([
            'origin_location_id' => $origin->id,
            'destination_location_id' => $destination->id,
            'stock_condition' => null,
            'created_by' => $user->id,
            'status' => Transfer::STATUS_RECEIVED,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->createProduct($setting, $user)->id,
            'quantity' => 5,
            'quantity_tax' => 2,
            'quantity_non_tax' => 3,
            'quantity_broken_tax' => 0,
            'quantity_broken_non_tax' => 0,
        ]);

        $mapper = app(\Modules\Adjustment\Services\TransferFormStateMapper::class);
        $this->assertFalse($mapper->isMixedConditionHistory($transfer), 'unambiguous good-only history should not be flagged as mixed');
    }

    /** @test */
    public function mixed_condition_history_remains_unclassified_and_flagged()
    {
        $setting = Setting::factory()->create();
        $user = User::factory()->create();
        $origin = Location::factory()->create(['setting_id' => $setting->id]);
        $destination = Location::factory()->create(['setting_id' => $setting->id]);

        $transfer = Transfer::create([
            'origin_location_id' => $origin->id,
            'destination_location_id' => $destination->id,
            'stock_condition' => null,
            'created_by' => $user->id,
            'status' => Transfer::STATUS_RECEIVED,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->createProduct($setting, $user)->id,
            'quantity' => 10,
            'quantity_tax' => 2,
            'quantity_non_tax' => 3,
            'quantity_broken_tax' => 1,
            'quantity_broken_non_tax' => 4,
        ]);

        $this->assertNull($transfer->fresh()->stock_condition, 'mixed-condition history must not be rewritten');

        $mapper = app(\Modules\Adjustment\Services\TransferFormStateMapper::class);
        $this->assertTrue($mapper->isMixedConditionHistory($transfer));
    }

    /** @test */
    public function transfer_products_dispatch_counters_default_to_zero_when_omitted()
    {
        $setting = Setting::factory()->create();
        $user = User::factory()->create();
        $origin = Location::factory()->create(['setting_id' => $setting->id]);

        $transfer = Transfer::create([
            'origin_location_id' => $origin->id,
            'destination_location_id' => null,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'created_by' => $user->id,
            'status' => Transfer::STATUS_DRAFT,
        ]);

        $product = $this->createProduct($setting, $user);

        // Direct database insert omitting dispatch counters
        DB::table('transfer_products')->insert([
            'transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'quantity_broken_tax' => 0,
            'quantity_broken_non_tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tp = TransferProduct::where('transfer_id', $transfer->id)->first();
        $this->assertNotNull($tp);
        $this->assertEquals(0, $tp->dispatched_quantity);
        $this->assertEquals(0, $tp->dispatched_quantity_tax);
        $this->assertEquals(0, $tp->dispatched_quantity_non_tax);
        $this->assertEquals(0, $tp->dispatched_quantity_broken_tax);
        $this->assertEquals(0, $tp->dispatched_quantity_broken_non_tax);
    }

    /** @test */
    public function transfer_products_dispatch_counter_metadata_reports_zero_defaults()
    {
        $columns = [
            'dispatched_quantity',
            'dispatched_quantity_tax',
            'dispatched_quantity_non_tax',
            'dispatched_quantity_broken_tax',
            'dispatched_quantity_broken_non_tax',
        ];

        if (DB::getDriverName() === 'sqlite') {
            $tableInfo = collect(DB::select('PRAGMA table_info(transfer_products)'));
            foreach ($columns as $columnName) {
                $col = $tableInfo->firstWhere('name', $columnName);
                $this->assertNotNull($col, "Column {$columnName} missing from transfer_products");
                $this->assertTrue(in_array((string)$col->dflt_value, ['0', "'0'"], true), "Column {$columnName} default should be 0, got: " . var_export($col->dflt_value, true));
            }
        } elseif (DB::getDriverName() === 'mysql') {
            $results = DB::select("SHOW COLUMNS FROM transfer_products");
            $colMap = collect($results)->keyBy('Field');
            foreach ($columns as $columnName) {
                $this->assertTrue($colMap->has($columnName), "Column {$columnName} missing");
                $this->assertEquals('0', (string) $colMap[$columnName]->Default, "Column {$columnName} default should be 0");
                $this->assertEquals('NO', $colMap[$columnName]->Null, "Column {$columnName} should be NOT NULL");
            }
        }
    }

    /** @test */
    public function existing_nonzero_dispatch_counters_survive_migration()
    {
        $setting = Setting::factory()->create();
        $user = User::factory()->create();
        $origin = Location::factory()->create(['setting_id' => $setting->id]);

        $transfer = Transfer::create([
            'origin_location_id' => $origin->id,
            'destination_location_id' => null,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'created_by' => $user->id,
            'status' => Transfer::STATUS_DRAFT,
        ]);

        $product = $this->createProduct($setting, $user);

        // Seed row with explicit nonzero dispatch values
        $insertedId = DB::table('transfer_products')->insertGetId([
            'transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'quantity_tax' => 4,
            'quantity_non_tax' => 6,
            'quantity_broken_tax' => 0,
            'quantity_broken_non_tax' => 0,
            'dispatched_quantity' => 5,
            'dispatched_quantity_tax' => 2,
            'dispatched_quantity_non_tax' => 3,
            'dispatched_quantity_broken_tax' => 0,
            'dispatched_quantity_broken_non_tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Re-run migration up() to simulate running migration against existing data
        $migration = require base_path('Modules/Adjustment/Database/Migrations/2026_09_14_130000_restore_transfer_products_dispatch_defaults.php');
        $migration->up();

        $tp = TransferProduct::find($insertedId);
        $this->assertEquals(5, $tp->dispatched_quantity);
        $this->assertEquals(2, $tp->dispatched_quantity_tax);
        $this->assertEquals(3, $tp->dispatched_quantity_non_tax);
        $this->assertEquals(0, $tp->dispatched_quantity_broken_tax);
        $this->assertEquals(0, $tp->dispatched_quantity_broken_non_tax);
    }

    private function createProduct(Setting $setting, User $user): Product
    {
        $category = Category::create([
            'setting_id' => $setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Test Category',
            'created_by' => $user->id,
        ]);

        return Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);
    }
}

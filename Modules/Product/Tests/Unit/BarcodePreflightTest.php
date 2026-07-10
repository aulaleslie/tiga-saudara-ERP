<?php

namespace Modules\Product\Tests\Unit;

use Tests\TestCase;
use Modules\Product\Utils\BarcodeUtils;
use Modules\Product\Services\BarcodePreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Exception;

class BarcodePreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        Schema::dropIfExists('barcode_identities');
        Schema::dropIfExists('product_unit_conversions');
        Schema::dropIfExists('products');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('barcode')->nullable();
            $table->timestamps();
        });

        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('barcode')->nullable();
            $table->timestamps();
        });

        $migrationFile = glob(database_path('../Modules/Product/Database/Migrations/*_create_barcode_identities_table.php'))[0];
        $migration = require $migrationFile;
        $migration->up();
    }

    public function test_it_canonicalizes_barcodes_correctly()
    {
        $this->assertEquals('123456', BarcodeUtils::canonicalize(' 123456 '));
        $this->assertEquals('0123456', BarcodeUtils::canonicalize('0123456'));
        $this->assertEquals('abc-123', BarcodeUtils::canonicalize('ABC-123'));
        $this->assertNull(BarcodeUtils::canonicalize(null));
        $this->assertNull(BarcodeUtils::canonicalize('   '));
    }

    public function test_preflight_service_detects_duplicates()
    {
        DB::table('products')->insert([
            ['id' => 101, 'barcode' => '123456'],
            ['id' => 102, 'barcode' => ' 123456 ']
        ]);

        $service = new BarcodePreflightService();
        $results = $service->detectDuplicates();

        $this->assertArrayHasKey('123456', $results['conflicts']);
        $this->assertCount(2, $results['conflicts']['123456']);
        $this->assertEmpty($results['invalid']);
    }

    public function test_preflight_service_flags_invalid_whitespace_barcodes()
    {
        DB::table('products')->insert([
            ['id' => 101, 'barcode' => '   ']
        ]);

        $service = new BarcodePreflightService();
        $results = $service->detectDuplicates();

        $this->assertEmpty($results['conflicts']);
        $this->assertCount(1, $results['invalid']);
        $this->assertEquals(101, $results['invalid'][0]['id']);
    }

    public function test_barcode_identity_cascades_on_delete()
    {
        DB::table('products')->insert([
            ['id' => 201, 'barcode' => '777']
        ]);

        DB::table('barcode_identities')->insert([
            'canonical_key' => '777',
            'value' => '777',
            'product_id' => 201,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('barcode_identities', ['canonical_key' => '777']);
        DB::table('products')->where('id', 201)->delete();
        $this->assertDatabaseMissing('barcode_identities', ['canonical_key' => '777']);
    }

    public function test_migration_is_restartable()
    {
        $migrationFile = glob(database_path('../Modules/Product/Database/Migrations/*_backfill_barcode_registry_and_add_unique_constraints.php'))[0];
        $migration = require $migrationFile;

        DB::table('products')->insert([
            ['id' => 301, 'barcode' => '888']
        ]);

        $migration->up();
        $this->assertDatabaseHas('barcode_identities', ['canonical_key' => '888']);

        $migration->up();
        $this->assertDatabaseHas('barcode_identities', ['canonical_key' => '888']);
        $this->assertEquals(1, DB::table('barcode_identities')->where('canonical_key', '888')->count());
    }

    public function test_migration_throws_on_duplicates()
    {
        DB::table('products')->insert([
            ['id' => 401, 'barcode' => '999'],
            ['id' => 402, 'barcode' => ' 999 ']
        ]);

        $migrationFile = glob(database_path('../Modules/Product/Database/Migrations/*_backfill_barcode_registry_and_add_unique_constraints.php'))[0];
        $migration = require $migrationFile;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid or duplicate barcodes found');
        $migration->up();
    }
    public function test_sqlite_enforces_exactly_one_owner()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('Must have exactly one owner');

        DB::table('barcode_identities')->insert([
            'canonical_key' => '111',
            'value' => '111',
            'product_id' => null,
            'product_unit_conversion_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sqlite_enforces_exactly_one_owner_both()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('Must have exactly one owner');

        DB::table('products')->insert([
            ['id' => 1001, 'barcode' => '222']
        ]);
        DB::table('product_unit_conversions')->insert([
            ['id' => 1002, 'barcode' => '222']
        ]);

        DB::table('barcode_identities')->insert([
            'canonical_key' => '222',
            'value' => '222',
            'product_id' => 1001,
            'product_unit_conversion_id' => 1002,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_migration_rollback_behavior()
    {
        $migrationFile = glob(database_path('../Modules/Product/Database/Migrations/*_backfill_barcode_registry_and_add_unique_constraints.php'))[0];
        $migration = require $migrationFile;

        DB::table('products')->insert([
            ['id' => 501, 'barcode' => '555']
        ]);

        $migration->up();
        $this->assertDatabaseHas('barcode_identities', ['canonical_key' => '555']);

        $migration->down();
        $this->assertDatabaseCount('barcode_identities', 0);
        
        $sm = Schema::getConnection()->getDoctrineSchemaManager();
        $productIndexes = $sm->listTableIndexes('products');
        $this->assertArrayNotHasKey('products_barcode_unique', $productIndexes);
    }

    public function test_migration_restart_fails_on_mismatch_owner()
    {
        DB::table('products')->insert([
            ['id' => 601, 'barcode' => '666'],
            ['id' => 999, 'barcode' => 'somethingelse']
        ]);
        
        DB::table('barcode_identities')->insert([
            'canonical_key' => '666',
            'value' => '666',
            'product_id' => 999, // Mismatched owner
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migrationFile = glob(database_path('../Modules/Product/Database/Migrations/*_backfill_barcode_registry_and_add_unique_constraints.php'))[0];
        $migration = require $migrationFile;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Restart failed: identity mismatch for canonical_key '666' (existing product_id: 999, new product_id: 601)");
        $migration->up();
    }
}

<?php

namespace Modules\Product\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class BarcodeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        Schema::dropIfExists('product_barcode_assignments');
        Schema::dropIfExists('products');
        Schema::dropIfExists('users');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('barcode')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $migrationFile = glob(database_path('../Modules/Product/Database/Migrations/*_create_product_barcode_assignments_table.php'))[0];
        $migration = require $migrationFile;
        $migration->up();
    }

    public function test_assignment_retains_snapshot_on_product_delete()
    {
        DB::table('products')->insert(['id' => 101, 'barcode' => '123']);
        DB::table('users')->insert(['id' => 201, 'name' => 'Admin']);

        DB::table('product_barcode_assignments')->insert([
            'product_id' => 101,
            'product_name' => 'Snapshot Name',
            'product_code' => 'SNAP-1',
            'old_barcode' => null,
            'new_barcode' => '123',
            'action' => \Modules\Product\Entities\ProductBarcodeAssignment::ACTION_INITIALIZE,
            'actor_id' => 201,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify assignment exists
        $this->assertDatabaseHas('product_barcode_assignments', [
            'product_id' => 101,
            'product_name' => 'Snapshot Name',
            'actor_id' => 201,
        ]);

        // Delete product
        DB::table('products')->where('id', 101)->delete();

        // Verify assignment still exists but product_id is null
        $this->assertDatabaseHas('product_barcode_assignments', [
            'product_id' => null,
            'product_name' => 'Snapshot Name', // Snapshot data retained
            'actor_id' => 201,
        ]);

        // Delete user
        DB::table('users')->where('id', 201)->delete();

        // Verify assignment still exists but actor_id is null
        $this->assertDatabaseHas('product_barcode_assignments', [
            'product_id' => null,
            'actor_id' => null,
            'product_name' => 'Snapshot Name', // Snapshot data retained
        ]);
    }
}

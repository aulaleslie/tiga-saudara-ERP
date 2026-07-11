<?php

namespace Modules\Product\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\User;
use Modules\Product\Entities\Product;
use Modules\Product\Services\BarcodeIdentityService;
use Modules\Product\Services\ProductBarcodeAssignmentService;
use Spatie\Permission\Models\Permission;

class ProductBarcodeAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ProductBarcodeAssignmentService $service;
    protected User $authorizedActor;
    protected User $unauthorizedActor;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('migrate');

        Schema::dropIfExists('product_barcode_assignments');
        Schema::dropIfExists('barcode_identities');
        Schema::dropIfExists('products');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_name')->nullable();
            $table->string('product_code')->nullable();
            $table->string('barcode')->unique()->nullable();
            $table->timestamps();
        });

        // Re-run the specific migrations to recreate them against the simple products table
        $identitiesMigrationFile = glob(database_path('../Modules/Product/Database/Migrations/*_create_barcode_identities_table.php'))[0];
        $identitiesMigration = require $identitiesMigrationFile;
        $identitiesMigration->up();

        $assignmentsMigrationFile = glob(database_path('../Modules/Product/Database/Migrations/*_create_product_barcode_assignments_table.php'))[0];
        $assignmentsMigration = require $assignmentsMigrationFile;
        $assignmentsMigration->up();

        Permission::firstOrCreate(['name' => 'products.barcodes.manage', 'guard_name' => 'web']);
        
        DB::table('users')->insert([
            'id' => 999,
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->authorizedActor = User::find(999);
        $this->authorizedActor->givePermissionTo('products.barcodes.manage');

        DB::table('users')->insert([
            'id' => 888,
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->unauthorizedActor = User::find(888);

        $this->service = app(ProductBarcodeAssignmentService::class);
    }

    protected function createProduct($id, $barcode = null, $name = 'Test', $code = 'TST')
    {
        DB::table('products')->insert([
            'id' => $id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $barcode,
        ]);
        return Product::find($id);
    }

    public function test_initialization_with_leading_zeros_and_alphanumeric()
    {
        $product = $this->createProduct(101, null);
        $barcode = '00123ABC';

        $result = $this->service->assign($product->id, $barcode, null, $this->authorizedActor);

        $this->assertTrue($result['success']);
        $this->assertEquals('assigned', $result['status']);
        
        $product->refresh();
        $this->assertEquals($barcode, $product->barcode);

        $this->assertDatabaseHas('barcode_identities', [
            'canonical_key' => '00123abc',
            'value' => '00123ABC',
            'product_id' => $product->id,
        ]);

        $this->assertDatabaseHas('product_barcode_assignments', [
            'product_id' => $product->id,
            'action' => 'initialize',
            'new_barcode' => '00123ABC',
            'actor_id' => $this->authorizedActor->id,
        ]);
    }

    public function test_replacement()
    {
        $product = $this->createProduct(102, 'OLD123');
        app(BarcodeIdentityService::class)->reserve('OLD123', $product->id);

        $result = $this->service->assign($product->id, 'NEW456', 'OLD123', $this->authorizedActor);

        $this->assertTrue($result['success']);
        $this->assertEquals('assigned', $result['status']);
        
        $product->refresh();
        $this->assertEquals('NEW456', $product->barcode);

        $this->assertDatabaseMissing('barcode_identities', ['canonical_key' => 'old123']);
        $this->assertDatabaseHas('barcode_identities', ['canonical_key' => 'new456', 'product_id' => $product->id]);

        $this->assertDatabaseHas('product_barcode_assignments', [
            'product_id' => $product->id,
            'action' => 'replace',
            'old_barcode' => 'OLD123',
            'new_barcode' => 'NEW456',
        ]);
    }

    public function test_no_op()
    {
        $product = $this->createProduct(103, '12345');
        
        $result = $this->service->assign($product->id, ' 12345 ', '12345', $this->authorizedActor);

        $this->assertTrue($result['success']);
        $this->assertEquals('no_op', $result['status']);
        
        $this->assertEquals(0, DB::table('product_barcode_assignments')->count());
    }

    public function test_stale_state_rejection()
    {
        $product = $this->createProduct(104, 'ACTUAL');

        $result = $this->service->assign($product->id, 'NEW', 'EXPECTED', $this->authorizedActor);

        $this->assertFalse($result['success']);
        $this->assertEquals('stale_state', $result['error']);
        $this->assertEquals('ACTUAL', $result['current_barcode']);
    }

    public function test_product_conflict()
    {
        $otherProduct = $this->createProduct(105, 'TAKEN', 'Other', 'OTH-1');
        app(BarcodeIdentityService::class)->reserve('TAKEN', $otherProduct->id);

        $product = $this->createProduct(106, null);

        $result = $this->service->assign($product->id, 'taken', null, $this->authorizedActor);

        $this->assertFalse($result['success']);
        $this->assertEquals('duplicate', $result['error']);
        $this->assertEquals('product', $result['conflict']['type']);
        $this->assertEquals($otherProduct->id, $result['conflict']['product_id']);
    }

    public function test_authorization()
    {
        $product = $this->createProduct(107, null);
        
        $result = $this->service->assign($product->id, '123', null, $this->unauthorizedActor);

        $this->assertFalse($result['success']);
        $this->assertEquals('unauthorized', $result['error']);
    }

    public function test_conversion_conflict()
    {
        $product = $this->createProduct(108, null);

        $mockIdentityService = \Mockery::mock(\Modules\Product\Services\BarcodeIdentityService::class);
        $mockIdentityService->shouldReceive('replace')->andReturn([
            'success' => false,
            'error' => 'duplicate',
            'conflict' => [
                'type' => 'conversion',
                'product_id' => 109,
                'conversion_id' => 99,
                'product_code' => 'P-109',
                'product_name' => 'Prod 109',
                'unit_name' => 'Box',
                'multiplier' => 10
            ]
        ]);

        $this->app->instance(\Modules\Product\Services\BarcodeIdentityService::class, $mockIdentityService);
        $service = app(\Modules\Product\Services\ProductBarcodeAssignmentService::class);

        $result = $service->assign($product->id, '123', null, $this->authorizedActor);

        $this->assertFalse($result['success']);
        $this->assertEquals('duplicate', $result['error']);
        $this->assertEquals('conversion', $result['conflict']['type']);
    }

    public function test_rollback_on_failure()
    {
        $product = $this->createProduct(109, null);

        $mockIdentityService = \Mockery::mock(\Modules\Product\Services\BarcodeIdentityService::class);
        $mockIdentityService->shouldReceive('replace')->andThrow(new \Exception('DB failure'));

        $this->app->instance(\Modules\Product\Services\BarcodeIdentityService::class, $mockIdentityService);
        $service = app(\Modules\Product\Services\ProductBarcodeAssignmentService::class);

        try {
            $service->assign($product->id, 'FAIL', null, $this->authorizedActor);
        } catch (\Exception $e) {}

        $product->refresh();
        $this->assertNull($product->barcode);
        $this->assertDatabaseMissing('product_barcode_assignments', [
            'product_id' => $product->id,
        ]);
    }

    public function test_duplicate_response_propagation_on_assignment()
    {
        $product = $this->createProduct(110, null);

        $mockIdentityService = \Mockery::mock(\Modules\Product\Services\BarcodeIdentityService::class);
        // Correcting the mock from 'reserve' to 'replace' as per feedback
        $mockIdentityService->shouldReceive('replace')->andReturn([
            'success' => false,
            'error' => 'duplicate'
        ]);
        // Also mock reserve just in case it's initialization
        $mockIdentityService->shouldReceive('reserve')->andReturn([
            'success' => false,
            'error' => 'duplicate'
        ]);

        $this->app->instance(\Modules\Product\Services\BarcodeIdentityService::class, $mockIdentityService);
        $service = app(\Modules\Product\Services\ProductBarcodeAssignmentService::class);

        $result = $service->assign($product->id, 'RACE123', null, $this->authorizedActor);

        $this->assertFalse($result['success']);
        $this->assertEquals('duplicate', $result['error']);
    }

    public function test_concurrent_database_collision()
    {
        $product1 = $this->createProduct(111, 'COLLISION123');
        $product2 = $this->createProduct(112, null);

        // The real identity service will succeed because createProduct bypasses the registry,
        // simulating a race condition or direct DB insert. The unique constraint on products
        // table will then catch the duplicate during save(), forcing a rollback.
        $service = app(\Modules\Product\Services\ProductBarcodeAssignmentService::class);

        $result = $service->assign($product2->id, 'COLLISION123', null, $this->authorizedActor);

        $this->assertFalse($result['success']);
        $this->assertEquals('duplicate', $result['error']);
        $this->assertEquals('unknown', $result['conflict']['type']);

        // Assert the registry reservation was rolled back
        $this->assertDatabaseMissing('barcode_identities', [
            'value' => 'COLLISION123',
            'product_id' => $product2->id,
        ]);
    }
}

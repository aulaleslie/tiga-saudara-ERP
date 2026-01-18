<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Jobs\ProcessPurchaseImportBatch;
use Modules\Purchase\Jobs\StagePurchaseImportRows;
use App\Models\User;
use Tests\TestCase;

class PurchaseImportAsyncTest extends TestCase
{
    // use RefreshDatabase; // Disabled to avoid migration issues with SQLite

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();

        // Manual Schema Setup for Test
        $this->createTables();
    }

    protected function createTables()
    {
        $schema = \Illuminate\Support\Facades\Schema::connection('sqlite');

        $schema->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_active');
            $table->rememberToken();
            $table->timestamps();
        });

        $schema->create('purchase_import_batches', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('source_csv_path');
            $table->string('file_sha256');
            $table->string('status');
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->timestamps();
        });

        $schema->create('purchase_import_rows', function ($table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->integer('row_number');
            $table->json('raw_json');
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->timestamps();
        });

        $schema->create('roles', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        $schema->create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        $schema->create('settings', function ($table) {
            $table->id();
            $table->string('company_name');
            $table->timestamps();
        });

        $schema->create('user_setting', function ($table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('role_id')->nullable();
        });

        $schema->create('permissions', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        $schema->create('role_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });
    }

    /** @test */
    public function upload_endpoint_dispatches_stage_properties_job()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        
        // Mock permission
        \Illuminate\Support\Facades\Gate::define('purchases.create', fn() => true);

        // Mock csv content
        $headers = ['Tanggal', 'Supplier', 'No Faktur', 'Produk', 'Kuantitas', 'Satuan', 'Harga Satuan'];
        $row1 = ['01/01/2023', 'Supplier A', 'INV-001', 'Product A', '10', 'PCS', '10000'];
        $content = implode(',', $headers) . "\n" . implode(',', $row1);

        $file = UploadedFile::fake()->createWithContent('import.csv', $content);

        // Mock permission
        \Illuminate\Support\Facades\Gate::define('purchases.create', fn() => true);

        $response = $this->post(route('purchases.upload.store'), [
            'file' => $file,
        ]);

        $response->assertRedirect();
        
        // Assert batch created
        $batch = PurchaseImportBatch::first();
        $this->assertNotNull($batch);
        $this->assertEquals('queued', strtolower($batch->status));

        // Assert job dispatched
        Queue::assertPushed(StagePurchaseImportRows::class, function ($job) use ($batch) {
            return $job->batchId === $batch->id;
        });
    }

    /** @test */
    public function stage_job_creates_rows_and_dispatches_process_job()
    {
        // Setup batch and file
        $user = User::factory()->create();
        $headers = ['Tanggal', 'Supplier', 'No Faktur', 'Produk', 'Kuantitas', 'Satuan', 'Harga Satuan'];
        $row1 = ['01/01/2023', 'Supplier A', 'INV-001', 'Product A', '10', 'PCS', '10000'];
        $content = implode(',', $headers) . "\n" . implode(',', $row1);
        
        $path = 'imports/purchases/test.csv';
        Storage::put($path, $content);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => $path,
            'file_sha256' => 'dummy',
            'status' => 'queued',
        ]);

        $normalizedHeaders = [
            'tanggal' => 'Tanggal',
            'supplier' => 'Supplier',
            'no_faktur' => 'No Faktur',
            'produk' => 'Produk',
            'kuantitas' => 'Kuantitas',
            'satuan' => 'Satuan',
            'harga_satuan' => 'Harga Satuan'
        ];

        // Run job
        $job = new StagePurchaseImportRows($batch->id, $normalizedHeaders, $headers, ',');
        $job->handle();

        // Assert rows created
        $this->assertDatabaseHas('purchase_import_rows', [
            'batch_id' => $batch->id,
            'row_number' => 1,
        ]);

        $batch->refresh();
        $this->assertEquals('validating', strtolower($batch->status)); // Use loose comparison or normalize case
        $this->assertEquals(1, $batch->total_rows);

        // Assert process job dispatched
        Queue::assertPushed(ProcessPurchaseImportBatch::class, function ($job) use ($batch) {
            return $job->batchId === $batch->id;
        });
    }
}

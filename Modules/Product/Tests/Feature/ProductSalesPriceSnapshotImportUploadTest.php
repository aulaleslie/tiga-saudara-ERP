<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Jobs\ProcessSalesPriceSnapshotBatch;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductSalesPriceSnapshotImportUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure a default setting and location exist so controller resolution passes
        $setting = Setting::factory()->create();
        \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $setting->id]);
    }

    public function test_unauthorized_user_cannot_access_upload_page()
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        
        $response = $this->get(route('products.sales-price-snapshot.upload.page'));
        $response->assertStatus(403);
    }

    public function test_authorized_user_can_access_upload_page()
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn() => true); // Superadmin

        $response = $this->get(route('products.sales-price-snapshot.upload.page'));
        $response->assertStatus(200);
        $response->assertSee('Upload Harga Jual Snapshot (Accurate Export)');
    }

    public function test_it_rejects_unsupported_file_types()
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn() => true);

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->post(route('products.sales-price-snapshot.upload'), [
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertEquals(0, ProductImportBatch::count());
    }

    public function test_it_accepts_xlsx_creates_batch_and_dispatches_job()
    {
        Queue::fake();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn() => true);

        $file = UploadedFile::fake()->create('prices.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->post(route('products.sales-price-snapshot.upload'), [
            'file' => $file,
        ]);

        $batch = ProductImportBatch::first();
        $this->assertNotNull($batch);
        $this->assertEquals(ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT, $batch->import_type);
        $this->assertEquals('queued', $batch->status);
        $this->assertNotNull($batch->source_csv_path);

        $response->assertRedirect(route('products.imports.show', $batch));

        Queue::assertPushed(ProcessSalesPriceSnapshotBatch::class, function ($job) use ($batch) {
            return $job->batchId === $batch->id;
        });
    }
}

<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Jobs\ProcessDualCompanyTierPriceBatch;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DualCompanyTierPriceImportUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $setting = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        Location::factory()->create(['setting_id' => $setting->id]);
    }

    private function makeWorkbookUpload(string $originalName = 'harga.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();

        foreach ([
            ProcessDualCompanyTierPriceBatch::REQUIRED_SHEETS[0],
            ProcessDualCompanyTierPriceBatch::REQUIRED_SHEETS[1],
        ] as $index => $title) {
            $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet($index);
            $sheet->setTitle($title);
            $sheet->fromArray([
                ['Nama Produk', 'Harga Jual', 'Harga Tier 1', 'Harga Tier 2', 'Harga Beli Terakhir', 'Harga Beli Rata-rata'],
                ['LAPTOP', '1200', '1100', '1000', '900', '850'],
            ], null, 'A4');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'dualtier_');
        (new Xlsx($spreadsheet))->save($tempPath);

        return new UploadedFile(
            $tempPath,
            $originalName,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_unauthorized_user_cannot_access_upload_page(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());

        $this->get(route('products.dual-company-tier-price.upload.page'))->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_submit_upload(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());

        $this->post(route('products.dual-company-tier-price.upload'), [
            'file' => $this->makeWorkbookUpload(),
        ])->assertStatus(403);

        $this->assertDatabaseCount('product_import_batches', 0);
    }

    public function test_authorized_user_can_access_upload_page(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        Gate::before(fn () => true);

        $this->get(route('products.dual-company-tier-price.upload.page'))
            ->assertStatus(200)
            ->assertSee('Upload Harga Tier Dua Perusahaan');
    }

    public function test_it_rejects_non_xlsx_files(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        Gate::before(fn () => true);
        Queue::fake();

        $response = $this->post(route('products.dual-company-tier-price.upload'), [
            'file' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('product_import_batches', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_a_file_that_is_not_a_readable_workbook(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        Gate::before(fn () => true);
        Queue::fake();

        $response = $this->post(route('products.dual-company-tier-price.upload'), [
            'file' => UploadedFile::fake()->createWithContent('fake.xlsx', 'not really a workbook'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('product_import_batches', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_creates_a_batch_queues_processing_and_redirects(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        Gate::before(fn () => true);
        Queue::fake();

        $response = $this->post(route('products.dual-company-tier-price.upload'), [
            'file' => $this->makeWorkbookUpload(),
        ]);

        $batch = ProductImportBatch::firstOrFail();

        $this->assertSame(ProductImportBatch::TYPE_DUAL_COMPANY_TIER_PRICE, $batch->import_type);
        $this->assertSame('queued', $batch->status);
        $response->assertRedirect(route('products.imports.show', $batch));

        Queue::assertPushed(
            ProcessDualCompanyTierPriceBatch::class,
            fn (ProcessDualCompanyTierPriceBatch $job) => $job->batchId === $batch->id
        );
    }

    public function test_it_uploads_successfully_when_no_locations_exist(): void
    {
        // This import mutates no stock, so it must not depend on a configured location.
        Location::query()->delete();
        $this->assertDatabaseCount('locations', 0);

        $this->actingAs(\App\Models\User::factory()->create());
        Gate::before(fn () => true);
        Queue::fake();

        $response = $this->post(route('products.dual-company-tier-price.upload'), [
            'file' => $this->makeWorkbookUpload(),
        ]);

        $response->assertSessionHasNoErrors();

        $batch = ProductImportBatch::firstOrFail();

        $this->assertNull($batch->location_id);
        $this->assertNull($batch->location);
        $this->assertSame('queued', $batch->status);
        $this->assertSame(ProductImportBatch::TYPE_DUAL_COMPANY_TIER_PRICE, $batch->import_type);
        $response->assertRedirect(route('products.imports.show', $batch));

        Queue::assertPushed(
            ProcessDualCompanyTierPriceBatch::class,
            fn (ProcessDualCompanyTierPriceBatch $job) => $job->batchId === $batch->id
        );

        // The batch monitor must render a locationless batch without erroring.
        $this->get(route('products.imports.index'))->assertStatus(200);
        $this->get(route('products.imports.show', $batch))->assertStatus(200);
    }

    public function test_batch_detail_offers_no_undo_action_for_this_import_type(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        Gate::before(fn () => true);

        $batch = ProductImportBatch::create([
            'user_id' => auth()->id(),
            'location_id' => null,
            'source_csv_path' => 'imports/products/none.xlsx',
            'status' => 'completed',
            'import_type' => ProductImportBatch::TYPE_DUAL_COMPANY_TIER_PRICE,
            'undo_available_until' => now()->addDay(),
        ]);

        $this->assertFalse($batch->canUndo());

        $this->get(route('products.imports.show', $batch))
            ->assertStatus(200)
            ->assertDontSee(route('products.imports.undo', $batch));
    }
}

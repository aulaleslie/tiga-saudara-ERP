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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

    private function createValidXlsxUpload(string $originalName, string $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Name*', 'ProductCode', 'SellPrice'],
            ['* Global Laptop', 'GLOBAL-01', '1200'],
        ], null, 'A1');

        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile(
            $tempPath,
            $originalName,
            $mimeType,
            null,
            true // test mode
        );
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

        // A fake PDF file that cannot be read by PhpSpreadsheet
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

        $file = $this->createValidXlsxUpload('prices.xlsx');

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

    public function test_it_accepts_structurally_valid_xlsx_with_generic_binary_mime_type()
    {
        Queue::fake();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn() => true);

        // Mimics a valid XLSX that server fileinfo detects as generic application/octet-stream
        $file = $this->createValidXlsxUpload('generic.xlsx', 'application/octet-stream');

        $response = $this->post(route('products.sales-price-snapshot.upload'), [
            'file' => $file,
        ]);

        $batch = ProductImportBatch::first();
        $this->assertNotNull($batch);
        $this->assertEquals(ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT, $batch->import_type);

        $response->assertRedirect(route('products.imports.show', $batch));

        Queue::assertPushed(ProcessSalesPriceSnapshotBatch::class);
    }

    public function test_it_rejects_non_xlsx_payload_renamed_to_xlsx()
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn() => true);

        // A fake text file pretending to be XLSX
        $file = UploadedFile::fake()->create('fake.xlsx', 100, 'text/plain');

        $response = $this->post(route('products.sales-price-snapshot.upload'), [
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertStringContainsString('not a valid XLSX workbook', session('errors')->first('file'));
        $this->assertEquals(0, ProductImportBatch::count());
    }

    public function test_it_rejects_valid_xlsx_payload_with_non_xlsx_filename()
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn() => true);

        // A valid XLSX but wrong extension
        $file = $this->createValidXlsxUpload('prices.txt');

        $response = $this->post(route('products.sales-price-snapshot.upload'), [
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertEquals('The file must be a file of type: xlsx.', session('errors')->first('file'));
        $this->assertEquals(0, ProductImportBatch::count());
    }
}

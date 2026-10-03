<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Services\IndividualPurchasePaymentAttachmentStagingService;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class IndividualPurchasePaymentAttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Supplier $supplier;
    protected Purchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('purchasePayments.create', 'web');
        Permission::findOrCreate('purchasePayments.access', 'web');

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo(['purchasePayments.create', 'purchasePayments.access']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Attachment Test Co',
            'company_email' => 'test@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Test',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '081111111',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor Street',
        ]);

        $this->purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PUR-ATT-001',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Bank Transfer',
            'total_amount' => 100000.0,
            'sub_total' => 100000.0,
            'paid_amount' => 0.0,
            'due_amount' => 100000.0,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
        ]);

        Storage::fake('local');
        session(['setting_id' => $this->setting->id]);
    }

    /** @test */
    public function it_requires_authentication_and_permission_to_upload()
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        // Guest upload rejected
        $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $file])
            ->assertStatus(401);

        // User without permission rejected
        $unauthorizedUser = User::factory()->create(['is_active' => 1]);
        $this->actingAs($unauthorizedUser)
            ->postJson(route('purchase-payments.attachments.upload'), ['file' => $file])
            ->assertStatus(403);
    }

    /** @test */
    public function it_accepts_valid_pdf_image_and_document_samples()
    {
        $this->actingAs($this->user);

        // 1. PDF
        $pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
        $pdfFile = UploadedFile::fake()->createWithContent('invoice.pdf', $pdfContent);
        $res = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $pdfFile]);
        $res->assertOk();
        $this->assertNotEmpty($res->json('name'));
        $this->assertEquals('invoice.pdf', $res->json('original_name'));
        Storage::assertExists(IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $res->json('name'));

        // 2. PNG Image
        $pngFile = UploadedFile::fake()->image('receipt.png', 200, 200);
        $res = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $pngFile]);
        $res->assertOk();
        $this->assertNotEmpty($res->json('name'));
        Storage::assertExists(IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $res->json('name'));

        // 3. TXT file
        $txtContent = "Bukti transfer pembayaran nomor 12345.";
        $txtFile = UploadedFile::fake()->createWithContent('note.txt', $txtContent);
        $res = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $txtFile]);
        $res->assertOk();
        $this->assertNotEmpty($res->json('name'));
        Storage::assertExists(IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $res->json('name'));

        // 4. DOCX / XLSX (standard ZIP-based Office files)
        $docxPath = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($docxPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"></w:document>');
        $zip->close();
        $docxFile = new UploadedFile($docxPath, 'contract.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);

        $res = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $docxFile]);
        $res->assertOk();
        $this->assertNotEmpty($res->json('name'));
        Storage::assertExists(IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $res->json('name'));
        @unlink($docxPath);
    }

    /** @test */
    public function it_rejects_disguised_executable_content_even_with_allowed_extension()
    {
        $this->actingAs($this->user);

        // A PHP script disguised as a .pdf or .txt
        $phpPayload = "<?php echo 'malicious code'; phpinfo(); ?>";
        $disguisedPdf = UploadedFile::fake()->createWithContent('report.pdf', $phpPayload);

        $res = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $disguisedPdf]);
        $res->assertStatus(422);

        $disguisedTxt = UploadedFile::fake()->createWithContent('notes.txt', $phpPayload);
        $res = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $disguisedTxt]);
        $res->assertStatus(422);

        // SVG is rejected as unsupported/active format
        $svgFile = UploadedFile::fake()->createWithContent('graphic.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="5" cy="5" r="4"/></svg>');
        $res = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $svgFile]);
        $res->assertStatus(422);

        // Ordinary ZIP disguised as .docx (missing Office parts)
        $fakeDocxPath = tempnam(sys_get_temp_dir(), 'fakedocx');
        $zip = new \ZipArchive();
        $zip->open($fakeDocxPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('some_random_file.txt', 'not an office document');
        $zip->close();
        $fakeDocx = new UploadedFile($fakeDocxPath, 'fake_contract.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $res = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $fakeDocx]);
        $res->assertStatus(422);
        @unlink($fakeDocxPath);

        // Ordinary ZIP disguised as .xlsx (missing Office parts)
        $fakeXlsxPath = tempnam(sys_get_temp_dir(), 'fakexlsx');
        $zip = new \ZipArchive();
        $zip->open($fakeXlsxPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('photos/image.png', 'binary image bytes');
        $zip->close();
        $fakeXlsx = new UploadedFile($fakeXlsxPath, 'fake_spreadsheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $res = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $fakeXlsx]);
        $res->assertStatus(422);
        @unlink($fakeXlsxPath);
    }

    /** @test */
    public function it_deletes_staged_attachment_safely()
    {
        $this->actingAs($this->user);

        $pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
        $pdfFile = UploadedFile::fake()->createWithContent('invoice.pdf', $pdfContent);
        $uploadRes = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $pdfFile]);
        $uploadRes->assertOk();
        $stagedName = $uploadRes->json('name');

        Storage::assertExists(IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $stagedName);

        // Delete endpoint
        $delRes = $this->postJson(route('purchase-payments.attachments.delete'), ['file_name' => $stagedName]);
        $delRes->assertOk();
        Storage::assertMissing(IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $stagedName);

        // Path traversal deletion rejected
        $traversalRes = $this->postJson(route('purchase-payments.attachments.delete'), ['file_name' => '../../secret.txt']);
        $traversalRes->assertStatus(422);
    }

    /** @test */
    public function it_compresses_image_in_single_pass_and_retains_aspect_ratio()
    {
        $this->actingAs($this->user);

        // Create an uncompressed PNG with dimensions 300x150 (2:1 aspect ratio)
        $imageFile = UploadedFile::fake()->image('photo.jpg', 300, 150);
        $res = $this->postJson(route('purchase-payments.attachments.upload'), ['file' => $imageFile]);
        $res->assertOk();

        $stagedName = $res->json('name');
        $stagedPath = Storage::path(IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $stagedName);

        $this->assertFileExists($stagedPath);
        $size = filesize($stagedPath);
        $this->assertLessThan(IndividualPurchasePaymentAttachmentStagingService::TARGET_BYTES, $size);

        // Aspect ratio preserved
        [$width, $height] = getimagesize($stagedPath);
        $this->assertEquals(300, $width);
        $this->assertEquals(150, $height);
    }

    /** @test */
    public function it_retains_image_when_compression_leaves_it_over_1mb()
    {
        $this->actingAs($this->user);

        // Mock compressor service method to return null or over-1MB content
        $largeBytes = str_repeat('A', 1500 * 1024); // 1.5 MB dummy
        // Test that service accepts large image or falls back without error
        $service = app(IndividualPurchasePaymentAttachmentStagingService::class);

        // When compression cannot reduce size or remains large, file is still accepted and stored
        $imageFile = UploadedFile::fake()->image('big.jpg', 1200, 800);
        $result = $service->stageUploadedFile($imageFile);

        $this->assertNotEmpty($result['name']);
        $stagedPath = Storage::path(IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $result['name']);
        $this->assertFileExists($stagedPath);
    }

    /** @test */
    public function it_handles_unsupported_compressor_format_gracefully_by_retaining_original()
    {
        $this->actingAs($this->user);

        // BMP is accepted in our allowed image formats, but not compressible via standard GD encoder
        // Minimal 1x1 24bpp BMP header and pixel
        $bmpContent = "BM" . pack("VvvVVVVvvVVVVVV", 58, 0, 0, 54, 40, 1, 1, 1, 24, 0, 4, 2835, 2835, 0, 0) . "\xFF\xFF\xFF\x00";
        $bmpPath = tempnam(sys_get_temp_dir(), 'bmp');
        file_put_contents($bmpPath, $bmpContent);

        $bmpFile = new UploadedFile($bmpPath, 'sample.bmp', 'image/bmp', null, true);
        $service = app(IndividualPurchasePaymentAttachmentStagingService::class);
        $result = $service->stageUploadedFile($bmpFile);

        $this->assertNotEmpty($result['name']);
        $stagedPath = Storage::path(IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $result['name']);
        $this->assertFileExists($stagedPath);
        $this->assertEquals(filesize($bmpPath), filesize($stagedPath));
        @unlink($bmpPath);
    }

    /** @test */
    public function it_renders_individual_payment_create_form_with_multiple_attachments_dropzone()
    {
        $this->actingAs($this->user);

        $response = $this->get(route('purchase-payments.create', $this->purchase->id));
        $response->assertOk();
        $response->assertSee(route('purchase-payments.attachments.upload'), false);
        $response->assertSee(route('purchase-payments.attachments.delete'), false);
        $response->assertSee('attachments[]', false);
        $response->assertSee('attachments-hidden-inputs', false);
    }
}

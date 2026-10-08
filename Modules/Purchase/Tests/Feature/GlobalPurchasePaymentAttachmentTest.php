<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use Modules\Purchase\Services\GlobalPurchasePaymentService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class GlobalPurchasePaymentAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $supplier;
    protected $service;
    protected $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \App\Models\User::factory()->create();
        $this->setting = Setting::factory()->create();
        
        $this->supplier = Supplier::create([
            'supplier_name' => 'Attachment Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'setting_id' => $this->setting->id,
            'account_number' => 'COA-' . uniqid(),
            'name' => 'Cash in Bank',
            'category' => 'Kas & Bank',
        ]);

        $this->paymentMethod = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'Bank Transfer',
            'coa_id' => $coa->id,
            'is_active' => true,
        ]);

        $this->service = app(GlobalPurchasePaymentService::class);
    }

    protected function createPurchase($amount)
    {
        return Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . rand(1000, 9999),
            'supplier_id' => $this->supplier->id,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => $amount,
            'paid_amount' => 0,
            'due_amount' => $amount,
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_attachment_free_submission()
    {
        $p1 = $this->createPurchase(1000);
        $p2 = $this->createPurchase(2000);

        $data = [
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-001',
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $p1->id => 1000,
                $p2->id => 2000,
            ],
            'note' => 'No attachment',
        ];

        $payments = $this->service->storeMultiPayment($this->supplier->id, $data);

        $this->assertCount(2, $payments);
        $this->assertEquals(0, Media::count());
    }

    public function test_distinct_multi_file_rows_and_empty_rows_preserve_original_name_metadata()
    {
        $p1 = $this->createPurchase(1000);
        $p2 = $this->createPurchase(2000);

        Storage::fake('local');
        $dropzoneDir = Storage::path('temp/dropzone');
        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0777, true);
        }

        // Row 1: two files (one with .meta original name)
        $file1 = 'staged-file-1.pdf';
        $fullPath1 = $dropzoneDir . '/' . $file1;
        file_put_contents($fullPath1, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
        file_put_contents($fullPath1 . '.meta', json_encode(['original_name' => 'Faktur Pembelian 1.pdf']));

        $file2 = 'staged-file-2.txt';
        $fullPath2 = $dropzoneDir . '/' . $file2;
        file_put_contents($fullPath2, "Kwitansi pelunasan baris pertama");

        // Row 2 has 0 files (empty row)

        $data = [
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-002',
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $p1->id => 500,
                $p2->id => 1000,
            ],
            'attachments' => [
                $p1->id => [$file1, $file2],
                $p2->id => [],
            ],
        ];

        $payments = $this->service->storeMultiPayment($this->supplier->id, $data);

        $this->assertCount(2, $payments);

        $pay1 = collect($payments)->firstWhere('purchase_id', $p1->id);
        $pay2 = collect($payments)->firstWhere('purchase_id', $p2->id);

        // Row 1 has 2 distinct attachments
        $mediaPay1 = $pay1->getMedia('attachments');
        $this->assertCount(2, $mediaPay1);
        $this->assertEquals('Faktur Pembelian 1.pdf', $mediaPay1[0]->getCustomProperty('original_name'));
        $this->assertFalse(file_exists($fullPath1 . '.meta')); // Cleaned up

        // Row 2 has 0 attachments
        $this->assertCount(0, $pay2->getMedia('attachments'));
        $this->assertEquals(2, Media::count());

        // Staged files are cleaned up from dropzone
        $this->assertFalse(file_exists($fullPath1));
        $this->assertFalse(file_exists($fullPath2));
    }

    public function test_rejects_duplicate_file_references_across_rows()
    {
        $p1 = $this->createPurchase(1000);
        $p2 = $this->createPurchase(2000);

        Storage::fake('local');
        $dropzoneDir = Storage::path('temp/dropzone');
        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0777, true);
        }

        $sharedFile = 'shared-receipt.pdf';
        file_put_contents($dropzoneDir . '/' . $sharedFile, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $data = [
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-DUP',
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $p1->id => 500,
                $p2->id => 1000,
            ],
            'attachments' => [
                $p1->id => [$sharedFile],
                $p2->id => [$sharedFile], // Duplicate across rows!
            ],
        ];

        try {
            $this->service->storeMultiPayment($this->supplier->id, $data);
            $this->fail('Expected ValidationException on duplicate file references across rows');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('attachments', $e->errors());
            $this->assertStringContainsString('duplikat', $e->errors()['attachments'][0]);
        }

        $this->assertEquals(0, PurchasePayment::count());
        $this->assertEquals(0, Media::count());
    }

    public function test_rejects_files_on_zero_amount_allocation_rows()
    {
        $p1 = $this->createPurchase(1000);
        $p2 = $this->createPurchase(2000);

        Storage::fake('local');
        $dropzoneDir = Storage::path('temp/dropzone');
        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0777, true);
        }

        $file1 = 'zero-row-file.pdf';
        file_put_contents($dropzoneDir . '/' . $file1, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $data = [
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-ZERO-ATTACH',
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $p1->id => 500,
                $p2->id => 0, // Zero allocation!
            ],
            'attachments' => [
                $p1->id => [],
                $p2->id => [$file1], // Attachments on zero allocation row!
            ],
        ];

        try {
            $this->service->storeMultiPayment($this->supplier->id, $data);
            $this->fail('Expected ValidationException when zero-allocation row has files');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertTrue(
                isset($e->errors()['attachments']) || isset($e->errors()["allocations.{$p2->id}"]),
                'Errors must identify zero allocation row having attachments'
            );
        }

        $this->assertEquals(0, PurchasePayment::count());
        $this->assertEquals(0, Media::count());
    }

    public function test_rejects_tampered_row_associations_or_nonexistent_purchase_in_attachments()
    {
        $p1 = $this->createPurchase(1000);

        Storage::fake('local');
        $dropzoneDir = Storage::path('temp/dropzone');
        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0777, true);
        }

        $file1 = 'tampered.pdf';
        file_put_contents($dropzoneDir . '/' . $file1, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $data = [
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-TAMPERED',
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $p1->id => 500,
            ],
            'attachments' => [
                999999 => [$file1], // Tampered / unallocated purchase ID
            ],
        ];

        try {
            $this->service->storeMultiPayment($this->supplier->id, $data);
            $this->fail('Expected ValidationException when unallocated purchase ID has attachments');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('attachments', $e->errors());
        }

        $this->assertEquals(0, PurchasePayment::count());
    }

    public function test_rejects_malformed_file_entries_mixed_with_valid_files()
    {
        $p1 = $this->createPurchase(1000);

        Storage::fake('local');
        $dropzoneDir = Storage::path('temp/dropzone');
        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0777, true);
        }

        $validFile = 'valid-receipt.pdf';
        file_put_contents($dropzoneDir . '/' . $validFile, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        // Payload contains a valid file mixed with a blank string and a non-string entry
        $data = [
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-MALFORMED-ENTRY',
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $p1->id => 500,
            ],
            'attachments' => [
                $p1->id => [$validFile, '', 12345],
            ],
        ];

        try {
            $this->service->storeMultiPayment($this->supplier->id, $data);
            $this->fail('Expected ValidationException on malformed file entries');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertTrue(
                isset($e->errors()["attachments.{$p1->id}"]) || isset($e->errors()['attachments']),
                'Must reject submission when malformed entries exist instead of silently dropping them'
            );
        }

        $this->assertEquals(0, PurchasePayment::count());
        $this->assertEquals(0, Media::count());
    }

    public function test_failure_rolls_back_everything()
    {
        $p1 = $this->createPurchase(1000);
        $p2 = $this->createPurchase(2000);

        Storage::fake('local');
        config()->set('media-library.disk_name', 'local');
        $dropzoneDir = Storage::path('temp/dropzone');
        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0777, true);
        }

        $file1 = 'test-file-1.pdf';
        $file2 = 'test-file-2.pdf';
        file_put_contents($dropzoneDir . '/' . $file1, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
        file_put_contents($dropzoneDir . '/' . $file2, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        // Fail on the second payment by deleting the second file before it can be added
        \Illuminate\Support\Facades\Event::listen(\Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAdded::class, function($event) use ($dropzoneDir, $file2) {
            @unlink($dropzoneDir . '/' . $file2);
        });

        $data = [
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-003',
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [
                $p1->id => 500,
                $p2->id => 1000,
            ],
            'attachments' => [
                $p1->id => [$file1],
                $p2->id => [$file2],
            ],
        ];

        try {
            $this->service->storeMultiPayment($this->supplier->id, $data);
            $this->fail('Service should have thrown an exception');
        } catch (\Throwable $e) {
            // DB Transaction rollback is implicit, verify our models were not persisted
            $this->assertEquals(0, PurchasePayment::count());
            
            // Verify media was cleaned up / never persisted
            $this->assertEquals(0, Media::count());
            
            // Validate the disk is completely empty (no partial physical copies)
            $directories = Storage::disk('local')->directories();
            $this->assertEmpty(array_filter($directories, fn($dir) => $dir !== 'temp'));
            
            // Validate purchases were unaffected
            $this->assertEquals(1000, $p1->fresh()->live_due_amount);
            $this->assertEquals(2000, $p2->fresh()->live_due_amount);
        }
    }
}

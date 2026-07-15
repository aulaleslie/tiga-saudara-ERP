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
            'payment_method_id' => 999,
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

    public function test_attachment_copied_to_every_generated_payment()
    {
        $p1 = $this->createPurchase(1000);
        $p2 = $this->createPurchase(2000);

        Storage::fake('local');
        $fileName = 'test-receipt.jpg';
        Storage::put('temp/dropzone/' . $fileName, 'dummy content');

        $data = [
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-002',
            'payment_method_id' => 999,
            'allocations' => [
                $p1->id => 500,
                $p2->id => 1000,
            ],
            'attachment' => $fileName,
        ];

        $payments = $this->service->storeMultiPayment($this->supplier->id, $data);

        $this->assertCount(2, $payments);
        
        // Assert each payment has exactly 1 media record and independent files
        foreach ($payments as $payment) {
            $this->assertCount(1, $payment->getMedia('attachments'));
        }
        $this->assertEquals(2, Media::count());
        $this->assertFalse(Storage::exists('temp/dropzone/' . $fileName)); // Cleaned up
    }

    public function test_failure_rolls_back_everything()
    {
        $p1 = $this->createPurchase(1000);
        $p2 = $this->createPurchase(2000);

        Storage::fake('local');
        config()->set('media-library.disk_name', 'local');
        $fileName = 'test-attachment.jpg';
        Storage::put('temp/dropzone/' . $fileName, 'dummy content');
        
        $attachmentPath = Storage::path('temp/dropzone/' . $fileName);

        // Fail on the second copy by deleting the temp file during the first media event
        \Illuminate\Support\Facades\Event::listen(\Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAdded::class, function($event) use ($attachmentPath) {
            @unlink($attachmentPath);
        });

        $data = [
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-003',
            'payment_method_id' => 999,
            'allocations' => [
                $p1->id => 500,
                $p2->id => 1000,
            ],
            'attachment' => $fileName,
        ];

        try {
            $this->service->storeMultiPayment($this->supplier->id, $data);
            $this->fail('Service should have thrown an exception');
        } catch (\Exception $e) {
            // DB Transaction rollback is implicit, but we can verify our models were not persisted
            $this->assertEquals(0, PurchasePayment::count());
            
            // Verify media was cleaned up / never persisted
            $this->assertEquals(0, Media::count());
            
            // Validate the disk is completely empty (no partial physical copies)
            $directories = Storage::disk('local')->directories();
            $this->assertEmpty(array_filter($directories, fn($dir) => $dir !== 'temp'));
            
            // The staged temporary source was also cleaned up
            $this->assertFalse(file_exists($attachmentPath));
            
            // Validate purchases were unaffected
            $this->assertEquals(1000, $p1->fresh()->live_due_amount);
            $this->assertEquals(2000, $p2->fresh()->live_due_amount);
        }
    }
}

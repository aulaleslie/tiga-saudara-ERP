<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\GlobalSalePaymentService;
use Modules\Sale\Services\GlobalSalePaymentAttachmentReplicator;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class GlobalSalePaymentAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notification@test.com',
            'footer_text' => 'Test Footer',
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'contact_name' => 'John Doe',
            'setting_id' => $this->setting->id,
        ]);

        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coaId,
            'is_cash' => true,
        ]);
    }

    /** @test */
    public function attachment_is_optional_for_payment()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-NOATTACH',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $service = new GlobalSalePaymentService();
        $payments = $service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => 50000],
            'date' => now()->toDateString(),
            'reference' => 'PAY-NOATTACH',
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        $this->assertCount(1, $payments);
        $this->assertEquals(0, $payments[0]->getMedia('attachments')->count());
    }

    /** @test */
    public function attachment_is_replicated_to_all_payments()
    {
        $sale1 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-ATTACH-1',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $sale2 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-ATTACH-2',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
        ]);

        // Create temp attachment
        Storage::disk('local')->put('temp/dropzone/invoice.pdf', 'PDF Content');
        $attachmentPath = Storage::path('temp/dropzone/invoice.pdf');

        $service = new GlobalSalePaymentService();
        $payments = $service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale1->id => 50000, $sale2->id => 80000],
            'date' => now()->toDateString(),
            'reference' => 'PAY-ATTACH',
            'payment_method_id' => $this->paymentMethod->id,
            'attachment' => $attachmentPath,
        ]);

        $this->assertCount(2, $payments);
        foreach ($payments as $payment) {
            $this->assertEquals(1, $payment->getMedia('attachments')->count());
        }
    }

    /** @test */
    public function missing_temporary_attachment_is_rejected()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-MISSING',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $service = new GlobalSalePaymentService();
        $this->expectException(ValidationException::class);
        $service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => 50000],
            'date' => now()->toDateString(),
            'reference' => 'PAY-MISSING',
            'payment_method_id' => $this->paymentMethod->id,
            'attachment' => '/nonexistent/path/invoice.pdf',
        ]);

        // Verify no payments were created
        $this->assertEquals(0, SalePayment::count());
    }

    /** @test */
    public function failure_during_first_media_copy_rolls_back_all_payments()
    {
        $sale1 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-FAIL-FIRST-1',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $sale2 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-FAIL-FIRST-2',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
        ]);

        // Create temp attachment, then delete it to cause failure
        Storage::disk('local')->put('temp/dropzone/bad.pdf', 'PDF Content');
        $attachmentPath = Storage::path('temp/dropzone/bad.pdf');
        unlink($attachmentPath);

        $service = new GlobalSalePaymentService();
        try {
            $service->storeMultiPayment($this->customer->id, [
                'allocations' => [$sale1->id => 50000, $sale2->id => 80000],
                'date' => now()->toDateString(),
                'reference' => 'PAY-FAIL-FIRST',
                'payment_method_id' => $this->paymentMethod->id,
                'attachment' => $attachmentPath,
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            // Expected
        }

        // Verify no payments exist (rollback)
        $this->assertEquals(0, SalePayment::count());

        // Verify sales were not modified
        $sale1->refresh();
        $sale2->refresh();
        $this->assertEquals(100000, $sale1->live_due_amount);
        $this->assertEquals(200000, $sale2->live_due_amount);
    }

    /** @test */
    public function failure_after_first_successful_copy_rolls_back_all_and_cleans_files()
    {
        // This test demonstrates that if media copy fails after one or more successful copies,
        // the transaction rolls back and the replicator's cleanup handler removes the copied files.
        // Specifically:
        // 1. The source attachment passes initial validation.
        // 2. The first payment and first media copy succeed.
        // 3. Replication fails before or during the second copy.
        // 4. The database transaction rolls back every generated payment and media record.
        // 5. Every physical media artifact created by the first copy is removed.

        $sale1 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-FAIL-PARTIAL-1',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $sale2 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-FAIL-PARTIAL-2',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
        ]);

        // Create temp attachment
        Storage::disk('local')->put('temp/dropzone/invoice.pdf', 'PDF Content');
        $attachmentPath = Storage::path('temp/dropzone/invoice.pdf');

        // Create a replicator that will fail before copying to the second payment (index 1)
        $replicator = new GlobalSalePaymentAttachmentReplicator();
        $replicator->failBeforePaymentIndex = 1; // Fail before copying to payment 2

        $service = new GlobalSalePaymentService($replicator);

        // Before attempting the failed replication, we need to track what media directories
        // would be created. We'll capture the media directory structure and verify cleanup.
        $mediaBasePath = Storage::disk('local')->path('');
        $mediaDirectory = $mediaBasePath . DIRECTORY_SEPARATOR . 'media';

        // Record existing media directory state (if any)
        $mediaExistedBefore = is_dir($mediaDirectory);
        $filesBeforeFailure = [];
        if ($mediaExistedBefore) {
            $filesBeforeFailure = $this->getAllFilesRecursive($mediaDirectory);
        }

        $exceptionWasThrown = false;
        $exceptionMessage = '';
        try {
            $service->storeMultiPayment($this->customer->id, [
                'allocations' => [$sale1->id => 50000, $sale2->id => 80000],
                'date' => now()->toDateString(),
                'reference' => 'PAY-FAIL-PARTIAL',
                'payment_method_id' => $this->paymentMethod->id,
                'attachment' => $attachmentPath,
            ]);
            $this->fail('Expected exception during attachment replication');
        } catch (\Exception $e) {
            $exceptionWasThrown = true;
            $exceptionMessage = $e->getMessage();
            // The exception will be re-thrown as-is from the replicator
            $this->assertStringContainsString('Injected replicator failure', $exceptionMessage, 'Should fail with injected replicator exception. Got: ' . $exceptionMessage);
        }

        $this->assertTrue($exceptionWasThrown, 'Exception should have been thrown when replication fails after first copy');

        // Verify no payments were created (transaction rolled back)
        $this->assertEquals(0, SalePayment::count(), 'No payments should exist after failed replication');

        // Verify no media records exist
        $this->assertEquals(0, \Spatie\MediaLibrary\MediaCollections\Models\Media::count(), 'No media records should exist after rolled-back transaction');

        // Verify sales were not modified
        $sale1->refresh();
        $sale2->refresh();
        $this->assertEquals(100000, $sale1->live_due_amount);
        $this->assertEquals(200000, $sale2->live_due_amount);

        // Verify that any media paths created by the first (successful) copy were cleaned up
        // by checking that no directory was left behind for any payment
        $copiedPaths = $replicator->getCopiedMediaPaths();
        $this->assertEquals(0, count($copiedPaths), 'Replicator cleanup should have cleared all tracked paths after rollback');

        // Verify the replicator reset its state after cleanup
        $this->assertEmpty($replicator->getCopiedMediaPaths(), 'Replicator should have reset tracked paths after cleanup');

        // Verify physical files were actually cleaned up
        // (media directory state should be back to pre-failure state)
        $filesAfterFailure = [];
        if (is_dir($mediaDirectory)) {
            $filesAfterFailure = $this->getAllFilesRecursive($mediaDirectory);
        }

        // The media directory should have no new files created by the failure
        $newFiles = array_diff($filesAfterFailure, $filesBeforeFailure);
        $this->assertEmpty($newFiles, 'No new media files should exist after cleanup. Found: ' . implode(', ', $newFiles));
    }

    /**
     * Recursively get all files in a directory.
     *
     * @param string $directory
     * @return array
     */
    private function getAllFilesRecursive(string $directory): array
    {
        $files = [];
        $items = @scandir($directory);

        if ($items === false) {
            return $files;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $files = array_merge($files, $this->getAllFilesRecursive($path));
            } else {
                $files[] = $path;
            }
        }

        return $files;
    }
}

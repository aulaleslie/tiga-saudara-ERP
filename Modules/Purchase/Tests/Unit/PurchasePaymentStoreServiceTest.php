<?php

namespace Modules\Purchase\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Services\PurchasePaymentStoreService;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchasePaymentStoreServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Supplier $supplier;
    protected Purchase $purchase;
    protected User $user;
    protected PaymentMethod $paymentMethod;
    protected PurchasePaymentStoreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Payment Test Company',
            'company_email' => 'payment@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'payment@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Payment Test Supplier',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '081111111',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor Street',
        ]);

        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'setting_id' => $this->setting->id,
            'account_number' => 'COA-' . uniqid(),
            'name' => 'Cash in Bank',
            'category' => 'Kas & Bank',
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'name' => 'Bank Transfer',
            'coa_id' => $coa->id,
            'is_active' => true,
        ]);

        $this->purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PUR-PAY-001',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Bank Transfer',
            'total_amount' => 100000.0,
            'sub_total' => 100000.0,
            'paid_amount' => 0.0,
            'due_amount' => 100000.0,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
        ]);

        $this->service = new PurchasePaymentStoreService();
    }

    /** @test */
    public function it_rejects_zero_or_negative_payment_amounts()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->store(
            purchase: $this->purchase,
            actor: $this->user,
            data: [
                'date' => now()->format('Y-m-d'),
                'reference' => 'PAY-NEG-001',
                'amount' => 0.0,
                'payment_method_id' => $this->paymentMethod->id,
            ]
        );
    }

    /** @test */
    public function it_rejects_payments_exceeding_live_due_amount()
    {
        $this->expectException(\DomainException::class);

        $this->service->store(
            purchase: $this->purchase,
            actor: $this->user,
            data: [
                'date' => now()->format('Y-m-d'),
                'reference' => 'PAY-EXCEED-001',
                'amount' => 150000.0,
                'payment_method_id' => $this->paymentMethod->id,
            ]
        );
    }

    /** @test */
    public function it_protects_against_concurrent_payment_race_conditions_under_lock()
    {
        // First payment of 60,000 succeeds
        $pay1 = $this->service->store(
            purchase: $this->purchase,
            actor: $this->user,
            data: [
                'date' => now()->format('Y-m-d'),
                'reference' => 'PAY-RACE-001',
                'amount' => 60000.0,
                'payment_method_id' => $this->paymentMethod->id,
            ]
        );

        $this->assertEquals(60000.0, $pay1->amount);
        $this->purchase->refresh();
        $this->assertEquals(40000.0, $this->purchase->due_amount);

        // Simulated concurrent request also trying to pay 60,000 against stale initial state
        // Under lock, live due amount is 40,000, so 60,000 is rejected with DomainException.
        $this->expectException(\DomainException::class);

        $this->service->store(
            purchase: $this->purchase,
            actor: $this->user,
            data: [
                'date' => now()->format('Y-m-d'),
                'reference' => 'PAY-RACE-002',
                'amount' => 60000.0,
                'payment_method_id' => $this->paymentMethod->id,
            ]
        );
    }

    /** @test */
    public function it_allows_partial_and_full_payment_settlement_on_consignment_billing_purchases()
    {
        $consignmentPurchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PUR-CONS-BILL-001',
            'supplier_id' => $this->supplier->id,
            'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING,
            'payment_method' => 'Bank Transfer',
            'total_amount' => 100000.0,
            'sub_total' => 100000.0,
            'paid_amount' => 0.0,
            'due_amount' => 100000.0,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
        ]);

        // Partial payment of 40,000
        $pay1 = $this->service->store(
            purchase: $consignmentPurchase,
            actor: $this->user,
            data: [
                'date' => now()->format('Y-m-d'),
                'reference' => 'PAY-CONS-001',
                'amount' => 40000.0,
                'payment_method_id' => $this->paymentMethod->id,
            ]
        );

        $this->assertEquals(40000.0, $pay1->amount);
        $consignmentPurchase->refresh();
        $this->assertEquals(40000.0, $consignmentPurchase->paid_amount);
        $this->assertEquals(60000.0, $consignmentPurchase->due_amount);
        $this->assertEquals(Purchase::PAYMENT_STATUS_PARTIAL, $consignmentPurchase->payment_status);

        // Final settlement payment of 60,000
        $pay2 = $this->service->store(
            purchase: $consignmentPurchase,
            actor: $this->user,
            data: [
                'date' => now()->format('Y-m-d'),
                'reference' => 'PAY-CONS-002',
                'amount' => 60000.0,
                'payment_method_id' => $this->paymentMethod->id,
            ]
        );

        $this->assertEquals(60000.0, $pay2->amount);
        $consignmentPurchase->refresh();
        $this->assertEquals(100000.0, $consignmentPurchase->paid_amount);
        $this->assertEquals(0.0, $consignmentPurchase->due_amount);
        $this->assertEquals(Purchase::PAYMENT_STATUS_PAID, $consignmentPurchase->payment_status);
    }

    /** @test */
    public function it_rejects_path_traversal_attempts_in_payment_attachments()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal is strictly prohibited');

        $this->service->store(
            purchase: $this->purchase,
            actor: $this->user,
            data: [
                'date' => now()->format('Y-m-d'),
                'reference' => 'PAY-TRAVERSAL',
                'amount' => 10000.0,
                'payment_method_id' => $this->paymentMethod->id,
            ],
            attachment: '../../etc/passwd'
        );
    }

    /** @test */
    public function it_purges_physical_payment_attachment_and_rolls_back_when_transaction_fails()
    {
        // Stage a valid file in temp/dropzone
        $dropzoneDir = storage_path('app/temp/dropzone');
        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0755, true);
        }

        $stagedFile = $dropzoneDir . '/pay_rollback.pdf';
        file_put_contents($stagedFile, "%PDF-1.4\n%DUMMY-PDF-CONTENT\n");

        // Hook Purchase updating event to simulate database/commit failure after attachment storage
        Purchase::updating(function ($model) {
            if ($model->reference === 'PUR-PAY-001') {
                throw new \RuntimeException('Simulated purchase header update failure');
            }
        });

        $initialPaymentCount = PurchasePayment::count();

        $caughtException = null;
        try {
            $this->service->store(
                purchase: $this->purchase,
                actor: $this->user,
                data: [
                    'date' => now()->format('Y-m-d'),
                    'reference' => 'PAY-ROLLBACK-001',
                    'amount' => 20000.0,
                    'payment_method_id' => $this->paymentMethod->id,
                ],
                attachment: 'pay_rollback.pdf'
            );
        } catch (\Throwable $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException, 'Expected exception was not thrown during post-attachment header failure');
        $this->assertStringContainsString('Simulated purchase header update failure', $caughtException->getMessage());

        // Assert database state rolled back
        $this->assertEquals($initialPaymentCount, PurchasePayment::count(), 'No payment record should exist after failure');
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table('media')->where('model_type', PurchasePayment::class)->count());

        // Assert physical file created during addMedia was purged from disk
        $mediaStoragePath = storage_path('app/public');
        $matchingFiles = \Illuminate\Support\Facades\File::glob($mediaStoragePath . '/*/pay_rollback.pdf');
        $this->assertEmpty($matchingFiles, 'Physical payment media file must be purged from disk on transaction rollback');

        // Clean up staged test file
        if (file_exists($stagedFile)) {
            @unlink($stagedFile);
        }
    }

    /** @test */
    public function it_rejects_plain_text_content_renamed_as_pdf()
    {
        $dropzoneDir = storage_path('app/temp/dropzone');
        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0755, true);
        }

        $fakePdf = $dropzoneDir . '/fake_text.pdf';
        file_put_contents($fakePdf, '<?php echo "malicious payload"; ?>');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('unsupported file extension or MIME type');

            $this->service->store(
                purchase: $this->purchase,
                actor: $this->user,
                data: [
                    'date' => now()->format('Y-m-d'),
                    'reference' => 'PAY-MIME-MISMATCH',
                    'amount' => 10000.0,
                    'payment_method_id' => $this->paymentMethod->id,
                ],
                attachment: 'fake_text.pdf'
            );
        } finally {
            if (file_exists($fakePdf)) {
                @unlink($fakePdf);
            }
        }
    }

    /** @test */
    public function it_rejects_valid_pdf_content_renamed_as_php()
    {
        $dropzoneDir = storage_path('app/temp/dropzone');
        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0755, true);
        }

        $fakePhp = $dropzoneDir . '/valid_pdf.php';
        file_put_contents($fakePhp, "%PDF-1.4\n%DUMMY-PDF-CONTENT\n");

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('unsupported file extension or MIME type');

            $this->service->store(
                purchase: $this->purchase,
                actor: $this->user,
                data: [
                    'date' => now()->format('Y-m-d'),
                    'reference' => 'PAY-EXT-MISMATCH',
                    'amount' => 10000.0,
                    'payment_method_id' => $this->paymentMethod->id,
                ],
                attachment: 'valid_pdf.php'
            );
        } finally {
            if (file_exists($fakePhp)) {
                @unlink($fakePhp);
            }
        }
    }

    /** @test */
    public function it_rejects_inactive_payment_methods_inside_transaction()
    {
        $this->paymentMethod->update(['is_active' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not active or available');

        $this->service->store(
            purchase: $this->purchase,
            actor: $this->user,
            data: [
                'date' => now()->format('Y-m-d'),
                'reference' => 'PAY-INACTIVE-PM',
                'amount' => 10000.0,
                'payment_method_id' => $this->paymentMethod->id,
            ]
        );
    }

    /** @test */
    public function it_rejects_zero_byte_empty_mime_payment_attachments()
    {
        $dropzoneDir = storage_path('app/temp/dropzone');
        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0755, true);
        }

        $zeroByteFile = $dropzoneDir . '/zero_byte_pay.pdf';
        file_put_contents($zeroByteFile, ''); // 0-byte file (application/x-empty)

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('unsupported file extension or MIME type');

            $this->service->store(
                purchase: $this->purchase,
                actor: $this->user,
                data: [
                    'date' => now()->format('Y-m-d'),
                    'reference' => 'PAY-ZERO-BYTE',
                    'amount' => 10000.0,
                    'payment_method_id' => $this->paymentMethod->id,
                ],
                attachment: 'zero_byte_pay.pdf'
            );
        } finally {
            if (file_exists($zeroByteFile)) {
                @unlink($zeroByteFile);
            }
        }
    }

    /** @test */
    public function it_rejects_attachments_resolving_to_prefix_sibling_directories()
    {
        $dropzoneDir = storage_path('app/temp/dropzone');
        $siblingDir = storage_path('app/temp/dropzone-private');

        if (!file_exists($dropzoneDir)) {
            mkdir($dropzoneDir, 0755, true);
        }
        if (!file_exists($siblingDir)) {
            mkdir($siblingDir, 0755, true);
        }

        $siblingFile = $siblingDir . '/secret.pdf';
        file_put_contents($siblingFile, "%PDF-1.4\n%SECRET-PAYMENT-ATTACHMENT-CONTENT\n");

        $symlinkInDropzone = $dropzoneDir . '/sibling_escape.pdf';
        if (file_exists($symlinkInDropzone)) {
            @unlink($symlinkInDropzone);
        }

        @symlink($siblingFile, $symlinkInDropzone);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('escapes the temporary dropzone directory');

            $this->service->store(
                purchase: $this->purchase,
                actor: $this->user,
                data: [
                    'date' => now()->format('Y-m-d'),
                    'reference' => 'PAY-SIBLING-ESCAPE',
                    'amount' => 10000.0,
                    'payment_method_id' => $this->paymentMethod->id,
                ],
                attachment: 'sibling_escape.pdf'
            );
        } finally {
            if (file_exists($symlinkInDropzone)) {
                @unlink($symlinkInDropzone);
            }
            if (file_exists($siblingFile)) {
                @unlink($siblingFile);
            }
            if (file_exists($siblingDir)) {
                @rmdir($siblingDir);
            }
        }
    }
}

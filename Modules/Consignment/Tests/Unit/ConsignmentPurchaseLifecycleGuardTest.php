<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBillingConversionService;
use Modules\Consignment\Services\ConsignmentBillingPreviewService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Services\PurchaseReceivingCompletionService;
use Modules\Purchase\Services\PurchaseSourceGuard;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class ConsignmentPurchaseLifecycleGuardTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Supplier $supplier;
    protected Product $product;
    protected Location $location;
    protected Tax $tax11;
    protected User $user;
    protected Purchase $consignmentPurchase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Consignment Guard Test',
            'company_email' => 'guard@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'guard@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
            'document_prefix' => 'CSG',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Guard',
            'supplier_email' => 'guard@example.com',
            'supplier_phone' => '081111115',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor St',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Rack E',
            'is_consignment' => true,
        ]);

        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Widget Guard',
            'product_code' => 'W-GUARD',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 10,
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
        ]);

        $confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-GUARD-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Cust D',
            'customer_email' => 'd@example.com',
            'customer_phone' => '08123126',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => 'Cust D',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'status' => 'APPROVED',
        ]);

        $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'dispatched_quantity' => 2,
        ]);

        $soldSource = ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 2,
            'dispatched_at' => now(),
            'source_hash' => 'hash_guard_123',
            'source_snapshot' => [],
        ]);

        $confLine = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 2,
        ]);

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-GUARD-001',
            'receival_number' => 'CR-GUARD-001',
            'status' => 'APPROVED',
            'date' => now(),
        ]);

        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-GUARD-001',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => now(),
        ]);

        $recLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 100000,
            'subtotal_dpp' => 100000,
            'total_cost' => 100000,
            'total_dpp' => 100000,
        ]);

        $crd = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $recLine->id,
            'product_id' => $this->product->id,
            'quantity_received' => 2,
            'received_base_quantity' => 2,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 11000,
        ]);

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 2,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 11000,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        $previewService = new ConsignmentBillingPreviewService();
        $conversionService = new ConsignmentBillingConversionService($previewService);

        $this->consignmentPurchase = $conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            [
                'supplier_invoice_number' => 'INV-GUARD-001',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ]
        );
    }

    /** @test */
    public function it_rejects_receiving_operations_for_consignment_billing_purchase()
    {
        $this->expectException(\DomainException::class);

        PurchaseSourceGuard::assertReceivingAllowed($this->consignmentPurchase);
    }

    /** @test */
    public function it_returns_edit_mode_none_for_consignment_billing_purchase()
    {
        $this->assertEquals(Purchase::EDIT_MODE_NONE, $this->consignmentPurchase->resolveEditMode($this->user));
    }

    /** @test */
    public function it_rejects_return_settlement_for_consignment_billing_purchase()
    {
        $this->expectException(\Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException::class);

        PurchaseSourceGuard::assertReturnAllowed($this->consignmentPurchase);
    }

    /** @test */
    public function it_allows_return_settlement_for_ordinary_purchase()
    {
        $ordinary = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO/GUARD/ORDINARY',
            'supplier_id' => $this->supplier->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
        ]);

        PurchaseSourceGuard::assertReturnAllowed($ordinary);
        $this->assertTrue($ordinary->isOrdinary());
    }

    /** @test */
    public function it_rejects_direct_detail_mutation_and_deletion_for_consignment_billing_purchase()
    {
        $detail = $this->consignmentPurchase->purchaseDetails()->firstOrFail();

        // Direct Eloquent mutation, bypassing every controller/service guard.
        try {
            $detail->update(['quantity' => 99]);
            $this->fail('Detail update on a consignment-billing Purchase should be rejected.');
        } catch (\Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $detail->delete();
            $this->fail('Detail deletion on a consignment-billing Purchase should be rejected.');
        } catch (\Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $detail->refresh();
        $this->assertNotEquals(99, (float) $detail->quantity);
        $this->assertDatabaseHas('purchase_details', ['id' => $detail->id]);
    }

    /** @test */
    public function it_rejects_deletion_for_consignment_billing_purchase()
    {
        $this->expectException(\DomainException::class);

        PurchaseSourceGuard::assertDeletionAllowed($this->consignmentPurchase);
    }

    /** @test */
    public function it_allows_valid_purchase_payment_creation_and_reconciliation()
    {
        $this->assertEquals(111000.0, $this->consignmentPurchase->due_amount);
        $this->assertEquals('Unpaid', $this->consignmentPurchase->payment_status);

        $payment = PurchasePayment::create([
            'purchase_id' => $this->consignmentPurchase->id,
            'reference' => 'INV-PAY-001',
            'amount' => 50000,
            'date' => now(),
            'payment_method' => 'Bank Transfer',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $this->consignmentPurchase->refresh();
        $this->consignmentPurchase->reconcileFromActivePayments();

        $this->assertEquals(50000.0, $this->consignmentPurchase->paid_amount);
        $this->assertEquals(61000.0, $this->consignmentPurchase->due_amount);
        $this->assertEquals(Purchase::PAYMENT_STATUS_PARTIAL, $this->consignmentPurchase->payment_status);

        // Settle the remainder: the consignment Purchase must reach a canonical PAID
        // status and report a zero live outstanding balance.
        PurchasePayment::create([
            'purchase_id' => $this->consignmentPurchase->id,
            'reference' => 'INV-PAY-002',
            'amount' => 61000,
            'date' => now(),
            'payment_method' => 'Bank Transfer',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $this->consignmentPurchase->refresh();
        $this->consignmentPurchase->reconcileFromActivePayments();
        $this->consignmentPurchase->refresh();

        $this->assertEquals(111000.0, $this->consignmentPurchase->paid_amount);
        $this->assertEquals(0.0, $this->consignmentPurchase->due_amount);
        $this->assertTrue(\App\Constants\PaymentStatus::matches(
            \App\Constants\PaymentStatus::PAID,
            $this->consignmentPurchase->payment_status
        ), "Expected PAID, got {$this->consignmentPurchase->payment_status}");

        // Canonical live balances, which reconciliation renders, agree with the columns.
        $this->assertEquals(111000.0, $this->consignmentPurchase->getEffectivePaidAmount());
        $this->assertEquals(0.0, $this->consignmentPurchase->live_due_amount);
    }

    /** @test */
    public function it_reports_canonical_live_balances_for_a_consignment_purchase_regardless_of_stored_status_casing()
    {
        // Historical uppercase casing persisted directly into the column.
        \Illuminate\Support\Facades\DB::table('purchases')
            ->where('id', $this->consignmentPurchase->id)
            ->update(['payment_status' => 'PARTIAL']);

        PurchasePayment::create([
            'purchase_id' => $this->consignmentPurchase->id,
            'reference' => 'INV-PAY-CASE',
            'amount' => 40000,
            'date' => now(),
            'payment_method' => 'Bank Transfer',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $purchase = Purchase::findOrFail($this->consignmentPurchase->id);

        // Live balances are computed from active payments, so they are unaffected by
        // whatever casing the status column happens to hold.
        $this->assertEquals(40000.0, $purchase->getEffectivePaidAmount());
        $this->assertEquals(71000.0, $purchase->live_due_amount);

        // And the stored value is still recognized as PARTIAL by canonical matching.
        $this->assertTrue(\App\Constants\PaymentStatus::matches(
            \App\Constants\PaymentStatus::PARTIAL,
            $purchase->payment_status
        ));
    }

    /** @test */
    public function it_blocks_direct_purchase_correction_service_calls_on_consignment_purchases()
    {
        $correctionService = app(\Modules\Purchase\Services\PurchaseCorrectionService::class);

        $this->expectException(\Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException::class);

        $correctionService->correct(
            purchase: $this->consignmentPurchase,
            actor: $this->user,
            reason: 'Attempt direct service correction',
            lineCorrections: []
        );
    }

    /** @test */
    public function it_rolls_back_conversion_completely_when_an_attachment_storage_fails()
    {
        // Setup an unbilled confirmation
        $confirmation2 = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-GUARD-002',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $confLine2 = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation2->id,
            'consignment_sold_source_id' => ConsignmentSoldSource::first()->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 1,
        ]);

        $crd = ConsignmentReceivingDetail::first();

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine2->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 5500,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        $previewService = new ConsignmentBillingPreviewService();
        $conversionService = new ConsignmentBillingConversionService($previewService);

        // Attachment 1 is a valid file
        $file1 = \Illuminate\Http\UploadedFile::fake()->createWithContent('inv1.pdf', "%PDF-1.4\n%DUMMY-PDF-CONTENT\n");

        // Attachment 2 is a valid file that triggers a simulated storage hardware write failure during addMedia
        $file2 = \Illuminate\Http\UploadedFile::fake()->createWithContent('inv2.pdf', "%PDF-1.4\n%DUMMY-PDF-CONTENT\n");

        \Spatie\MediaLibrary\MediaCollections\Models\Media::creating(function ($media) {
            if ($media->file_name === 'inv2.pdf') {
                throw new \RuntimeException('Simulated attachment storage hardware write error');
            }
        });

        $initialPurchaseCount = Purchase::count();
        $initialLineageCount = \Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::count();

        $caughtException = null;
        try {
            $conversionService->convert(
                $confirmation2->id,
                $this->setting->id,
                $this->user->id,
                [
                    'supplier_invoice_number' => 'INV-FAIL-ATTACHMENT',
                    'invoice_date' => '2026-08-28',
                    'due_date' => '2026-09-28',
                ],
                [$file1, $file2]
            );
        } catch (\RuntimeException $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException, 'Expected RuntimeException was not thrown during conversion');
        $this->assertStringContainsString('Simulated attachment storage hardware write error', $caughtException->getMessage());

        // Assert database state was completely rolled back
        $this->assertEquals($initialPurchaseCount, Purchase::count(), 'No new Purchase should exist');
        $this->assertEquals($initialLineageCount, \Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::count(), 'No new lineage records should exist');

        $confirmation2->refresh();
        $this->assertTrue($confirmation2->is_ready_for_billing, 'Confirmation should remain ready for billing');
        $this->assertNull($confirmation2->purchase_id, 'Confirmation purchase_id should remain null');
        $this->assertNull($confirmation2->billed_at, 'Confirmation billed_at should remain null');

        // Assert no BILLING_CONVERTED audit log exists for confirmation2
        $this->assertEquals(0, \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::where('consignment_billing_confirmation_id', $confirmation2->id)->where('action', 'BILLING_CONVERTED')->count());

        // Assert durable BILLING_CONVERSION_FAILED audit log exists at service boundary
        $this->assertEquals(1, \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::where('consignment_billing_confirmation_id', $confirmation2->id)->where('action', 'BILLING_CONVERSION_FAILED')->count());

        // Assert no media records remain in media table for this attempt
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table('media')->where('model_type', Purchase::class)->where('collection_name', 'attachments')->whereNotIn('model_id', [$this->consignmentPurchase->id])->count());

        // Assert physical media files are absent from disk after compensating cleanup
        $mediaStoragePath = storage_path('app/public');
        $matchingFiles = \Illuminate\Support\Facades\File::glob($mediaStoragePath . '/*/inv1.pdf');
        $this->assertEmpty($matchingFiles, 'Physical media files must be absent from disk after attachment failure compensating cleanup');
    }

    /** @test */
    public function it_purges_physical_media_files_and_audits_failure_when_post_attachment_operation_fails()
    {
        // Setup an unbilled confirmation
        $confirmation3 = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-GUARD-003',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $confLine3 = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation3->id,
            'consignment_sold_source_id' => ConsignmentSoldSource::first()->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 1,
        ]);

        $crd = ConsignmentReceivingDetail::first();

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine3->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 5500,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        $previewService = new ConsignmentBillingPreviewService();
        $conversionService = new ConsignmentBillingConversionService($previewService);

        // Attachment is a valid file that will succeed in storage
        $validFile = \Illuminate\Http\UploadedFile::fake()->createWithContent('inv_post_fail.pdf', "%PDF-1.4\n%DUMMY-PDF-CONTENT\n");

        // Hook ConsignmentAllocationAuditLog creating event to simulate failure AFTER attachment storage
        \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::creating(function ($log) {
            if ($log->action === 'BILLING_CONVERTED') {
                throw new \RuntimeException('Simulated post-attachment audit persistence failure');
            }
        });

        $initialPurchaseCount = Purchase::count();

        $caughtException = null;
        try {
            $conversionService->convert(
                $confirmation3->id,
                $this->setting->id,
                $this->user->id,
                [
                    'supplier_invoice_number' => 'INV-POST-FAIL-ATTACHMENT',
                    'invoice_date' => '2026-08-28',
                    'due_date' => '2026-09-28',
                ],
                [$validFile]
            );
        } catch (\Throwable $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException, 'Expected exception was not thrown during post-attachment failure simulation');
        $this->assertStringContainsString('Simulated post-attachment audit persistence failure', $caughtException->getMessage());

        // Assert database state was completely rolled back
        $this->assertEquals($initialPurchaseCount, Purchase::count(), 'No new Purchase should exist after post-attachment failure');

        $confirmation3->refresh();
        $this->assertTrue($confirmation3->is_ready_for_billing, 'Confirmation should remain ready for billing after post-attachment failure');
        $this->assertNull($confirmation3->purchase_id, 'Confirmation purchase_id should remain null after post-attachment failure');

        // Assert no BILLING_CONVERTED audit exists
        $this->assertEquals(0, \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::where('consignment_billing_confirmation_id', $confirmation3->id)->where('action', 'BILLING_CONVERTED')->count());

        // Assert durable BILLING_CONVERSION_FAILED audit log exists
        $this->assertEquals(1, \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::where('consignment_billing_confirmation_id', $confirmation3->id)->where('action', 'BILLING_CONVERSION_FAILED')->count());

        // Assert physical media file stored during attachment loop was purged from disk
        $mediaStoragePath = storage_path('app/public');
        $matchingFiles = \Illuminate\Support\Facades\File::glob($mediaStoragePath . '/*/inv_post_fail.pdf');
        $this->assertEmpty($matchingFiles, 'Physical media files must be absent from disk when a post-attachment operation fails');
    }

    /** @test */
    public function it_blocks_direct_update_of_consignment_purchase_detail_lineage()
    {
        $lineage = \Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::where('purchase_id', $this->consignmentPurchase->id)->first();
        $this->assertNotNull($lineage);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('immutable and cannot be updated');

        $lineage->update(['billed_base_quantity' => 999]);
    }

    /** @test */
    public function it_blocks_direct_deletion_of_consignment_purchase_detail_lineage()
    {
        $lineage = \Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::where('purchase_id', $this->consignmentPurchase->id)->first();
        $this->assertNotNull($lineage);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('immutable and cannot be deleted');

        $lineage->delete();
    }

    /** @test */
    public function it_denies_reporting_date_override_policy_and_service_for_consignment_billing_purchase()
    {
        $user = \App\Models\User::factory()->create();

        // 1. Policy check returns false
        $canOverride = $user->can('overrideReportingDate', $this->consignmentPurchase);
        $this->assertFalse($canOverride);

        // 2. Service check throws PurchaseSourceOperationNotAllowedException
        $service = app(\App\Services\ReportingDateOverrideService::class);
        $this->expectException(\Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException::class);
        $this->expectExceptionMessage('Reporting date overrides are not permitted');

        $service->setOverride($this->consignmentPurchase, '2026-10-01', 'Reason for change', $user);
    }

    /** @test */
    public function it_blocks_direct_eloquent_updates_to_commercial_fields_or_source_type_on_consignment_billing_purchase()
    {
        // Commercial field update attempt
        try {
            $this->consignmentPurchase->update(['total_amount' => 999999.0]);
            $this->fail('Direct update to total_amount on consignment billing Purchase must throw DomainException.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Source type mutation attempt
        try {
            $this->consignmentPurchase->update(['source_type' => Purchase::SOURCE_ORDINARY]);
            $this->fail('Direct update to source_type on consignment billing Purchase must throw DomainException.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Created_at timestamp mutation attempt
        try {
            $this->consignmentPurchase->update(['created_at' => now()->subYear()]);
            $this->fail('Direct update to created_at on consignment billing Purchase must throw DomainException.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    /** @test */
    public function it_allows_settlement_balance_updates_on_consignment_billing_purchase()
    {
        $this->consignmentPurchase->update([
            'paid_amount' => 5000.0,
            'due_amount' => 5000.0,
            'payment_status' => Purchase::PAYMENT_STATUS_PARTIAL,
        ]);

        $this->consignmentPurchase->refresh();
        $this->assertEquals(5000.0, $this->consignmentPurchase->paid_amount);
        $this->assertEquals(5000.0, $this->consignmentPurchase->due_amount);
        $this->assertEquals(Purchase::PAYMENT_STATUS_PARTIAL, $this->consignmentPurchase->payment_status);
    }
}

<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage;
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
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class ConsignmentBillingConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Supplier $supplier;
    protected Product $product;
    protected Location $location;
    protected Tax $tax11;
    protected User $user;
    protected ConsignmentBillingConversionService $conversionService;

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
            'company_name' => 'Consignment Conversion Test',
            'company_email' => 'conv@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'conv@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
            'document_prefix' => 'CSG',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Conversion',
            'supplier_email' => 'conv@example.com',
            'supplier_phone' => '081111114',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor St',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Rack D',
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
            'product_name' => 'Widget Conversion',
            'product_code' => 'W-CONV',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 10,
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
        ]);

        $previewService = new ConsignmentBillingPreviewService();
        $this->conversionService = new ConsignmentBillingConversionService($previewService);
    }

    protected function createApprovedConfirmation(): ConsignmentBillingConfirmation
    {
        $confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-CONV-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Cust C',
            'customer_email' => 'c@example.com',
            'customer_phone' => '08123125',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => 'Cust C',
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
            'dispatched_quantity' => 4,
        ]);

        $soldSource = ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 4,
            'dispatched_at' => now(),
            'source_hash' => 'hash_conv_123',
            'source_snapshot' => [],
        ]);

        $confLine = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 4,
        ]);

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-CONV-001',
            'receival_number' => 'CR-CONV-001',
            'status' => 'APPROVED',
            'date' => now(),
        ]);

        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-CONV-001',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => now(),
        ]);

        $recLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 4,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 200000,
            'subtotal_dpp' => 200000,
            'total_cost' => 200000,
            'total_dpp' => 200000,
        ]);

        $crd = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $recLine->id,
            'product_id' => $this->product->id,
            'quantity_received' => 4,
            'received_base_quantity' => 4,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 22000,
        ]);

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 4,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 22000,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        return $confirmation;
    }

    /** @test */
    public function it_converts_confirmation_to_purchase_atomically_without_inventory_mutation()
    {
        $confirmation = $this->createApprovedConfirmation();

        $stockBefore = $this->product->fresh()->product_quantity;

        $metadata = [
            'supplier_invoice_number' => 'INV-CONV-001',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
            'billing_notes' => 'Test billing note',
        ];

        $purchase = $this->conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            $metadata
        );

        $this->assertInstanceOf(Purchase::class, $purchase);
        $this->assertEquals(Purchase::SOURCE_CONSIGNMENT_BILLING, $purchase->source_type);
        $this->assertEquals(Purchase::STATUS_RECEIVED, $purchase->status);
        $this->assertEquals('INV-CONV-001', $purchase->supplier_purchase_number);
        $this->assertEquals(222000.0, $purchase->total_amount);
        $this->assertEquals(0.0, $purchase->paid_amount);

        // Verify confirmation link and billed state
        $confirmation->refresh();
        $this->assertEquals($purchase->id, $confirmation->purchase_id);
        $this->assertFalse($confirmation->is_ready_for_billing);
        $this->assertEquals($this->user->id, $confirmation->billed_by);
        $this->assertNotNull($confirmation->billed_at);

        // Verify lineage creation
        $lineage = ConsignmentPurchaseDetailLineage::where('purchase_id', $purchase->id)->first();
        $this->assertNotNull($lineage);
        $this->assertEquals(4.0, $lineage->billed_base_quantity);

        // Verify inventory inertness: stock quantity unchanged, no received notes created
        $this->assertEquals($stockBefore, $this->product->fresh()->product_quantity);
        $this->assertEquals(0, ReceivedNote::where('po_id', $purchase->id)->count());
    }

    /** @test */
    public function it_is_idempotent_on_repeated_conversion_calls()
    {
        $confirmation = $this->createApprovedConfirmation();

        $metadata = [
            'supplier_invoice_number' => 'INV-CONV-002',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ];

        $p1 = $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, $metadata);
        $p2 = $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, $metadata);

        $this->assertEquals($p1->id, $p2->id);
        $this->assertEquals(1, Purchase::where('id', $p1->id)->count());
    }

    /** @test */
    public function it_rejects_idempotent_retry_if_linked_purchase_belongs_to_foreign_setting()
    {
        $confirmation = $this->createApprovedConfirmation();
        $purchase = $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, [
            'supplier_invoice_number' => 'INV-IDEM-SETTING',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        // Mutate purchase setting_id directly in DB to simulate corrupted link
        \Illuminate\Support\Facades\DB::table('purchases')->where('id', $purchase->id)->update(['setting_id' => 999999]);

        try {
            $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, [
                'supplier_invoice_number' => 'INV-IDEM-SETTING',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ]);
            $this->fail('Retry must fail when linked purchase belongs to foreign setting.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('missing or does not belong to active setting', $e->getMessage());
        }
    }

    /** @test */
    public function it_rejects_idempotent_retry_if_linked_purchase_belongs_to_different_supplier()
    {
        $confirmation = $this->createApprovedConfirmation();
        $purchase = $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, [
            'supplier_invoice_number' => 'INV-IDEM-SUPPLIER',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        $otherSupplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Other Supplier',
            'supplier_email' => 'other@example.com',
            'supplier_phone' => '1234567890',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Test Address',
            'is_active' => true,
        ]);

        \Illuminate\Support\Facades\DB::table('purchases')->where('id', $purchase->id)->update(['supplier_id' => $otherSupplier->id]);

        try {
            $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, [
                'supplier_invoice_number' => 'INV-IDEM-SUPPLIER',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ]);
            $this->fail('Retry must fail when linked purchase supplier does not match.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('does not match confirmation supplier', $e->getMessage());
        }
    }

    /** @test */
    public function it_rejects_idempotent_retry_if_linked_purchase_is_not_consignment_billing_source()
    {
        $confirmation = $this->createApprovedConfirmation();
        $purchase = $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, [
            'supplier_invoice_number' => 'INV-IDEM-SOURCE',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        \Illuminate\Support\Facades\DB::table('purchases')->where('id', $purchase->id)->update(['source_type' => 'REGULAR']);

        try {
            $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, [
                'supplier_invoice_number' => 'INV-IDEM-SOURCE',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ]);
            $this->fail('Retry must fail when linked purchase source_type is invalid.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('source type [REGULAR] is invalid', $e->getMessage());
        }
    }

    /** @test */
    public function it_rejects_idempotent_retry_if_linked_purchase_lacks_lineage_ownership()
    {
        $confirmation = $this->createApprovedConfirmation();
        $purchase = $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, [
            'supplier_invoice_number' => 'INV-IDEM-LINEAGE',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        // Delete lineage records to simulate missing lineage ownership
        ConsignmentPurchaseDetailLineage::where('purchase_id', $purchase->id)->delete();

        try {
            $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, [
                'supplier_invoice_number' => 'INV-IDEM-LINEAGE',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ]);
            $this->fail('Retry must fail when linked purchase lacks lineage ownership.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('lacks purchase detail lineage ownership', $e->getMessage());
        }
    }

    /** @test */
    public function it_rolls_back_everything_if_any_step_fails()
    {
        $confirmation = $this->createApprovedConfirmation();

        $metadata = [
            'supplier_invoice_number' => 'INV-FAIL',
            'invoice_date' => '', // Will fail preview validation
        ];

        // Note: assertions must follow the caught exception, not expectException(),
        // otherwise the post-failure state is never actually verified.
        try {
            $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, $metadata);
            $this->fail('Conversion should have failed preview validation.');
        } catch (\DomainException $e) {
            // expected
        }

        $this->assertNull($confirmation->fresh()->purchase_id);
        $this->assertTrue($confirmation->fresh()->is_ready_for_billing);
        $this->assertEquals(0, Purchase::where('supplier_purchase_number', 'INV-FAIL')->count());
    }

    /** @test */
    public function it_records_a_durable_audit_record_for_a_failed_conversion_attempt()
    {
        $confirmation = $this->createApprovedConfirmation();

        $metadata = [
            'supplier_invoice_number' => 'INV-AUDIT-FAIL',
            'invoice_date' => '',
        ];

        try {
            $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, $metadata);
            $this->fail('Conversion should have failed preview validation.');
        } catch (\DomainException $e) {
            // expected
        }

        // Conversion itself rolled back ...
        $this->assertNull($confirmation->fresh()->purchase_id);

        // ... but the failure audit evidence survives automatically recorded by the service boundary.
        $logs = \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::where('consignment_billing_confirmation_id', $confirmation->id)
            ->where('action', \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::ACTION_BILLING_CONVERSION_FAILED)
            ->get();

        $this->assertCount(1, $logs, 'Exactly one failure audit record must be generated automatically');

        $log = $logs->first();
        $this->assertEquals($this->user->id, $log->actor_id);
        $this->assertNotEmpty($log->reason);
        $this->assertEquals('INV-AUDIT-FAIL', $log->snapshot['metadata']['supplier_invoice_number']);
    }

    /** @test */
    public function it_does_not_audit_a_failed_attempt_against_a_foreign_setting_confirmation()
    {
        $confirmation = $this->createApprovedConfirmation();

        $this->conversionService->recordFailedAttempt(
            $confirmation->id,
            999999, // foreign setting
            $this->user->id,
            ['supplier_invoice_number' => 'INV-FOREIGN'],
            'Some failure'
        );

        $this->assertEquals(
            0,
            \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::where('consignment_billing_confirmation_id', $confirmation->id)
                ->where('action', \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::ACTION_BILLING_CONVERSION_FAILED)
                ->count()
        );
    }

    /** @test */
    public function it_records_generated_evidence_identities_in_the_conversion_audit_payload()
    {
        $confirmation = $this->createApprovedConfirmation();

        $purchase = $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, [
            'supplier_invoice_number' => 'INV-AUDIT-OK',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        $log = \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::where('consignment_billing_confirmation_id', $confirmation->id)
            ->where('action', 'BILLING_CONVERTED')
            ->firstOrFail();

        $expectedDetailIds = $purchase->purchaseDetails()->pluck('id')->sort()->values()->all();
        $expectedLineageIds = ConsignmentPurchaseDetailLineage::where('purchase_id', $purchase->id)
            ->pluck('id')->sort()->values()->all();

        $this->assertNotEmpty($log->snapshot['purchase_detail_ids']);
        $this->assertNotEmpty($log->snapshot['lineage_ids']);
        $this->assertEquals($expectedDetailIds, collect($log->snapshot['purchase_detail_ids'])->sort()->values()->all());
        $this->assertEquals($expectedLineageIds, collect($log->snapshot['lineage_ids'])->sort()->values()->all());
        $this->assertSame(0, $log->snapshot['attachment_count']);
    }

    /** @test */
    public function it_leaves_no_media_records_when_conversion_fails_before_commit()
    {
        $confirmation = $this->createApprovedConfirmation();

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n%DUMMY-PDF-CONTENT\n");

        try {
            $this->conversionService->convert(
                $confirmation->id,
                $this->setting->id,
                $this->user->id,
                ['supplier_invoice_number' => 'INV-MEDIA-FAIL', 'invoice_date' => ''],
                [$file]
            );
            $this->fail('Conversion should have failed preview validation.');
        } catch (\DomainException $e) {
            // expected
        }

        // Attachments are only stored after a successful commit, so a failed conversion
        // leaves neither Purchase nor media rows behind.
        $this->assertNull($confirmation->fresh()->purchase_id);
        $this->assertEquals(0, Purchase::where('supplier_purchase_number', 'INV-MEDIA-FAIL')->count());
        $this->assertEquals(0, \DB::table('media')->count());
    }

    /** @test */
    public function it_rejects_unsupported_attachment_mime_types_at_service_boundary()
    {
        $confirmation = $this->createApprovedConfirmation();
        $unsupportedFile = \Illuminate\Http\UploadedFile::fake()->create('malicious.exe', 10, 'application/x-msdownload');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported file type');

        $this->conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            [
                'supplier_invoice_number' => 'INV-INVALID-MIME',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ],
            [$unsupportedFile]
        );
    }

    /** @test */
    public function it_rejects_oversized_attachments_at_service_boundary()
    {
        $confirmation = $this->createApprovedConfirmation();
        $oversizedFile = \Illuminate\Http\UploadedFile::fake()->createWithContent('huge_invoice.pdf', str_repeat("%PDF-1.4\nHeader Padding Line Content\n", 400000)); // > 10MB

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the maximum allowed size');

        $this->conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            [
                'supplier_invoice_number' => 'INV-OVERSIZED',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ],
            [$oversizedFile]
        );
    }

    /** @test */
    public function it_rejects_plain_text_content_renamed_as_pdf_at_conversion_boundary()
    {
        $confirmation = $this->createApprovedConfirmation();
        $fakeTextPdf = \Illuminate\Http\UploadedFile::fake()->createWithContent('malicious.pdf', '<?php echo "exploit"; ?>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported file type or extension');

        $this->conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            [
                'supplier_invoice_number' => 'INV-FAKE-TEXT-PDF',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ],
            [$fakeTextPdf]
        );
    }

    /** @test */
    public function it_rejects_valid_pdf_content_renamed_as_php_at_conversion_boundary()
    {
        $confirmation = $this->createApprovedConfirmation();
        $fakePdfPhp = \Illuminate\Http\UploadedFile::fake()->createWithContent('exploit.php', "%PDF-1.4\n%DUMMY-PDF-CONTENT\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported file type or extension');

        $this->conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            [
                'supplier_invoice_number' => 'INV-PDF-PHP',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ],
            [$fakePdfPhp]
        );
    }

    /** @test */
    public function it_rejects_zero_byte_empty_mime_attachments_at_conversion_boundary()
    {
        $confirmation = $this->createApprovedConfirmation();
        $zeroByteFile = \Illuminate\Http\UploadedFile::fake()->create('zero_byte.pdf', 0); // 0-byte file (application/x-empty)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported file type');

        $this->conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            [
                'supplier_invoice_number' => 'INV-ZERO-BYTE',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ],
            [$zeroByteFile]
        );
    }

    /** @test */
    public function it_attaches_immutable_provenance_custom_properties_and_sanitizes_audit_snapshot()
    {
        $confirmation = $this->createApprovedConfirmation();
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('supplier_invoice.pdf', "%PDF-1.4\n%PROVENANCE-TEST-PDF-CONTENT\n");

        $purchase = $this->conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            [
                'supplier_invoice_number' => 'INV-PROVENANCE-001',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
                'billing_notes' => 'Test notes',
            ],
            [$file]
        );

        $media = $purchase->getFirstMedia('attachments');
        $this->assertNotNull($media);
        $this->assertEquals('CONSIGNMENT_BILLING', $media->getCustomProperty('source'));
        $this->assertEquals($confirmation->id, $media->getCustomProperty('confirmation_id'));
        $this->assertEquals($confirmation->confirmation_number, $media->getCustomProperty('confirmation_number'));
        $this->assertEquals('supplier_invoice.pdf', $media->getCustomProperty('original_name'));
        $this->assertNotEmpty($media->getCustomProperty('file_hash'));

        // Assert audit log snapshot metadata is sanitized (contains only scalar values and structured attachment metadata)
        $log = \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::where('consignment_billing_confirmation_id', $confirmation->id)
            ->where('action', 'BILLING_CONVERTED')
            ->first();

        $this->assertNotNull($log);
        $metadata = $log->snapshot['metadata'];
        $this->assertIsArray($metadata);
        $this->assertEquals('INV-PROVENANCE-001', $metadata['supplier_invoice_number']);
        $this->assertArrayNotHasKey('attachments', $metadata); // UploadedFile instances stripped from metadata

        $attachments = $log->snapshot['attachments'];
        $this->assertIsArray($attachments);
        $this->assertCount(1, $attachments);
        $this->assertEquals($media->id, $attachments[0]['media_id']);
        $this->assertEquals('supplier_invoice.pdf', $attachments[0]['original_name']);
        $this->assertEquals($media->getCustomProperty('file_hash'), $attachments[0]['sha256']);
    }

    /** @test */
    public function it_blocks_direct_media_deletion_for_conversion_attachments()
    {
        $confirmation = $this->createApprovedConfirmation();
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('supplier_invoice.pdf', "%PDF-1.4\n%PROVENANCE-TEST-PDF-CONTENT\n");

        $purchase = $this->conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            [
                'supplier_invoice_number' => 'INV-IMMUTABLE-DELETE',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ],
            [$file]
        );

        $media = $purchase->getFirstMedia('attachments');
        $this->assertNotNull($media);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('immutable and cannot be deleted');

        $media->delete();
    }

    /** @test */
    public function it_ensures_compensating_deletion_authorization_for_one_media_cannot_delete_different_immutable_attachment()
    {
        $confirmation = $this->createApprovedConfirmation();
        $file1 = \Illuminate\Http\UploadedFile::fake()->createWithContent('inv_a.pdf', "%PDF-1.4\n%CONTENT-A\n");
        $file2 = \Illuminate\Http\UploadedFile::fake()->createWithContent('inv_b.pdf', "%PDF-1.4\n%CONTENT-B\n");

        $purchase = $this->conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            [
                'supplier_invoice_number' => 'INV-INSTANCE-ISOLATION',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ],
            [$file1, $file2]
        );

        $mediaItems = $purchase->getMedia('attachments');
        $this->assertCount(2, $mediaItems);

        $mediaA = $mediaItems[0];
        $mediaB = $mediaItems[1];

        // Authorize ONLY mediaA for compensating rollback deletion
        $mediaA->isAuthorizedCompensatingRollback = true;

        // Deleting mediaA should succeed because its specific instance was authorized
        $mediaAId = $mediaA->id;
        $mediaA->delete();
        $this->assertDatabaseMissing('media', ['id' => $mediaAId]);

        // Attempting to delete mediaB (unauthorized instance) MUST still throw DomainException
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('immutable and cannot be deleted');

        $mediaB->delete();
    }

    /** @test */
    public function it_handles_fractional_consignment_quantities_during_conversion()
    {
        $confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-FRACTIONAL-001',
            'status' => ConsignmentBillingConfirmation::STATUS_DRAFT,
            'date' => now(),
            'is_ready_for_billing' => false,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Cust Frac',
            'customer_email' => 'frac@example.com',
            'customer_phone' => '081231259',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => 'Cust Frac',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 150000,
            'paid_amount' => 150000,
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
            'dispatched_quantity' => 1.5,
        ]);

        $soldSource = ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 1.5,
            'dispatched_at' => now(),
            'source_hash' => 'hash_frac_123',
            'source_snapshot' => [],
        ]);

        $confLine = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 1.5,
        ]);

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-FRAC-001',
            'receival_number' => 'CR-FRAC-001',
            'status' => 'APPROVED',
            'date' => now(),
        ]);

        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-FRAC-001',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => now(),
        ]);

        $recLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1.5,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'subtotal_cost' => 150000,
            'subtotal_dpp' => 150000,
            'total_cost' => 150000,
            'total_dpp' => 150000,
        ]);

        $crd = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $recLine->id,
            'product_id' => $this->product->id,
            'quantity_received' => 1.5,
            'received_base_quantity' => 1.5,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 16500,
        ]);

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 1.5,
            'unit_cost' => 100000,
            'unit_dpp' => 100000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 16500,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        $confirmation->update([
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'is_ready_for_billing' => true,
        ]);

        $purchase = $this->conversionService->convert(
            $confirmation->id,
            $this->setting->id,
            $this->user->id,
            [
                'supplier_invoice_number' => 'INV-FRACTIONAL-001',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ]
        );

        $detail = $purchase->purchaseDetails()->first();
        $this->assertNotNull($detail);
        $this->assertEquals(1.5, (float) $detail->quantity);

        $lineage = \Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::where('purchase_id', $purchase->id)->first();
        $this->assertNotNull($lineage);
        $this->assertEquals(1.5, (float) $lineage->billed_base_quantity);
    }

    /** @test */
    public function it_rejects_string_attachment_paths_outside_dedicated_staging_directory_and_preserves_original()
    {
        $confirmation = $this->createApprovedConfirmation();
        $publicDir = storage_path('app/public');
        if (!file_exists($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        $outsidePdf = $publicDir . '/unauthorized_public_doc.pdf';
        file_put_contents($outsidePdf, "%PDF-1.4\n%PUBLIC-FILE-CONTENT\n");

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('escapes the dedicated consignment billing staging directory');

            $this->conversionService->convert(
                $confirmation->id,
                $this->setting->id,
                $this->user->id,
                [
                    'supplier_invoice_number' => 'INV-OUTSIDE-STAGING',
                    'invoice_date' => '2026-08-28',
                    'due_date' => '2026-09-28',
                ],
                [$outsidePdf]
            );
        } finally {
            $this->assertTrue(file_exists($outsidePdf), 'File outside staging directory must remain physically intact on disk');
            if (file_exists($outsidePdf)) {
                @unlink($outsidePdf);
            }
        }
    }

    /** @test */
    public function it_cleans_up_consumed_staging_file_upon_successful_conversion()
    {
        $confirmation = $this->createApprovedConfirmation();
        $stagingDir = storage_path('app/temp/consignment-billing');
        if (!file_exists($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }

        $stagingPdf = $stagingDir . '/valid_staging_invoice.pdf';
        file_put_contents($stagingPdf, "%PDF-1.4\n%STAGING-SUCCESS-TEST-PDF-CONTENT\n");

        $this->assertTrue(file_exists($stagingPdf));

        try {
            $purchase = $this->conversionService->convert(
                $confirmation->id,
                $this->setting->id,
                $this->user->id,
                [
                    'supplier_invoice_number' => 'INV-STAGING-CLEANUP-001',
                    'invoice_date' => '2026-08-28',
                    'due_date' => '2026-09-28',
                ],
                [$stagingPdf]
            );

            $media = $purchase->getFirstMedia('attachments');
            $this->assertNotNull($media);
            $this->assertEquals('valid_staging_invoice.pdf', $media->getCustomProperty('original_name'));

            // Assert consumed staging file was automatically removed from staging directory
            $this->assertFalse(file_exists($stagingPdf), 'Consumed staging file must be cleaned up from staging directory upon successful conversion');
        } finally {
            if (file_exists($stagingPdf)) {
                @unlink($stagingPdf);
            }
        }
    }

    /** @test */
    public function it_returns_existing_purchase_on_repeated_conversion_with_consumed_staging_file_path()
    {
        $confirmation = $this->createApprovedConfirmation();
        $stagingDir = storage_path('app/temp/consignment-billing');
        if (!file_exists($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }

        $stagingPdf = $stagingDir . '/consumed_retry_invoice.pdf';
        file_put_contents($stagingPdf, "%PDF-1.4\n%RETRY-STAGING-CONTENT\n");

        $metadata = [
            'supplier_invoice_number' => 'INV-RETRY-STAGING-001',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ];

        // First conversion consumes the staging file
        $p1 = $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, $metadata, [$stagingPdf]);
        $this->assertNotNull($p1);
        $this->assertFalse(file_exists($stagingPdf), 'Staging file should be consumed on first conversion');

        // Repeated conversion using the now-consumed staging file path returns existing purchase idempotently
        $p2 = $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, $metadata, [$stagingPdf]);

        $this->assertEquals($p1->id, $p2->id);
    }

    /** @test */
    public function it_records_durable_failure_audit_when_attachment_validation_fails()
    {
        $confirmation = $this->createApprovedConfirmation();
        $invalidFile = \Illuminate\Http\UploadedFile::fake()->create('malicious.exe', 100);

        $metadata = [
            'supplier_invoice_number' => 'INV-ATTACHMENT-AUDIT-FAIL',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ];

        try {
            $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, $metadata, [$invalidFile]);
            $this->fail('Conversion should have thrown an InvalidArgumentException for invalid attachment MIME/extension.');
        } catch (\InvalidArgumentException $e) {
            // expected
        }

        $logs = \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::where('consignment_billing_confirmation_id', $confirmation->id)
            ->where('action', \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::ACTION_BILLING_CONVERSION_FAILED)
            ->get();

        $this->assertCount(1, $logs, 'Attachment policy validation failure must automatically record exactly one failure audit log');

        $log = $logs->first();
        $this->assertEquals($this->user->id, $log->actor_id);
        $this->assertStringContainsStringIgnoringCase('unsupported file type or extension', $log->reason);
    }

    /** @test */
    public function it_rejects_conversion_when_supplier_is_inactive_or_setting_mismatched()
    {
        $confirmation = $this->createApprovedConfirmation();
        
        // Deactivate supplier
        $this->supplier->update(['is_active' => false]);

        $metadata = [
            'supplier_invoice_number' => 'INV-INACTIVE-SUPPLIER',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ];

        try {
            $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, $metadata);
            $this->fail('Conversion must throw DomainException when supplier is inactive.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('inactive', $e->getMessage());
        }
    }

    /** @test */
    public function it_persists_calculated_due_date_from_payment_term_longevity_on_conversion()
    {
        $confirmation = $this->createApprovedConfirmation();
        $term = \Modules\Purchase\Entities\PaymentTerm::create([
            'name' => 'Net 60',
            'longevity' => 60,
            'is_active' => true,
        ]);

        $metadata = [
            'supplier_invoice_number' => 'INV-TERM-60-CONV',
            'invoice_date' => '2026-08-28',
            'payment_term_id' => $term->id,
        ];

        $purchase = $this->conversionService->convert($confirmation->id, $this->setting->id, $this->user->id, $metadata);

        // 1. Purchase due date & term
        $this->assertEquals('2026-10-27', $purchase->due_date->format('Y-m-d'));
        $this->assertEquals($term->id, $purchase->payment_term_id);

        // 2. Confirmation due date & term
        $this->assertEquals('2026-10-27', $confirmation->fresh()->due_date->format('Y-m-d'));
        $this->assertEquals($term->id, $confirmation->fresh()->payment_term_id);

        // 3. Conversion audit log snapshot metadata due date & term
        $log = \Modules\Consignment\Entities\ConsignmentAllocationAuditLog::where('consignment_billing_confirmation_id', $confirmation->id)
            ->where('action', 'BILLING_CONVERTED')
            ->firstOrFail();

        $this->assertEquals('2026-10-27', $log->snapshot['metadata']['due_date']);
        $this->assertEquals($term->id, $log->snapshot['metadata']['payment_term_id']);
    }
}

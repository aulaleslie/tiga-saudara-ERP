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
use Modules\Consignment\Entities\ConsignmentSerializedAllocation;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBillingPreviewService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class ConsignmentBillingPreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Supplier $supplier;
    protected Product $product;
    protected Location $location;
    protected Tax $tax11;
    protected ConsignmentBillingPreviewService $previewService;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Consignment Preview Test',
            'company_email' => 'preview@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'preview@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
            'document_prefix' => 'CSG',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Preview',
            'supplier_email' => 'prev@example.com',
            'supplier_phone' => '081111113',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor St',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Rack C',
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
            'product_name' => 'Widget Preview',
            'product_code' => 'W-PREV',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 10,
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
        ]);

        $this->previewService = new ConsignmentBillingPreviewService();
    }

    protected function createApprovedConfirmationWithAllocations(float $cost1 = 50000, float $cost2 = 50000): ConsignmentBillingConfirmation
    {
        $confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-PREV-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Cust B',
            'customer_email' => 'b@example.com',
            'customer_phone' => '08123124',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => 'Cust B',
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
            'dispatched_quantity' => 5,
        ]);

        $soldSource = ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 5,
            'dispatched_at' => now(),
            'source_hash' => 'hash_prev_123',
            'source_snapshot' => [],
        ]);

        $confLine = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 5,
        ]);

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-PREV-001',
            'receival_number' => 'CR-PREV-001',
            'status' => 'APPROVED',
            'date' => now(),
        ]);

        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-PREV-001',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => now(),
        ]);

        $recLine1 = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 3,
            'unit_cost' => $cost1,
            'unit_dpp' => $cost1,
            'subtotal_cost' => $cost1 * 3,
            'subtotal_dpp' => $cost1 * 3,
            'total_cost' => $cost1 * 3,
            'total_dpp' => $cost1 * 3,
        ]);

        $crd1 = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $recLine1->id,
            'product_id' => $this->product->id,
            'quantity_received' => 3,
            'received_base_quantity' => 3,
            'unit_cost' => $cost1,
            'unit_dpp' => $cost1,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => round($cost1 * 3 * 0.11, 2),
        ]);

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receiving_detail_id' => $crd1->id,
            'allocated_base_quantity' => 3,
            'unit_cost' => $cost1,
            'unit_dpp' => $cost1,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => round($cost1 * 3 * 0.11, 2),
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
            'receiving_detail_snapshot' => [
                'unit_cost' => $cost1,
                'unit_dpp' => $cost1,
                'tax_id' => $this->tax11->id,
                'tax_rate' => 11,
                'tax_snapshot_version' => 2,
            ],
        ]);

        $receival2 = ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-PREV-002',
            'receival_number' => 'CR-PREV-002',
            'status' => 'APPROVED',
            'date' => now(),
        ]);

        $receiving2 = ConsignmentReceiving::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'consignment_receival_id' => $receival2->id,
            'receiving_number' => 'CR-PREV-002',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => now(),
        ]);

        $recLine2 = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival2->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'unit_cost' => $cost2,
            'unit_dpp' => $cost2,
            'subtotal_cost' => $cost2 * 2,
            'subtotal_dpp' => $cost2 * 2,
            'total_cost' => $cost2 * 2,
            'total_dpp' => $cost2 * 2,
        ]);

        $crd2 = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving2->id,
            'consignment_receival_line_id' => $recLine2->id,
            'product_id' => $this->product->id,
            'quantity_received' => 2,
            'received_base_quantity' => 2,
            'unit_cost' => $cost2,
            'unit_dpp' => $cost2,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => round($cost2 * 2 * 0.11, 2),
        ]);

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receiving_detail_id' => $crd2->id,
            'allocated_base_quantity' => 2,
            'unit_cost' => $cost2,
            'unit_dpp' => $cost2,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => round($cost2 * 2 * 0.11, 2),
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
            'receiving_detail_snapshot' => [
                'unit_cost' => $cost2,
                'unit_dpp' => $cost2,
                'tax_id' => $this->tax11->id,
                'tax_rate' => 11,
                'tax_snapshot_version' => 2,
            ],
        ]);

        return $confirmation;
    }

    /** @test */
    public function it_generates_valid_preview_for_approved_confirmation_with_same_cost()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $metadata = [
            'supplier_invoice_number' => 'INV/2026/001',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ];

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $metadata);

        $this->assertTrue($preview['valid']);
        $this->assertEmpty($preview['blockers']);
        $this->assertCount(1, $preview['lines']); // Grouped into 1 line since costs & tax match

        $line = $preview['lines'][0];
        $this->assertEquals(5.0, $line['quantity']);
        // PKP defaults tax-included=true, so an unchanged row's displayed unit
        // price is the gross (tax-inclusive) price that reproduces the original
        // total, while original_unit_price keeps the DPP evidence.
        $this->assertEquals(50000.0, $line['original_unit_price']);
        $this->assertEquals(55500.0, $line['unit_price']);
        $this->assertEquals(250000.0, $line['sub_total']);
        $this->assertEquals(27500.0, $line['product_tax_amount']);
        $this->assertEquals(277500.0, $line['total_amount']);

        $this->assertEquals(250000.0, $preview['totals']['sub_total']);
        $this->assertEquals(27500.0, $preview['totals']['tax_amount']);
        $this->assertEquals(277500.0, $preview['totals']['total_amount']);
    }

    /** @test */
    public function it_preserves_distinct_lines_when_receiving_costs_differ()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 60000);

        $metadata = [
            'supplier_invoice_number' => 'INV/2026/002',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ];

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $metadata);

        $this->assertTrue($preview['valid']);
        $this->assertEmpty($preview['blockers']);
        $this->assertCount(2, $preview['lines']); // Kept distinct because costs are 50,000 and 60,000

        $totalSubTotal = 3 * 50000 + 2 * 60000; // 270,000
        $this->assertEquals(270000.0, $preview['totals']['sub_total']);
    }

    /** @test */
    public function it_rejects_missing_supplier_invoice_number_or_date()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations();

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, [
            'supplier_invoice_number' => '',
            'invoice_date' => '',
        ]);

        $this->assertFalse($preview['valid']);
        $this->assertContains('Supplier invoice number is required.', $preview['blockers']);
        $this->assertContains('Invoice date is required.', $preview['blockers']);
    }

    /** @test */
    public function it_rejects_foreign_setting_or_unapproved_confirmation()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations();

        $preview = $this->previewService->generatePreview($confirmation->id, 99999, [
            'supplier_invoice_number' => 'INV-FOREIGN',
            'invoice_date' => '2026-08-28',
        ]);

        $this->assertFalse($preview['valid']);
        // Foreign and nonexistent confirmations must be indistinguishable.
        $this->assertContains('Confirmation record is not available.', $preview['blockers']);

        $missing = $this->previewService->generatePreview(999999, $this->setting->id, [
            'supplier_invoice_number' => 'INV-MISSING',
            'invoice_date' => '2026-08-28',
        ]);

        $this->assertFalse($missing['valid']);
        $this->assertSame($preview['blockers'], $missing['blockers']);
    }

    private function validMetadata(): array
    {
        return [
            'supplier_invoice_number' => 'INV/VERSION/001',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ];
    }

    /** @test */
    public function it_accepts_explicit_version_2_proportional_tax_evidence()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertTrue($preview['valid']);
        $this->assertEmpty($preview['blockers']);

        foreach ($preview['lines'][0]['allocations'] as $allocation) {
            $this->assertFalse($allocation['is_legacy_full_lot_tax']);
            $this->assertEquals(
                ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
                $allocation['tax_snapshot_version']
            );
        }
    }

    /** @test */
    public function it_accepts_and_normalizes_explicit_version_1_legacy_full_lot_tax_evidence()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        // Legacy shape: partial quantity but full-lot tax stored, explicitly marked v1.
        $confirmation->load('lines.receiptAllocations.receivingDetail');
        $line = $confirmation->lines->first();
        $alloc = $line->receiptAllocations->first();
        $crd = $alloc->receivingDetail;
        $alloc->forceFill([
            'allocated_base_quantity' => 2,
            'tax_amount' => (float) $crd->tax_amount, // full-lot tax for 3 units
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_LEGACY,
        ])->saveQuietly();
        $line->forceFill(['allocated_base_quantity' => 4])->saveQuietly();
        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $taxBlockers = array_values(array_filter($preview['blockers'], fn ($b) => str_contains($b, 'tax')));
        $this->assertSame([], $taxBlockers, 'Explicit v1 legacy tax evidence must be accepted.');

        $legacy = null;
        foreach ($preview['lines'] as $previewLine) {
            foreach ($previewLine['allocations'] as $allocation) {
                if ((int) $allocation['receipt_allocation_id'] === (int) $alloc->id) {
                    $legacy = $allocation;
                }
            }
        }

        $this->assertNotNull($legacy);
        $this->assertTrue($legacy['is_legacy_full_lot_tax']);
        // Normalized to proportional tax for the billed quantity (2 of 3 units).
        $this->assertEquals(round(50000 * 2 * 0.11, 2), $legacy['tax_amount']);
        $this->assertEquals((float) $crd->tax_amount, $legacy['original_stored_tax_amount']);
    }

    /** @test */
    public function it_rejects_allocations_with_missing_tax_snapshot_version()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $alloc = $confirmation->lines->first()->receiptAllocations->first();
        \Illuminate\Support\Facades\DB::table('consignment_receipt_allocations')
            ->where('id', $alloc->id)
            ->update(['tax_snapshot_version' => null]);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertFalse($preview['valid']);
        $this->assertContains(
            "Receipt allocation #{$alloc->id} has no tax snapshot version; tax evidence cannot be classified.",
            $preview['blockers']
        );
    }

    /** @test */
    public function it_rejects_allocations_with_unsupported_tax_snapshot_version()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $alloc = $confirmation->lines->first()->receiptAllocations->first();
        \Illuminate\Support\Facades\DB::table('consignment_receipt_allocations')
            ->where('id', $alloc->id)
            ->update(['tax_snapshot_version' => 99]);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertFalse($preview['valid']);
        $this->assertContains(
            "Receipt allocation #{$alloc->id} has unsupported tax snapshot version [99].",
            $preview['blockers']
        );
    }

    /** @test */
    public function it_calculates_grouped_line_tax_as_exact_sum_of_normalized_allocation_taxes()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(45455, 45455);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());
        $this->assertTrue($preview['valid']);

        $line = $preview['lines'][0];
        $expectedLineTax = array_sum(array_column($line['allocations'], 'tax_amount'));

        $this->assertEquals(round($expectedLineTax, 2), $line['product_tax_amount']);
        $this->assertEquals(round($expectedLineTax, 2), $preview['totals']['tax_amount']);
    }

    /** @test */
    public function it_rejects_preview_when_serialized_allocation_count_is_mismatched()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);
        $confLine = $confirmation->lines->first();
        $alloc = $confLine->receiptAllocations()->with('receivingDetail.receivalLine')->first();

        // Mark line as serialized
        $confLine->soldSource->update(['is_serialized' => true]);
        $alloc->receivingDetail->receivalLine->update(['is_serialized' => true]);

        // Alloc qty is 3, but no serialized allocations exist -> missing serial
        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('requires [3] serials, but [0] approved serial allocations were provided', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function it_rejects_preview_when_duplicate_serial_identity_is_attached()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);
        $confLine = $confirmation->lines->first();
        $alloc = $confLine->receiptAllocations()->with('receivingDetail')->first();
        $crd = $alloc->receivingDetail;

        $sn = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->setting->id,
            'serial_number' => 'SN-DUP-TEST-001',
            'status' => 'IN_STOCK',
        ]);
        $crd->serialNumbers()->attach($sn->id);

        // Attach duplicate serial allocations
        \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'setting_id' => $this->setting->id,
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_sold_source_id' => $confLine->consignment_sold_source_id,
            'consignment_receiving_detail_id' => $crd->id,
            'product_serial_number_id' => $sn->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);
        \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'setting_id' => $this->setting->id,
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_sold_source_id' => $confLine->consignment_sold_source_id,
            'consignment_receiving_detail_id' => $crd->id,
            'product_serial_number_id' => $sn->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('is allocated more than once across confirmation', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function it_rejects_preview_when_same_physical_serial_is_allocated_across_different_confirmation_lines()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);
        $confLine1 = $confirmation->lines->first();
        $alloc1 = $confLine1->receiptAllocations()->with('receivingDetail')->first();
        $crd1 = $alloc1->receivingDetail;

        $dispatchDetail2 = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $confLine1->soldSource->dispatchDetail->dispatch_id,
            'sale_id' => $confLine1->soldSource->sale_id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $soldSource2 = ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $dispatchDetail2->id,
            'sale_id' => $confLine1->soldSource->sale_id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 1,
            'dispatched_at' => now(),
            'source_hash' => 'hash_cross_line_2',
            'source_snapshot' => [],
        ]);

        // Create second confirmation line & allocation
        $confLine2 = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_sold_source_id' => $soldSource2->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 1,
        ]);
        $alloc2 = ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine2->id,
            'consignment_receiving_detail_id' => $crd1->id,
            'allocated_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 5500,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        $sn = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->setting->id,
            'serial_number' => 'SN-CROSS-LINE-001',
            'status' => 'IN_STOCK',
        ]);
        $crd1->serialNumbers()->attach($sn->id);

        // Attach SAME physical serial to both confLine1 and confLine2
        \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'setting_id' => $this->setting->id,
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confLine1->id,
            'consignment_sold_source_id' => $confLine1->consignment_sold_source_id,
            'consignment_receiving_detail_id' => $crd1->id,
            'product_serial_number_id' => $sn->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);
        \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'setting_id' => $this->setting->id,
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confLine2->id,
            'consignment_sold_source_id' => $confLine2->consignment_sold_source_id,
            'consignment_receiving_detail_id' => $crd1->id,
            'product_serial_number_id' => $sn->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('is allocated more than once across confirmation', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function it_rejects_preview_when_serial_allocation_is_attached_to_non_serialized_line()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);
        $confLine = $confirmation->lines->first();
        $alloc = $confLine->receiptAllocations()->with('receivingDetail.receivalLine')->first();
        $crd = $alloc->receivingDetail;

        // Ensure sold source and receival line are NOT serialized
        $confLine->soldSource->update(['is_serialized' => false]);
        $crd->receivalLine->update(['is_serialized' => false]);

        $sn = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->setting->id,
            'serial_number' => 'SN-NONSER-TEST-001',
            'status' => 'IN_STOCK',
        ]);
        $crd->serialNumbers()->attach($sn->id);

        \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'setting_id' => $this->setting->id,
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_sold_source_id' => $confLine->consignment_sold_source_id,
            'consignment_receiving_detail_id' => $crd->id,
            'product_serial_number_id' => $sn->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('Non-serialized confirmation line', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function it_rejects_preview_when_serialized_receipt_allocation_quantity_is_fractional()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);
        $confLine = $confirmation->lines->first();
        $alloc = $confLine->receiptAllocations()->with('receivingDetail.receivalLine')->first();

        // Mark sold source and receival line as serialized and set allocation quantity to fractional
        $confLine->soldSource->update(['is_serialized' => true]);
        $alloc->receivingDetail->receivalLine->update(['is_serialized' => true]);
        \Illuminate\Support\Facades\DB::table('consignment_receipt_allocations')
            ->where('id', $alloc->id)
            ->update(['allocated_base_quantity' => 1.5]);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('must be integral', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function it_rejects_preview_when_serialized_allocation_sold_source_id_is_mismatched()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);
        $confLine = $confirmation->lines->first();
        $alloc = $confLine->receiptAllocations()->with('receivingDetail.receivalLine')->first();
        $crd = $alloc->receivingDetail;

        $dispatchDetail2 = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $confLine->soldSource->dispatchDetail->dispatch_id,
            'sale_id' => $confLine->soldSource->sale_id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $soldSource2 = ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $dispatchDetail2->id,
            'sale_id' => $confLine->soldSource->sale_id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 1,
            'dispatched_at' => now(),
            'source_hash' => 'hash_mismatch_sold_2',
            'source_snapshot' => [],
        ]);

        $sn = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->setting->id,
            'serial_number' => 'SN-MISMATCH-SOLD-001',
            'status' => 'IN_STOCK',
        ]);
        $crd->serialNumbers()->attach($sn->id);

        \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'setting_id' => $this->setting->id,
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_sold_source_id' => $soldSource2->id, // Mismatched sold_source_id
            'consignment_receiving_detail_id' => $crd->id,
            'product_serial_number_id' => $sn->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('does not match confirmation line', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function it_rejects_preview_when_allocated_serial_is_foreign_to_sold_source_serial_identities()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);
        $confLine = $confirmation->lines->first();
        $alloc = $confLine->receiptAllocations()->with('receivingDetail.receivalLine')->first();
        $crd = $alloc->receivingDetail;

        // Set both soldSource and all receivalLines as serialized
        \Illuminate\Support\Facades\DB::table('consignment_sold_sources')
            ->where('id', $confLine->consignment_sold_source_id)
            ->update(['serial_identities' => json_encode(['SN-EXPECTED-001'])]);

        foreach ($confLine->receiptAllocations()->with('receivingDetail.receivalLine')->get() as $ra) {
            $ra->receivingDetail?->receivalLine?->update(['is_serialized' => true]);
        }

        $snForeign = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->setting->id,
            'serial_number' => 'SN-FOREIGN-001',
            'status' => 'IN_STOCK',
        ]);
        $crd->serialNumbers()->attach($snForeign->id);

        \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'setting_id' => $this->setting->id,
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_sold_source_id' => $confLine->consignment_sold_source_id,
            'consignment_receiving_detail_id' => $crd->id,
            'product_serial_number_id' => $snForeign->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('is not present in sold source', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function it_rejects_preview_when_sold_and_receiving_serialization_classifications_mismatch()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);
        $confLine = $confirmation->lines->first();
        $alloc = $confLine->receiptAllocations()->with('receivingDetail.receivalLine')->first();
        $crd = $alloc->receivingDetail;

        // Set soldSource as serialized (has serial_identities) but receivalLine as non-serialized
        \Illuminate\Support\Facades\DB::table('consignment_sold_sources')
            ->where('id', $confLine->consignment_sold_source_id)
            ->update(['serial_identities' => json_encode(['SN-MISMATCH-001'])]);
        $crd->receivalLine->update(['is_serialized' => false]);

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $this->validMetadata());

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('sold-side serialization [true] does not match receiving detail', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function it_calculates_due_date_from_payment_term_longevity_when_due_date_is_omitted()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);
        $term = \Modules\Purchase\Entities\PaymentTerm::create([
            'name' => 'Net 30',
            'longevity' => 30,
            'is_active' => true,
        ]);

        $metadata = [
            'supplier_invoice_number' => 'INV-TERM-30',
            'invoice_date' => '2026-08-28',
            'payment_term_id' => $term->id,
        ];

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $metadata);

        $this->assertTrue($preview['valid']);
        $this->assertEquals('2026-09-27', $preview['resolved_due_date']);
    }

    /** @test */
    public function it_accepts_explicit_due_date_on_or_after_invoice_date()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $metadata = [
            'supplier_invoice_number' => 'INV-EXPLICIT-DUE',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-15',
        ];

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $metadata);

        $this->assertTrue($preview['valid']);
        $this->assertEquals('2026-09-15', $preview['resolved_due_date']);
    }

    /** @test */
    public function it_rejects_preview_when_both_due_date_and_payment_term_are_missing()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $metadata = [
            'supplier_invoice_number' => 'INV-NO-DUE',
            'invoice_date' => '2026-08-28',
        ];

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $metadata);

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('At least one of due date or payment term is required', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function it_rejects_preview_when_due_date_is_before_invoice_date()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $metadata = [
            'supplier_invoice_number' => 'INV-PAST-DUE',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-08-20',
        ];

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $metadata);

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('cannot be before invoice date', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function non_pkp_form_default_intent_does_not_reject_its_own_submission()
    {
        $this->setting->update(['is_pkp' => false]);
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $metadata = [
            'supplier_invoice_number' => 'INV-NONPKP-001',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ];

        // Mirrors exactly what the billing page always sends: is_tax_included=0 present
        // regardless of PKP status, and no row edits.
        $pricingIntent = [
            'is_tax_included' => false,
            'global_discount_type' => 'fixed',
            'global_discount_value' => 0,
            'rows' => [],
        ];

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $metadata, $pricingIntent);

        $this->assertTrue($preview['valid']);
        $this->assertEmpty($preview['blockers']);
    }

    /** @test */
    public function pkp_row_with_only_default_ui_state_is_treated_as_unedited()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $metadata = [
            'supplier_invoice_number' => 'INV-PKP-DEFAULTS',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ];

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $metadata);
        $rowId = $preview['lines'][0]['row_id'];
        $originalTotal = $preview['lines'][0]['total_amount'];

        // Mirrors what the form sends for a row nobody touched: its current tax_id and
        // the default discount_type dropdown value, but no actual price/discount/tax edit.
        $pricingIntent = [
            'is_tax_included' => true,
            'rows' => [
                $rowId => [
                    'discount_type' => 'fixed',
                    'tax_id' => $preview['lines'][0]['tax_id'],
                ],
            ],
        ];

        $secondPreview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $metadata, $pricingIntent);

        $this->assertTrue($secondPreview['valid']);
        $this->assertEquals($originalTotal, $secondPreview['lines'][0]['total_amount']);
        $this->assertEquals(50000.0, $secondPreview['lines'][0]['original_unit_price']);
    }

    /** @test */
    public function preview_rejects_a_pricing_intent_referencing_an_unknown_row_id()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $metadata = [
            'supplier_invoice_number' => 'INV-STALE-ROW',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ];

        $pricingIntent = [
            'rows' => [
                'unknown-row-id' => ['discount_type' => 'fixed', 'discount_value' => 1000],
            ],
        ];

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, $metadata, $pricingIntent);

        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('unknown or stale row', implode('; ', $preview['blockers']));
    }

    /** @test */
    public function preview_renders_billing_rows_with_completely_blank_metadata()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        // No supplier_invoice_number, no invoice_date, no due_date, no payment_term_id
        // at all -- the operator must be able to see and edit rows before typing
        // anything into the invoice header.
        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, []);

        $this->assertFalse($preview['valid']); // metadata is incomplete, so not yet convertible
        $this->assertNotEmpty($preview['lines']);
        $this->assertEquals(5.0, $preview['lines'][0]['quantity']);
        $this->assertGreaterThan(0.0, $preview['totals']['total_amount']);
        $this->assertContains('Supplier invoice number is required.', $preview['blockers']);
        $this->assertContains('Invoice date is required.', $preview['blockers']);
        $this->assertContains('At least one of due date or payment term is required.', $preview['blockers']);
    }

    /** @test */
    public function preview_recalculates_rows_from_pricing_intent_even_with_blank_metadata()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);

        $blankPreview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, []);
        $rowId = $blankPreview['lines'][0]['row_id'];

        $pricingIntent = [
            'rows' => [
                $rowId => ['discount_type' => 'fixed', 'discount_value' => 5000],
            ],
        ];

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, [], $pricingIntent);

        $this->assertNotEmpty($preview['lines']);
        // 5 units @ 50000, Rp5,000 fixed discount/unit, non-PKP by default in this
        // fixture's setting -- but this fixture setting is PKP; assert the discount
        // was applied against the row (unit price reduced) regardless of tax handling.
        $this->assertLessThan($blankPreview['lines'][0]['total_amount'], $preview['lines'][0]['total_amount']);
    }

    /** @test */
    public function evidence_level_blocker_still_suppresses_the_pricing_table()
    {
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 50000);
        $this->supplier->forceFill(['is_active' => false])->saveQuietly();

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, []);

        $this->assertFalse($preview['valid']);
        $this->assertEmpty($preview['lines']);
    }

    /** @test */
    public function two_individually_valid_row_unit_prices_are_rejected_when_their_sum_overflows_the_header_column()
    {
        // purchases.total_amount is DECIMAL(15,2), max 9999999999999.99. Two
        // distinct-cost lots produce two rows here; each row's submitted unit price
        // is well within unit_price's own DECIMAL(15,6) range (max
        // 999999999.999999) and each row total individually fits the header
        // column, but their combined sum does not -- this must be caught as a
        // preview blocker rather than surfacing only as a database error at
        // conversion. Quantities are raised so a realistic (in-range) unit price
        // on each row is enough to make the row totals large.
        $confirmation = $this->createApprovedConfirmationWithAllocations(50000, 60000);

        $confirmation->load('lines.receiptAllocations');
        foreach ($confirmation->lines as $line) {
            $line->forceFill(['allocated_base_quantity' => 5001])->saveQuietly();
            foreach ($line->receiptAllocations as $alloc) {
                $alloc->forceFill(['allocated_base_quantity' => 5001])->saveQuietly();
            }
        }

        $preview = $this->previewService->generatePreview($confirmation->id, $this->setting->id, []);
        $rowId1 = $preview['lines'][0]['row_id'];
        $rowId2 = $preview['lines'][1]['row_id'];

        $pricingIntent = [
            'rows' => [
                $rowId1 => ['unit_price' => 999999999.0],
                $rowId2 => ['unit_price' => 999999999.0],
            ],
        ];

        $result = $this->previewService->generatePreview($confirmation->id, $this->setting->id, [], $pricingIntent);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds the maximum', implode('; ', $result['blockers']));
    }
}

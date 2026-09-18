<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSerializedAllocation;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseShowConsignmentProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $supplier;
    protected $category;
    protected $unit;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

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
            'company_phone' => '123456',
            'notification_email' => 'notify@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        session(['setting_id' => $this->setting->id]);

        $this->user = User::factory()->create();

        $this->supplier = Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        DB::statement('PRAGMA foreign_keys = OFF');

        $this->category = Category::create([
            'id' => 1,
            'category_code' => 'CAT01',
            'category_name' => 'Test Category',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->unit = Unit::create([
            'id' => 1,
            'operator' => '*',
            'operation_value' => 1,
            'short_name' => 'pc',
            'name' => 'Piece',
            'setting_id' => $this->setting->id,
        ]);

        \Modules\Setting\Entities\Location::create([
            'id' => 1,
            'name' => 'Test Location',
            'setting_id' => $this->setting->id,
        ]);

        Permission::findOrCreate('purchases.show', 'web');
        Permission::findOrCreate('purchases.reporting-date.override', 'web');
        Permission::findOrCreate('purchases.due-date.override', 'web');
        $this->user->givePermissionTo('purchases.show');
        $this->actingAs($this->user);
    }

    protected function createProduct($name = 'Test Product', $code = 'P001')
    {
        return Product::create([
            'product_name' => $name,
            'product_code' => $code,
            'product_unit' => 'pc',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 0,
            'product_stock_alert' => 1,
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'product_barcode_symbology' => 'C128',
            'unit_id' => 1,
            'stock_managed' => 1,
        ]);
    }

    protected function createConsignmentPurchase(string $reference = 'TPI-BL-001'): Purchase
    {
        return Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => $reference,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
            'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING,
        ]);
    }

    protected function createReceival(string $reference): ConsignmentReceival
    {
        return ConsignmentReceival::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'reference' => $reference,
            'date' => now(),
            'status' => ConsignmentReceival::STATUS_APPROVED,
        ]);
    }

    protected function createReceiving(ConsignmentReceival $receival, string $receivingNumber): ConsignmentReceiving
    {
        return ConsignmentReceiving::create([
            'consignment_receival_id' => $receival->id,
            'setting_id' => $this->setting->id,
            'location_id' => 1,
            'receiving_number' => $receivingNumber,
            'date' => now(),
            'status' => ConsignmentReceiving::STATUS_APPROVED,
        ]);
    }

    protected function createReceivingDetail(ConsignmentReceiving $receiving, Product $product): ConsignmentReceivingDetail
    {
        $receivalLine = \Modules\Consignment\Entities\ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receiving->consignment_receival_id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'unit_id' => 1,
            'unit_code' => 'pc',
            'quantity' => 1,
            'unit_cost' => 1000,
            'unit_dpp' => 1000,
            'subtotal_cost' => 1000,
            'tax_amount' => 0,
            'total_cost' => 1000,
            'is_serialized' => false,
        ]);

        return ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $receivalLine->id,
            'product_id' => $product->id,
            'quantity_received' => 1,
            'unit_cost' => 1000,
            'unit_dpp' => 1000,
        ]);
    }

    protected function createReceiptAllocation(ConsignmentReceivingDetail $receivingDetail, string $receivalReference, string $receivingReference, int $confirmationLineId): ConsignmentReceiptAllocation
    {
        return ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confirmationLineId,
            'consignment_receiving_detail_id' => $receivingDetail->id,
            'allocated_base_quantity' => 1,
            'unit_cost' => 1000,
            'unit_dpp' => 1000,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
            'receival_reference' => $receivalReference,
            'receiving_reference' => $receivingReference,
        ]);
    }

    protected function createConfirmation(Purchase $purchase): \Modules\Consignment\Entities\ConsignmentBillingConfirmation
    {
        $existing = \Modules\Consignment\Entities\ConsignmentBillingConfirmation::where('purchase_id', $purchase->id)->first();

        if ($existing) {
            return $existing;
        }

        return \Modules\Consignment\Entities\ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'confirmation_number' => 'CBC-' . $purchase->id,
            'status' => \Modules\Consignment\Entities\ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'purchase_id' => $purchase->id,
        ]);
    }

    protected function createDispatchDetail(Product $product): \Modules\Sale\Entities\DispatchDetail
    {
        $sale = \Modules\Sale\Entities\Sale::create([
            'reference' => 'SALE-' . uniqid(),
            'date' => now(),
            'due_date' => now()->addDays(30),
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'status' => \Modules\Sale\Entities\Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
        ]);

        return \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => 1,
        ]);
    }

    protected function createConfirmationLine(\Modules\Consignment\Entities\ConsignmentBillingConfirmation $confirmation, Product $product): \Modules\Consignment\Entities\ConsignmentBillingConfirmationLine
    {
        $dispatchDetail = $this->createDispatchDetail($product);

        $soldSource = \Modules\Consignment\Entities\ConsignmentSoldSource::create([
            'setting_id' => $this->setting->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $dispatchDetail->sale_id,
            'product_id' => $product->id,
            'location_id' => 1,
            'original_base_quantity' => 1,
            'source_hash' => bin2hex(random_bytes(16)),
            'source_snapshot' => [],
        ]);

        return \Modules\Consignment\Entities\ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $product->id,
            'location_id' => 1,
            'allocated_base_quantity' => 1,
        ]);
    }

    protected function createLineage(Purchase $purchase, PurchaseDetail $detail, Product $product, array $overrides = []): ConsignmentPurchaseDetailLineage
    {
        $confirmation = $this->createConfirmation($purchase);
        $confirmationLine = $this->createConfirmationLine($confirmation, $product);

        $overrides = array_merge(['consignment_billing_confirmation_line_id' => $confirmationLine->id], $overrides);

        return ConsignmentPurchaseDetailLineage::create(array_merge([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'purchase_detail_id' => $detail->id,
            'product_id' => $product->id,
            'billed_base_quantity' => 1,
            'unit_cost' => 1000,
            'unit_dpp' => 1000,
            'consignment_billing_confirmation_id' => $confirmation->id,
        ], $overrides));
    }

    public function test_multiple_serialized_allocations_share_one_source_group()
    {
        $purchase = $this->createConsignmentPurchase('TPI-BL-GROUP-001');
        $product = $this->createProduct('Grouped Product', 'GP001');

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $receival = $this->createReceival('TPI-CR-2026-08-00001');
        $receiving = $this->createReceiving($receival, 'TPI-CRN-2026-08-00001');
        $receivingDetail = $this->createReceivingDetail($receiving, $product);
        $confirmation = $this->createConfirmation($purchase);
        $confirmationLine = $this->createConfirmationLine($confirmation, $product);
        $receiptAllocation = $this->createReceiptAllocation($receivingDetail, 'TPI-CR-2026-08-00001', 'TPI-CRN-2026-08-00001', $confirmationLine->id);

        $serialA = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-GROUP-A',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'location_id' => 1,
        ]);
        $serialB = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-GROUP-B',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'location_id' => 1,
        ]);

        $allocationA = ConsignmentSerializedAllocation::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confirmationLine->id,
            'consignment_sold_source_id' => $confirmationLine->consignment_sold_source_id,
            'product_serial_number_id' => $serialA->id,
            'consignment_receiving_detail_id' => $receivingDetail->id,
            'status' => ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);
        $allocationB = ConsignmentSerializedAllocation::create([
            'consignment_billing_confirmation_id' => $confirmation->id,
            'consignment_billing_confirmation_line_id' => $confirmationLine->id,
            'consignment_sold_source_id' => $confirmationLine->consignment_sold_source_id,
            'product_serial_number_id' => $serialB->id,
            'consignment_receiving_detail_id' => $receivingDetail->id,
            'status' => ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);

        $this->createLineage($purchase, $detail, $product, [
            'consignment_receipt_allocation_id' => $receiptAllocation->id,
            'consignment_serialized_allocation_id' => $allocationA->id,
            'consignment_receiving_detail_id' => $receivingDetail->id,
            'billed_base_quantity' => 1,
        ]);
        $this->createLineage($purchase, $detail, $product, [
            'consignment_receipt_allocation_id' => $receiptAllocation->id,
            'consignment_serialized_allocation_id' => $allocationB->id,
            'consignment_receiving_detail_id' => $receivingDetail->id,
            'billed_base_quantity' => 1,
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertSee('Konsinyasi TPI-CR-2026-08-00001');
        $response->assertSee('Penerimaan TPI-CRN-2026-08-00001');
        $response->assertSee('SN-GROUP-A');
        $response->assertSee('SN-GROUP-B');
        $response->assertSee('Qty 2.000');
    }

    public function test_one_purchase_detail_spans_different_sources()
    {
        $purchase = $this->createConsignmentPurchase('TPI-BL-MULTISRC-001');
        $product = $this->createProduct('Multi Source Product', 'MS001');

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $confirmation = $this->createConfirmation($purchase);

        $receivalOne = $this->createReceival('TPI-CR-2026-08-00010');
        $receivingOne = $this->createReceiving($receivalOne, 'TPI-CRN-2026-08-00010');
        $receivingDetailOne = $this->createReceivingDetail($receivingOne, $product);
        $confirmationLineOne = $this->createConfirmationLine($confirmation, $product);
        $allocationOne = $this->createReceiptAllocation($receivingDetailOne, 'TPI-CR-2026-08-00010', 'TPI-CRN-2026-08-00010', $confirmationLineOne->id);

        $receivalTwo = $this->createReceival('TPI-CR-2026-08-00011');
        $receivingTwo = $this->createReceiving($receivalTwo, 'TPI-CRN-2026-08-00011');
        $receivingDetailTwo = $this->createReceivingDetail($receivingTwo, $product);
        $confirmationLineTwo = $this->createConfirmationLine($confirmation, $product);
        $allocationTwo = $this->createReceiptAllocation($receivingDetailTwo, 'TPI-CR-2026-08-00011', 'TPI-CRN-2026-08-00011', $confirmationLineTwo->id);

        $this->createLineage($purchase, $detail, $product, [
            'consignment_receipt_allocation_id' => $allocationOne->id,
            'consignment_receiving_detail_id' => $receivingDetailOne->id,
            'billed_base_quantity' => 1,
        ]);
        $this->createLineage($purchase, $detail, $product, [
            'consignment_receipt_allocation_id' => $allocationTwo->id,
            'consignment_receiving_detail_id' => $receivingDetailTwo->id,
            'billed_base_quantity' => 1,
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertSee('Konsinyasi TPI-CR-2026-08-00010');
        $response->assertSee('Konsinyasi TPI-CR-2026-08-00011');
        $response->assertSee('Penerimaan TPI-CRN-2026-08-00010');
        $response->assertSee('Penerimaan TPI-CRN-2026-08-00011');
    }

    public function test_non_serialized_allocation_shows_quantity_without_serial_marker()
    {
        $purchase = $this->createConsignmentPurchase('TPI-BL-NONSER-001');
        $product = $this->createProduct('Non Serial Product', 'NS001');

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 3,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 3000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $receival = $this->createReceival('TPI-CR-2026-08-00020');
        $receiving = $this->createReceiving($receival, 'TPI-CRN-2026-08-00020');
        $receivingDetail = $this->createReceivingDetail($receiving, $product);
        $confirmation = $this->createConfirmation($purchase);
        $confirmationLine = $this->createConfirmationLine($confirmation, $product);
        $allocation = $this->createReceiptAllocation($receivingDetail, 'TPI-CR-2026-08-00020', 'TPI-CRN-2026-08-00020', $confirmationLine->id);

        $this->createLineage($purchase, $detail, $product, [
            'consignment_receipt_allocation_id' => $allocation->id,
            'consignment_receiving_detail_id' => $receivingDetail->id,
            'consignment_serialized_allocation_id' => null,
            'billed_base_quantity' => 3,
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertSee('Qty 3.000');
        $response->assertDontSee('SN:');
    }

    public function test_missing_legacy_source_reference_shows_unavailable_label()
    {
        $purchase = $this->createConsignmentPurchase('TPI-BL-LEGACY-001');
        $product = $this->createProduct('Legacy Product', 'LG001');

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Legacy lineage: receiving detail exists but has no receipt allocation snapshot
        // and its receiving has no resolvable receival, so both references are unresolved.
        $receival = $this->createReceival('');
        $receiving = $this->createReceiving($receival, '');
        $receivingDetail = $this->createReceivingDetail($receiving, $product);

        $this->createLineage($purchase, $detail, $product, [
            'consignment_receipt_allocation_id' => null,
            'consignment_receiving_detail_id' => $receivingDetail->id,
            'billed_base_quantity' => 1,
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertSee('Tidak Tersedia');
        $response->assertSee('Qty 1.000');
        $response->assertDontSee('Penerimaan #');
    }

    public function test_ordinary_purchase_has_no_consignment_source_groups()
    {
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-ORDINARY-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        $product = $this->createProduct('Ordinary Product', 'ORD001');

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertDontSee('Asal Konsinyasi');
        $response->assertDontSee('Penerimaan {{');
        $response->assertDontSee('TPI-CR-');
    }
}

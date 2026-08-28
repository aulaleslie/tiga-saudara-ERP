<?php

namespace Modules\Consignment\Tests\Feature;

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
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConsignmentPhase3FeatureTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected User $billingUser;
    protected User $unauthorizedUser;
    protected Supplier $supplier;
    protected Location $location;
    protected Product $product;
    protected Tax $tax11;
    protected ConsignmentBillingConfirmation $confirmation;

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

        $this->setting1 = Setting::create([
            'company_name' => 'Setting 1 ERP',
            'company_email' => 's1@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 's1@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address 1',
            'is_pkp' => true,
            'document_prefix' => 'S1',
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Setting 2 ERP',
            'company_email' => 's2@example.com',
            'company_phone' => '08123456780',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 's2@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address 2',
            'is_pkp' => true,
            'document_prefix' => 'S2',
        ]);

        // Create billing permissions
        Permission::findOrCreate('consignments.billing.access', 'web');
        Permission::findOrCreate('consignments.billing.convert', 'web');

        $role = Role::findOrCreate('Billing Clerk', 'web');
        $role->givePermissionTo(['consignments.billing.access', 'consignments.billing.convert']);

        $this->billingUser = User::factory()->create();
        $this->billingUser->settings()->attach($this->setting1->id, ['role_id' => $role->id]);

        $this->unauthorizedUser = User::factory()->create();

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting1->id,
            'supplier_name' => 'Supplier Feature',
            'supplier_email' => 'feat@example.com',
            'supplier_phone' => '081111116',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor St',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Rack F',
            'is_consignment' => true,
        ]);

        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Widget Feature',
            'product_code' => 'W-FEAT',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 10,
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
        ]);

        $this->confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-FEAT-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting1->id,
            'customer_name' => 'Cust E',
            'customer_email' => 'e@example.com',
            'customer_phone' => '08123127',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => 'Cust E',
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
            'setting_id' => $this->setting1->id,
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
            'dispatched_quantity' => 3,
        ]);

        $soldSource = ConsignmentSoldSource::create([
            'setting_id' => $this->setting1->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 3,
            'dispatched_at' => now(),
            'source_hash' => 'hash_feat_123',
            'source_snapshot' => [],
        ]);

        $confLine = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $this->confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 3,
        ]);

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-FEAT-001',
            'receival_number' => 'CR-FEAT-001',
            'status' => 'APPROVED',
            'date' => now(),
        ]);

        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-FEAT-001',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => now(),
        ]);

        $recLine = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 3,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 150000,
            'subtotal_dpp' => 150000,
            'total_cost' => 150000,
            'total_dpp' => 150000,
        ]);

        $crd = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $recLine->id,
            'product_id' => $this->product->id,
            'quantity_received' => 3,
            'received_base_quantity' => 3,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
        ]);

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 3,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 16500,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);
    }

    /** @test */
    public function it_denies_unauthorized_users_from_billing_routes()
    {
        $this->actingAs($this->unauthorizedUser)
            ->withSession(['setting_id' => $this->setting1->id]);

        $this->get(route('consignments.billing.index'))->assertStatus(403);
        $this->get(route('consignments.billing.create', $this->confirmation->id))->assertStatus(403);
    }

    /** @test */
    public function it_denies_foreign_setting_access_to_billing_conversion()
    {
        $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting2->id]); // Different setting!

        $this->get(route('consignments.billing.create', $this->confirmation->id))->assertStatus(403);
    }

    /** @test */
    public function it_renders_billing_index_and_create_views_successfully()
    {
        $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting1->id]);

        $indexRes = $this->get(route('consignments.billing.index'));
        $indexRes->assertStatus(200);
        $indexRes->assertSee('Tagihan Konsinyasi Supplier Siap Konversi');

        $createRes = $this->get(route('consignments.billing.create', $this->confirmation->id));
        $createRes->assertStatus(200);
        $createRes->assertSee('Form Konversi Tagihan Supplier');
    }

    /** @test */
    public function it_previews_and_converts_billing_confirmation_via_controller()
    {
        $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting1->id]);

        $previewResponse = $this->postJson(route('consignments.billing.preview', $this->confirmation->id), [
            'supplier_invoice_number' => 'INV-FEAT-999',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        $previewResponse->assertStatus(200);
        $previewResponse->assertJsonPath('valid', true);

        $convertResponse = $this->post(route('consignments.billing.convert', $this->confirmation->id), [
            'supplier_invoice_number' => 'INV-FEAT-999',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        $convertResponse->assertRedirect();

        $this->assertDatabaseHas('purchases', [
            'supplier_purchase_number' => 'INV-FEAT-999',
            'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING,
        ]);
    }

    /** @test */
    public function it_blocks_status_update_and_corrections_on_consignment_billing_purchases()
    {
        // Grant permissions for purchase management & correction to user
        Permission::findOrCreate('purchases.update', 'web');
        Permission::findOrCreate('purchases.approval', 'web');
        Permission::findOrCreate('purchases.received.correct', 'web');
        $this->billingUser->givePermissionTo(['purchases.update', 'purchases.approval', 'purchases.received.correct']);

        $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting1->id]);

        $this->post(route('consignments.billing.convert', $this->confirmation->id), [
            'supplier_invoice_number' => 'INV-FEAT-STATUS',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        $purchase = Purchase::where('supplier_purchase_number', 'INV-FEAT-STATUS')->firstOrFail();

        // 1. Attempt updateStatus HTTP request -> translated DomainException returns redirect with error or 422
        $response = $this->patch(route('purchases.updateStatus', $purchase->id), [
            'status' => Purchase::STATUS_DRAFTED,
        ]);
        $response->assertRedirect();
        $this->assertEquals(Purchase::STATUS_RECEIVED, $purchase->fresh()->status);

        // 2. Attempt correction edit HTTP request -> redirected back with error
        $corrResponse = $this->get(route('purchases.correction.edit', $purchase->id));
        $corrResponse->assertRedirect();
    }

    /** @test */
    public function it_converts_multiple_serials_from_single_receipt_allocation_without_unique_constraint_failure()
    {
        $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting1->id]);

        // Create 2 serial numbers
        $sn1 = \Modules\Product\Entities\ProductSerialNumber::create([
            'setting_id' => $this->setting1->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-MULTI-001',
            'status' => 'IN_STOCK',
        ]);

        $sn2 = \Modules\Product\Entities\ProductSerialNumber::create([
            'setting_id' => $this->setting1->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-MULTI-002',
            'status' => 'IN_STOCK',
        ]);

        $conf2 = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-SERIAL-002',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $confLine2 = \Modules\Consignment\Entities\ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $conf2->id,
            'consignment_sold_source_id' => $this->confirmation->lines->first()->consignment_sold_source_id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 2,
        ]);

        \Illuminate\Support\Facades\DB::table('consignment_sold_sources')
            ->where('id', $confLine2->consignment_sold_source_id)
            ->update(['serial_identities' => json_encode([$sn1->serial_number, $sn2->serial_number])]);

        \Modules\Consignment\Entities\ConsignmentSoldSourceSerial::create([
            'consignment_sold_source_id' => $confLine2->consignment_sold_source_id,
            'product_serial_number_id' => $sn1->id,
        ]);
        \Modules\Consignment\Entities\ConsignmentSoldSourceSerial::create([
            'consignment_sold_source_id' => $confLine2->consignment_sold_source_id,
            'product_serial_number_id' => $sn2->id,
        ]);

        $crd = \Modules\Consignment\Entities\ConsignmentReceivingDetail::first();
        $crd->receivalLine->update(['is_serialized' => true]);
        $crd->serialNumbers()->syncWithoutDetaching([$sn1->id, $sn2->id]);

        $cra2 = ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine2->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 2,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 11000,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        $sa1 = \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'consignment_billing_confirmation_id' => $conf2->id,
            'consignment_billing_confirmation_line_id' => $confLine2->id,
            'consignment_sold_source_id' => $confLine2->consignment_sold_source_id,
            'product_serial_number_id' => $sn1->id,
            'consignment_receiving_detail_id' => $crd->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);

        $sa2 = \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'consignment_billing_confirmation_id' => $conf2->id,
            'consignment_billing_confirmation_line_id' => $confLine2->id,
            'consignment_sold_source_id' => $confLine2->consignment_sold_source_id,
            'product_serial_number_id' => $sn2->id,
            'consignment_receiving_detail_id' => $crd->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);

        $preview = $this->postJson(route('consignments.billing.preview', $conf2->id), [
            'supplier_invoice_number' => 'INV-SERIAL-MULTI',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);
        $preview->assertStatus(200);
        $preview->assertJsonPath('valid', true);

        $response = $this->post(route('consignments.billing.convert', $conf2->id), [
            'supplier_invoice_number' => 'INV-SERIAL-MULTI',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // Verify exact tax remainder distribution sum equals total allocation tax
        $lineage1 = \Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::where('consignment_serialized_allocation_id', $sa1->id)->firstOrFail();
        $lineage2 = \Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage::where('consignment_serialized_allocation_id', $sa2->id)->firstOrFail();

        $this->assertEquals(11000.0, round($lineage1->tax_amount + $lineage2->tax_amount, 2));

        // Serialized lineage audit records the tax semantics it was derived under
        $this->assertSame(
            ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
            $lineage1->commercial_snapshot['tax_snapshot_version']
        );
        $this->assertSame(
            ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
            $lineage2->commercial_snapshot['tax_snapshot_version']
        );
    }

    /** @test */
    public function it_correctly_matches_serials_across_multiple_confirmation_lines_sharing_same_receiving_detail()
    {
        $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting1->id]);

        $snA = \Modules\Product\Entities\ProductSerialNumber::create([
            'setting_id' => $this->setting1->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-LINE-A',
            'status' => 'IN_STOCK',
        ]);

        $snB = \Modules\Product\Entities\ProductSerialNumber::create([
            'setting_id' => $this->setting1->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-LINE-B',
            'status' => 'IN_STOCK',
        ]);

        $conf3 = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-CROSS-LINE-003',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $soldSource1 = \Modules\Consignment\Entities\ConsignmentSoldSource::first();
        \Illuminate\Support\Facades\DB::table('consignment_sold_sources')
            ->where('id', $soldSource1->id)
            ->update(['serial_identities' => json_encode([$snA->serial_number])]);

        \Modules\Consignment\Entities\ConsignmentSoldSourceSerial::create([
            'consignment_sold_source_id' => $soldSource1->id,
            'product_serial_number_id' => $snA->id,
        ]);

        $dispatchDetail2 = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $soldSource1->dispatchDetail->dispatch_id,
            'sale_id' => $soldSource1->sale_id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $soldSource2 = \Modules\Consignment\Entities\ConsignmentSoldSource::create(array_merge(
            $soldSource1->toArray(),
            [
                'id' => null,
                'dispatch_detail_id' => $dispatchDetail2->id,
                'source_hash' => md5('TEST-HASH-2'),
                'serial_identities' => [$snB->serial_number],
            ]
        ));

        \Modules\Consignment\Entities\ConsignmentSoldSourceSerial::create([
            'consignment_sold_source_id' => $soldSource2->id,
            'product_serial_number_id' => $snB->id,
        ]);

        $line1 = \Modules\Consignment\Entities\ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $conf3->id,
            'consignment_sold_source_id' => $soldSource1->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 1,
        ]);

        $line2 = \Modules\Consignment\Entities\ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $conf3->id,
            'consignment_sold_source_id' => $soldSource2->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 1,
        ]);

        $crd = \Modules\Consignment\Entities\ConsignmentReceivingDetail::first();
        $crd->receivalLine->update(['is_serialized' => true]);
        $crd->serialNumbers()->syncWithoutDetaching([$snA->id, $snB->id]);

        $craLine1 = ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $line1->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 5500,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        $craLine2 = ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $line2->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 5500,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        $saA = \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'consignment_billing_confirmation_id' => $conf3->id,
            'consignment_billing_confirmation_line_id' => $line1->id,
            'consignment_sold_source_id' => $line1->consignment_sold_source_id,
            'product_serial_number_id' => $snA->id,
            'consignment_receiving_detail_id' => $crd->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);

        $saB = \Modules\Consignment\Entities\ConsignmentSerializedAllocation::create([
            'consignment_billing_confirmation_id' => $conf3->id,
            'consignment_billing_confirmation_line_id' => $line2->id,
            'consignment_sold_source_id' => $line2->consignment_sold_source_id,
            'product_serial_number_id' => $snB->id,
            'consignment_receiving_detail_id' => $crd->id,
            'status' => \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED,
        ]);

        $response = $this->post(route('consignments.billing.convert', $conf3->id), [
            'supplier_invoice_number' => 'INV-CROSS-LINE',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // Check line1 lineage belongs to line1 and saA
        $this->assertDatabaseHas('consignment_purchase_detail_lineages', [
            'consignment_billing_confirmation_line_id' => $line1->id,
            'consignment_serialized_allocation_id' => $saA->id,
        ]);

        // Check line2 lineage belongs to line2 and saB
        $this->assertDatabaseHas('consignment_purchase_detail_lineages', [
            'consignment_billing_confirmation_line_id' => $line2->id,
            'consignment_serialized_allocation_id' => $saB->id,
        ]);
    }

    /** @test */
    public function it_blocks_conversion_and_rolls_back_if_evidence_is_corrupted()
    {
        $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting1->id]);

        // Tamper with receiving document status to simulate evidence corruption
        $crd = \Modules\Consignment\Entities\ConsignmentReceivingDetail::first();
        $receiving = $crd->receiving;
        $receiving->update(['status' => \Modules\Consignment\Entities\ConsignmentReceiving::STATUS_REJECTED]);

        $response = $this->post(route('consignments.billing.convert', $this->confirmation->id), [
            'supplier_invoice_number' => 'INV-CORRUPT',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);

        // Assert conversion is blocked and Purchase was NOT created
        $this->assertDatabaseMissing('purchases', [
            'supplier_purchase_number' => 'INV-CORRUPT',
        ]);
        $this->assertDatabaseMissing('consignment_purchase_detail_lineages', [
            'consignment_billing_confirmation_id' => $this->confirmation->id,
        ]);
    }

    /** @test */
    public function it_revalidates_and_blocks_conversion_on_stale_status_under_lock()
    {
        $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting1->id]);

        $crd = \Modules\Consignment\Entities\ConsignmentReceivingDetail::first();
        $receiving = $crd->receiving;

        // Mock DB transaction to simulate concurrent mutation right before preview validation check
        \Illuminate\Support\Facades\DB::transaction(function () use ($receiving) {
            // Lock evidence
            \Modules\Consignment\Entities\ConsignmentReceiving::where('id', $receiving->id)->lockForUpdate()->first();

            // Mutate receiving status to REVERSED while under lock
            $receiving->update(['status' => \Modules\Consignment\Entities\ConsignmentReceiving::STATUS_REVERSED]);

            // Attempt conversion with service -> must throw DomainException due to revalidation failure
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('must be [APPROVED]');

            app(\Modules\Consignment\Services\ConsignmentBillingConversionService::class)->convert(
                $this->confirmation->id,
                $this->setting1->id,
                $this->billingUser->id,
                [
                    'supplier_invoice_number' => 'INV-LOCK-MUTATE',
                    'invoice_date' => '2026-08-28',
                    'due_date' => '2026-09-28',
                ]
            );
        });
    }

    /** @test */
    public function it_handles_partial_receipt_allocations_across_multiple_confirmations_with_proportional_tax()
    {
        $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting1->id]);

        // Create two distinct sold sources (4 units and 6 units)
        $dispatchDetail1 = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $this->confirmation->lines->first()->soldSource->dispatch_detail_id,
            'sale_id' => $this->confirmation->lines->first()->soldSource->sale_id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'dispatched_quantity' => 4,
        ]);

        $hash1 = hash('sha256', json_encode([
            'dispatch_detail_id' => $dispatchDetail1->id,
            'setting_id' => $this->setting1->id,
            'location_id' => $this->location->id,
            'product_id' => $this->product->id,
            'original_base_quantity' => '4',
            'dispatched_at' => \Carbon\Carbon::parse($dispatchDetail1->created_at)->format('Y-m-d H:i:s'),
            'serials' => [],
            'dispatch_status' => 'APPROVED',
            'is_consignment_location' => true,
            'pos_checkout_id' => null,
            'tax_id' => null,
        ]));

        $soldSourcePart1 = ConsignmentSoldSource::create([
            'setting_id' => $this->setting1->id,
            'dispatch_detail_id' => $dispatchDetail1->id,
            'sale_id' => $this->confirmation->lines->first()->soldSource->sale_id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 4,
            'dispatched_at' => $dispatchDetail1->created_at,
            'source_hash' => $hash1,
            'source_snapshot' => ['source_hash' => $hash1],
        ]);

        $dispatchDetail2 = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $this->confirmation->lines->first()->soldSource->dispatch_detail_id,
            'sale_id' => $this->confirmation->lines->first()->soldSource->sale_id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'dispatched_quantity' => 6,
        ]);

        $hash2 = hash('sha256', json_encode([
            'dispatch_detail_id' => $dispatchDetail2->id,
            'setting_id' => $this->setting1->id,
            'location_id' => $this->location->id,
            'product_id' => $this->product->id,
            'original_base_quantity' => '6',
            'dispatched_at' => \Carbon\Carbon::parse($dispatchDetail2->created_at)->format('Y-m-d H:i:s'),
            'serials' => [],
            'dispatch_status' => 'APPROVED',
            'is_consignment_location' => true,
            'pos_checkout_id' => null,
            'tax_id' => null,
        ]));

        $soldSourcePart2 = ConsignmentSoldSource::create([
            'setting_id' => $this->setting1->id,
            'dispatch_detail_id' => $dispatchDetail2->id,
            'sale_id' => $this->confirmation->lines->first()->soldSource->sale_id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 6,
            'dispatched_at' => $dispatchDetail2->created_at,
            'source_hash' => $hash2,
            'source_snapshot' => ['source_hash' => $hash2],
        ]);

        // Receiving detail has total 10 units at 50,000 unit_cost / 50,000 unit_dpp, tax rate 11% (tax = 5,500 per unit)
        $crdPartial = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $this->confirmation->lines->first()->receiptAllocations->first()->receivingDetail->consignment_receiving_id,
            'consignment_receival_line_id' => $this->confirmation->lines->first()->receiptAllocations->first()->receivingDetail->consignment_receival_line_id,
            'product_id' => $this->product->id,
            'quantity_received' => 10,
            'received_base_quantity' => 10,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 55000,
        ]);

        $lifecycle = app(\Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService::class);

        // Build & approve Confirmation 1 through public lifecycle API (4 units)
        $draft1 = $lifecycle->createDraft(
            $this->setting1->id,
            $this->supplier->id,
            now()->format('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $soldSourcePart1->id,
                    'product_id' => $this->product->id,
                    'location_id' => $this->location->id,
                    'allocated_base_quantity' => 4,
                    'receipt_allocations' => [
                        [
                            'consignment_receiving_detail_id' => $crdPartial->id,
                            'allocated_base_quantity' => 4,
                        ],
                    ],
                ],
            ],
            'Partial draft 1',
            $this->billingUser->id
        );

        $submitted1 = $lifecycle->submitConfirmation($draft1, $this->billingUser->id);
        $confPart1 = $lifecycle->approveConfirmation($submitted1, $this->billingUser->id);

        // Build & approve Confirmation 2 through public lifecycle API (6 units)
        $draft2 = $lifecycle->createDraft(
            $this->setting1->id,
            $this->supplier->id,
            now()->format('Y-m-d'),
            [
                [
                    'consignment_sold_source_id' => $soldSourcePart2->id,
                    'product_id' => $this->product->id,
                    'location_id' => $this->location->id,
                    'allocated_base_quantity' => 6,
                    'receipt_allocations' => [
                        [
                            'consignment_receiving_detail_id' => $crdPartial->id,
                            'allocated_base_quantity' => 6,
                        ],
                    ],
                ],
            ],
            'Partial draft 2',
            $this->billingUser->id
        );

        $submitted2 = $lifecycle->submitConfirmation($draft2, $this->billingUser->id);
        $confPart2 = $lifecycle->approveConfirmation($submitted2, $this->billingUser->id);

        // Assert lifecycle service correctly calculated proportional tax amounts and stamped v2 snapshot marker
        $alloc1 = ConsignmentReceiptAllocation::where('consignment_billing_confirmation_line_id', $confPart1->lines->first()->id)->firstOrFail();
        $alloc2 = ConsignmentReceiptAllocation::where('consignment_billing_confirmation_line_id', $confPart2->lines->first()->id)->firstOrFail();

        $this->assertEquals(22000.0, (float) $alloc1->tax_amount);
        $this->assertEquals(33000.0, (float) $alloc2->tax_amount);
        $this->assertEquals(2, $alloc1->receiving_detail_snapshot['tax_snapshot_version']);
        $this->assertEquals(2, $alloc2->receiving_detail_snapshot['tax_snapshot_version']);
        $this->assertSame(ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL, $alloc1->tax_snapshot_version);
        $this->assertSame(ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL, $alloc2->tax_snapshot_version);

        // Preview & Convert Confirmation 1
        $preview1 = $this->postJson(route('consignments.billing.preview', $confPart1->id), [
            'supplier_invoice_number' => 'INV-PART-001',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);
        $preview1->assertStatus(200);
        $preview1->assertJsonPath('valid', true);

        $response1 = $this->post(route('consignments.billing.convert', $confPart1->id), [
            'supplier_invoice_number' => 'INV-PART-001',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);
        $response1->assertRedirect();

        // Preview & Convert Confirmation 2
        $preview2 = $this->postJson(route('consignments.billing.preview', $confPart2->id), [
            'supplier_invoice_number' => 'INV-PART-002',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);
        $preview2->assertStatus(200);
        $preview2->assertJsonPath('valid', true);

        $response2 = $this->post(route('consignments.billing.convert', $confPart2->id), [
            'supplier_invoice_number' => 'INV-PART-002',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);
        $response2->assertRedirect();

        // Verify both purchases exist
        $this->assertDatabaseHas('purchases', ['supplier_purchase_number' => 'INV-PART-001']);
        $this->assertDatabaseHas('purchases', ['supplier_purchase_number' => 'INV-PART-002']);

        // Reconciliation must surface BOTH billed Purchases for the shared receiving
        // detail, not just whichever allocation happens to be ordered first.
        $purchase1 = Purchase::where('supplier_purchase_number', 'INV-PART-001')->firstOrFail();
        $purchase2 = Purchase::where('supplier_purchase_number', 'INV-PART-002')->firstOrFail();

        $this->grantReconciliationAccess();
        $reconciliation = $this->get(route('consignments.reconciliation.index'));
        $reconciliation->assertStatus(200);

        $reconciliation->assertSee($purchase1->reference);
        $reconciliation->assertSee($purchase2->reference);
        $reconciliation->assertSee('INV-PART-001');
        $reconciliation->assertSee('INV-PART-002');
    }

    /** @test */
    public function it_scopes_the_reconciliation_product_selector_to_the_active_setting()
    {
        $foreignUnit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Boxes',
            'short_name' => 'BOX',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $foreignProduct = Product::create([
            'setting_id' => $this->setting2->id,
            'product_name' => 'Foreign Secret Widget',
            'product_code' => 'W-FOREIGN-SECRET',
            'product_unit' => $foreignUnit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 5,
        ]);

        $this->grantReconciliationAccess();

        $response = $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting1->id])
            ->get(route('consignments.reconciliation.index'));

        $response->assertStatus(200);
        $response->assertSee($this->product->product_name);
        $response->assertDontSee($foreignProduct->product_name);
        $response->assertDontSee($foreignProduct->product_code);
    }

    /** @test */
    public function it_does_not_disclose_whether_a_foreign_confirmation_exists()
    {
        $foreignConfirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting2->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-FOREIGN-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $this->actingAs($this->billingUser)->withSession(['setting_id' => $this->setting1->id]);

        $foreign = $this->postJson(route('consignments.billing.preview', $foreignConfirmation->id), [
            'supplier_invoice_number' => 'INV-PROBE',
            'invoice_date' => '2026-08-28',
        ]);

        $missing = $this->postJson(route('consignments.billing.preview', 999999), [
            'supplier_invoice_number' => 'INV-PROBE',
            'invoice_date' => '2026-08-28',
        ]);

        // Identical status and body: probing cannot distinguish the two.
        $this->assertSame($foreign->status(), $missing->status());
        $this->assertSame($foreign->json('blockers'), $missing->json('blockers'));
    }

    /** @test */
    public function it_allows_legacy_full_lot_tax_partial_allocations_for_backward_compatibility()
    {
        $this->actingAs($this->billingUser)
            ->withSession(['setting_id' => $this->setting1->id]);

        // CRD has 10 units with total tax 55,000
        $crdLegacy = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $this->confirmation->lines->first()->receiptAllocations->first()->receivingDetail->consignment_receiving_id,
            'consignment_receival_line_id' => $this->confirmation->lines->first()->receiptAllocations->first()->receivingDetail->consignment_receival_line_id,
            'product_id' => $this->product->id,
            'quantity_received' => 10,
            'received_base_quantity' => 10,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 55000,
        ]);

        // Legacy partial allocation created prior to proportional fix storing full CRD tax (55,000 instead of 22,000)
        $confLegacy = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-LEGACY-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $lineLegacy = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $confLegacy->id,
            'consignment_sold_source_id' => $this->confirmation->lines->first()->consignment_sold_source_id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 4,
        ]);

        $allocLegacy = ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $lineLegacy->id,
            'consignment_receiving_detail_id' => $crdLegacy->id,
            'allocated_base_quantity' => 4,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 55000, // Legacy un-proportional full-lot tax
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_LEGACY,
            'receiving_detail_snapshot' => [
                'unit_cost' => 50000,
                'unit_dpp' => 50000,
                'tax_id' => $this->tax11->id,
                'tax_rate' => 11,
                'tax_snapshot_version' => 1, // Explicit v1 marker
            ],
        ]);

        $preview = $this->postJson(route('consignments.billing.preview', $confLegacy->id), [
            'supplier_invoice_number' => 'INV-LEGACY-001',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);
        $preview->assertStatus(200);
        $preview->assertJsonPath('valid', true);

        // Preview should calculate normalized proportional tax (22,000) for preview lines
        $this->assertEquals(22000.0, (float) $preview->json('lines.0.product_tax_amount'));

        $response = $this->post(route('consignments.billing.convert', $confLegacy->id), [
            'supplier_invoice_number' => 'INV-LEGACY-001',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ]);
        $response->assertRedirect();

        $purchase = Purchase::where('supplier_purchase_number', 'INV-LEGACY-001')->firstOrFail();

        // Assert lossless monetary reconciliation: sum(lineage tax) = Purchase detail tax = Purchase header tax = 22,000
        $this->assertEquals(22000.0, (float) $purchase->tax_amount);
        $this->assertEquals(22000.0, (float) $purchase->purchaseDetails->first()->product_tax_amount);

        $lineage = ConsignmentPurchaseDetailLineage::where('consignment_billing_confirmation_id', $confLegacy->id)->firstOrFail();
        $this->assertEquals(22000.0, (float) $lineage->tax_amount);

        // Assert original un-proportional stored evidence is preserved in commercial snapshot
        $this->assertEquals(55000.0, (float) $lineage->commercial_snapshot['original_stored_tax_amount']);
        $this->assertTrue($lineage->commercial_snapshot['is_legacy_full_lot_tax']);
        // Lineage remains self-describing: v1 semantics are recorded even if the
        // source allocation later becomes unavailable or is inspected independently.
        $this->assertSame(
            ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_LEGACY,
            $lineage->commercial_snapshot['tax_snapshot_version']
        );
    }

    /** @test */
    public function it_shows_source_and_lineage_context_on_purchase_and_payment_views()
    {
        $this->actingAs($this->billingUser)->withSession(['setting_id' => $this->setting1->id]);

        $this->post(route('consignments.billing.convert', $this->confirmation->id), [
            'supplier_invoice_number' => 'INV-PRESENT-001',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
        ])->assertRedirect();

        $purchase = Purchase::where('supplier_purchase_number', 'INV-PRESENT-001')->firstOrFail();

        // The Purchase show view consults many permissions; register the application's
        // full catalogue so every Gate check resolves rather than throwing.
        foreach (require base_path('app/Config/Permissions.php') as $group) {
            foreach (array_keys($group) as $perm) {
                Permission::findOrCreate($perm, 'web');
            }
        }
        Role::findOrCreate('Billing Clerk', 'web')
            ->givePermissionTo(['purchases.show', 'purchasePayments.access', 'purchasePayments.create']);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Purchase detail view: source banner plus per-line allocation lineage.
        $show = $this->get(route('purchases.show', $purchase->id));
        $show->assertStatus(200);
        $show->assertSee($this->confirmation->confirmation_number);
        $show->assertSee('Asal Konsinyasi');

        // Payment views carry the source/lineage context too.
        $paymentsIndex = $this->get(route('purchase-payments.index', $purchase->id));
        $paymentsIndex->assertStatus(200);
        $paymentsIndex->assertSee('Tagihan Konsinyasi');
        $paymentsIndex->assertSee('INV-PRESENT-001');

        $paymentCreate = $this->get(route('purchase-payments.create', $purchase->id));
        $paymentCreate->assertStatus(200);
        $paymentCreate->assertSee('Tagihan Konsinyasi');
    }

    /** @test */
    public function it_denies_deletion_of_conversion_attachments_via_http_endpoint()
    {
        $this->actingAs($this->billingUser)->withSession(['setting_id' => $this->setting1->id]);

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('supplier_invoice.pdf', "%PDF-1.4\n%HTTP-IMMUTABLE-TEST\n");

        $this->post(route('consignments.billing.convert', $this->confirmation->id), [
            'supplier_invoice_number' => 'INV-HTTP-IMMUTABLE',
            'invoice_date' => '2026-08-28',
            'due_date' => '2026-09-28',
            'attachments' => [$file],
        ])->assertRedirect();

        $purchase = Purchase::where('supplier_purchase_number', 'INV-HTTP-IMMUTABLE')->firstOrFail();
        $media = $purchase->getFirstMedia('attachments');
        $this->assertNotNull($media);

        // Attempt deletion via HTTP endpoint -> must return 403 Forbidden
        $response = $this->delete(route('purchases.attachments.destroy', [$purchase->id, $media->id]));
        $response->assertStatus(403);

        // Assert media row still exists in database
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    /**
     * Reconciliation lives behind the allocations permission, which the billing role
     * does not carry by default.
     */
    private function grantReconciliationAccess(): void
    {
        Permission::findOrCreate('consignments.allocations.access', 'web');
        Role::findOrCreate('Billing Clerk', 'web')->givePermissionTo('consignments.allocations.access');
        $this->billingUser->forgetCachedPermissions();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}

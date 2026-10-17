<?php

namespace Modules\Purchase\Tests\Feature;

use App\Livewire\Purchase\PurchaseNoteEditor;
use App\Livewire\Purchase\SupplierPurchaseNumberEditor;
use App\Livewire\Purchase\TaxRefNoEditor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage;
use Modules\Consignment\Entities\ConsignmentReceival;
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
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConsignmentPurchaseInlineEditorTest extends TestCase
{
    use RefreshDatabase;

    protected User $authorizedUser;
    protected User $unauthorizedUser;
    protected User $globalUser;
    protected User $globalOnlyUser;
    protected Setting $setting1;
    protected Setting $setting2;
    protected Supplier $supplier;
    protected Product $product;
    protected Location $location;
    protected Tax $tax11;
    protected ConsignmentBillingConfirmation $confirmation;
    protected Purchase $consignmentPurchase;

    protected function setUp(): void
    {
        parent::setUp();

        $updatePermission = Permission::firstOrCreate(['name' => 'purchases.update', 'guard_name' => 'web']);
        $showPermission = Permission::firstOrCreate(['name' => 'purchases.show', 'guard_name' => 'web']);
        $globalAccessPermission = Permission::firstOrCreate(['name' => 'purchasePayments.global.access', 'guard_name' => 'web']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting1 = Setting::create([
            'company_name' => 'Setting 1',
            'company_email' => 'setting1@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address 1',
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Setting 2',
            'company_email' => 'setting2@example.com',
            'company_phone' => '654321',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify2@example.com',
            'footer_text' => 'Footer 2',
            'company_address' => 'Address 2',
        ]);

        $fullRole = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $fullRole->syncPermissions([$updatePermission, $showPermission, $globalAccessPermission]);

        $editOnlyRole = Role::firstOrCreate(['name' => 'Edit Only', 'guard_name' => 'web']);
        $editOnlyRole->syncPermissions([$updatePermission, $showPermission]);

        $globalOnlyRole = Role::firstOrCreate(['name' => 'Global Only', 'guard_name' => 'web']);
        $globalOnlyRole->syncPermissions([$showPermission, $globalAccessPermission]);

        $viewerRole = Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);

        $this->authorizedUser = User::factory()->create(['is_active' => true]);
        $this->authorizedUser->assignRole($editOnlyRole);
        $this->authorizedUser->settings()->attach($this->setting1->id, ['role_id' => $editOnlyRole->id]);

        $this->unauthorizedUser = User::factory()->create(['is_active' => true]);
        $this->unauthorizedUser->assignRole($viewerRole);
        $this->unauthorizedUser->settings()->attach($this->setting1->id, ['role_id' => $viewerRole->id]);

        $this->globalUser = User::factory()->create(['is_active' => true]);
        $this->globalUser->assignRole($fullRole);
        $this->globalUser->settings()->attach($this->setting2->id, ['role_id' => $fullRole->id]);

        $this->globalOnlyUser = User::factory()->create(['is_active' => true]);
        $this->globalOnlyUser->assignRole($globalOnlyRole);
        $this->globalOnlyUser->settings()->attach($this->setting2->id, ['role_id' => $globalOnlyRole->id]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting1->id,
            'supplier_name' => 'Supplier Consignment',
            'supplier_email' => 'consignment@example.com',
            'supplier_phone' => '0812345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor Street 1',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Consignment Rack A',
            'is_consignment' => true,
        ]);

        $unit = Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Consignment Product A',
            'product_code' => 'CSG-A',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 5,
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
        ]);

        $this->confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-CONF-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => now(),
            'is_ready_for_billing' => true,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting1->id,
            'customer_name' => 'Cust Inline',
            'customer_email' => 'inline@example.com',
            'customer_phone' => '08123126',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => 'Cust Inline',
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
            'dispatched_quantity' => 1,
        ]);

        $soldSource = ConsignmentSoldSource::create([
            'setting_id' => $this->setting1->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'original_base_quantity' => 1,
            'dispatched_at' => now(),
            'source_hash' => 'hash_source_inline',
            'source_snapshot' => ['sample' => 'snapshot_data'],
        ]);

        $confLine = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $this->confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'allocated_base_quantity' => 1,
        ]);

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-INLINE-001',
            'receival_number' => 'CR-INLINE-001',
            'status' => 'APPROVED',
            'date' => now(),
        ]);

        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-INLINE-001',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => now(),
        ]);

        $recLine = \Modules\Consignment\Entities\ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'subtotal_cost' => 50000,
            'subtotal_dpp' => 50000,
            'total_cost' => 50000,
            'total_dpp' => 50000,
        ]);

        $crd = ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id,
            'consignment_receival_line_id' => $recLine->id,
            'product_id' => $this->product->id,
            'quantity_received' => 1,
            'received_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_id' => $this->tax11->id,
            'tax_rate' => 11,
            'tax_amount' => 5500,
        ]);

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
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

        $this->consignmentPurchase = $conversionService->convert(
            $this->confirmation->id,
            $this->setting1->id,
            $this->authorizedUser->id,
            [
                'supplier_invoice_number' => 'INV-INLINE-001',
                'invoice_date' => '2026-08-28',
                'due_date' => '2026-09-28',
            ]
        );

        session(['setting_id' => $this->setting1->id]);
    }

    public function test_authorized_user_can_edit_and_clear_note_on_consignment_purchase(): void
    {
        $this->actingAs($this->authorizedUser);

        Livewire::test(PurchaseNoteEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->assertSet('canEdit', true)
            ->call('startEditing')
            ->set('note', 'Updated note for consignment')
            ->call('save')
            ->assertDispatched('notify');

        $this->consignmentPurchase->refresh();
        $this->assertEquals('Updated note for consignment', $this->consignmentPurchase->note);

        // Clear note
        Livewire::test(PurchaseNoteEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->call('startEditing')
            ->set('note', '')
            ->call('save')
            ->assertDispatched('notify');

        $this->consignmentPurchase->refresh();
        $this->assertNull($this->consignmentPurchase->note);

        // Verify source confirmation snapshot is unchanged
        $this->confirmation->refresh();
        $this->assertEquals('CBC-CONF-001', $this->confirmation->confirmation_number);
        $this->assertEquals('INV-INLINE-001', $this->confirmation->supplier_invoice_number);
    }

    public function test_note_length_validation_on_consignment_purchase(): void
    {
        $this->actingAs($this->authorizedUser);

        Livewire::test(PurchaseNoteEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->call('startEditing')
            ->set('note', str_repeat('a', 1001))
            ->call('save')
            ->assertHasErrors(['note' => 'max']);
    }

    public function test_authorized_user_can_edit_and_clear_supplier_purchase_number_on_consignment_purchase(): void
    {
        $this->actingAs($this->authorizedUser);

        Livewire::test(SupplierPurchaseNumberEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->assertSet('canEdit', true)
            ->call('startEditing')
            ->set('supplierPurchaseNumber', 'SPN-CSG-999')
            ->call('save')
            ->assertDispatched('notify');

        $this->consignmentPurchase->refresh();
        $this->assertEquals('SPN-CSG-999', $this->consignmentPurchase->supplier_purchase_number);

        // Clear supplier purchase number
        Livewire::test(SupplierPurchaseNumberEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->call('startEditing')
            ->set('supplierPurchaseNumber', '')
            ->call('save')
            ->assertDispatched('notify');

        $this->consignmentPurchase->refresh();
        $this->assertNull($this->consignmentPurchase->supplier_purchase_number);
    }

    public function test_supplier_purchase_number_uniqueness_validation_on_consignment_purchase(): void
    {
        Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'reference' => 'PO-DUP-TEST',
            'supplier_id' => $this->supplier->id,
            'supplier_purchase_number' => 'EXISTING-SPN',
            'setting_id' => $this->setting1->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $this->actingAs($this->authorizedUser);

        Livewire::test(SupplierPurchaseNumberEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->call('startEditing')
            ->set('supplierPurchaseNumber', 'EXISTING-SPN')
            ->call('save')
            ->assertHasErrors(['supplierPurchaseNumber']);
    }

    public function test_authorized_user_can_edit_and_clear_tax_ref_no_on_consignment_purchase(): void
    {
        $this->actingAs($this->authorizedUser);

        Livewire::test(TaxRefNoEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->assertSet('canEdit', true)
            ->call('startEditing')
            ->set('taxRefNo', 'TAX-REF-CSG-001')
            ->call('save')
            ->assertDispatched('notify');

        $this->consignmentPurchase->refresh();
        $this->assertEquals('TAX-REF-CSG-001', $this->consignmentPurchase->tax_ref_no);

        // Clear tax ref no
        Livewire::test(TaxRefNoEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->call('startEditing')
            ->set('taxRefNo', '')
            ->call('save')
            ->assertDispatched('notify');

        $this->consignmentPurchase->refresh();
        $this->assertNull($this->consignmentPurchase->tax_ref_no);
    }

    public function test_tax_ref_no_uniqueness_validation_on_consignment_purchase(): void
    {
        Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'reference' => 'PO-TAX-DUP-TEST',
            'supplier_id' => $this->supplier->id,
            'tax_ref_no' => 'EXISTING-TAX-REF',
            'setting_id' => $this->setting1->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $this->actingAs($this->authorizedUser);

        Livewire::test(TaxRefNoEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->call('startEditing')
            ->set('taxRefNo', 'EXISTING-TAX-REF')
            ->call('save')
            ->assertHasErrors(['taxRefNo']);
    }

    public function test_unauthorized_user_cannot_edit_consignment_purchase_inline(): void
    {
        $this->actingAs($this->unauthorizedUser);

        Livewire::test(PurchaseNoteEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->assertSet('canEdit', false);

        Livewire::test(SupplierPurchaseNumberEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->assertSet('canEdit', false);

        Livewire::test(TaxRefNoEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->assertSet('canEdit', false);
    }

    public function test_global_user_with_both_permissions_can_edit_in_global_mode(): void
    {
        $this->actingAs($this->globalUser);
        session(['setting_id' => $this->setting2->id]);

        Livewire::test(PurchaseNoteEditor::class, [
            'purchaseId' => $this->consignmentPurchase->id,
            'globalMode' => true,
        ])
            ->assertSet('canEdit', true)
            ->call('startEditing')
            ->set('note', 'Global mode consignment note')
            ->call('save');

        $this->consignmentPurchase->refresh();
        $this->assertEquals('Global mode consignment note', $this->consignmentPurchase->note);
    }

    public function test_global_only_user_cannot_edit_in_global_mode(): void
    {
        $this->actingAs($this->globalOnlyUser);
        session(['setting_id' => $this->setting2->id]);

        Livewire::test(PurchaseNoteEditor::class, [
            'purchaseId' => $this->consignmentPurchase->id,
            'globalMode' => true,
        ])
            ->assertSet('canEdit', false);
    }

    public function test_source_snapshots_and_lineage_remain_intact_after_inline_edits(): void
    {
        $lineageBefore = ConsignmentPurchaseDetailLineage::where('purchase_id', $this->consignmentPurchase->id)->get()->toArray();
        $confirmationBefore = $this->confirmation->fresh()->toArray();

        $this->actingAs($this->authorizedUser);

        Livewire::test(PurchaseNoteEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->call('startEditing')
            ->set('note', 'New Note for Lineage Test')
            ->call('save');

        Livewire::test(SupplierPurchaseNumberEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->call('startEditing')
            ->set('supplierPurchaseNumber', 'NEW-SPN-LINEAGE')
            ->call('save');

        Livewire::test(TaxRefNoEditor::class, ['purchaseId' => $this->consignmentPurchase->id])
            ->call('startEditing')
            ->set('taxRefNo', 'NEW-TAX-LINEAGE')
            ->call('save');

        $lineageAfter = ConsignmentPurchaseDetailLineage::where('purchase_id', $this->consignmentPurchase->id)->get()->toArray();
        $confirmationAfter = $this->confirmation->fresh()->toArray();

        $this->assertEquals($lineageBefore, $lineageAfter);
        $this->assertEquals($confirmationBefore, $confirmationAfter);
    }
}

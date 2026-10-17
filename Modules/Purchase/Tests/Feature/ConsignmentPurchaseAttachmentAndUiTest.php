<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
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
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConsignmentPurchaseAttachmentAndUiTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected Supplier $supplier;
    protected Product $product;
    protected ConsignmentBillingConfirmation $confirmation;
    protected Purchase $consignmentPurchase;
    protected Purchase $foreignPurchase;
    protected User $authorizedUser;
    protected User $unauthorizedUser;
    protected User $globalUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        Permission::firstOrCreate(['name' => 'purchases.show', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.update', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.reporting-date.override', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.due-date.override', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchasePayments.global.access', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.received.correct', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.received.monetary.edit', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.approved.edit', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.receive.cancel', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.receive', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.approval', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.create', 'guard_name' => 'web']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting1 = Setting::create([
            'company_name' => 'Attachment Setting 1',
            'company_email' => 'att1@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'att1@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address 1',
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Attachment Setting 2',
            'company_email' => 'att2@example.com',
            'company_phone' => '654321',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'att2@example.com',
            'footer_text' => 'Footer 2',
            'company_address' => 'Address 2',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting1->id,
            'supplier_name' => 'Attachment Supplier',
            'supplier_email' => 'att-supp@example.com',
            'supplier_phone' => '0812345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor Street 1',
        ]);

        $location = Location::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Rack Att',
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
            'product_name' => 'Attachment Product',
            'product_code' => 'ATT-A',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 10,
        ]);

        $this->confirmation = ConsignmentBillingConfirmation::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'confirmation_number' => 'CBC-ATT-001',
            'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
            'date' => '2026-08-01',
            'is_ready_for_billing' => true,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->setting1->id,
            'customer_name' => 'Cust Att',
            'customer_email' => 'attcust@example.com',
            'customer_phone' => '08123126',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => '2026-08-01',
            'customer_id' => $customer->id,
            'customer_name' => 'Cust Att',
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
            'location_id' => $location->id,
            'dispatched_quantity' => 1,
        ]);

        $soldSource = ConsignmentSoldSource::create([
            'setting_id' => $this->setting1->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'original_base_quantity' => 1,
            'dispatched_at' => '2026-08-01 10:00:00',
            'source_hash' => 'hash_att_test',
            'source_snapshot' => [],
        ]);

        $confLine = ConsignmentBillingConfirmationLine::create([
            'consignment_billing_confirmation_id' => $this->confirmation->id,
            'consignment_sold_source_id' => $soldSource->id,
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'allocated_base_quantity' => 1,
        ]);

        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'CR-ATT-001',
            'receival_number' => 'CR-ATT-001',
            'status' => 'APPROVED',
            'date' => '2026-08-01',
        ]);

        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting1->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $location->id,
            'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-ATT-001',
            'status' => ConsignmentReceiving::STATUS_APPROVED,
            'date' => '2026-08-01',
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
        ]);

        ConsignmentReceiptAllocation::create([
            'consignment_billing_confirmation_line_id' => $confLine->id,
            'consignment_receiving_detail_id' => $crd->id,
            'allocated_base_quantity' => 1,
            'unit_cost' => 50000,
            'unit_dpp' => 50000,
            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
        ]);

        $roleUpdate = Role::firstOrCreate(['name' => 'Role Att Update', 'guard_name' => 'web']);
        $roleUpdate->syncPermissions([
            'purchases.show',
            'purchases.update',
            'purchases.reporting-date.override',
            'purchases.due-date.override',
            'purchases.received.monetary.edit',
        ]);

        $roleViewOnly = Role::firstOrCreate(['name' => 'Role Att View', 'guard_name' => 'web']);
        $roleViewOnly->syncPermissions(['purchases.show']);

        $roleGlobal = Role::firstOrCreate(['name' => 'Role Att Global', 'guard_name' => 'web']);
        $roleGlobal->syncPermissions([
            'purchases.show',
            'purchases.update',
            'purchasePayments.global.access',
            'purchases.received.monetary.edit',
        ]);

        $this->authorizedUser = User::factory()->create(['is_active' => true]);
        $this->authorizedUser->assignRole($roleUpdate);
        $this->authorizedUser->settings()->attach($this->setting1->id, ['role_id' => $roleUpdate->id]);

        $this->unauthorizedUser = User::factory()->create(['is_active' => true]);
        $this->unauthorizedUser->assignRole($roleViewOnly);
        $this->unauthorizedUser->settings()->attach($this->setting1->id, ['role_id' => $roleViewOnly->id]);

        $this->globalUser = User::factory()->create(['is_active' => true]);
        $this->globalUser->assignRole($roleGlobal);
        $this->globalUser->settings()->attach($this->setting2->id, ['role_id' => $roleGlobal->id]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Convert with initial billing attachment
        $initialAttachment = UploadedFile::fake()->createWithContent('original_billing_inv.pdf', "%PDF-1.4\n%DUMMY-PDF-CONTENT\n");

        $previewService = new ConsignmentBillingPreviewService();
        $conversionService = new ConsignmentBillingConversionService($previewService);

        $this->consignmentPurchase = $conversionService->convert(
            $this->confirmation->id,
            $this->setting1->id,
            $this->authorizedUser->id,
            [
                'supplier_invoice_number' => 'INV-ATT-001',
                'invoice_date' => '2026-08-01',
                'due_date' => '2026-08-31',
            ],
            [$initialAttachment]
        );

        // Create a foreign purchase belonging to setting2
        $this->foreignPurchase = Purchase::create([
            'date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'reference' => 'PO-FOREIGN-ATT',
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting2->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        session(['setting_id' => $this->setting1->id]);
    }

    /**
     * Task 3.1: Original attachment from conversion is deletable from the Purchase.
     */
    public function test_original_conversion_attachment_is_deletable_from_purchase(): void
    {
        $this->actingAs($this->authorizedUser);

        $media = $this->consignmentPurchase->getFirstMedia('attachments');
        $this->assertNotNull($media);
        $this->assertEquals('CONSIGNMENT_BILLING', $media->getCustomProperty('source'));
        $this->assertEquals('original_billing_inv.pdf', $media->getCustomProperty('original_name'));

        // Delete the original billing attachment
        $response = $this->delete(route('purchases.attachments.destroy', [$this->consignmentPurchase, $media]));
        $response->assertRedirect();

        // Assert media is deleted from Purchase
        $this->assertNull($media->fresh());
        $this->assertCount(0, $this->consignmentPurchase->fresh()->getMedia('attachments'));

        // Assert conversion audit and confirmation remain intact
        $this->assertDatabaseHas('consignment_billing_confirmations', [
            'id' => $this->confirmation->id,
            'confirmation_number' => 'CBC-ATT-001',
        ]);
        $this->assertDatabaseHas('consignment_allocation_audit_logs', [
            'consignment_billing_confirmation_id' => $this->confirmation->id,
            'action' => 'BILLING_CONVERTED',
        ]);
    }

    /**
     * Task 3.1: Later-added attachments can be uploaded and deleted under ordinary rules.
     */
    public function test_later_added_attachment_can_be_uploaded_and_deleted(): void
    {
        $this->actingAs($this->authorizedUser);

        // Upload new attachment
        $file = UploadedFile::fake()->createWithContent('subsequent_proof.pdf', "%PDF-1.4\n%SUBSEQUENT-PDF-CONTENT\n");
        $response = $this->post(route('purchases.attachments.store', $this->consignmentPurchase), [
            'attachment' => $file,
        ]);
        $response->assertRedirect();

        $newMedia = $this->consignmentPurchase->getMedia('attachments')->first(function ($media) {
            return $media->getCustomProperty('original_name') === 'subsequent_proof.pdf';
        });
        $this->assertNotNull($newMedia);

        // Delete the later-added attachment
        $deleteResponse = $this->delete(route('purchases.attachments.destroy', [$this->consignmentPurchase, $newMedia]));
        $deleteResponse->assertRedirect();

        $this->assertNull($newMedia->fresh());
    }

    /**
     * Task 3.1: Deletion denied when lacking purchases.update permission.
     */
    public function test_attachment_deletion_denied_for_unauthorized_user(): void
    {
        $this->actingAs($this->unauthorizedUser);

        $media = $this->consignmentPurchase->getFirstMedia('attachments');
        $this->assertNotNull($media);

        $response = $this->delete(route('purchases.attachments.destroy', [$this->consignmentPurchase, $media]));
        $response->assertForbidden();

        $this->assertNotNull($media->fresh());
    }

    /**
     * Task 3.1: Foreign attachment deletion (media belonging to another purchase) is rejected with 404.
     */
    public function test_foreign_attachment_deletion_is_rejected(): void
    {
        $this->actingAs($this->authorizedUser);

        // Add media to foreign purchase
        $this->foreignPurchase->addMedia(UploadedFile::fake()->createWithContent('foreign.pdf', "%PDF-1.4\n%FOREIGN-PDF-CONTENT\n"))
            ->toMediaCollection('attachments');
        $foreignMedia = $this->foreignPurchase->getFirstMedia('attachments');

        // Attempt to delete foreign media through consignmentPurchase route
        $response = $this->delete(route('purchases.attachments.destroy', [$this->consignmentPurchase, $foreignMedia]));
        $response->assertNotFound();

        $this->assertNotNull($foreignMedia->fresh());
    }

    /**
     * Task 3.2: Full and monetary edit actions remain unavailable for consignment Purchases.
     */
    public function test_full_and_monetary_edit_actions_remain_unavailable_for_consignment_purchases(): void
    {
        $this->assertEquals(Purchase::EDIT_MODE_NONE, $this->consignmentPurchase->resolveEditMode($this->authorizedUser));

        $this->actingAs($this->authorizedUser);

        // Ordinary edit route rejects commercial edit for consignment billing
        $response = $this->get(route('purchases.edit', $this->consignmentPurchase));
        $response->assertRedirect();

        // Direct assertion that commercial editing is guarded
        $this->expectException(\Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException::class);
        \Modules\Purchase\Services\PurchaseSourceGuard::assertCommercialEditAllowed($this->consignmentPurchase);
    }

    /**
     * Task 3.2: Global monetary edit route aborts 403 because edit mode is EDIT_MODE_NONE.
     */
    public function test_global_monetary_edit_action_aborts_403_for_consignment_purchases(): void
    {
        $this->actingAs($this->globalUser);
        session(['setting_id' => $this->setting2->id]);

        $responseGlobal = $this->get(route('purchases.global-payments.edit-monetary', $this->consignmentPurchase->id));
        $responseGlobal->assertForbidden();
    }

    /**
     * Task 3.2: Informational copy in show view distinguishes locked commercial from editable administrative data,
     * and shows attachment delete button for authorized user.
     */
    public function test_informational_copy_and_action_controls_rendered_in_show_view(): void
    {
        $this->actingAs($this->authorizedUser);

        $response = $this->get(route('purchases.show', $this->consignmentPurchase));

        $response->assertOk();
        // Informational alert explaining locked commercial vs editable administrative data
        $response->assertSee('Dokumen Pembelian Hasil Konversi Tagihan Konsinyasi');
        $response->assertSee('Item dan nilai komersial bersifat read-only, sedangkan informasi administratif (tanggal, referensi, catatan, dan lampiran) dapat dikelola.');
        
        // Header edit button should NOT be present
        $response->assertDontSee('id="purchase-header-edit"', false);

        // Attachment delete form is present
        $response->assertSee(route('purchases.attachments.destroy', [$this->consignmentPurchase->id, $this->consignmentPurchase->getFirstMedia('attachments')->id]));
        $response->assertSee('Faktur Konsinyasi');
        $response->assertDontSee('Faktur Konsinyasi (Permanen)');
    }
}

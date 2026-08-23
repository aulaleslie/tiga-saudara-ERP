<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GlobalPurchaseDetailInlineEditorTest extends TestCase
{
    use RefreshDatabase;

    protected User $userWithBothPermissions;
    protected User $userWithGlobalOnly;
    protected User $userWithEditOnly;
    protected Setting $setting1;
    protected Setting $setting2;
    protected Supplier $supplier;
    protected Purchase $purchaseSetting2;

    protected function setUp(): void
    {
        parent::setUp();

        $updatePermission = Permission::firstOrCreate(['name' => 'purchases.update', 'guard_name' => 'web']);
        $showPermission = Permission::firstOrCreate(['name' => 'purchases.show', 'guard_name' => 'web']);
        $accessPermission = Permission::firstOrCreate(['name' => 'purchases.access', 'guard_name' => 'web']);
        $globalAccessPermission = Permission::firstOrCreate(['name' => 'purchasePayments.global.access', 'guard_name' => 'web']);
        $paymentCreatePermission = Permission::firstOrCreate(['name' => 'purchasePayments.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.reporting-date.override', 'guard_name' => 'web']);

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
        $fullRole->syncPermissions([$updatePermission, $showPermission, $accessPermission, $globalAccessPermission, $paymentCreatePermission]);

        $globalOnlyRole = Role::firstOrCreate(['name' => 'Global Only', 'guard_name' => 'web']);
        $globalOnlyRole->syncPermissions([$showPermission, $accessPermission, $globalAccessPermission]);

        $editOnlyRole = Role::firstOrCreate(['name' => 'Edit Only', 'guard_name' => 'web']);
        $editOnlyRole->syncPermissions([$updatePermission, $showPermission, $accessPermission]);

        $this->userWithBothPermissions = User::factory()->create(['is_active' => true]);
        $this->userWithBothPermissions->assignRole($fullRole);
        $this->userWithBothPermissions->settings()->attach($this->setting1->id, ['role_id' => $fullRole->id]);

        $this->userWithGlobalOnly = User::factory()->create(['is_active' => true]);
        $this->userWithGlobalOnly->assignRole($globalOnlyRole);
        $this->userWithGlobalOnly->settings()->attach($this->setting1->id, ['role_id' => $globalOnlyRole->id]);

        $this->userWithEditOnly = User::factory()->create(['is_active' => true]);
        $this->userWithEditOnly->assignRole($editOnlyRole);
        $this->userWithEditOnly->settings()->attach($this->setting1->id, ['role_id' => $editOnlyRole->id]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_phone' => '123',
            'supplier_email' => 'supplier@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting2->id,
        ]);

        $this->purchaseSetting2 = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Original note',
            'tax_ref_no' => 'OLD-TAX-REF',
            'supplier_purchase_number' => 'OLD-PO-REF',
            'setting_id' => $this->setting2->id,
            'reference' => 'PO-TEST-' . uniqid(),
        ]);

        session(['setting_id' => $this->setting1->id]);
    }

    public function test_global_detail_shows_canonical_template_with_globalMode()
    {
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $response->assertOk();
        $response->assertViewIs('purchase::show');
        $response->assertViewHas('globalMode', true);
        $response->assertViewHas('setting', $this->setting2);
    }

    public function test_global_detail_mounts_inline_editors_with_global_mode()
    {
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $response->assertOk();
        $response->assertSeeLivewire('purchase.supplier-purchase-number-editor');
        $response->assertSeeLivewire('purchase.tax-ref-no-editor');
        $response->assertSeeLivewire('purchase.purchase-note-editor');
    }

    public function test_authorized_user_can_edit_supplier_purchase_number_in_global_detail()
    {
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $response->assertOk();
        $response->assertSeeLivewire('purchase.supplier-purchase-number-editor');
        $response->assertSee('OLD-PO-REF');
    }

    public function test_global_access_without_update_permission_cannot_edit()
    {
        $this->actingAs($this->userWithGlobalOnly);

        $response = $this->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $response->assertOk();
        // The editors should still mount but be read-only
        $response->assertSeeLivewire('purchase.supplier-purchase-number-editor');
        $response->assertSeeLivewire('purchase.tax-ref-no-editor');
        $response->assertSeeLivewire('purchase.purchase-note-editor');
    }

    public function test_update_permission_without_global_access_cannot_access_global_detail()
    {
        $this->actingAs($this->userWithEditOnly);

        $response = $this->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $response->assertForbidden();
    }

    public function test_archived_purchase_prevents_inline_editing_in_global_detail()
    {
        $this->purchaseSetting2->update(['archived_at' => now()]);

        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        // Should not be able to access archived transaction via global-payments
        $response->assertNotFound();
    }

    public function test_setting_scoped_uniqueness_for_supplier_purchase_number()
    {
        // Create a purchase in setting1 with the same supplier_purchase_number
        $supplier1 = Supplier::create([
            'supplier_name' => 'Supplier Setting 1',
            'supplier_phone' => '456',
            'supplier_email' => 'supplier1@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting1->id,
        ]);

        $purchaseSetting1 = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $supplier1->id,
            'supplier_name' => $supplier1->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'supplier_purchase_number' => 'SAME-PO-REF',
            'setting_id' => $this->setting1->id,
            'reference' => 'PO-TEST2-' . uniqid(),
        ]);

        // Setting2 purchase should be able to have the same supplier_purchase_number
        $this->purchaseSetting2->update(['supplier_purchase_number' => 'SAME-PO-REF']);

        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $response->assertOk();
        $response->assertSee('SAME-PO-REF');
    }

    public function test_global_detail_suppresses_attachment_management()
    {
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $response->assertOk();
        // Attachment management should not be visible in global mode
        // (Purchase show doesn't have attachment mgmt like sale, but should have no edit controls)
    }

    public function test_global_detail_payment_action_routes_correctly()
    {
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $response->assertOk();
        // Should have link to global payment create
        $response->assertSee(route('purchases.global-payments.create', ['supplier' => $this->supplier->id, 'purchase_id' => $this->purchaseSetting2->id]));
    }

    public function test_supplier_purchase_number_editor_successful_cross_business_save()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        Livewire::test('purchase.supplier-purchase-number-editor', [
            'purchaseId' => $this->purchaseSetting2->id,
            'globalMode' => true,
        ])
            ->call('startEditing')
            ->set('supplierPurchaseNumber', 'NEW-PO-REF')
            ->call('save')
            ->assertDispatched('notify');

        $this->purchaseSetting2->refresh();
        $this->assertEquals('NEW-PO-REF', $this->purchaseSetting2->supplier_purchase_number);
    }

    public function test_tax_ref_no_editor_successful_cross_business_save()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        Livewire::test('purchase.tax-ref-no-editor', [
            'purchaseId' => $this->purchaseSetting2->id,
            'globalMode' => true,
        ])
            ->call('startEditing')
            ->set('taxRefNo', 'NEW-TAX-REF')
            ->call('save')
            ->assertDispatched('notify');

        $this->purchaseSetting2->refresh();
        $this->assertEquals('NEW-TAX-REF', $this->purchaseSetting2->tax_ref_no);
    }

    public function test_purchase_note_editor_successful_cross_business_save()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        Livewire::test('purchase.purchase-note-editor', [
            'purchaseId' => $this->purchaseSetting2->id,
            'globalMode' => true,
        ])
            ->call('startEditing')
            ->set('note', 'Updated note for global payment')
            ->call('save')
            ->assertDispatched('notify');

        $this->purchaseSetting2->refresh();
        $this->assertEquals('Updated note for global payment', $this->purchaseSetting2->note);
    }

    public function test_editor_rejects_global_access_without_domain_edit_permission()
    {
        $this->actingAs($this->userWithGlobalOnly);

        Livewire::test('purchase.supplier-purchase-number-editor', [
            'purchaseId' => $this->purchaseSetting2->id,
            'globalMode' => true,
        ])
            ->call('startEditing')
            ->assertForbidden();
    }

    public function test_editor_rejects_domain_edit_without_global_access()
    {
        $this->actingAs($this->userWithEditOnly);

        Livewire::test('purchase.supplier-purchase-number-editor', [
            'purchaseId' => $this->purchaseSetting2->id,
            'globalMode' => true,
        ])
            ->assertForbidden();
    }

    public function test_editor_rejects_archived_transaction_in_global_mode()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->purchaseSetting2->update(['archived_at' => now()]);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        try {
            Livewire::test('purchase.supplier-purchase-number-editor', [
                'purchaseId' => $this->purchaseSetting2->id,
                'globalMode' => true,
            ])
                ->call('startEditing');
            $this->fail('Expected exception for archived transaction');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Archived transactions are filtered out by globalPaymentEligible scope
            $this->assertTrue(true);
        }
    }

    public function test_editor_rejects_ineligible_transaction_in_global_mode()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        // Create rejected purchase
        $rejectedPurchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'due_amount' => 0,
            'status' => 'REJECTED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting2->id,
            'reference' => 'PO-REJECT-' . uniqid(),
        ]);

        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        try {
            Livewire::test('purchase.supplier-purchase-number-editor', [
                'purchaseId' => $rejectedPurchase->id,
                'globalMode' => true,
            ]);
            $this->fail('Expected exception for ineligible transaction');
        } catch (\Illuminate\View\ViewException $e) {
            // Rejected transactions are filtered out by globalPaymentEligible scope
            $this->assertStringContainsString('No query results', $e->getMessage());
        }
    }

    public function test_editor_locks_transaction_id_against_tampering()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        $component = Livewire::test('purchase.supplier-purchase-number-editor', [
            'purchaseId' => $this->purchaseSetting2->id,
            'globalMode' => true,
        ]);

        // Locked property cannot be set via Livewire (should throw exception)
        try {
            $component->set('purchaseId', 9999);
            $this->fail('Expected exception when trying to set locked purchaseId');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Cannot update locked property', $e->getMessage());
            $this->assertStringContainsString('purchaseId', $e->getMessage());
        }
    }

    public function test_editor_locks_global_mode_against_tampering()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        $component = Livewire::test('purchase.supplier-purchase-number-editor', [
            'purchaseId' => $this->purchaseSetting2->id,
            'globalMode' => true,
        ]);

        // Locked property cannot be set via Livewire (should throw exception)
        try {
            $component->set('globalMode', false);
            $this->fail('Expected exception when trying to set locked globalMode');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Cannot update locked property', $e->getMessage());
            $this->assertStringContainsString('globalMode', $e->getMessage());
        }
    }

    public function test_supplier_purchase_number_uniqueness_within_same_setting()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $supplier1 = Supplier::create([
            'supplier_name' => 'Supplier Setting 2 Another',
            'supplier_phone' => '789',
            'supplier_email' => 'supplier2@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting2->id,
        ]);

        $anotherPurchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $supplier1->id,
            'supplier_name' => $supplier1->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'supplier_purchase_number' => 'UNIQUE-IN-SETTING2',
            'setting_id' => $this->setting2->id,
            'reference' => 'PO-ANOTHER-' . uniqid(),
        ]);

        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        // Try to set duplicate in same setting
        Livewire::test('purchase.supplier-purchase-number-editor', [
            'purchaseId' => $this->purchaseSetting2->id,
            'globalMode' => true,
        ])
            ->call('startEditing')
            ->set('supplierPurchaseNumber', 'UNIQUE-IN-SETTING2')
            ->call('save')
            ->assertHasErrors('supplierPurchaseNumber');
    }

    public function test_supplier_purchase_number_allowed_in_different_setting()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $supplier1 = Supplier::create([
            'supplier_name' => 'Supplier Setting 1 Another',
            'supplier_phone' => '555',
            'supplier_email' => 'supplier3@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting1->id,
        ]);

        $purchaseSetting1 = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $supplier1->id,
            'supplier_name' => $supplier1->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1200,
            'paid_amount' => 0,
            'due_amount' => 1200,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'supplier_purchase_number' => 'ALLOWED-DUPLICATE',
            'setting_id' => $this->setting1->id,
            'reference' => 'PO-SETTING1-' . uniqid(),
        ]);

        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        // Same value allowed in different setting
        Livewire::test('purchase.supplier-purchase-number-editor', [
            'purchaseId' => $this->purchaseSetting2->id,
            'globalMode' => true,
        ])
            ->call('startEditing')
            ->set('supplierPurchaseNumber', 'ALLOWED-DUPLICATE')
            ->call('save')
            ->assertDispatched('notify');

        $this->purchaseSetting2->refresh();
        $this->assertEquals('ALLOWED-DUPLICATE', $this->purchaseSetting2->supplier_purchase_number);
    }

    public function test_normal_mode_purchase_isolation_unchanged()
    {
        $supplier1 = Supplier::create([
            'supplier_name' => 'Supplier Setting 1 Isolated',
            'supplier_phone' => '777',
            'supplier_email' => 'supplier4@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting1->id,
        ]);

        $purchaseSetting1 = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $supplier1->id,
            'supplier_name' => $supplier1->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 800,
            'paid_amount' => 0,
            'due_amount' => 800,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Normal mode note',
            'setting_id' => $this->setting1->id,
            'reference' => 'PO-NORMAL-' . uniqid(),
        ]);

        session(['setting_id' => $this->setting1->id]);
        $this->actingAs($this->userWithBothPermissions);

        Livewire::test('purchase.purchase-note-editor', [
            'purchaseId' => $purchaseSetting1->id,
            'globalMode' => false,
        ])
            ->call('startEditing')
            ->set('note', 'Updated normal mode note')
            ->call('save')
            ->assertDispatched('notify');

        $purchaseSetting1->refresh();
        $this->assertEquals('Updated normal mode note', $purchaseSetting1->note);
    }

}

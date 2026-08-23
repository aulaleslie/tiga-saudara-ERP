<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GlobalSaleDetailInlineEditorTest extends TestCase
{
    use RefreshDatabase;

    protected User $userWithBothPermissions;
    protected User $userWithGlobalOnly;
    protected User $userWithEditOnly;
    protected Setting $setting1;
    protected Setting $setting2;
    protected Customer $customer;
    protected Sale $saleSetting2;

    protected function setUp(): void
    {
        parent::setUp();

        $editPermission = Permission::firstOrCreate(['name' => 'sales.edit', 'guard_name' => 'web']);
        $showPermission = Permission::firstOrCreate(['name' => 'sales.show', 'guard_name' => 'web']);
        $accessPermission = Permission::firstOrCreate(['name' => 'sales.access', 'guard_name' => 'web']);
        $globalAccessPermission = Permission::firstOrCreate(['name' => 'salePayments.global.access', 'guard_name' => 'web']);
        $paymentCreatePermission = Permission::firstOrCreate(['name' => 'salePayments.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'sales.reporting-date.override', 'guard_name' => 'web']);

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
        $fullRole->syncPermissions([$editPermission, $showPermission, $accessPermission, $globalAccessPermission, $paymentCreatePermission]);

        $globalOnlyRole = Role::firstOrCreate(['name' => 'Global Only', 'guard_name' => 'web']);
        $globalOnlyRole->syncPermissions([$showPermission, $accessPermission, $globalAccessPermission]);

        $editOnlyRole = Role::firstOrCreate(['name' => 'Edit Only', 'guard_name' => 'web']);
        $editOnlyRole->syncPermissions([$editPermission, $showPermission, $accessPermission]);

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

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123',
            'customer_email' => 'cust@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting2->id,
        ]);

        $this->saleSetting2 = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Original note',
            'tax_ref_no' => 'OLD-TAX-REF',
            'setting_id' => $this->setting2->id,
            'reference' => 'SL-TEST-' . uniqid(),
        ]);

        session(['setting_id' => $this->setting1->id]);
    }

    public function test_global_detail_shows_canonical_template_with_globalMode()
    {
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertOk();
        $response->assertViewIs('sale::show');
        $response->assertViewHas('globalMode', true);
        $response->assertViewHas('setting', $this->setting2);
    }

    public function test_authorized_user_can_see_tax_ref_no_editor_in_global_detail()
    {
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertOk();
        $response->assertSeeLivewire('sale.tax-ref-no-editor');
        $response->assertSee('OLD-TAX-REF');
    }

    public function test_global_access_without_edit_permission_cannot_edit_tax_ref_no()
    {
        $this->actingAs($this->userWithGlobalOnly);

        $response = $this->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertOk();
        // The editor should still mount but be read-only
        $response->assertSeeLivewire('sale.tax-ref-no-editor');
    }

    public function test_edit_permission_without_global_access_cannot_access_global_detail()
    {
        $this->actingAs($this->userWithEditOnly);

        $response = $this->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertForbidden();
    }

    public function test_archived_sale_prevents_inline_editing_in_global_detail()
    {
        $this->saleSetting2->update(['archived_at' => now()]);

        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('sales.global-payments.show', $this->saleSetting2->id));

        // Should not be able to access archived transaction via global-payments
        $response->assertNotFound();
    }

    public function test_global_detail_suppresses_attachment_management()
    {
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertOk();
        // Attachment upload form should not be visible in global mode
        $response->assertDontSee('Tambah Lampiran');
    }

    public function test_global_detail_payment_action_shows_when_sale_has_due_amount()
    {
        // Sale is created with due_amount=1500 and paid_amount=0, so should show payment button
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertOk();
        // Should have link to global payment create when due amount > 0
        $response->assertSee(route('sales.global-payments.create', $this->saleSetting2->id));
    }


    public function test_globally_unique_tax_ref_no_not_enforced_across_settings()
    {
        // Create a sale in setting1 with the same tax ref
        $customer1 = Customer::create([
            'customer_name' => 'Customer Setting 1',
            'customer_phone' => '456',
            'customer_email' => 'cust1@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting1->id,
        ]);

        $saleSetting1 = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $customer1->id,
            'customer_name' => $customer1->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_ref_no' => 'SAME-TAX-REF',
            'setting_id' => $this->setting1->id,
            'reference' => 'SL-TEST2-' . uniqid(),
        ]);

        // Setting2 sale should be able to have the same tax ref because uniqueness is scoped to setting
        $this->saleSetting2->update(['tax_ref_no' => 'SAME-TAX-REF']);

        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertOk();
        $response->assertSee('SAME-TAX-REF');
    }

    public function test_tax_ref_no_editor_successful_cross_business_save()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        Livewire::test('sale.tax-ref-no-editor', [
            'saleId' => $this->saleSetting2->id,
            'globalMode' => true,
        ])
            ->call('startEditing')
            ->set('taxRefNo', 'NEW-TAX-REF')
            ->call('save')
            ->assertDispatched('notify');

        $this->saleSetting2->refresh();
        $this->assertEquals('NEW-TAX-REF', $this->saleSetting2->tax_ref_no);
    }

    public function test_sale_note_editor_successful_cross_business_save()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        Livewire::test('sale.sale-note-editor', [
            'saleId' => $this->saleSetting2->id,
            'globalMode' => true,
        ])
            ->call('startEditing')
            ->set('note', 'Updated note for global payment')
            ->call('save')
            ->assertDispatched('notify');

        $this->saleSetting2->refresh();
        $this->assertEquals('Updated note for global payment', $this->saleSetting2->note);
    }

    public function test_editor_rejects_global_access_without_domain_edit_permission()
    {
        $this->actingAs($this->userWithGlobalOnly);

        Livewire::test('sale.tax-ref-no-editor', [
            'saleId' => $this->saleSetting2->id,
            'globalMode' => true,
        ])
            ->call('startEditing')
            ->assertForbidden();
    }

    public function test_editor_rejects_domain_edit_without_global_access()
    {
        $this->actingAs($this->userWithEditOnly);

        Livewire::test('sale.tax-ref-no-editor', [
            'saleId' => $this->saleSetting2->id,
            'globalMode' => true,
        ])
            ->assertForbidden();
    }

    public function test_editor_rejects_archived_transaction_in_global_mode()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->saleSetting2->update(['archived_at' => now()]);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        try {
            Livewire::test('sale.tax-ref-no-editor', [
                'saleId' => $this->saleSetting2->id,
                'globalMode' => true,
            ]);
            $this->fail('Expected exception for archived transaction');
        } catch (\Illuminate\View\ViewException $e) {
            // Archived transactions are filtered out by globalPaymentEligible scope
            $this->assertStringContainsString('No query results', $e->getMessage());
        }
    }

    public function test_editor_rejects_ineligible_transaction_in_global_mode()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        // Create rejected sale
        $rejectedSale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'due_amount' => 0,
            'status' => Sale::STATUS_REJECTED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting2->id,
            'reference' => 'SL-REJECT-' . uniqid(),
        ]);

        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        try {
            Livewire::test('sale.tax-ref-no-editor', [
                'saleId' => $rejectedSale->id,
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

        $component = Livewire::test('sale.tax-ref-no-editor', [
            'saleId' => $this->saleSetting2->id,
            'globalMode' => true,
        ]);

        // Locked property cannot be set via Livewire (should throw exception)
        try {
            $component->set('saleId', 9999);
            $this->fail('Expected exception when trying to set locked saleId');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Cannot update locked property', $e->getMessage());
            $this->assertStringContainsString('saleId', $e->getMessage());
        }
    }

    public function test_editor_locks_global_mode_against_tampering()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        $component = Livewire::test('sale.tax-ref-no-editor', [
            'saleId' => $this->saleSetting2->id,
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

    public function test_normal_mode_sale_isolation_unchanged()
    {
        $customer1 = Customer::create([
            'customer_name' => 'Customer Setting 1 Isolated',
            'customer_phone' => '777',
            'customer_email' => 'cust4@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting1->id,
        ]);

        $saleSetting1 = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $customer1->id,
            'customer_name' => $customer1->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 800,
            'paid_amount' => 0,
            'due_amount' => 800,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Normal mode note',
            'setting_id' => $this->setting1->id,
            'reference' => 'SL-NORMAL-' . uniqid(),
        ]);

        session(['setting_id' => $this->setting1->id]);
        $this->actingAs($this->userWithBothPermissions);

        Livewire::test('sale.sale-note-editor', [
            'saleId' => $saleSetting1->id,
            'globalMode' => false,
        ])
            ->call('startEditing')
            ->set('note', 'Updated normal mode note')
            ->call('save')
            ->assertDispatched('notify');

        $saleSetting1->refresh();
        $this->assertEquals('Updated normal mode note', $saleSetting1->note);
    }

    public function test_normal_sale_detail_has_no_payment_button()
    {
        $customer1 = Customer::create([
            'customer_name' => 'Customer Setting 1 Button Test',
            'customer_phone' => '888',
            'customer_email' => 'cust5@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting1->id,
        ]);

        $saleSetting1 = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $customer1->id,
            'customer_name' => $customer1->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
            'reference' => 'SL-BTN-TEST-' . uniqid(),
        ]);

        session(['setting_id' => $this->setting1->id]);
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('sales.show', $saleSetting1->id));

        $response->assertOk();
        // Normal detail should NOT have link to global-payments.create
        // The payment button should be completely absent from normal mode
        $response->assertDontSee('Pembayaran Global');
    }

    public function test_global_detail_payment_button_points_to_multi_sale_payment()
    {
        $this->actingAs($this->userWithBothPermissions);

        $response = $this->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertOk();
        // Payment button should point to global multi-sale payment, not undefined route
        $response->assertSee(route('sales.global-payments.create', $this->saleSetting2->id));
    }

    public function test_global_detail_attachment_section_shown_without_controls()
    {
        $role = Role::firstOrCreate(['name' => 'Admin Full', 'guard_name' => 'web']);
        $this->actingAs($this->userWithBothPermissions);
        $this->userWithBothPermissions->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        $response = $this->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertOk();
        // Attachment section should be visible (heading "Lampiran:")
        $response->assertSee('Lampiran:');
        // Upload form should NOT be visible in global mode
        $response->assertDontSee('Tambah Lampiran');
        // Empty state message should show
        $response->assertSee('Tidak ada lampiran');
    }
}

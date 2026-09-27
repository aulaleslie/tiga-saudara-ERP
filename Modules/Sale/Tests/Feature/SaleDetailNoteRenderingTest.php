<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SaleDetailNoteRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected User $authorizedUser;
    protected User $unauthorizedUser;
    protected Setting $setting;
    protected Customer $customer;
    protected Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $editPermission = Permission::firstOrCreate(['name' => 'sales.edit', 'guard_name' => 'web']);
        $showPermission = Permission::firstOrCreate(['name' => 'sales.show', 'guard_name' => 'web']);
        $accessPermission = Permission::firstOrCreate(['name' => 'sales.access', 'guard_name' => 'web']);
        $globalAccessPermission = Permission::firstOrCreate(['name' => 'salePayments.global.access', 'guard_name' => 'web']);
        // Defined so blade @can('overrideReportingDate') does not throw when checking unset permission
        Permission::firstOrCreate(['name' => 'sales.reporting-date.override', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo([$editPermission, $showPermission, $accessPermission, $globalAccessPermission]);

        $viewRole = Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
        $viewRole->givePermissionTo([$showPermission, $accessPermission, $globalAccessPermission]);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->authorizedUser = User::factory()->create(['is_active' => true]);
        $this->authorizedUser->assignRole($role);
        $this->authorizedUser->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $this->unauthorizedUser = User::factory()->create(['is_active' => true]);
        $this->unauthorizedUser->assignRole($viewRole);
        $this->unauthorizedUser->settings()->attach($this->setting->id, ['role_id' => $viewRole->id]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123',
            'customer_email' => 'cust@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->sale = Sale::create([
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
            'note' => 'Operational note for detail test',
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . Str::random(6),
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    public function test_detail_view_renders_livewire_note_editor_with_edit_controls_for_authorized_user()
    {
        $this->actingAs($this->authorizedUser);

        $response = $this->get(route('sales.show', $this->sale));

        $response->assertOk();
        $response->assertSeeLivewire('sale.sale-note-editor');
        $response->assertSee('Operational note for detail test');
        $response->assertSee('Ubah');
    }

    public function test_detail_view_renders_read_only_note_without_edit_controls_for_unauthorized_user()
    {
        $this->actingAs($this->unauthorizedUser);

        $response = $this->get(route('sales.show', $this->sale));

        $response->assertOk();
        $response->assertSeeLivewire('sale.sale-note-editor');
        $response->assertSee('Operational note for detail test');
        $response->assertDontSee('wire:click="startEditing"', false);
    }

    public function test_global_detail_mounts_editor_with_global_mode_for_authorized_user()
    {
        $this->actingAs($this->authorizedUser);

        $response = $this->get(route('sales.global-payments.show', $this->sale->id));

        $response->assertOk();
        $response->assertSeeLivewire('sale.sale-note-editor');
        $response->assertSee('Operational note for detail test');
    }

    public function test_global_detail_renders_editor_as_read_only_for_unauthorized_user()
    {
        $this->unauthorizedUser->removeRole('Viewer');
        $viewerRole = Role::firstOrCreate(['name' => 'Viewer Global', 'guard_name' => 'web']);
        $viewerRole->givePermissionTo(['sales.show', 'sales.access', 'salePayments.global.access']);
        $this->unauthorizedUser->assignRole($viewerRole);
        $this->unauthorizedUser->settings()->syncWithPivotValues($this->setting->id, ['role_id' => $viewerRole->id]);

        $this->actingAs($this->unauthorizedUser);

        $response = $this->get(route('sales.global-payments.show', $this->sale->id));

        $response->assertOk();
        $response->assertSeeLivewire('sale.sale-note-editor');
        $response->assertSee('Operational note for detail test');
        $response->assertDontSee('wire:click="startEditing"', false);
    }

    public function test_global_detail_cannot_be_accessed_without_global_access_permission()
    {
        $unauthorizedRole = Role::firstOrCreate(['name' => 'No Global', 'guard_name' => 'web']);
        $unauthorizedRole->givePermissionTo(['sales.show', 'sales.access']);
        $localUser = User::factory()->create(['is_active' => true]);
        $localUser->assignRole($unauthorizedRole);
        $localUser->settings()->attach($this->setting->id, ['role_id' => $unauthorizedRole->id]);

        $this->actingAs($localUser);

        $response = $this->get(route('sales.global-payments.show', $this->sale->id));

        $response->assertForbidden();
    }
}

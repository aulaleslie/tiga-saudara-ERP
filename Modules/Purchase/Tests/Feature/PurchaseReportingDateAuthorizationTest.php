<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use App\Services\ReportingDateOverrideService;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseReportingDateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;

    public function setUp(): void
    {
        parent::setUp();

        // Permission rows must exist before they can be granted or checked.
        Permission::findOrCreate('purchases.reporting-date.override', 'web');
        Permission::findOrCreate('purchases.show', 'web');
        Role::findOrCreate('Super Admin', 'web');

        // The fresh-sqlite runner starts from an empty database, so create the
        // currency/setting this suite depends on instead of assuming seed data.
        Currency::firstOrCreate(['id' => 1], [
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);


        $this->user = User::first() ?: User::factory()->create([
            'is_active' => true,
        ]);

        $this->setting = Setting::first() ?: Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Test',
            'company_address' => 'Test Address',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    public function test_authorized_user_can_override_approved_purchase()
    {
        $this->user->givePermissionTo('purchases.reporting-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-' . time(),
            'due_date' => now()->addDays(30),
            'supplier_name' => 'Test Supplier',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);

        $can = $this->user->can('overrideReportingDate', $purchase);
        $this->assertTrue($can);
    }

    public function test_unauthorized_user_cannot_override()
    {
        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-' . time(),
            'due_date' => now()->addDays(30),
            'supplier_name' => 'Test Supplier',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);

        $can = $this->user->can('overrideReportingDate', $purchase);
        $this->assertFalse($can);
    }

    public function test_ineligible_purchase_status_denied()
    {
        $this->user->givePermissionTo('purchases.reporting-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_DRAFTED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-' . time(),
            'due_date' => now()->addDays(30),
            'supplier_name' => 'Test Supplier',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);

        $can = $this->user->can('overrideReportingDate', $purchase);
        $this->assertFalse($can);
    }

    public function test_cross_setting_access_denied()
    {
        $this->user->givePermissionTo('purchases.reporting-date.override');

        $otherSetting = Setting::factory()->create();

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $otherSetting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-' . time(),
            'due_date' => now()->addDays(30),
            'supplier_name' => 'Test Supplier',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);

        $can = $this->user->can('overrideReportingDate', $purchase);
        $this->assertFalse($can);
    }

    /**
     * AuthServiceProvider registers an application-wide Gate::before that grants
     * every ability to Super Admin, so the role bypasses this policy even without
     * the explicit permission. That bypass predates this feature and is asserted
     * here as the actual behavior rather than changed.
     */
    public function test_super_admin_bypasses_policy_without_explicit_permission()
    {
        $this->user->assignRole('Super Admin');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-' . time(),
            'due_date' => now()->addDays(30),
            'supplier_name' => 'Test Supplier',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);

        $can = $this->user->can('overrideReportingDate', $purchase);
        $this->assertTrue($can);
    }

    public function test_super_admin_with_permission_can_override()
    {
        $this->user->assignRole('Super Admin');
        $this->user->givePermissionTo('purchases.reporting-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-' . time(),
            'due_date' => now()->addDays(30),
            'supplier_name' => 'Test Supplier',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);

        $can = $this->user->can('overrideReportingDate', $purchase);
        $this->assertTrue($can);
    }

    public function test_endpoint_requires_authorization()
    {
        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-' . time(),
            'due_date' => now()->addDays(30),
            'supplier_name' => 'Test Supplier',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);

        $response = $this->post(route('purchases.reporting-date.store', $purchase), [
            'reporting_date' => now()->addDays(5)->format('Y-m-d'),
            'reason' => 'Test',
        ]);

        $this->assertEquals(403, $response->status());
    }

    public function test_endpoint_accepts_authorized_request()
    {
        $this->user->givePermissionTo('purchases.reporting-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-' . time(),
            'due_date' => now()->addDays(30),
            'supplier_name' => 'Test Supplier',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);

        $response = $this->post(route('purchases.reporting-date.store', $purchase), [
            'reporting_date' => now()->addDays(5)->format('Y-m-d'),
            'reason' => 'Test Reason',
        ]);

        $this->assertEquals(200, $response->status());
        $this->assertTrue($response->json('success'));
    }

    public function test_endpoint_validates_required_fields()
    {
        $this->user->givePermissionTo('purchases.reporting-date.override');

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'date' => now()->subDays(10),
            'reference' => 'TST-' . time(),
            'due_date' => now()->addDays(30),
            'supplier_name' => 'Test Supplier',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ]);

        $response = $this->postJson(route('purchases.reporting-date.store', $purchase), [
            'reporting_date' => '',
            'reason' => '',
        ]);

        $this->assertEquals(422, $response->status());
    }
}

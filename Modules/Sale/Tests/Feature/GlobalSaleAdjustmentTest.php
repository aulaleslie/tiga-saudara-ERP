<?php

namespace Modules\Sale\Tests\Feature;

use App\Livewire\Sale\EditForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GlobalSaleAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting1;
    protected Setting $setting2;
    protected Customer $customerSetting2;
    protected Sale $saleSetting2;
    protected Product $product;

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

        $this->setting1 = Setting::create([
            'company_name' => 'Setting 1',
            'company_email' => 'setting1@test.com',
            'company_phone' => '111',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'setting1@test.com',
            'company_address' => 'Addr 1',
            'footer_text' => 'Footer 1',
            'is_pkp' => false,
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Setting 2',
            'company_email' => 'setting2@test.com',
            'company_phone' => '222',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'setting2@test.com',
            'company_address' => 'Addr 2',
            'footer_text' => 'Footer 2',
            'is_pkp' => false,
        ]);

        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);

        Permission::findOrCreate('salePayments.global.access');
        Permission::findOrCreate('sales.edit');
        Permission::findOrCreate('sales.dispatched.monetary.edit');
        Permission::findOrCreate('sales.reporting-date.override');
        Permission::findOrCreate('sales.due-date.override');

        $this->user = User::factory()->create();
        $this->user->assignRole($superAdminRole);
        $this->user->givePermissionTo([
            'salePayments.global.access',
            'sales.edit',
            'sales.dispatched.monetary.edit',
            'sales.reporting-date.override',
            'sales.due-date.override',
        ]);

        $this->customerSetting2 = Customer::create([
            'customer_name' => 'Customer 2 ' . uniqid(),
            'customer_email' => uniqid() . '@example.com',
            'customer_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Address 2',
            'setting_id' => $this->setting2->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP01',
            'product_quantity' => 10,
            'product_price' => 1000,
            'product_cost' => 500,
            'product_unit' => 1,
            'setting_id' => $this->setting2->id,
        ]);

        $this->saleSetting2 = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'SL-' . uniqid(),
            'customer_id' => $this->customerSetting2->id,
            'customer_name' => $this->customerSetting2->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'setting_id' => $this->setting2->id,
        ]);

        SaleDetails::create([
            'sale_id' => $this->saleSetting2->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 1000,
            'product_tax_amount' => 0,
        ]);
    }

    public function test_global_detail_renders_monetary_and_date_adjustment_actions_when_authorized()
    {
        session(['setting_id' => $this->setting1->id]);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertStatus(200);
        $response->assertSee(route('sales.global-payments.edit-monetary', $this->saleSetting2->id));
        $response->assertSee('Ubah Nilai (Moneter)');
        $response->assertSee('Penyesuaian Tanggal');
        $response->assertDontSee('Cetak Faktur');
    }

    public function test_global_detail_hides_monetary_action_when_lacking_monetary_permission()
    {
        session(['setting_id' => $this->setting1->id]);

        $restrictedUser = User::factory()->create();
        $restrictedRole = Role::firstOrCreate(['name' => 'Restricted Sale Role ' . uniqid()]);
        $restrictedRole->givePermissionTo([
            'salePayments.global.access',
            'sales.edit',
            'sales.reporting-date.override',
        ]);
        $restrictedUser->settings()->attach($this->setting1->id, ['role_id' => $restrictedRole->id]);

        $response = $this->actingAs($restrictedUser)
            ->get(route('sales.global-payments.show', $this->saleSetting2->id));

        $response->assertStatus(200);
        $response->assertDontSee(route('sales.global-payments.edit-monetary', $this->saleSetting2->id));
        $response->assertSee('Penyesuaian Tanggal');
    }

    public function test_global_monetary_edit_route_denies_access_when_permission_missing()
    {
        session(['setting_id' => $this->setting1->id]);

        $restrictedUser = User::factory()->create();
        $restrictedRole = Role::firstOrCreate(['name' => 'Restricted Sale Role ' . uniqid()]);
        $restrictedRole->givePermissionTo([
            'salePayments.global.access',
            'sales.edit',
        ]);
        $restrictedUser->settings()->attach($this->setting1->id, ['role_id' => $restrictedRole->id]);

        $response = $this->actingAs($restrictedUser)
            ->get(route('sales.global-payments.edit-monetary', $this->saleSetting2->id));

        $response->assertStatus(403);
    }

    public function test_global_monetary_edit_saves_cross_setting_sale_successfully()
    {
        session(['setting_id' => $this->setting1->id]);

        $this->actingAs($this->user);
        Livewire::actingAs($this->user)->test(EditForm::class, [
            'sale' => $this->saleSetting2,
            'isGlobal' => true,
        ])
        ->set('shipping', 200)
        ->call('update')
        ->assertRedirect(route('sales.global-payments.show', $this->saleSetting2->id));

        $this->saleSetting2->refresh();
        $this->assertEquals(200, $this->saleSetting2->shipping_amount);
        $this->assertEquals(1200, $this->saleSetting2->total_amount);
    }

    public function test_global_date_adjustment_saves_cross_setting_dates_successfully()
    {
        session(['setting_id' => $this->setting1->id]);

        $newDueDate = now()->addDays(14)->format('Y-m-d');

        $response = $this->actingAs($this->user)->putJson(
            route('sales.global-payments.date-adjustment.update', $this->saleSetting2->id),
            [
                'reporting_action' => 'keep',
                'due_date_action' => 'set',
                'due_date' => $newDueDate,
                'reason' => 'Perpanjangan kesepakatan customer global',
            ]
        );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->saleSetting2->refresh();
        $this->assertEquals($newDueDate, $this->saleSetting2->due_date->format('Y-m-d'));
    }

    public function test_normal_sale_routes_remain_setting_scoped()
    {
        $ordinaryUser = User::factory()->create();
        $ordinaryRole = Role::firstOrCreate(['name' => 'Ordinary Sale Role ' . uniqid()]);
        Permission::findOrCreate('sales.edit');
        Permission::findOrCreate('sales.reporting-date.override');
        Permission::findOrCreate('sales.due-date.override');
        $ordinaryRole->givePermissionTo([
            'sales.edit',
            'sales.reporting-date.override',
            'sales.due-date.override',
        ]);
        $ordinaryUser->settings()->attach($this->setting1->id, ['role_id' => $ordinaryRole->id]);

        // Active session is setting 1, sale is in setting 2
        session(['setting_id' => $this->setting1->id]);

        // Attempting normal edit route for setting 2 sale
        $response = $this->actingAs($ordinaryUser)
            ->get(route('sales.edit', $this->saleSetting2->id));

        $response->assertStatus(404);

        // Attempting normal date adjustment route for setting 2 sale
        $responseDate = $this->actingAs($ordinaryUser)->putJson(
            route('sales.date-adjustment.update', $this->saleSetting2->id),
            [
                'reporting_action' => 'keep',
                'due_date_action' => 'set',
                'due_date' => now()->addDays(10)->format('Y-m-d'),
                'reason' => 'Bypass attempt',
            ]
        );

        $responseDate->assertStatus(403);
    }

    public function test_ordinary_non_super_admin_user_can_perform_global_monetary_edit()
    {
        $ordinaryUser = User::factory()->create();
        $ordinaryRole = Role::firstOrCreate(['name' => 'Global Granular Sale Role ' . uniqid()]);
        $ordinaryRole->givePermissionTo([
            'salePayments.global.access',
            'sales.edit',
            'sales.dispatched.monetary.edit',
        ]);
        $ordinaryUser->settings()->attach($this->setting1->id, ['role_id' => $ordinaryRole->id]);
        $ordinaryUser->settings()->attach($this->setting2->id, ['role_id' => $ordinaryRole->id]);
        $ordinaryUser->givePermissionTo([
            'salePayments.global.access',
            'sales.edit',
            'sales.dispatched.monetary.edit',
        ]);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        session(['setting_id' => $this->setting1->id]);

        $this->actingAs($ordinaryUser);
        Livewire::actingAs($ordinaryUser)->test(EditForm::class, [
            'sale' => $this->saleSetting2,
            'isGlobal' => true,
        ])
        ->set('shipping', 250)
        ->call('update')
        ->assertRedirect(route('sales.global-payments.show', $this->saleSetting2->id));

        $this->saleSetting2->refresh();
        $this->assertEquals(250, $this->saleSetting2->shipping_amount);
    }

    public function test_monetary_edit_uses_target_document_setting_pkp_rules_and_ignores_active_session_pkp()
    {
        // setting1 (active session) is_pkp = false
        // setting2 (document owner) is_pkp = true
        $this->setting2->update(['is_pkp' => true]);

        $tax = \Modules\Setting\Entities\Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
            'is_active' => true,
        ]);

        $this->saleSetting2->update([
            'tax_id' => $tax->id,
            'tax_percentage' => 11,
            'is_tax_included' => false,
        ]);

        $this->saleSetting2->saleDetails()->update([
            'tax_id' => $tax->id,
            'product_tax_amount' => 110.0,
        ]);

        session(['setting_id' => $this->setting1->id]);

        $this->actingAs($this->user);
        Livewire::actingAs($this->user)->test(EditForm::class, [
            'sale' => $this->saleSetting2,
            'isGlobal' => true,
        ])
        ->set('shipping', 0)
        ->call('update');

        $this->saleSetting2->refresh();
        // Target setting is_pkp = true, so product_tax_amount 110.0 is preserved despite active session setting1 is_pkp = false
        $this->assertEquals(110.0, (float) $this->saleSetting2->tax_amount);
    }

    public function test_archived_global_sale_cannot_be_adjusted()
    {
        session(['setting_id' => $this->setting1->id]);
        $this->saleSetting2->update(['archived_at' => now()]);

        // Monetary edit attempt fails
        $this->expectException(\App\Services\MonetaryEdit\MonetaryEditException::class);

        app(\Modules\Sale\Services\SaleMonetaryEditService::class)->apply(
            $this->saleSetting2,
            [
                [
                    'id' => $this->saleSetting2->saleDetails->first()->id,
                    'qty' => 1,
                    'price' => 1000,
                    'unit_price' => 1000,
                    'options' => [
                        'product_id' => $this->product->id,
                    ],
                ]
            ],
            ['shipping' => 100],
            isGlobal: true
        );
    }

    public function test_tampering_is_global_property_on_livewire_component_fails()
    {
        session(['setting_id' => $this->setting1->id]);

        $this->actingAs($this->user);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property');

        Livewire::actingAs($this->user)->test(EditForm::class, [
            'sale' => $this->saleSetting2,
            'isGlobal' => false,
        ])
        ->set('isGlobal', true);
    }

    public function test_revoking_permissions_after_mount_fails_at_submit_time()
    {
        session(['setting_id' => $this->setting1->id]);

        $ordinaryUser = User::factory()->create();
        $ordinaryRole = Role::firstOrCreate(['name' => 'Temp Sale Role ' . uniqid()]);
        $ordinaryRole->givePermissionTo([
            'salePayments.global.access',
            'sales.edit',
            'sales.dispatched.monetary.edit',
        ]);
        $ordinaryUser->settings()->attach($this->setting1->id, ['role_id' => $ordinaryRole->id]);

        $this->actingAs($ordinaryUser);

        // Revoke permission before service apply execution
        $ordinaryRole->revokePermissionTo('sales.dispatched.monetary.edit');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(\App\Services\MonetaryEdit\MonetaryEditException::class);

        app(\Modules\Sale\Services\SaleMonetaryEditService::class)->apply(
            $this->saleSetting2,
            [
                [
                    'id' => $this->saleSetting2->saleDetails->first()->id,
                    'qty' => 1,
                    'price' => 1000,
                    'unit_price' => 1000,
                    'options' => [
                        'product_id' => $this->product->id,
                    ],
                ]
            ],
            ['shipping' => 100],
            isGlobal: true
        );
    }
}

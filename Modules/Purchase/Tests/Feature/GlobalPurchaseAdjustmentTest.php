<?php

namespace Modules\Purchase\Tests\Feature;

use App\Livewire\Purchase\EditForm;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Services\PurchaseMonetaryEditService;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GlobalPurchaseAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting1;
    protected Setting $setting2;
    protected Supplier $supplierSetting2;
    protected Purchase $purchaseSetting2;
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
        
        Permission::findOrCreate('purchasePayments.global.access');
        Permission::findOrCreate('purchases.update');
        Permission::findOrCreate('purchases.received.monetary.edit');
        Permission::findOrCreate('purchases.reporting-date.override');
        Permission::findOrCreate('purchases.due-date.override');

        $this->user = User::factory()->create();
        $this->user->assignRole($superAdminRole);
        $this->user->givePermissionTo([
            'purchasePayments.global.access',
            'purchases.update',
            'purchases.received.monetary.edit',
            'purchases.reporting-date.override',
            'purchases.due-date.override',
        ]);

        $this->supplierSetting2 = Supplier::create([
            'supplier_name' => 'Supplier 2 ' . uniqid(),
            'supplier_email' => uniqid() . '@example.com',
            'supplier_phone' => '12345678',
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

        $this->purchaseSetting2 = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PR-' . uniqid(),
            'supplier_id' => $this->supplierSetting2->id,
            'supplier_name' => $this->supplierSetting2->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'setting_id' => $this->setting2->id,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $this->purchaseSetting2->id,
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
            ->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $response->assertStatus(200);
        $response->assertSee(route('purchases.global-payments.edit-monetary', $this->purchaseSetting2->id));
        $response->assertSee('Ubah Nilai (Moneter)');
        $response->assertSee('Penyesuaian Tanggal');
        $response->assertDontSee('Duplikat');
        $response->assertDontSee('Koreksi Penerimaan');
    }

    public function test_global_detail_hides_monetary_action_when_lacking_monetary_permission()
    {
        session(['setting_id' => $this->setting1->id]);

        $restrictedUser = User::factory()->create();
        $restrictedRole = Role::firstOrCreate(['name' => 'Restricted Role ' . uniqid()]);
        $restrictedRole->givePermissionTo([
            'purchasePayments.global.access',
            'purchases.update',
            'purchases.reporting-date.override',
        ]);
        $restrictedUser->settings()->attach($this->setting1->id, ['role_id' => $restrictedRole->id]);

        $response = $this->actingAs($restrictedUser)
            ->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $response->assertStatus(200);
        $response->assertDontSee(route('purchases.global-payments.edit-monetary', $this->purchaseSetting2->id));
        $response->assertSee('Penyesuaian Tanggal');
    }

    public function test_global_monetary_edit_route_denies_access_when_permission_missing()
    {
        session(['setting_id' => $this->setting1->id]);

        $restrictedUser = User::factory()->create();
        $restrictedRole = Role::firstOrCreate(['name' => 'Restricted Role ' . uniqid()]);
        $restrictedRole->givePermissionTo([
            'purchasePayments.global.access',
            'purchases.update',
        ]);
        $restrictedUser->settings()->attach($this->setting1->id, ['role_id' => $restrictedRole->id]);

        $response = $this->actingAs($restrictedUser)
            ->get(route('purchases.global-payments.edit-monetary', $this->purchaseSetting2->id));

        $response->assertStatus(403);
    }

    public function test_global_monetary_edit_saves_cross_setting_purchase_successfully()
    {
        session(['setting_id' => $this->setting1->id]);

        $this->actingAs($this->user);
        Livewire::actingAs($this->user)->test(EditForm::class, [
            'purchaseId' => $this->purchaseSetting2->id,
            'isGlobal' => true,
        ])
        ->set('shipping', 150)
        ->call('submit')
        ->assertRedirect(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $this->purchaseSetting2->refresh();
        $this->assertEquals(150, $this->purchaseSetting2->shipping_amount);
        $this->assertEquals(1150, $this->purchaseSetting2->total_amount);
    }

    public function test_global_date_adjustment_saves_cross_setting_dates_successfully()
    {
        session(['setting_id' => $this->setting1->id]);

        $newDueDate = now()->addDays(14)->format('Y-m-d');

        $response = $this->actingAs($this->user)->putJson(
            route('purchases.global-payments.date-adjustment.update', $this->purchaseSetting2->id),
            [
                'reporting_action' => 'keep',
                'due_date_action' => 'set',
                'due_date' => $newDueDate,
                'reason' => 'Perpanjangan kesepakatan supplier global',
            ]
        );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->purchaseSetting2->refresh();
        $this->assertEquals($newDueDate, $this->purchaseSetting2->due_date->format('Y-m-d'));
    }

    public function test_normal_purchase_routes_remain_setting_scoped()
    {
        $ordinaryUser = User::factory()->create();
        $ordinaryRole = Role::firstOrCreate(['name' => 'Ordinary Purchase Role ' . uniqid()]);
        Permission::findOrCreate('purchases.update');
        Permission::findOrCreate('purchases.reporting-date.override');
        Permission::findOrCreate('purchases.due-date.override');
        $ordinaryRole->givePermissionTo([
            'purchases.update',
            'purchases.reporting-date.override',
            'purchases.due-date.override',
        ]);
        $ordinaryUser->settings()->attach($this->setting1->id, ['role_id' => $ordinaryRole->id]);

        // Active session is setting 1, purchase is in setting 2
        session(['setting_id' => $this->setting1->id]);

        // Attempting normal edit route for setting 2 purchase
        $response = $this->actingAs($ordinaryUser)
            ->get(route('purchases.edit', $this->purchaseSetting2->id));

        $response->assertStatus(404);

        // Attempting normal date adjustment route for setting 2 purchase
        $responseDate = $this->actingAs($ordinaryUser)->putJson(
            route('purchases.date-adjustment.update', $this->purchaseSetting2->id),
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
        $ordinaryRole = Role::firstOrCreate(['name' => 'Global Granular Role ' . uniqid()]);
        $ordinaryRole->givePermissionTo([
            'purchasePayments.global.access',
            'purchases.update',
            'purchases.received.monetary.edit',
        ]);
        $ordinaryUser->settings()->attach($this->setting1->id, ['role_id' => $ordinaryRole->id]);
        $ordinaryUser->settings()->attach($this->setting2->id, ['role_id' => $ordinaryRole->id]);
        $ordinaryUser->givePermissionTo([
            'purchasePayments.global.access',
            'purchases.update',
            'purchases.received.monetary.edit',
        ]);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        session(['setting_id' => $this->setting1->id]);

        $this->actingAs($ordinaryUser);
        Livewire::actingAs($ordinaryUser)->test(EditForm::class, [
            'purchaseId' => $this->purchaseSetting2->id,
            'isGlobal' => true,
        ])
        ->set('shipping', 250)
        ->call('submit')
        ->assertRedirect(route('purchases.global-payments.show', $this->purchaseSetting2->id));

        $this->purchaseSetting2->refresh();
        $this->assertEquals(250, $this->purchaseSetting2->shipping_amount);
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

        $this->purchaseSetting2->update([
            'tax_id' => $tax->id,
            'tax_percentage' => 11,
            'is_tax_included' => false,
        ]);

        $this->purchaseSetting2->purchaseDetails()->update([
            'tax_id' => $tax->id,
            'product_tax_amount' => 110.0,
        ]);

        session(['setting_id' => $this->setting1->id]);

        $this->actingAs($this->user);
        Livewire::actingAs($this->user)->test(EditForm::class, [
            'purchaseId' => $this->purchaseSetting2->id,
            'isGlobal' => true,
        ])
        ->set('shipping', 0)
        ->call('submit');

        $this->purchaseSetting2->refresh();
        // Target setting is_pkp = true, so product_tax_amount 110.0 is preserved despite active session setting1 is_pkp = false
        $this->assertEquals(110.0, (float) $this->purchaseSetting2->tax_amount);
    }

    public function test_archived_global_purchase_cannot_be_adjusted()
    {
        session(['setting_id' => $this->setting1->id]);
        $this->purchaseSetting2->update(['archived_at' => now()]);

        // Monetary edit attempt fails
        $this->expectException(\App\Services\MonetaryEdit\MonetaryEditException::class);

        app(PurchaseMonetaryEditService::class)->apply(
            $this->purchaseSetting2,
            [
                [
                    'id' => $this->purchaseSetting2->purchaseDetails->first()->id,
                    'qty' => 1,
                    'price' => 1000,
                    'unit_price' => 1000,
                    'options' => [
                        'purchase_detail_id' => $this->purchaseSetting2->purchaseDetails->first()->id,
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
            'purchaseId' => $this->purchaseSetting2->id,
            'isGlobal' => false,
        ])
        ->set('isGlobal', true);
    }

    public function test_revoking_permissions_after_mount_fails_at_submit_time()
    {
        session(['setting_id' => $this->setting1->id]);

        $ordinaryUser = User::factory()->create();
        $ordinaryRole = Role::firstOrCreate(['name' => 'Temp Role ' . uniqid()]);
        $ordinaryRole->givePermissionTo([
            'purchasePayments.global.access',
            'purchases.update',
            'purchases.received.monetary.edit',
        ]);
        $ordinaryUser->settings()->attach($this->setting1->id, ['role_id' => $ordinaryRole->id]);

        $this->actingAs($ordinaryUser);

        // Revoke permission before service apply execution
        $ordinaryRole->revokePermissionTo('purchases.received.monetary.edit');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(\App\Services\MonetaryEdit\MonetaryEditException::class);

        app(PurchaseMonetaryEditService::class)->apply(
            $this->purchaseSetting2,
            [
                [
                    'id' => $this->purchaseSetting2->purchaseDetails->first()->id,
                    'qty' => 1,
                    'price' => 1000,
                    'unit_price' => 1000,
                    'options' => [
                        'purchase_detail_id' => $this->purchaseSetting2->purchaseDetails->first()->id,
                        'product_id' => $this->product->id,
                    ],
                ]
            ],
            ['shipping' => 100],
            isGlobal: true
        );
    }
}

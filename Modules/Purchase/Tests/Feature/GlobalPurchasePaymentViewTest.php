<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GlobalPurchasePaymentViewTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting1;
    protected $setting2;
    protected $purchaseSetting2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting1 = Setting::create([
            'company_name' => 'Setting 1',
            'company_email' => 'setting1@test.com',
            'company_phone' => '111',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'setting1@test.com',
            'company_address' => 'Addr 1',
            'footer_text' => 'Footer 1'
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Setting 2',
            'company_email' => 'setting2@test.com',
            'company_phone' => '222',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'setting2@test.com',
            'company_address' => 'Addr 2',
            'footer_text' => 'Footer 2'
        ]);

        Permission::firstOrCreate(['name' => 'purchasePayments.global.access']);
        
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $role->givePermissionTo('purchasePayments.global.access');

        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        $supplier = Supplier::create([
            'supplier_name' => 'Supplier 2 ' . uniqid(),
            'supplier_email' => uniqid() . '@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Address 2',
            'setting_id' => $this->setting2->id,
        ]);

        $this->purchaseSetting2 = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PR-' . uniqid(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
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
            'note' => '',
            'tax_rate' => 0,
            'setting_id' => $this->setting2->id,
        ]);
    }

    public function test_global_show_bypasses_setting_id_and_returns_200()
    {
        $this->withoutExceptionHandling();
        // Act as user in setting1
        session(['setting_id' => $this->setting1->id]);

        // Access purchase in setting2 via global show
        $response = $this->actingAs($this->user)->get(route('purchases.global-payments.show', $this->purchaseSetting2->id));
        $response->assertStatus(200);
        $response->assertViewIs('purchase::show');
        $response->assertViewHas('globalMode', true);

        // Assert inline editors are mounted for inline metadata edits
        $response->assertSeeLivewire('purchase.supplier-purchase-number-editor');
        $response->assertSeeLivewire('purchase.tax-ref-no-editor');
        $response->assertSeeLivewire('purchase.purchase-note-editor');

        // Assert non-inline action buttons like "Duplikat" are not present because of globalMode
        $response->assertDontSeeText('Duplikat');

        // Assert breadcrumb points back to global index
        $response->assertSee(route('purchases.global-payments.index'));
    }

    public function test_global_history_bypasses_setting_id_and_returns_200()
    {
        // Act as user in setting1
        session(['setting_id' => $this->setting1->id]);

        // Access purchase in setting2 via global history
        $response = $this->actingAs($this->user)->get(route('purchases.global-payments.history', $this->purchaseSetting2->id));
        $response->assertStatus(200);
        $response->assertViewIs('purchase::payments.index');
        
        // Assert breadcrumb points back to global index
        $response->assertSee(route('purchases.global-payments.index'));
    }
}

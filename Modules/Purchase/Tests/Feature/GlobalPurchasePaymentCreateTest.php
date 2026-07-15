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

class GlobalPurchasePaymentCreateTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting1;
    protected $setting2;
    protected $supplier;
    protected $purchase1;
    protected $purchase2;
    protected $purchaseUnreceived;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Currency ID 1 first
        \Modules\Currency\Entities\Currency::create([
            'id' => 1,
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ','
        ]);

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
        Permission::firstOrCreate(['name' => 'purchasePayments.create']);
        
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $role->givePermissionTo(['purchasePayments.global.access', 'purchasePayments.create']);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier Global ' . uniqid(),
            'supplier_email' => uniqid() . '@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Address',
            'setting_id' => $this->setting1->id, // Supplier belongs to setting1
        ]);

        $this->purchase1 = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PR-1',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'tax_rate' => 0,
            'setting_id' => $this->setting1->id,
        ]);
        
        $this->purchase2 = $this->purchase1->replicate();
        $this->purchase2->reference = 'PR-2';
        $this->purchase2->setting_id = $this->setting2->id;
        $this->purchase2->status = \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED;
        $this->purchase2->save();

        $this->purchaseUnreceived = $this->purchase1->replicate();
        $this->purchaseUnreceived->reference = 'PR-3';
        $this->purchaseUnreceived->status = 'Pending';
        $this->purchaseUnreceived->save();
    }

    public function test_candidate_query_across_settings()
    {
        // Actually STATUS_RECEIVED is "Completed".
        // Ensure that purchases with Completed status are picked up, across settings.
        
        session(['setting_id' => $this->setting1->id]);
        
        $response = $this->actingAs($this->user)->get(route('purchases.global-payments.create', [
            'supplier' => $this->supplier->id,
            'purchase_id' => $this->purchase1->id
        ]));
        
        $response->assertStatus(200);
        
        // Assert it sees both PR-1 and PR-2 because they are Completed and cross-setting
        $response->assertSee(route('purchases.global-payments.show', $this->purchase1->id));
        $response->assertSee(route('purchases.global-payments.show', $this->purchase2->id));
        
        // Assert it doesn't see PR-3 because it is Pending
        $response->assertDontSee(route('purchases.global-payments.show', $this->purchaseUnreceived->id));
    }
    
    public function test_rejects_ineligible_starting_purchase()
    {
        session(['setting_id' => $this->setting1->id]);
        
        // Try starting with an unreceived purchase
        $response = $this->actingAs($this->user)->get(route('purchases.global-payments.create', [
            'supplier' => $this->supplier->id,
            'purchase_id' => $this->purchaseUnreceived->id
        ]));
        // Should redirect back to index
        $response->assertRedirect(route('purchases.global-payments.index'));
    }
}

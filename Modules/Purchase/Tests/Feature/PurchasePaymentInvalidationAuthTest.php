<?php

namespace Modules\Purchase\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class PurchasePaymentInvalidationAuthTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $supplier;
    protected $purchase;
    protected $payment;

    protected function setUp(): void
    {
        parent::setUp();
        
        DB::statement('PRAGMA foreign_keys = OFF');
        
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        // Create permissions but don't assign them to the default test user
        $permissions = [
            'purchasePayments.access',
            'purchasePayments.create',
            'purchasePayments.edit',
            'purchasePayments.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->actingAs($this->user);

        Currency::create([
             'id' => 1,
             'currency_name' => 'Rupiah',
             'code' => 'IDR',
             'symbol' => 'Rp',
             'thousand_separator' => '.',
             'decimal_separator' => ',',
             'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456',
            'notification_email' => 'notify@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
        
        session(['setting_id' => $this->setting->id]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        $this->payment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 10000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);
    }

    /** @test */
    public function it_denies_access_to_payment_list_without_permission()
    {
        $this->get(route('purchase-payments.index', $this->purchase->id))
            ->assertStatus(403);
    }

    /** @test */
    public function it_denies_access_to_create_payment_without_permission()
    {
        $this->get(route('purchase-payments.create', $this->purchase->id))
            ->assertStatus(403);
    }

    /** @test */
    public function it_denies_storing_payment_without_permission()
    {
        $this->post(route('purchase-payments.store'), [
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-NEW',
            'payment_method' => 'Cash',
            'payment_method_id' => 1, // Mock payment method if needed, or simply pass ID if validation allows
        ])->assertStatus(403);
    }

    /** @test */
    public function it_denies_editing_payment_without_permission()
    {
        $this->get(route('purchase-payments.edit', ['purchase_id' => $this->purchase->id, 'purchasePayment' => $this->payment->id]))
            ->assertStatus(403);
    }

    /** @test */
    public function it_denies_updating_payment_without_permission()
    {
        $this->patch(route('purchase-payments.update', $this->payment->id), [
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-UPDATED',
            'payment_method' => 'Cash',
        ])->assertStatus(403);
    }

    /** @test */
    public function it_denies_invalidating_payment_without_permission()
    {
        $this->post(route('purchase-payments.invalidate', $this->payment->id))
            ->assertStatus(403);
            
        // Double check state hasn't changed
        $this->payment->refresh();
        $this->assertEquals(PurchasePayment::STATUS_ACTIVE, $this->payment->status);
    }

    /** @test */
    public function it_denies_deleting_payment_without_permission()
    {
        // Even if invalidated, user needs permission to delete
        $this->payment->update(['status' => PurchasePayment::STATUS_INVALIDATED]);
        
        $this->delete(route('purchase-payments.destroy', $this->payment->id))
            ->assertStatus(403);
            
        $this->assertDatabaseHas('purchase_payments', ['id' => $this->payment->id]);
    }
}

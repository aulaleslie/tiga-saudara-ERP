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

class PurchasePaymentInvalidateDeletePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $supplier;
    protected $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        
        DB::statement('PRAGMA foreign_keys = OFF');
        
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        Permission::findOrCreate('purchasePayments.delete', 'web');

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo('purchasePayments.delete');
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
    }

    /** @test */
    public function it_allows_deleting_active_payment_directly()
    {
        $payment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $this->delete(route('purchase-payments.destroy', $payment->id))
            ->assertRedirect(route('purchases.index'));
            
        $this->assertDatabaseMissing('purchase_payments', ['id' => $payment->id]);
    }

    /** @test */
    public function it_allows_deleting_invalidated_payment()
    {
        $payment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_INVALIDATED,
        ]);

        $this->delete(route('purchase-payments.destroy', $payment->id));
            
        $this->assertDatabaseMissing('purchase_payments', ['id' => $payment->id]);
    }

    /** @test */
    public function it_invalidates_active_payment()
    {
        $payment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $this->post(route('purchase-payments.invalidate', $payment->id))
            ->assertStatus(302);

        $payment->refresh();
        $this->assertEquals(PurchasePayment::STATUS_INVALIDATED, $payment->status);
        $this->assertNotNull($payment->invalidated_at);
        $this->assertEquals($this->user->id, $payment->invalidated_by);
    }

    /** @test */
    public function it_rejects_double_invalidation()
    {
        $payment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_INVALIDATED,
        ]);

        $this->post(route('purchase-payments.invalidate', $payment->id))
            ->assertStatus(403);
    }

    /** @test */
    public function it_blocks_unauthorized_invalidate_and_destroy()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);

        $payment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $this->post(route('purchase-payments.invalidate', $payment->id))
            ->assertStatus(403);

        $payment->status = PurchasePayment::STATUS_INVALIDATED;
        $payment->save();

        $this->delete(route('purchase-payments.destroy', $payment->id))
            ->assertStatus(403);
    }

    /** @test */
    public function it_blocks_cross_setting_access()
    {
        $otherSetting = Setting::create([
            'id' => 2,
            'company_name' => 'Other Company',
            'company_email' => 'other@company.com',
            'company_phone' => '123456',
            'notification_email' => 'other@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $otherPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-OTHER',
            'supplier_id' => $this->supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'setting_id' => $otherSetting->id,
        ]);

        $payment = PurchasePayment::create([
            'purchase_id' => $otherPurchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-OTHER',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $this->post(route('purchase-payments.invalidate', $payment->id))
            ->assertStatus(404);

        $payment->status = PurchasePayment::STATUS_INVALIDATED;
        $payment->save();

        $this->delete(route('purchase-payments.destroy', $payment->id))
            ->assertStatus(404);
    }

    /** @test */
    public function it_recomputes_totals_after_invalidate()
    {
        // Setup: Purchase total 10000, currently 10000 paid (PAID status)
        // We'll invalidate one payment of 5000 -> should become 5000 paid (PARTIAL status)

        $payment1 = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-1',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $payment2 = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-2',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $this->post(route('purchase-payments.invalidate', $payment1->id));

        $this->purchase->refresh();
        $this->assertEquals(5000, $this->purchase->paid_amount);
        $this->assertEquals(5000, $this->purchase->due_amount);
        $this->assertTrue(\App\Constants\PaymentStatus::matches(\App\Constants\PaymentStatus::PARTIAL, $this->purchase->payment_status), "Expected PARTIAL, got {$this->purchase->payment_status}");
    }
}

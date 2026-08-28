<?php

namespace Modules\Purchase\Tests\Unit;

use Tests\TestCase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\Purchase;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PurchasePaymentStatusScopeTest extends TestCase
{
    use RefreshDatabase;

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
        
        Setting::create([
             'id' => 1,
             'company_name' => 'Test Company',
             'company_email' => 'test@company.com',
             'company_phone' => '1234567890',
             'company_address' => 'Test Address',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'notification_email' => 'notification@test.com',
             'footer_text' => 'Test Footer',
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);

        $this->purchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PUR-001',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Cash',
            'status' => 'Received',
            'payment_status' => 'Unpaid',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'setting_id' => 1,
        ]);
    }

    /** @test */
    public function it_has_correct_status_constants()
    {
        $this->assertEquals('ACTIVE', PurchasePayment::STATUS_ACTIVE);
        $this->assertEquals('INVALIDATED', PurchasePayment::STATUS_INVALIDATED);
    }

    /** @test */
    public function it_has_is_active_helper()
    {
        $payment = new PurchasePayment(['status' => PurchasePayment::STATUS_ACTIVE]);
        $this->assertTrue($payment->isActive());
        
        $payment->status = PurchasePayment::STATUS_INVALIDATED;
        $this->assertFalse($payment->isActive());
    }

    /** @test */
    public function it_has_is_invalidated_helper()
    {
        $payment = new PurchasePayment(['status' => PurchasePayment::STATUS_INVALIDATED]);
        $this->assertTrue($payment->isInvalidated());
        
        $payment->status = PurchasePayment::STATUS_ACTIVE;
        $this->assertFalse($payment->isInvalidated());
    }

    /** @test */
    public function it_filters_active_and_invalidated_payments_via_scopes()
    {
        $active = PurchasePayment::create([
            'amount' => 100,
            'date' => now(),
            'reference' => 'PAY-001',
            'status' => PurchasePayment::STATUS_ACTIVE,
            'purchase_id' => $this->purchase->id,
            'payment_method' => 'Cash'
        ]);

        $invalidated = PurchasePayment::create([
            'amount' => 200,
            'date' => now(),
            'reference' => 'PAY-002',
            'status' => PurchasePayment::STATUS_INVALIDATED,
            'purchase_id' => $this->purchase->id,
            'payment_method' => 'Cash'
        ]);

        $activeResults = PurchasePayment::active()->get();
        $invalidatedResults = PurchasePayment::invalidated()->get();

        $this->assertTrue($activeResults->contains($active));
        $this->assertFalse($activeResults->contains($invalidated));
        
        $this->assertTrue($invalidatedResults->contains($invalidated));
        $this->assertFalse($invalidatedResults->contains($active));
    }

    /** @test */
    public function it_filters_purchases_by_payment_status_casing_variants_via_where_payment_status_scope()
    {
        // Purchase created with 'Unpaid'
        $purchasesUnpaid = Purchase::wherePaymentStatus('unpaid')->get();
        $this->assertTrue($purchasesUnpaid->contains($this->purchase));

        // Update payment_status column directly to uppercase 'PAID'
        \Illuminate\Support\Facades\DB::table('purchases')
            ->where('id', $this->purchase->id)
            ->update(['payment_status' => 'PAID']);

        $purchasesPaid = Purchase::wherePaymentStatus('Paid')->get();
        $this->assertTrue($purchasesPaid->contains($this->purchase));

        $purchasesMultiple = Purchase::wherePaymentStatus(['Paid', 'Partial'])->get();
        $this->assertTrue($purchasesMultiple->contains($this->purchase));
    }
}

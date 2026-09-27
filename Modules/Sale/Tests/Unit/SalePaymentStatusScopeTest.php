<?php

namespace Modules\Sale\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalePaymentStatusScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Sale $sale;

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

        $this->sale = Sale::query()->create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-UNIT-001',
            'setting_id' => 1,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'payment_method' => 'Cash',
            'status' => 'COMPLETED',
            'payment_status' => 'Unpaid',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);
    }

    /** @test */
    public function it_has_correct_status_constants(): void
    {
        $this->assertSame('ACTIVE', SalePayment::STATUS_ACTIVE);
        $this->assertSame('INVALIDATED', SalePayment::STATUS_INVALIDATED);
    }

    /** @test */
    public function it_has_is_active_helper(): void
    {
        $payment = new SalePayment(['status' => SalePayment::STATUS_ACTIVE]);
        $this->assertTrue($payment->isActive());

        $payment->status = SalePayment::STATUS_INVALIDATED;
        $this->assertFalse($payment->isActive());
    }

    /** @test */
    public function it_has_is_invalidated_helper(): void
    {
        $payment = new SalePayment(['status' => SalePayment::STATUS_INVALIDATED]);
        $this->assertTrue($payment->isInvalidated());

        $payment->status = SalePayment::STATUS_ACTIVE;
        $this->assertFalse($payment->isInvalidated());
    }

    /** @test */
    public function it_filters_active_and_invalidated_payments_via_scopes(): void
    {
        $active = SalePayment::query()->create([
            'sale_id' => $this->sale->id,
            'amount' => 100,
            'date' => now()->toDateString(),
            'reference' => 'PAY-ACTIVE-001',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $invalidated = SalePayment::query()->create([
            'sale_id' => $this->sale->id,
            'amount' => 200,
            'date' => now()->toDateString(),
            'reference' => 'PAY-INVALID-001',
            'payment_method' => 'Transfer',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        $activeResults = SalePayment::query()->active()->get();
        $invalidatedResults = SalePayment::query()->invalidated()->get();

        $this->assertTrue($activeResults->contains($active));
        $this->assertFalse($activeResults->contains($invalidated));
        $this->assertTrue($invalidatedResults->contains($invalidated));
        $this->assertFalse($invalidatedResults->contains($active));
    }
}
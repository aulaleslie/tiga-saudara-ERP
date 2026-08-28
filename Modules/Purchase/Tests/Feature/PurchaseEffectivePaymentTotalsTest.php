<?php

namespace Modules\Purchase\Tests\Feature;

use Tests\TestCase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class PurchaseEffectivePaymentTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $supplier;
    protected $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        
        DB::statement('PRAGMA foreign_keys = OFF');

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
            'setting_id' => $this->setting->id,
        ]);

        $this->purchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PUR-001',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Cash',
            'status' => 'Received',
            'payment_status' => 'Unpaid',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        $user = \App\Models\User::factory()->create();

        \Modules\Product\Entities\Category::create([
            'id' => 1,
            'category_name' => 'Cat',
            'category_code' => 'C',
            'created_by' => $user->id,
            'setting_id' => $this->setting->id,
        ]);

        \Modules\Product\Entities\Product::create([
            'id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 1000,
            'product_unit' => 'PC',
            'product_stock_alert' => 10,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => '',
            'category_id' => 1,
            'setting_id' => $this->setting->id,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $this->purchase->id,
            'product_id' => 1,
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 10000,
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
    }

    /** @test */
    public function it_calculates_effective_paid_sum_from_active_only()
    {
        PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-001',
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash'
        ]);

        PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 3000,
            'date' => now(),
            'reference' => 'PAY-002',
            'status' => PurchasePayment::STATUS_INVALIDATED,
            'payment_method' => 'Cash'
        ]);

        $this->assertEquals(5000, $this->purchase->getEffectivePaidAmount());
    }

    /** @test */
    public function it_sets_status_to_unpaid_when_all_payments_are_invalidated()
    {
        $payment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 10000,
            'date' => now(),
            'reference' => 'PAY-001',
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash'
        ]);

        // Manually trigger the "store" logic outcome for baseline
        $this->purchase->update([
            'paid_amount' => 10000,
            'due_amount' => 0,
            'payment_status' => 'PAID'
        ]);

        // Invalidate payment
        $payment->update(['status' => PurchasePayment::STATUS_INVALIDATED]);

        // Mock recalculation (like what settlement or controller would do)
        $effectivePaid = $this->purchase->getEffectivePaidAmount();
        $this->purchase->update([
            'paid_amount' => $effectivePaid,
            'due_amount' => $this->purchase->total_amount - $effectivePaid,
            'payment_status' => $effectivePaid <= 0 ? 'UNPAID' : 'PARTIAL'
        ]);

        $this->assertEquals(0, $this->purchase->paid_amount);
        $this->assertEquals(10000, $this->purchase->due_amount);
        $this->assertTrue(\App\Constants\PaymentStatus::matches(\App\Constants\PaymentStatus::UNPAID, $this->purchase->payment_status), 'Expected UNPAID, got ' . $this->purchase->payment_status);
    }

    /** @test */
    public function it_handles_floating_precision_at_threshold()
    {
        $this->purchase->update(['total_amount' => 100.00]);
        
        PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 99.995, // Rounding test
            'date' => now(),
            'reference' => 'PAY-001',
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash'
        ]);

        $effectivePaid = $this->purchase->getEffectivePaidAmount();
        $dueAmount = max(0, $this->purchase->total_amount - $effectivePaid);
        $paymentStatus = $dueAmount <= 0.01 ? 'PAID' : 'PARTIAL';

        $this->assertTrue(\App\Constants\PaymentStatus::matches(\App\Constants\PaymentStatus::PAID, $paymentStatus), 'Expected PAID, got ' . $paymentStatus);
    }
}

<?php

namespace Modules\Purchase\Tests\Unit;

use Tests\TestCase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\Purchase;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class PurchasePaymentEffectiveTotalsMatrixTest extends TestCase
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
            'total_amount' => 1000, // Stored as integer cents in real DB? No, getAmountAttribute divides by 100.
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);
    }

    /**
     * Helper to recalculate totals using the same logic as the controllers.
     */
    private function syncPurchaseTotals(Purchase $purchase)
    {
        $effectivePaid = $purchase->getEffectivePaidAmount();
        $total = (float) $purchase->total_amount;
        $due = max(0, $total - $effectivePaid);
        
        $status = 'UNPAID';
        if ($due <= 0.01) {
            $status = 'PAID';
        } elseif ($effectivePaid > 0) {
            $status = 'PARTIAL';
        }

        $purchase->update([
            'paid_amount' => $effectivePaid,
            'due_amount' => $due,
            'payment_status' => $status
        ]);
    }

    /** @test */
    public function it_maintains_invariants_across_all_states()
    {
        $matrix = [
            // [ActivePayments, InvalidatedPayments, expectedPaid, expectedStatus]
            [[100, 200],    [50],           300,    'PARTIAL'],
            [[1000],        [500, 500],     1000,   'PAID'],
            [[],            [100, 200],     0,      'UNPAID'],
            [[500, 500],    [],             1000,   'PAID'],
            [[0.01],        [999.99],       0.01,   'PARTIAL'], // Tiny payment
            [[999.99],      [0.01],         999.99, 'PAID'], // Very close to full (0.01 due is PAID)
        ];

        foreach ($matrix as $case) {
            // Cleanup for fresh case
            PurchasePayment::where('purchase_id', $this->purchase->id)->delete();
            
            [$active, $invalid, $expectedPaid, $expectedStatus] = $case;

            foreach ($active as $amount) {
                PurchasePayment::create([
                    'purchase_id' => $this->purchase->id,
                    'amount' => $amount,
                    'status' => PurchasePayment::STATUS_ACTIVE,
                    'reference' => 'PAY-A-' . uniqid(),
                    'date' => now()->toDateString(),
                    'payment_method' => 'Cash'
                ]);
            }

            foreach ($invalid as $amount) {
                PurchasePayment::create([
                    'purchase_id' => $this->purchase->id,
                    'amount' => $amount,
                    'status' => PurchasePayment::STATUS_INVALIDATED,
                    'reference' => 'PAY-I-' . uniqid(),
                    'date' => now()->toDateString(),
                    'payment_method' => 'Cash'
                ]);
            }

            $this->syncPurchaseTotals($this->purchase);
            $this->purchase->refresh();

            $this->assertEquals($expectedPaid, (float) $this->purchase->paid_amount, "Failed paid_amount for case: " . json_encode($case));
            $this->assertEquals($expectedStatus, $this->purchase->payment_status, "Failed payment_status for case: " . json_encode($case));
            
            // Invariant: paid + due = total (within 1 cent precision)
            $this->assertEqualsWithDelta((float)$this->purchase->total_amount, (float)$this->purchase->paid_amount + (float)$this->purchase->due_amount, 0.01, "Invariant failed for case: " . json_encode($case));
        }
    }

    /** @test */
    public function it_handles_zero_total_purchase()
    {
        $this->purchase->update(['total_amount' => 0, 'due_amount' => 0]);
        
        $this->syncPurchaseTotals($this->purchase);
        $this->purchase->refresh();

        $this->assertEquals('PAID', $this->purchase->payment_status);
        $this->assertEquals(0, $this->purchase->due_amount);
    }

    /** @test */
    public function it_handles_overpayment_as_paid()
    {
        PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 1500, // total is 1000
            'status' => PurchasePayment::STATUS_ACTIVE,
            'reference' => 'PAY-OVER',
            'date' => now()->toDateString(),
            'payment_method' => 'Cash'
        ]);

        $this->syncPurchaseTotals($this->purchase);
        $this->purchase->refresh();

        $this->assertEquals(1500, $this->purchase->paid_amount);
        $this->assertEquals(0, $this->purchase->due_amount);
        $this->assertEquals('PAID', $this->purchase->payment_status);
    }
}

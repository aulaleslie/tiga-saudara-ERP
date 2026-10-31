<?php

namespace Modules\Purchase\Tests\Unit;

use Tests\TestCase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PurchaseLiveBalanceProjectionTest extends TestCase
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
    }

    protected function createPurchase(array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PO-' . uniqid(),
            'setting_id' => 1,
            'payment_method' => 'Cash',
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ], $overrides));
    }

    /** @test */
    public function it_calculates_live_balance_with_no_payments(): void
    {
        $purchase = $this->createPurchase([
            'reference' => 'PO-001',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        $this->assertEquals(0, $purchase->getEffectivePaidAmount());
        $this->assertEquals(1000000, $purchase->live_due_amount);

        // Test scope greater than / less than
        $this->assertTrue(Purchase::whereLiveDueAmountGreaterThan(0)->where('id', $purchase->id)->exists());
        $this->assertFalse(Purchase::whereLiveDueAmountLessThanOrEqual(0)->where('id', $purchase->id)->exists());
    }

    /** @test */
    public function it_calculates_live_balance_with_partial_active_payments_ignoring_stale_headers(): void
    {
        $purchase = $this->createPurchase([
            'reference' => 'PO-002',
            'total_amount' => 1000000,
            'paid_amount' => 100000, // Deliberately stale
            'due_amount' => 900000,  // Deliberately stale
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 400000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        // Refresh without touching stored headers
        $fresh = Purchase::findOrFail($purchase->id);
        $this->assertEquals(400000, $fresh->getEffectivePaidAmount());
        $this->assertEquals(600000, $fresh->live_due_amount);
        $this->assertEquals(900000, $fresh->due_amount); // Stored header untouched

        // Preloaded active_payments_sum path
        $preloaded = Purchase::withSum(['purchasePayments as active_payments_sum' => function ($q) {
            $q->where('status', PurchasePayment::STATUS_ACTIVE);
        }], 'amount')->findOrFail($purchase->id);

        $this->assertEquals(400000, $preloaded->getEffectivePaidAmount());
        $this->assertEquals(600000, $preloaded->live_due_amount);

        // SQL predicates
        $this->assertTrue(Purchase::whereLiveDueAmountGreaterThan(500000)->where('id', $purchase->id)->exists());
        $this->assertFalse(Purchase::whereLiveDueAmountGreaterThan(600000)->where('id', $purchase->id)->exists());
        $this->assertTrue(Purchase::whereLiveDueAmountLessThanOrEqual(600000)->where('id', $purchase->id)->exists());
    }

    /** @test */
    public function it_excludes_invalidated_payments_from_live_balance(): void
    {
        $purchase = $this->createPurchase([
            'reference' => 'PO-003',
            'total_amount' => 1000000,
            'paid_amount' => 500000, // Deliberately stale
            'due_amount' => 500000,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 500000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-002',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_INVALIDATED,
        ]);

        $fresh = Purchase::findOrFail($purchase->id);
        $this->assertEquals(0, $fresh->getEffectivePaidAmount());
        $this->assertEquals(1000000, $fresh->live_due_amount);
    }

    /** @test */
    public function it_clamps_live_due_to_zero_on_over_settlement_without_modifying_records(): void
    {
        $purchase = $this->createPurchase([
            'reference' => 'PO-004',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'total_amount' => 500000,
            'paid_amount' => 600000,
            'due_amount' => 0,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 600000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-003',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $fresh = Purchase::findOrFail($purchase->id);
        $this->assertEquals(600000, $fresh->getEffectivePaidAmount());
        $this->assertEquals(0, $fresh->live_due_amount);

        // SQL predicates: over-settlement means <= 0 is true, > 0 is false
        $this->assertFalse(Purchase::whereLiveDueAmountGreaterThan(0)->where('id', $purchase->id)->exists());
        $this->assertTrue(Purchase::whereLiveDueAmountLessThanOrEqual(0)->where('id', $purchase->id)->exists());
    }

    /** @test */
    public function it_orders_deterministically_by_clamped_live_due_with_opposite_stored_ordering(): void
    {
        // PO A: stored due 100,000, live due 800,000 (total 1,000,000, active pay 200,000)
        $poA = $this->createPurchase([
            'reference' => 'PO-A',
            'payment_status' => 'Partial',
            'total_amount' => 1000000,
            'paid_amount' => 900000,
            'due_amount' => 100000, // Stored is smaller
        ]);
        PurchasePayment::create([
            'purchase_id' => $poA->id,
            'amount' => 200000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-A',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        // PO B: stored due 900,000, live due 200,000 (total 1,000,000, active pay 800,000)
        $poB = $this->createPurchase([
            'reference' => 'PO-B',
            'payment_status' => 'Partial',
            'total_amount' => 1000000,
            'paid_amount' => 100000,
            'due_amount' => 900000, // Stored is larger
        ]);
        PurchasePayment::create([
            'purchase_id' => $poB->id,
            'amount' => 800000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-B',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        // PO C: over-settled (total 500,000, active pay 600,000, clamped live due 0)
        $poC = $this->createPurchase([
            'reference' => 'PO-C',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'total_amount' => 500000,
            'paid_amount' => 600000,
            'due_amount' => 0,
        ]);
        PurchasePayment::create([
            'purchase_id' => $poC->id,
            'amount' => 600000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-C',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        // Order ascending by live due: PO-C (0) -> PO-B (200k) -> PO-A (800k)
        $ascIds = Purchase::whereIn('id', [$poA->id, $poB->id, $poC->id])
            ->orderByLiveDueAmount('asc')
            ->orderBy('id', 'asc')
            ->pluck('id')
            ->toArray();

        $this->assertEquals([$poC->id, $poB->id, $poA->id], $ascIds);

        // Order descending by live due: PO-A (800k) -> PO-B (200k) -> PO-C (0)
        $descIds = Purchase::whereIn('id', [$poA->id, $poB->id, $poC->id])
            ->orderByLiveDueAmount('desc')
            ->orderBy('id', 'asc')
            ->pluck('id')
            ->toArray();

        $this->assertEquals([$poA->id, $poB->id, $poC->id], $descIds);
    }
}

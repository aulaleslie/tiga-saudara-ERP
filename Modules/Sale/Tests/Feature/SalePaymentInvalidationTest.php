<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalePaymentInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

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

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->sale = Sale::query()->create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-FEATURE-001',
            'setting_id' => 1,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'payment_method' => 'Cash',
            'status' => 'COMPLETED',
            'payment_status' => 'Paid',
            'total_amount' => 800,
            'paid_amount' => 800,
            'due_amount' => 0,
        ]);
    }

    /** @test */
    public function it_invalidates_only_active_payments_for_a_sale_and_preserves_existing_invalidated_rows(): void
    {
        $firstActive = $this->createPayment([
            'reference' => 'PAY-ACTIVE-1',
            'amount' => 300,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);
        $secondActive = $this->createPayment([
            'reference' => 'PAY-ACTIVE-2',
            'amount' => 200,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);
        $alreadyInvalidated = $this->createPayment([
            'reference' => 'PAY-INVALID-1',
            'amount' => 100,
            'status' => SalePayment::STATUS_INVALIDATED,
            'invalidation_source' => 'MANUAL',
            'invalidation_source_id' => 77,
        ]);

        $affected = SalePayment::invalidateAllActiveForSale($this->sale->id, 'POS_RETURN_CASH_CORRECTION', 99);

        $this->assertSame(2, $affected);
        $this->assertSame(SalePayment::STATUS_INVALIDATED, (string) $firstActive->fresh()->status);
        $this->assertSame(SalePayment::STATUS_INVALIDATED, (string) $secondActive->fresh()->status);
        $this->assertNotNull($firstActive->fresh()->invalidated_at);
        $this->assertSame($this->user->id, (int) $firstActive->fresh()->invalidated_by);
        $this->assertSame('POS_RETURN_CASH_CORRECTION', (string) $firstActive->fresh()->invalidation_source);
        $this->assertSame(99, (int) $firstActive->fresh()->invalidation_source_id);

        $alreadyInvalidated->refresh();
        $this->assertSame('MANUAL', (string) $alreadyInvalidated->invalidation_source);
        $this->assertSame(77, (int) $alreadyInvalidated->invalidation_source_id);
    }

    /** @test */
    public function it_reconciles_active_payments_last_payment_first_and_creates_a_split_replacement_when_needed(): void
    {
        $firstPayment = $this->createPayment([
            'reference' => 'PAY-A',
            'amount' => 300,
            'payment_method' => 'Cash',
            'stage_order' => 1,
            'date' => now()->subDays(2)->toDateString(),
        ]);
        $secondPayment = $this->createPayment([
            'reference' => 'PAY-B',
            'amount' => 300,
            'payment_method' => 'Transfer',
            'stage_order' => 2,
            'edc_reference' => 'EDC-B',
            'date' => now()->subDay()->toDateString(),
            'note' => 'Original transfer note',
        ]);
        $thirdPayment = $this->createPayment([
            'reference' => 'PAY-C',
            'amount' => 200,
            'payment_method' => 'QRIS',
            'stage_order' => 3,
            'edc_reference' => 'EDC-C',
            'date' => now()->toDateString(),
        ]);

        $activeTotal = SalePayment::reconcileActivePaymentsForSale($this->sale->id, 560, 'POS_RETURN_CASH_CORRECTION', 123);

        $payments = SalePayment::query()
            ->where('sale_id', $this->sale->id)
            ->orderBy('stage_order')
            ->orderBy('id')
            ->get();

        $replacementPayment = $payments
            ->where('status', SalePayment::STATUS_ACTIVE)
            ->first(fn (SalePayment $payment) => (int) $payment->id !== (int) $firstPayment->id);

        $this->assertSame(560.0, $activeTotal);
        $this->assertCount(4, $payments);
        $this->assertSame(SalePayment::STATUS_ACTIVE, (string) $firstPayment->fresh()->status);
        $this->assertSame(SalePayment::STATUS_INVALIDATED, (string) $secondPayment->fresh()->status);
        $this->assertSame(SalePayment::STATUS_INVALIDATED, (string) $thirdPayment->fresh()->status);
        $this->assertSame($this->user->id, (int) $secondPayment->fresh()->invalidated_by);
        $this->assertSame('POS_RETURN_CASH_CORRECTION', (string) $secondPayment->fresh()->invalidation_source);
        $this->assertSame(123, (int) $secondPayment->fresh()->invalidation_source_id);
        $this->assertNotNull($replacementPayment);
        $this->assertSame(260.0, (float) $replacementPayment->amount);
        $this->assertSame('TRANSFER', (string) $replacementPayment->payment_method);
        $this->assertSame(2, (int) $replacementPayment->stage_order);
        $this->assertSame('EDC-B', (string) $replacementPayment->edc_reference);
        $this->assertNull($replacementPayment->idempotency_key);
        $this->assertStringContainsString('ORIGINAL TRANSFER NOTE', strtoupper((string) $replacementPayment->note));
        $this->assertStringContainsString(
            strtoupper(SalePayment::SPLIT_TRACE_NOTE_PREFIX . ' #' . $secondPayment->id),
            strtoupper((string) $replacementPayment->note)
        );
        $this->assertSame(560.0, (float) SalePayment::query()->where('sale_id', $this->sale->id)->active()->sum('amount'));
    }

    protected function createPayment(array $overrides = []): SalePayment
    {
        return SalePayment::query()->create(array_merge([
            'sale_id' => $this->sale->id,
            'amount' => 100,
            'date' => now()->toDateString(),
            'reference' => 'PAY-' . uniqid(),
            'payment_method' => 'Cash',
            'stage_order' => 1,
            'status' => SalePayment::STATUS_ACTIVE,
        ], $overrides));
    }
}
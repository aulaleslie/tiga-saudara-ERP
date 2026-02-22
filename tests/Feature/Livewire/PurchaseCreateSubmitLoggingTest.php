<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\CreateForm;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseCreateSubmitLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PaymentTerm $codTerm;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Logging Test Setting',
            'company_email' => 'logging@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);
        session(['setting_id' => $this->setting->id]);

        $this->codTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Cash on Delivery'],
            ['longevity' => 0]
        );

        $this->supplier = Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->codTerm->id,
        ]);

        Cart::instance('purchase')->destroy();
    }

    public function test_submit_debug_logs_are_suppressed_when_debug_flag_disabled(): void
    {
        config(['performance.purchase_submit_debug' => false]);
        Log::spy();

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'create-log-off'])
            ->call('submit')
            ->assertHasErrors(['supplier_id']);

        Log::shouldNotHaveReceived('info');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.submit.validation_failed'
                && ($context['flow'] ?? null) === 'purchase.create')
            ->once();
    }

    public function test_submit_debug_logs_are_emitted_when_debug_flag_enabled(): void
    {
        config(['performance.purchase_submit_debug' => true]);
        Log::spy();

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'create-log-on'])
            ->call('submit')
            ->assertHasErrors(['supplier_id']);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.submit.start'
                && ($context['flow'] ?? null) === 'purchase.create'
                && ($context['idempotency_token'] ?? null) === 'create-log-on')
            ->atLeast()
            ->once();
    }

    public function test_duplicate_prefill_loaded_log_is_emitted_when_debug_flag_enabled(): void
    {
        config(['performance.purchase_submit_debug' => true]);
        Log::spy();

        $purchase = $this->createSourcePurchase();

        Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'dup-log-on',
            'duplicateId' => $purchase->id,
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.duplicate.prefill.loaded'
                && ($context['flow'] ?? null) === 'purchase.create'
                && ($context['source_purchase_id'] ?? null) === $purchase->id)
            ->atLeast()
            ->once();
    }

    public function test_validation_failure_is_logged_even_when_debug_flag_disabled(): void
    {
        config(['performance.purchase_submit_debug' => false]);
        Log::spy();

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'validation-log'])
            ->call('submit')
            ->assertHasErrors(['supplier_id']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.submit.validation_failed'
                && ($context['failure_stage'] ?? null) === 'validation'
                && isset($context['validation_errors']['supplier_id'])
                && ! isset($context['validation_errors']['payment_term']))
            ->once();
    }

    public function test_empty_cart_abort_is_logged_even_when_debug_flag_disabled(): void
    {
        config(['performance.purchase_submit_debug' => false]);
        Log::spy();

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'empty-cart-log'])
            ->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->call('submit');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.submit.aborted_empty_cart'
                && ($context['flow'] ?? null) === 'purchase.create')
            ->once();
    }

    public function test_hidden_payload_invalid_warning_is_logged_for_label_like_args(): void
    {
        config(['performance.purchase_submit_debug' => false]);
        Log::spy();

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'hidden-invalid-log'])
            ->call('submit', 'PT Supplier Lama', 'Cash on Delivery')
            ->assertHasErrors(['supplier_id', 'payment_term']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.submit.hidden_payload_invalid'
                && ($context['hidden_supplier_arg'] ?? null) === 'PT Supplier Lama'
                && ($context['hidden_payment_term_arg'] ?? null) === 'Cash on Delivery')
            ->once();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.submit.validation_failed'
                && ($context['hidden_supplier_arg'] ?? null) === 'PT Supplier Lama')
            ->once();
    }

    private function createSourcePurchase(): Purchase
    {
        return Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $this->supplier->id,
            'supplier_purchase_number' => null,
            'tax_ref_no' => null,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'payment_term_id' => $this->codTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'paid_amount' => 0,
            'is_tax_included' => false,
        ]);
    }
}

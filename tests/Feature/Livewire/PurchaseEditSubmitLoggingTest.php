<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\EditForm;
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

class PurchaseEditSubmitLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PaymentTerm $codTerm;
    protected Supplier $supplier;
    protected Purchase $purchase;

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
            'company_name' => 'Edit Logging Setting',
            'company_email' => 'edit-logging@example.com',
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

        $this->purchase = Purchase::create([
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

        Cart::instance('purchase')->destroy();
    }

    public function test_submit_debug_logs_are_suppressed_when_debug_flag_disabled(): void
    {
        config(['performance.purchase_submit_debug' => false]);
        Log::spy();

        Livewire::test(EditForm::class, ['purchaseId' => $this->purchase->id])
            ->call('submit');

        Log::shouldNotHaveReceived('info');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.submit.aborted_empty_cart'
                && ($context['flow'] ?? null) === 'purchase.edit')
            ->once();
    }

    public function test_submit_debug_logs_are_emitted_when_debug_flag_enabled(): void
    {
        config(['performance.purchase_submit_debug' => true]);
        Log::spy();

        Livewire::test(EditForm::class, ['purchaseId' => $this->purchase->id])
            ->call('submit');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.submit.start'
                && ($context['flow'] ?? null) === 'purchase.edit'
                && ($context['purchase_id'] ?? null) === $this->purchase->id)
            ->atLeast()
            ->once();
    }

    public function test_validation_failure_is_logged_even_when_debug_flag_disabled(): void
    {
        config(['performance.purchase_submit_debug' => false]);
        Log::spy();

        Livewire::test(EditForm::class, ['purchaseId' => $this->purchase->id])
            ->set('supplier_id', null)
            ->call('submit')
            ->assertHasErrors(['supplier_id']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.submit.validation_failed'
                && ($context['flow'] ?? null) === 'purchase.edit'
                && ($context['failure_stage'] ?? null) === 'validation'
                && isset($context['validation_errors']['supplier_id']))
            ->once();
    }

    public function test_empty_cart_abort_is_logged_even_when_debug_flag_disabled(): void
    {
        config(['performance.purchase_submit_debug' => false]);
        Log::spy();

        Livewire::test(EditForm::class, ['purchaseId' => $this->purchase->id])
            ->call('submit');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $context) => $event === 'purchase.submit.aborted_empty_cart'
                && ($context['purchase_id'] ?? null) === $this->purchase->id)
            ->once();
    }
}

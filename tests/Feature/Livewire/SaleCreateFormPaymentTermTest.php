<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\CreateForm;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleCreateFormPaymentTermTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $codTerm;
    protected $net30Term;
    protected $customTerm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codTerm = PaymentTerm::where('name', 'Cash on Delivery')->first();
        $this->net30Term = PaymentTerm::where('name', 'Net 30')->first();
        $this->customTerm = PaymentTerm::where('name', 'Custom')->first();

        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
        session(['setting_id' => $this->setting->id]);
    }

    public function test_customer_selection_auto_sets_payment_term_and_due_date()
    {
        $customer = Customer::factory()->create([
            'payment_term_id' => $this->net30Term->id,
            'setting_id' => $this->setting->id,
        ]);

        $today = now()->format('Y-m-d');
        $expectedDueDate = now()->addDays(30)->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('date', $today)
            ->set('customerId', $customer->id)
            ->assertSet('paymentTermId', $this->net30Term->id)
            ->assertSet('dueDate', $expectedDueDate)
            ->assertSet('dueDateIsManual', false);
    }

    public function test_manual_due_date_edit_switches_to_custom_and_sets_manual_flag()
    {
        $tomorrow = now()->addDay()->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('dueDate', $tomorrow)
            ->assertSet('paymentTermId', $this->customTerm->id)
            ->assertSet('dueDateIsManual', true)
            ->assertSet('dueDate', $tomorrow);
    }

    public function test_payment_term_changed_event_recalculates_due_date_for_non_custom_term()
    {
        $today = now()->format('Y-m-d');
        $expectedDueDate = now()->addDays(30)->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('date', $today)
            ->dispatch('payment-term-changed', $this->net30Term->id)
            ->assertSet('paymentTermId', $this->net30Term->id)
            ->assertSet('dueDateIsManual', false)
            ->assertSet('dueDate', $expectedDueDate);
    }

    public function test_payment_term_changed_event_keeps_due_date_when_custom_selected()
    {
        $today = now()->format('Y-m-d');
        $currentDueDate = now()->addDays(30)->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('date', $today)
            ->set('paymentTermId', $this->net30Term->id)
            ->assertSet('dueDate', $currentDueDate)
            ->dispatch('payment-term-changed', $this->customTerm->id)
            ->assertSet('paymentTermId', $this->customTerm->id)
            ->assertSet('dueDateIsManual', true)
            ->assertSet('dueDate', $currentDueDate);
    }

    public function test_manual_due_date_then_payment_term_changed_event_to_non_custom_recalculates()
    {
        $fixedDueDate = now()->addDays(5)->format('Y-m-d');
        $expectedDueDate = now()->addDays(30)->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('dueDate', $fixedDueDate)
            ->assertSet('paymentTermId', $this->customTerm->id)
            ->assertSet('dueDateIsManual', true)
            ->dispatch('payment-term-changed', $this->net30Term->id)
            ->assertSet('paymentTermId', $this->net30Term->id)
            ->assertSet('dueDateIsManual', false)
            ->assertSet('dueDate', $expectedDueDate);
    }
}

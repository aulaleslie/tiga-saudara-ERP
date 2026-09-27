<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\EditForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleEditFormPaymentTermTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $customer;
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

        $this->customer = Customer::factory()->create([
            'payment_term_id' => $this->codTerm->id,
            'setting_id' => $this->setting->id,
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    public function test_non_custom_payment_term_change_recalculates_due_date(): void
    {
        $sale = $this->createSale($this->codTerm->id, $this->customer->id);
        $today = now()->format('Y-m-d');
        $expectedDueDate = now()->addDays(30)->format('Y-m-d');

        Livewire::test(EditForm::class, ['sale' => $sale])
            ->set('date', $today)
            ->dispatch('payment-term-changed', $this->net30Term->id)
            ->assertSet('paymentTermId', $this->net30Term->id)
            ->assertSet('dueDateIsManual', false)
            ->assertSet('dueDate', $expectedDueDate);
    }

    public function test_manual_due_date_edit_switches_to_custom_and_preserves_due_date(): void
    {
        $sale = $this->createSale($this->codTerm->id, $this->customer->id);
        $manualDueDate = now()->addDays(9)->format('Y-m-d');

        Livewire::test(EditForm::class, ['sale' => $sale])
            ->set('dueDate', $manualDueDate)
            ->assertSet('paymentTermId', $this->customTerm->id)
            ->assertSet('dueDateIsManual', true)
            ->assertSet('dueDate', $manualDueDate);
    }

    public function test_switching_from_custom_manual_to_non_custom_recalculates_due_date(): void
    {
        $sale = $this->createSale($this->codTerm->id, $this->customer->id);
        $manualDueDate = now()->addDays(5)->format('Y-m-d');
        $expectedDueDate = now()->addDays(30)->format('Y-m-d');

        Livewire::test(EditForm::class, ['sale' => $sale])
            ->set('dueDate', $manualDueDate)
            ->assertSet('paymentTermId', $this->customTerm->id)
            ->assertSet('dueDateIsManual', true)
            ->dispatch('payment-term-changed', $this->net30Term->id)
            ->assertSet('paymentTermId', $this->net30Term->id)
            ->assertSet('dueDateIsManual', false)
            ->assertSet('dueDate', $expectedDueDate);
    }

    public function test_customer_selection_with_custom_term_preserves_current_due_date(): void
    {
        $customCustomer = Customer::factory()->create([
            'payment_term_id' => $this->customTerm->id,
            'setting_id' => $this->setting->id,
        ]);
        $sale = $this->createSale($this->codTerm->id, $this->customer->id);
        $currentDueDate = now()->addDays(30)->format('Y-m-d');

        Livewire::test(EditForm::class, ['sale' => $sale])
            ->set('paymentTermId', $this->net30Term->id)
            ->assertSet('dueDate', $currentDueDate)
            ->dispatch('customerSelected', $customCustomer->id)
            ->assertSet('customerId', $customCustomer->id)
            ->assertSet('paymentTermId', $this->customTerm->id)
            ->assertSet('dueDateIsManual', true)
            ->assertSet('dueDate', $currentDueDate);
    }

    private function createSale(?int $paymentTermId, int $customerId): Sale
    {
        $customer = Customer::findOrFail($customerId);

        return Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $customerId,
            'customer_name' => $customer->customer_name,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'payment_term_id' => $paymentTermId,
        ]);
    }
}

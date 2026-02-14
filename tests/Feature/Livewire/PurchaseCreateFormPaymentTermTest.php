<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\CreateForm;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseCreateFormPaymentTermTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $codTerm;
    protected $net30Term;
    protected $customTerm;

    protected function setUp(): void
    {
        parent::setUp();

        // Data is seeded by migrations (2024_11_27_154759_add_payment_terms_table.php)
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

    public function test_supplier_selection_auto_sets_payment_term_and_due_date()
    {
        $supplier = Supplier::factory()->create([
            'payment_term_id' => $this->net30Term->id,
            'setting_id' => $this->setting->id,
        ]);

        $today = now()->format('Y-m-d');
        $expectedDueDate = now()->addDays(30)->format('Y-m-d');

        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('date', $today)
            ->set('supplier_id', $supplier->id)
            ->assertSet('payment_term', $this->net30Term->id)
            ->assertSet('due_date', $expectedDueDate)
            ->assertSet('dueDateIsManual', false);

        $this->assertGreaterThanOrEqual(1, (int) $component->get('dueDateRenderVersion'));
        $this->assertDueDateInputValue($component->html(), $expectedDueDate);
        $this->assertHiddenInputValue($component->html(), 'purchase_supplier_id', (string) $supplier->id);
        $this->assertHiddenInputValue($component->html(), 'purchase_payment_term', (string) $this->net30Term->id);
    }

    public function test_supplier_without_term_sets_default_cod()
    {
        $supplier = Supplier::factory()->create([
            'payment_term_id' => null,
            'setting_id' => $this->setting->id,
        ]);

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('supplier_id', $supplier->id)
            ->assertSet('payment_term', $this->codTerm->id)
            ->assertSet('dueDateIsManual', false);
    }

    public function test_manual_due_date_edit_switches_to_custom_and_sets_manual_flag()
    {
        $tomorrow = now()->addDay()->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('due_date', $tomorrow)
            ->assertSet('payment_term', $this->customTerm->id)
            ->assertSet('dueDateIsManual', true)
            ->assertSet('due_date', $tomorrow);
    }

    public function test_invoice_date_change_recalculates_due_date_when_not_manual()
    {
        $tomorrow = now()->addDay()->format('Y-m-d');
        $expectedDueDate = now()->addDays(31)->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('payment_term', $this->net30Term->id)
            ->set('date', $tomorrow)
            ->assertSet('due_date', $expectedDueDate)
            ->assertSet('dueDateIsManual', false);
    }

    public function test_invoice_date_change_does_not_recalculate_due_date_when_manual()
    {
        $today = now()->format('Y-m-d');
        $fixedDueDate = now()->addDays(5)->format('Y-m-d');
        $tomorrow = now()->addDay()->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('date', $today)
            ->set('due_date', $fixedDueDate)
            ->assertSet('dueDateIsManual', true)
            ->set('date', $tomorrow)
            ->assertSet('due_date', $fixedDueDate); // Stays at manual value
    }

    public function test_selecting_payment_term_after_manual_edit_resets_manual_flag()
    {
        $fixedDueDate = now()->addDays(5)->format('Y-m-d');
        $expectedDueDate = now()->addDays(30)->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('due_date', $fixedDueDate)
            ->assertSet('dueDateIsManual', true)
            ->set('payment_term', $this->net30Term->id)
            ->assertSet('dueDateIsManual', false)
            ->assertSet('due_date', $expectedDueDate);
    }

    public function test_payment_term_changed_event_recalculates_due_date_for_non_custom_term()
    {
        $today = now()->format('Y-m-d');
        $expectedDueDate = now()->addDays(30)->format('Y-m-d');

        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('date', $today)
            ->dispatch('payment-term-changed', $this->net30Term->id)
            ->assertSet('payment_term', $this->net30Term->id)
            ->assertSet('dueDateIsManual', false)
            ->assertSet('due_date', $expectedDueDate);

        $this->assertGreaterThanOrEqual(1, (int) $component->get('dueDateRenderVersion'));
        $this->assertDueDateInputValue($component->html(), $expectedDueDate);
        $this->assertHiddenInputValue($component->html(), 'purchase_payment_term', (string) $this->net30Term->id);
    }

    public function test_payment_term_changed_event_keeps_due_date_when_custom_selected()
    {
        $today = now()->format('Y-m-d');
        $currentDueDate = now()->addDays(30)->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('date', $today)
            ->set('payment_term', $this->net30Term->id)
            ->assertSet('due_date', $currentDueDate)
            ->dispatch('payment-term-changed', $this->customTerm->id)
            ->assertSet('payment_term', $this->customTerm->id)
            ->assertSet('dueDateIsManual', true)
            ->assertSet('due_date', $currentDueDate);
    }

    public function test_manual_due_date_then_payment_term_changed_event_to_non_custom_recalculates()
    {
        $fixedDueDate = now()->addDays(5)->format('Y-m-d');
        $expectedDueDate = now()->addDays(30)->format('Y-m-d');

        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('due_date', $fixedDueDate)
            ->assertSet('payment_term', $this->customTerm->id)
            ->assertSet('dueDateIsManual', true)
            ->dispatch('payment-term-changed', $this->net30Term->id)
            ->assertSet('payment_term', $this->net30Term->id)
            ->assertSet('dueDateIsManual', false)
            ->assertSet('due_date', $expectedDueDate);

        $this->assertGreaterThanOrEqual(1, (int) $component->get('dueDateRenderVersion'));
        $this->assertDueDateInputValue($component->html(), $expectedDueDate);
        $this->assertHiddenInputValue($component->html(), 'purchase_payment_term', (string) $this->net30Term->id);
    }

    public function test_sidu_supplier_sets_60_days_due_date()
    {
        // PT SIDU TJAHAJA ASIA (ID: 2), Payment Term ID: 858234, Longevity: 60
        // Use firstOrCreate to avoid unique constraint if it's already there (though refreshDatabase should clean it)
        $net60 = PaymentTerm::firstOrCreate(
            ['id' => 858234],
            ['name' => 'Net 60', 'longevity' => 60]
        );

        $supplier = Supplier::factory()->create([
            'id' => 2,
            'supplier_name' => 'PT SIDU TJAHAJA ASIA',
            'payment_term_id' => $net60->id,
            'setting_id' => $this->setting->id,
        ]);

        $today = now()->format('Y-m-d');
        $expectedDueDate = now()->addDays(60)->format('Y-m-d');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token'])
            ->set('date', $today)
            ->set('supplier_id', $supplier->id)
            ->assertSet('payment_term', $net60->id)
            ->assertSet('due_date', $expectedDueDate);
    }

    private function assertDueDateInputValue(string $html, string $expectedValue): void
    {
        $pattern = '/id="due_date"[^>]*value="' . preg_quote($expectedValue, '/') . '"/';
        $this->assertMatchesRegularExpression($pattern, $html);
    }

    private function assertHiddenInputValue(string $html, string $id, string $expectedValue): void
    {
        $pattern = '/id="' . preg_quote($id, '/') . '"[^>]*value="' . preg_quote($expectedValue, '/') . '"/';
        $this->assertMatchesRegularExpression($pattern, $html);
    }
}

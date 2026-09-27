<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\CreateForm;
use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseCreateHotPathLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PaymentTerm $net30Term;

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

        $this->net30Term = PaymentTerm::query()->where('name', 'Net 30')->firstOrFail();
    }

    public function test_create_form_hot_path_logs_are_suppressed_when_flag_disabled(): void
    {
        config(['performance.livewire_hotpath_debug' => false]);
        Log::spy();

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'perf-token-disabled'])
            ->set('payment_term', $this->net30Term->id);

        Log::shouldNotHaveReceived('info', [
            'DEBUG: updatedPaymentTerm called',
            ['value' => $this->net30Term->id],
        ]);
    }

    public function test_create_form_hot_path_logs_are_emitted_when_flag_enabled(): void
    {
        config(['performance.livewire_hotpath_debug' => true]);
        Log::spy();

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'perf-token-enabled'])
            ->set('payment_term', $this->net30Term->id);

        Log::shouldHaveReceived('info')
            ->with('DEBUG: updatedPaymentTerm called', ['value' => $this->net30Term->id])
            ->atLeast()
            ->once();
    }

    public function test_product_cart_hot_path_logs_are_suppressed_when_flag_disabled(): void
    {
        config(['performance.livewire_hotpath_debug' => false]);
        Log::spy();

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);

        Log::shouldNotHaveReceived('info', [
            'validated',
            ['data' => null],
        ]);
    }

    public function test_product_cart_hot_path_logs_are_emitted_when_flag_enabled(): void
    {
        config(['performance.livewire_hotpath_debug' => true]);
        Log::spy();

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);

        Log::shouldHaveReceived('info')
            ->with('validated', ['data' => null])
            ->atLeast()
            ->once();
    }
}


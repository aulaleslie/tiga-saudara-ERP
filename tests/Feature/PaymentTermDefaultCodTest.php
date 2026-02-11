<?php

namespace Tests\Feature;

use App\Livewire\Purchase\CreateForm as PurchaseCreateForm;
use App\Livewire\Sale\CreateForm as SaleCreateForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PaymentTermDefaultCodTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_create_form_defaults_to_cod_and_fallbacks_when_supplier_has_no_term(): void
    {
        [$setting, $user] = $this->bootContext();
        $defaultCodId = PaymentTerm::defaultCodTermId();
        $net30 = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);

        $supplierWithoutTerm = Supplier::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => null,
        ]);

        $supplierWithTerm = Supplier::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => $net30->id,
        ]);

        $component = Livewire::test(PurchaseCreateForm::class, [
            'idempotencyToken' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $component->assertSet('payment_term', $defaultCodId);

        $component->set('supplier_id', $supplierWithoutTerm->id)
            ->assertSet('payment_term', $defaultCodId);

        $component->set('supplier_id', $supplierWithTerm->id)
            ->assertSet('payment_term', $net30->id);
    }

    public function test_sale_create_form_defaults_to_cod_and_fallbacks_when_customer_has_no_term(): void
    {
        [$setting, $user] = $this->bootContext();
        $defaultCodId = PaymentTerm::defaultCodTermId();
        $net14 = PaymentTerm::create(['name' => 'Net 14', 'longevity' => 14]);

        $customerWithoutTerm = Customer::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => null,
        ]);

        $customerWithTerm = Customer::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => $net14->id,
        ]);

        $component = Livewire::test(SaleCreateForm::class, [
            'idempotencyToken' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $component->assertSet('paymentTermId', $defaultCodId);

        $component->set('customerId', $customerWithoutTerm->id)
            ->assertSet('paymentTermId', $defaultCodId);

        $component->set('customerId', $customerWithTerm->id)
            ->assertSet('paymentTermId', $net14->id);
    }

    private function bootContext(): array
    {
        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
            'is_pkp' => false,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);
        session(['setting_id' => $setting->id]);

        return [$setting, $user];
    }
}

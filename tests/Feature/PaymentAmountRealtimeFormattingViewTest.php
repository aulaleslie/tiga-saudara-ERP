<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Focused coverage for the add-realtime-financial-input-formatting change (task 3.1): the
 * single Sales and Purchase payment amount fields keep the [data-payment-amount] marker and
 * load the shared real-time formatter (financial-input.js) ahead of the compatibility alias
 * (payment-amount-input.js), so the field displays Indonesian-grouped text continuously
 * while being edited instead of only on blur.
 */
class PaymentAmountRealtimeFormattingViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->withoutMiddleware([CheckUserRoleForSetting::class]);
    }

    public function test_single_sale_payment_form_marks_amount_field_and_loads_shared_formatter(): void
    {
        $setting = Setting::factory()->create();
        $customer = Customer::factory()->create();
        $user = User::factory()->create();

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => '',
            'note' => null,
            'payment_term_id' => null,
            'tax_id' => null,
            'setting_id' => $setting->id,
            'reference' => 'SALE-REALTIME-1',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('sale-payments.create', $sale->id));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-payment-amount', $html);
        $this->assertStringContainsString('id="amount"', $html);

        // financial-input.js (real-time formatter) must load before the compatibility alias.
        $financialInputPos = strpos($html, "js/financial-input.js");
        $paymentAliasPos = strpos($html, "js/payment-amount-input.js");
        $this->assertNotFalse($financialInputPos, 'financial-input.js should be included (globally via main-js.blade.php)');
        $this->assertNotFalse($paymentAliasPos, 'payment-amount-input.js compatibility alias should still be included');
        $this->assertLessThan($paymentAliasPos, $financialInputPos, 'financial-input.js must load before payment-amount-input.js');
    }

    public function test_single_purchase_payment_form_marks_amount_field_and_loads_shared_formatter(): void
    {
        $setting = Setting::factory()->create();
        $supplier = Supplier::factory()->create(['setting_id' => $setting->id]);
        $user = User::factory()->create();

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'supplier_id' => $supplier->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => '',
            'reference' => 'PURCH-REALTIME-1',
            'setting_id' => $setting->id,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('purchase-payments.create', $purchase->id));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-payment-amount', $html);
        $this->assertStringContainsString('id="amount"', $html);

        $financialInputPos = strpos($html, "js/financial-input.js");
        $paymentAliasPos = strpos($html, "js/payment-amount-input.js");
        $this->assertNotFalse($financialInputPos);
        $this->assertNotFalse($paymentAliasPos);
        $this->assertLessThan($paymentAliasPos, $financialInputPos);
    }

    /**
     * Focused coverage for add-realtime-financial-input-formatting task 3.3: the DataTables-
     * backed global Purchase allocation table marks its per-row allocation inputs with
     * [data-payment-amount] and loads the shared real-time formatter, so canonical hidden
     * values stay in sync via PaymentAmountInput across visible and (once paginated) detached
     * rows rather than parsing localized text directly.
     */
    public function test_global_purchase_payment_form_marks_allocation_inputs_and_loads_shared_formatter(): void
    {
        $setting = Setting::factory()->create();
        $supplier = Supplier::factory()->create(['setting_id' => $setting->id]);
        $user = User::factory()->create();

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'supplier_id' => $supplier->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => '',
            'reference' => 'PURCH-GLOBAL-REALTIME-1',
            'setting_id' => $setting->id,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('purchases.global-payments.create', ['supplier' => $supplier->id, 'purchase_id' => $purchase->id]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-payment-amount', $html);
        $this->assertStringContainsString('allocation-input', $html);
        $this->assertStringContainsString('name="allocations[' . $purchase->id . ']"', $html);

        $financialInputPos = strpos($html, 'js/financial-input.js');
        $paymentAliasPos = strpos($html, 'js/payment-amount-input.js');
        $this->assertNotFalse($financialInputPos);
        $this->assertNotFalse($paymentAliasPos);
        $this->assertLessThan($paymentAliasPos, $financialInputPos);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use Carbon\Carbon;
use Modules\People\Entities\Customer;
use Modules\Sale\Http\Requests\StoreSaleRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Session;

class SaleReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'test@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
            'document_prefix'           => 'ABC',
            'sale_prefix_document'      => 'SL',
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'customer_phone' => '123456',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);
    }

    public function test_sale_respects_manual_reference(): void
    {
        $manualRef = 'MANUAL-REF-001';
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now(),
            'reference' => $manualRef,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals($manualRef, $sale->reference);
    }

    public function test_sale_auto_generates_reference_if_missing(): void
    {
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => Carbon::parse('2026-01-31'),
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

    // Expected pattern: ABC-SL-2026-01-000001 (based on make_reference_id logic)
        $this->assertStringStartsWith('ABC-SL-2026-01-', $sale->reference);
    }

    public function test_store_sale_request_validation_allows_nullable_reference(): void
    {
        Session::put('setting_id', $this->setting->id);

        $rules = (new StoreSaleRequest())->rules();
        $payload = [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'shipping_amount' => 0,
            'total_amount' => 100,
            'payment_term_id' => 1, // assume exists or adjust
            // 'reference' is missing
        ];

        // Ensure payment term exists
        \Modules\Purchase\Entities\PaymentTerm::create([
            'id' => 1,
            'longevity' => 0,
            'name' => 'Cash',
        ]);

        $validator = Validator::make($payload, $rules);
        $this->assertFalse($validator->errors()->has('reference'));
    }

    public function test_store_sale_request_validation_enforces_uniqueness(): void
    {
        Session::put('setting_id', $this->setting->id);

        $manualRef = 'DUPLICATE-REF';
        Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now(),
            'reference' => $manualRef,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $rules = (new StoreSaleRequest())->rules();
        $payload = [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'shipping_amount' => 0,
            'total_amount' => 100,
            'payment_term_id' => 1,
            'reference' => $manualRef,
        ];

        $validator = Validator::make($payload, $rules);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('reference'));
    }
}

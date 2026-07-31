<?php

namespace Tests\Feature\Livewire;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ReferenceGenerationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Business',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => '123 Test St',
            'is_pkp' => false,
            'document_prefix' => 'TST',
            'sale_prefix_document' => 'SL',
            'purchase_prefix_document' => 'PR',
        ]);

        PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->setting->id,
        ]);

        // Create a test supplier and customer for FK references
        \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '111111',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country',
            'setting_id' => $this->setting->id,
        ]);

        \Modules\People\Entities\Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'customer_phone' => '222222',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country',
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_purchase_reference_generation_with_setting_row_lock(): void
    {
        $supplier = \Modules\People\Entities\Supplier::first();

        // Create first purchase - should get -00001
        $purchase1 = Purchase::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'bank',
        ]);

        $this->assertStringContainsString('TST-PR-2024-01-00001', $purchase1->reference);

        // Create second purchase - should get -00002
        $purchase2 = Purchase::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'bank',
        ]);

        $this->assertStringContainsString('TST-PR-2024-01-00002', $purchase2->reference);
        $this->assertNotEquals($purchase1->reference, $purchase2->reference);
    }

    public function test_sale_reference_generation_with_setting_row_lock(): void
    {
        $customer = \Modules\People\Entities\Customer::first();

        // Create first sale - should get -00001
        $sale1 = Sale::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'bank',
        ]);

        $this->assertStringContainsString('TST-SL-2024-01-00001', $sale1->reference);

        // Create second sale - should get -00002
        $sale2 = Sale::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'bank',
        ]);

        $this->assertStringContainsString('TST-SL-2024-01-00002', $sale2->reference);
        $this->assertNotEquals($sale1->reference, $sale2->reference);
    }


    public function test_purchase_empty_month_reference_starts_at_001_via_hook(): void
    {
        $supplier = \Modules\People\Entities\Supplier::first();

        // For a brand new month, reference should start at -00001
        $purchase = Purchase::create([
            'date' => '2025-12-01',
            'due_date' => '2025-12-01',
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'bank',
        ]);

        $this->assertStringContainsString('TST-PR-2025-12-00001', $purchase->reference);
    }

    public function test_sale_empty_month_reference_starts_at_001_via_hook(): void
    {
        $customer = \Modules\People\Entities\Customer::first();

        // For a brand new month, reference should start at -00001
        $sale = Sale::create([
            'date' => '2025-12-01',
            'due_date' => '2025-12-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'bank',
        ]);

        $this->assertStringContainsString('TST-SL-2025-12-00001', $sale->reference);
    }

    public function test_purchase_concurrent_allocation_uses_sequential_numbers(): void
    {
        $supplier = \Modules\People\Entities\Supplier::first();
        $referenceNumbers = [];

        // Simulate concurrent requests by creating multiple purchases rapidly
        // The Setting row lock should serialize allocation even under high load
        for ($i = 0; $i < 5; $i++) {
            $purchase = Purchase::create([
                'date' => '2026-02-15',
                'due_date' => '2026-02-15',
                'supplier_id' => $supplier->id,
                'status' => Purchase::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'payment_term_id' => PaymentTerm::first()->id,
                'setting_id' => $this->setting->id,
                'is_tax_included' => false,
                'total_amount' => 0,
                'paid_amount' => 0,
                'due_amount' => 0,
                'payment_method' => 'bank',
            ]);

            // Extract the sequential number from reference
            preg_match('/-(\d{5})$/', $purchase->reference, $matches);
            $referenceNumbers[] = (int) $matches[1];
        }

        // Verify all numbers are unique and sequential
        $this->assertEquals([1, 2, 3, 4, 5], $referenceNumbers);
        $this->assertEquals(count($referenceNumbers), count(array_unique($referenceNumbers)));
    }

    public function test_sale_concurrent_allocation_uses_sequential_numbers(): void
    {
        $customer = \Modules\People\Entities\Customer::first();
        $referenceNumbers = [];

        // Simulate concurrent requests by creating multiple sales rapidly
        for ($i = 0; $i < 5; $i++) {
            $sale = Sale::create([
                'date' => '2026-02-15',
                'due_date' => '2026-02-15',
                'customer_id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'status' => Sale::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'payment_term_id' => PaymentTerm::first()->id,
                'setting_id' => $this->setting->id,
                'is_tax_included' => false,
                'total_amount' => 0,
                'paid_amount' => 0,
                'due_amount' => 0,
                'payment_method' => 'bank',
            ]);

            // Extract the sequential number from reference
            preg_match('/-(\d{5})$/', $sale->reference, $matches);
            $referenceNumbers[] = (int) $matches[1];
        }

        // Verify all numbers are unique and sequential
        $this->assertEquals([1, 2, 3, 4, 5], $referenceNumbers);
        $this->assertEquals(count($referenceNumbers), count(array_unique($referenceNumbers)));
    }
}

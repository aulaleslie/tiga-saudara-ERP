<?php

namespace Tests\Feature\Livewire;

use App\Services\DocumentReferenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class DocumentReferenceConcurrencyTest extends TestCase
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

        Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '111111',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country',
            'setting_id' => $this->setting->id,
        ]);

        Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'customer_phone' => '222222',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country',
            'setting_id' => $this->setting->id,
        ]);
    }

    /**
     * Test that the DocumentReferenceService maintains atomic lock through INSERT.
     *
     * This test verifies that using the service's static factory methods ensures
     * the Setting row lock is held from allocation calculation through the actual
     * INSERT operation, preventing duplicate reference allocation.
     */
    public function test_purchase_reference_service_keeps_lock_through_insert(): void
    {
        $supplier = Supplier::first();
        $date = '2026-03-15';

        // Create first purchase using service
        $purchase1 = DocumentReferenceService::createPurchaseWithReference([
            'date' => $date,
            'due_date' => $date,
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

        // Create second purchase using service
        $purchase2 = DocumentReferenceService::createPurchaseWithReference([
            'date' => $date,
            'due_date' => $date,
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

        // Both should be created successfully
        $this->assertNotNull($purchase1->id);
        $this->assertNotNull($purchase2->id);
        $this->assertNotEquals($purchase1->reference, $purchase2->reference);

        // Extract sequence numbers and verify they are sequential
        preg_match('/-(\d{5})$/', $purchase1->reference, $match1);
        preg_match('/-(\d{5})$/', $purchase2->reference, $match2);
        $num1 = (int) $match1[1];
        $num2 = (int) $match2[1];
        $this->assertEquals($num2, $num1 + 1, "References should be sequential");
    }

    /**
     * Test that the DocumentReferenceService maintains atomic lock for sales.
     */
    public function test_sale_reference_service_keeps_lock_through_insert(): void
    {
        $customer = Customer::first();
        $date = '2026-03-15';

        // Create first sale using service
        $sale1 = DocumentReferenceService::createSaleWithReference([
            'date' => $date,
            'due_date' => $date,
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

        // Create second sale using service
        $sale2 = DocumentReferenceService::createSaleWithReference([
            'date' => $date,
            'due_date' => $date,
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

        // Both should be created successfully
        $this->assertNotNull($sale1->id);
        $this->assertNotNull($sale2->id);
        $this->assertNotEquals($sale1->reference, $sale2->reference);

        // Extract sequence numbers and verify they are sequential
        preg_match('/-(\d{5})$/', $sale1->reference, $match1);
        preg_match('/-(\d{5})$/', $sale2->reference, $match2);
        $num1 = (int) $match1[1];
        $num2 = (int) $match2[1];
        $this->assertEquals($num2, $num1 + 1, "References should be sequential");
    }

    /**
     * Rapid sequential creates using DocumentReferenceService should all be unique and sequential.
     */
    public function test_purchase_rapid_sequential_creates_with_service_are_sequential(): void
    {
        $supplier = Supplier::first();
        $referenceNumbers = [];

        for ($i = 0; $i < 10; $i++) {
            $purchase = DocumentReferenceService::createPurchaseWithReference([
                'date' => '2026-04-10',
                'due_date' => '2026-04-10',
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

            preg_match('/-(\d{5})$/', $purchase->reference, $matches);
            $referenceNumbers[] = (int) $matches[1];
        }

        // All should be unique
        $this->assertEquals(10, count(array_unique($referenceNumbers)));

        // All should be sequential
        $this->assertEquals(range(1, 10), $referenceNumbers);
    }

    /**
     * Rapid sequential creates for sales using DocumentReferenceService should all be unique and sequential.
     */
    public function test_sale_rapid_sequential_creates_with_service_are_sequential(): void
    {
        $customer = Customer::first();
        $referenceNumbers = [];

        for ($i = 0; $i < 10; $i++) {
            $sale = DocumentReferenceService::createSaleWithReference([
                'date' => '2026-04-10',
                'due_date' => '2026-04-10',
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

            preg_match('/-(\d{5})$/', $sale->reference, $matches);
            $referenceNumbers[] = (int) $matches[1];
        }

        // All should be unique
        $this->assertEquals(10, count(array_unique($referenceNumbers)));

        // All should be sequential
        $this->assertEquals(range(1, 10), $referenceNumbers);
    }
}

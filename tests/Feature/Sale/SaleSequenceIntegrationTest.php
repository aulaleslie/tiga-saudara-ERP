<?php

namespace Tests\Feature\Sale;

use App\Services\DocumentReferenceService;
use App\Services\Sequence\DocumentSequence;
use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\DocumentType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleSequenceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private Customer $customerA;
    private Customer $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->settingA = Setting::create([
            'company_name' => 'Setting A',
            'company_email' => 'a@test.com',
            'company_phone' => '111',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'a@test.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr A',
            'document_prefix' => 'PD-BL',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Setting B',
            'company_email' => 'b@test.com',
            'company_phone' => '222',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'b@test.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr B',
            'document_prefix' => 'PD-JK',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
        ]);

        PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->settingA->id,
        ]);

        $this->customerA = Customer::create([
            'customer_name' => 'Customer A',
            'customer_email' => 'ca@test.com',
            'customer_phone' => '111',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->settingA->id,
        ]);

        $this->customerB = Customer::create([
            'customer_name' => 'Customer B',
            'customer_email' => 'cb@test.com',
            'customer_phone' => '222',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->settingB->id,
        ]);
    }

    public function test_normal_sale_creation_allocates_atomic_reference(): void
    {
        $sale = DocumentReferenceService::createSaleWithReference([
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->settingA->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals('PD-BL-SL-2026-08-00001', $sale->reference);

        // Sequence row updated
        $seq = DocumentSequence::where('document_type', 'sale')
            ->where('setting_id', $this->settingA->id)
            ->first();
        $this->assertNotNull($seq);
        $this->assertEquals(1, $seq->last_number);
    }

    public function test_cross_business_draft_move_reallocates_in_target_namespace(): void
    {
        $sale = DocumentReferenceService::createSaleWithReference([
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->settingA->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals('PD-BL-SL-2026-08-00001', $sale->reference);

        // Move to Setting B
        DocumentReferenceService::moveSaleToSetting(
            $sale,
            $this->settingB->id,
            Carbon::parse('2026-08-15')
        );

        $sale->refresh();
        $this->assertEquals($this->settingB->id, $sale->setting_id);
        $this->assertEquals('PD-JK-SL-2026-08-00001', $sale->reference);

        // Source sequence counter remains at 1 and is not decremented
        $seqA = DocumentSequence::where('document_type', 'sale')->where('setting_id', $this->settingA->id)->first();
        $this->assertEquals(1, $seqA->last_number);

        // Target sequence counter is at 1
        $seqB = DocumentSequence::where('document_type', 'sale')->where('setting_id', $this->settingB->id)->first();
        $this->assertEquals(1, $seqB->last_number);
    }

    public function test_non_draft_business_move_is_blocked(): void
    {
        $sale = DocumentReferenceService::createSaleWithReference([
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'status' => Sale::STATUS_DISPATCHED, // Not draft
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->settingA->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        DocumentReferenceService::moveSaleToSetting(
            $sale,
            $this->settingB->id,
            Carbon::parse('2026-08-15')
        );
    }

    public function test_sale_date_edit_alone_does_not_regenerate_reference(): void
    {
        $sale = DocumentReferenceService::createSaleWithReference([
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->settingA->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);

        $originalRef = $sale->reference;
        $this->assertEquals('PD-BL-SL-2026-08-00001', $originalRef);

        // Edit date to next month
        $sale->update([
            'date' => '2026-09-01',
        ]);

        $sale->refresh();
        $this->assertEquals($originalRef, $sale->reference);
    }

    public function test_raw_sale_create_delegates_to_shared_allocator(): void
    {
        $sale = Sale::create([
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->settingA->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals('PD-BL-SL-2026-08-00001', $sale->reference);
    }
}

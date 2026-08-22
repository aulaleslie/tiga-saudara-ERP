<?php

namespace Tests\Feature\Purchase;

use App\Services\DocumentReferenceService;
use App\Services\Sequence\DocumentSequence;
use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\DocumentType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseSequenceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private Supplier $supplierA;
    private Supplier $supplierB;

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

        $this->supplierA = Supplier::create([
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'sa@test.com',
            'supplier_phone' => '111',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->settingA->id,
        ]);

        $this->supplierB = Supplier::create([
            'supplier_name' => 'Supplier B',
            'supplier_email' => 'sb@test.com',
            'supplier_phone' => '222',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->settingB->id,
        ]);
    }

    public function test_normal_purchase_creation_allocates_atomic_reference(): void
    {
        $purchase = DocumentReferenceService::createPurchaseWithReference([
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'supplier_id' => $this->supplierA->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->settingA->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals('PD-BL-PR-2026-08-00001', $purchase->reference);

        // Sequence row updated
        $seq = DocumentSequence::where('document_type', 'purchase')
            ->where('setting_id', $this->settingA->id)
            ->first();
        $this->assertNotNull($seq);
        $this->assertEquals(1, $seq->last_number);
    }

    public function test_cross_business_draft_move_reallocates_in_target_namespace(): void
    {
        $purchase = DocumentReferenceService::createPurchaseWithReference([
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'supplier_id' => $this->supplierA->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->settingA->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals('PD-BL-PR-2026-08-00001', $purchase->reference);

        // Move to Setting B
        DocumentReferenceService::movePurchaseToSetting(
            $purchase,
            $this->settingB->id,
            Carbon::parse('2026-08-15')
        );

        $purchase->refresh();
        $this->assertEquals($this->settingB->id, $purchase->setting_id);
        $this->assertEquals('PD-JK-PR-2026-08-00001', $purchase->reference);

        // Source sequence counter remains at 1 and is not decremented
        $seqA = DocumentSequence::where('document_type', 'purchase')->where('setting_id', $this->settingA->id)->first();
        $this->assertEquals(1, $seqA->last_number);

        // Target sequence counter is at 1
        $seqB = DocumentSequence::where('document_type', 'purchase')->where('setting_id', $this->settingB->id)->first();
        $this->assertEquals(1, $seqB->last_number);
    }

    public function test_non_draft_business_move_is_blocked(): void
    {
        $purchase = DocumentReferenceService::createPurchaseWithReference([
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'supplier_id' => $this->supplierA->id,
            'status' => Purchase::STATUS_RECEIVED, // Not draft
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
        DocumentReferenceService::movePurchaseToSetting(
            $purchase,
            $this->settingB->id,
            Carbon::parse('2026-08-15')
        );
    }

    public function test_purchase_date_edit_alone_does_not_regenerate_reference(): void
    {
        $purchase = DocumentReferenceService::createPurchaseWithReference([
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'supplier_id' => $this->supplierA->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->settingA->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);

        $originalRef = $purchase->reference;
        $this->assertEquals('PD-BL-PR-2026-08-00001', $originalRef);

        // Edit date to next month
        $purchase->update([
            'date' => '2026-09-01',
        ]);

        $purchase->refresh();
        $this->assertEquals($originalRef, $purchase->reference);
    }

    public function test_raw_purchase_create_delegates_to_shared_allocator(): void
    {
        $purchase = Purchase::create([
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'supplier_id' => $this->supplierA->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->settingA->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals('PD-BL-PR-2026-08-00001', $purchase->reference);
    }
}

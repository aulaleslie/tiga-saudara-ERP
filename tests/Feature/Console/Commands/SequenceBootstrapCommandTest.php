<?php

namespace Tests\Feature\Console\Commands;

use App\Services\Sequence\DocumentSequence;
use App\Services\Sequence\DocumentType;
use App\Services\Sequence\SequenceReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SequenceBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;
    private Customer $customer;

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

        $this->setting = Setting::create([
            'company_name' => 'Test Business',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => '123 Test St',
            'is_pkp' => false,
            'document_prefix' => 'PD-BL',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
        ]);

        PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->setting->id,
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier',
            'supplier_email' => 's@test.com',
            'supplier_phone' => '111',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->setting->id,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Customer',
            'customer_email' => 'c@test.com',
            'customer_phone' => '222',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_dry_run_reports_without_writing_to_database(): void
    {
        DB::table('purchases')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'PD-BL-PR-2026-08-00181',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('sequence:bootstrap', ['--family' => 'purchase', '--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('181')
            ->assertExitCode(0);

        // Assert no rows in document_sequences
        $this->assertDatabaseCount('document_sequences', 0);
    }

    public function test_bootstrap_creates_counters_from_active_and_archived_documents(): void
    {
        // Active purchase 00100
        DB::table('purchases')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'PD-BL-PR-2026-08-00100',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Archived purchase 00181 (higher number)
        DB::table('purchases')->insert([
            'date' => '2026-08-02',
            'due_date' => '2026-08-02',
            'reference' => 'PD-BL-PR-2026-08-00181',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'archived_at' => now(),
            'archived_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sale document 00050
        DB::table('sales')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'PD-BL-SL-2026-08-00050',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('sequence:bootstrap', ['--family' => 'all'])
            ->assertExitCode(0);

        // Check purchase counter is 181
        $pSeq = DocumentSequence::where('document_type', 'purchase')
            ->where('setting_id', $this->setting->id)
            ->first();
        $this->assertNotNull($pSeq);
        $this->assertEquals(181, $pSeq->last_number);

        // Check sale counter is 50
        $sSeq = DocumentSequence::where('document_type', 'sale')
            ->where('setting_id', $this->setting->id)
            ->first();
        $this->assertNotNull($sSeq);
        $this->assertEquals(50, $sSeq->last_number);
    }

    public function test_repeated_bootstrap_is_monotonic_and_idempotent(): void
    {
        DB::table('purchases')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'PD-BL-PR-2026-08-00020',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('sequence:bootstrap', ['--family' => 'purchase'])->assertExitCode(0);

        $seq = DocumentSequence::where('document_type', 'purchase')->first();
        $this->assertEquals(20, $seq->last_number);

        // Manually advance counter (simulating live allocator activity to 25)
        $seq->update(['last_number' => 25]);

        // Run bootstrap again: it should NOT decrement counter back to 20!
        $this->artisan('sequence:bootstrap', ['--family' => 'purchase'])->assertExitCode(0);

        $seq->refresh();
        $this->assertEquals(25, $seq->last_number);
    }

    public function test_bootstrap_reports_malformed_and_date_drift(): void
    {
        // Malformed reference
        DB::table('purchases')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'MALFORMED-REF',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Date drift reference (August ref, September date)
        DB::table('purchases')->insert([
            'date' => '2026-09-15',
            'due_date' => '2026-09-15',
            'reference' => 'PD-BL-PR-2026-08-00010',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('sequence:bootstrap', ['--family' => 'purchase', '--dry-run' => true])
            ->expectsOutputToContain('MALFORMED-REF')
            ->expectsOutputToContain('PD-BL-PR-2026-08-00010')
            ->assertExitCode(0);
    }
}

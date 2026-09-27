<?php

namespace Tests\Feature;

use App\Services\DocumentReferenceService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
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

class LegacyDocumentReferenceRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $setting;
    private Supplier $supplier;
    private Customer $customer;

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
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '111111',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country',
            'setting_id' => $this->setting->id,
        ]);

        $this->customer = Customer::create([
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
     * Confirms the database unique constraint on (setting_id, reference) for purchases.
     */
    public function test_purchases_table_enforces_setting_id_and_reference_uniqueness(): void
    {
        DB::table('purchases')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'PD-BL-PR-2026-08-00181',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('purchases')->insert([
            'date' => '2026-08-02',
            'due_date' => '2026-08-02',
            'reference' => 'PD-BL-PR-2026-08-00181',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Confirms the database unique constraint on (setting_id, reference) for sales.
     */
    public function test_sales_table_enforces_setting_id_and_reference_uniqueness(): void
    {
        DB::table('sales')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'PD-BL-SL-2026-08-00181',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('sales')->insert([
            'date' => '2026-08-02',
            'due_date' => '2026-08-02',
            'reference' => 'PD-BL-SL-2026-08-00181',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reproduces the incident failure:
     * When rows are inserted out of ID order (e.g. ID 1 has suffix 00181, ID 2 has suffix 00179),
     * legacy allocator checks latest('id') (ID 2 = 00179) and computes 00180 or 00181, colliding with existing ID 1.
     */
    public function test_legacy_allocator_fails_when_rows_are_out_of_id_order(): void
    {
        // Insert ID 1 with higher suffix 00181
        DB::table('purchases')->insert([
            'id' => 1,
            'date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'reference' => 'PD-BL-PR-2026-08-00181',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert ID 2 with lower suffix 00179 (out of order ID / date anomaly)
        DB::table('purchases')->insert([
            'id' => 2,
            'date' => '2026-08-16',
            'due_date' => '2026-08-16',
            'reference' => 'PD-BL-PR-2026-08-00179',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // When legacy allocator runs, latest('id') is ID 2 (suffix 00179), so it allocates 00180
        $purchase3 = DocumentReferenceService::createPurchaseWithReference([
            'date' => '2026-08-20',
            'due_date' => '2026-08-20',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);

        $this->assertEquals('PD-BL-PR-2026-08-00180', $purchase3->reference);

        // Next allocation will attempt 00181 which collides with ID 1 and throws QueryException under legacy logic!
        $this->expectException(QueryException::class);
        DocumentReferenceService::createPurchaseWithReference([
            'date' => '2026-08-21',
            'due_date' => '2026-08-21',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
        ]);
    }

    /**
     * Demonstrates embedded reference and date drift:
     * A purchase created with date in September 2026 but having an August 2026 reference.
     */
    public function test_legacy_date_scoped_query_ignores_archived_or_drifted_month_references(): void
    {
        // Reference has August 2026 (2026-08-00050) but date column was set to September 2026 (2026-09-01)
        DB::table('purchases')->insert([
            'id' => 10,
            'date' => '2026-09-01',
            'due_date' => '2026-09-01',
            'reference' => 'PD-BL-PR-2026-08-00050',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Querying for August 2026 in legacy allocator checks `whereYear('date', 2026)->whereMonth('date', 8)`
        // and completely misses this reference because date is in September!
        $latestAugustRef = Purchase::withArchived()
            ->where('setting_id', $this->setting->id)
            ->whereYear('date', 2026)
            ->whereMonth('date', 8)
            ->latest('id')
            ->value('reference');

        $this->assertNull($latestAugustRef);
    }
}

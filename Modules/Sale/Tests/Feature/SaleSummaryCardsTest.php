<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Customer;
use Modules\Sale\Livewire\SaleSummaryCards;
use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SaleSummaryCardsTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();
        
        DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting = Setting::create([
             'id' => 1,
             'company_name' => 'Test Company',
             'company_email' => 'test@company.com',
             'company_phone' => '1234567890',
             'company_address' => 'Test Address',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'notification_email' => 'notification@test.com',
             'footer_text' => 'Test Footer',
        ]);
        
        session(['setting_id' => $this->setting->id]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@customer.com',
            'customer_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_sale_summary_cards_computes_correct_counts_and_totals()
    {
        // Piutang Belum Tertagih
        Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(2),
            'reference' => 'SALE-001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        // Piutang Telat (Overdue)
        Sale::create([
            'date' => now()->subDays(5),
            'due_date' => now()->subDays(2),
            'reference' => 'SALE-002',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'setting_id' => $this->setting->id,
        ]);

        // Piutang Telat with partial payment
        Sale::create([
            'date' => now()->subDays(8),
            'due_date' => now()->subDays(1),
            'reference' => 'SALE-005',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'PARTIAL',
            'total_amount' => 5000,
            'paid_amount' => 1000,
            'due_amount' => 4000,
            'setting_id' => $this->setting->id,
        ]);

        // Terbayar Sebagian, still counted in Piutang Belum Tertagih because due remains open
        Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(5),
            'reference' => 'SALE-004',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED_PARTIALLY,
            'payment_status' => 'PARTIAL',
            'total_amount' => 4000,
            'paid_amount' => 1000,
            'due_amount' => 3000,
            'setting_id' => $this->setting->id,
        ]);

        // Penerimaan (Paid)
        $sale3 = Sale::create([
            'date' => now()->subDays(10),
            'due_date' => now()->subDays(10),
            'reference' => 'SALE-003',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'PAID',
            'total_amount' => 3000,
            'paid_amount' => 3000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        SalePayment::create([
            'sale_id' => $sale3->id,
            'amount' => 3000, 
            'date' => now()->subDays(2),
            'reference' => 'PAY-001',
            'status' => SalePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash'
        ]);

        Livewire::test(SaleSummaryCards::class)
            ->assertSet('piutangBelumTertagih.count', 4) // Open debt includes UNPAID and PARTIAL sales
            ->assertSet('piutangBelumTertagih.total', 10000)
            ->assertSet('piutangTelat.count', 2)
            ->assertSet('piutangTelat.total', 6000)
            ->assertSet('penerimaan.count', 1)
            ->assertSet('penerimaan.total', 3000); // Amount should be EXACTLY 3000 (not divided by 100)
    }

    public function test_sale_summary_cards_isolates_data_by_tenant_setting_id()
    {
        $setting2 = Setting::create([
             'id' => 2,
             'company_name' => 'Other Company',
             'company_email' => 'other@company.com',
             'company_phone' => '1234567890',
             'company_address' => 'Other Address',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'notification_email' => 'notification@other.com',
             'footer_text' => 'Other Footer',
        ]);

        $customer2 = Customer::create([
            'customer_name' => 'Other Customer',
            'customer_email' => 'other@customer.com',
            'customer_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $setting2->id,
        ]);

        // Sale for the active session tenant (setting_id = 1)
        Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(2),
            'reference' => 'SALE-MINE',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        // Sale belonging to another tenant (setting_id = 2) - MUST be excluded
        Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(2),
            'reference' => 'SALE-OTHER',
            'customer_id' => $customer2->id,
            'customer_name' => $customer2->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 9999,
            'paid_amount' => 0,
            'due_amount' => 9999,
            'setting_id' => $setting2->id,
        ]);

        Livewire::test(SaleSummaryCards::class)
            ->assertSet('piutangBelumTertagih.count', 1)
            ->assertSet('piutangBelumTertagih.total', 1000);
    }
    
    public function test_sale_summary_cards_excludes_draft_and_rejected_sales()
    {
        Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(2),
            'reference' => 'SALE-DRAFT',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DRAFTED, // Draft should be excluded
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);
        
        Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(2),
            'reference' => 'SALE-REJECTED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_REJECTED, // Rejected should be excluded
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);
        
        Livewire::test(SaleSummaryCards::class)
            ->assertSet('piutangBelumTertagih.count', 0)
            ->assertSet('piutangBelumTertagih.total', 0);
    }
    
    public function test_sale_summary_cards_fallback_path_uses_paid_amount_when_no_active_payments_exist()
    {
        // Old PAID invoice (should be excluded)
        Sale::create([
            'date' => now()->subDays(40),
            'due_date' => now()->subDays(40),
            'reference' => 'SALE-OLD-PAID',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'PAID',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        // Recent PAID invoice with no active payment row (should be included in fallback)
        Sale::create([
            'date' => now()->subDays(10),
            'due_date' => now()->subDays(10),
            'reference' => 'SALE-RECENT-PAID',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'PAID',
            'total_amount' => 2500,
            'paid_amount' => 2500,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        Livewire::test(SaleSummaryCards::class)
            ->assertSet('penerimaan.count', 1)
            ->assertSet('penerimaan.total', 2500);
    }
}

<?php

namespace Modules\Purchase\Tests\Feature;

use App\Livewire\Purchase\PurchaseTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Livewire\PurchaseSummaryCards;
use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseSummaryCardsTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $supplier;

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

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_purchase_summary_cards_computes_correct_counts_and_totals()
    {
        // Belum Dibayar
        Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(2),
            'reference' => 'PUR-001',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        // Telat Bayar (Overdue)
        Purchase::create([
            'date' => now()->subDays(5),
            'due_date' => now()->subDays(2),
            'reference' => 'PUR-002',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'setting_id' => $this->setting->id,
        ]);

        // Pelunasan (Paid)
        $purchase3 = Purchase::create([
            'date' => now()->subDays(10),
            'due_date' => now()->subDays(10),
            'reference' => 'PUR-003',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'total_amount' => 3000,
            'paid_amount' => 3000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase3->id,
            'amount' => 3000, 
            'date' => now()->subDays(2),
            'reference' => 'PAY-001',
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash'
        ]);

        Livewire::test(PurchaseSummaryCards::class)
            ->assertSet('belumDibayar.count', 2) // Unpaid includes both
            ->assertSet('belumDibayar.total', 3000)
            ->assertSet('telatBayar.count', 1) // Only PUR-002
            ->assertSet('telatBayar.total', 2000)
            ->assertSet('pelunasan.count', 1)
            ->assertSet('pelunasan.total', 3000);
    }

    public function test_purchase_table_filters_unpaid_when_event_dispatched()
    {
        Livewire::test(PurchaseTable::class, ['settingId' => $this->setting->id])
            ->dispatch('purchase-filter', type: 'unpaid')
            ->assertSet('paymentStatusFilter', 'UNPAID')
            ->assertSet('dueAmountOnly', true)
            ->assertSet('overdueOnly', false)
            ->assertSet('cardStatusFilter', [
                Purchase::STATUS_APPROVED,
                Purchase::STATUS_RECEIVED_PARTIALLY,
                Purchase::STATUS_RECEIVED,
            ]);
    }

    public function test_purchase_table_filters_overdue_when_event_dispatched()
    {
        Livewire::test(PurchaseTable::class, ['settingId' => $this->setting->id])
            ->dispatch('purchase-filter', type: 'overdue')
            ->assertSet('paymentStatusFilter', 'UNPAID')
            ->assertSet('overdueOnly', true)
            ->assertSet('dueAmountOnly', false)
            ->assertSet('cardStatusFilter', [
                Purchase::STATUS_APPROVED,
                Purchase::STATUS_RECEIVED_PARTIALLY,
                Purchase::STATUS_RECEIVED,
            ]);
    }

    public function test_purchase_table_ghost_invoices_excluded_when_unpaid_filter_applied()
    {
        // Ghost: UNPAID but due_amount = 0
        Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(10),
            'reference' => 'PUR-GHOST',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 500,
            'paid_amount' => 500,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);
        // Real unpaid
        Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(10),
            'reference' => 'PUR-REAL',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        $component = Livewire::test(PurchaseTable::class, ['settingId' => $this->setting->id])
            ->dispatch('purchase-filter', type: 'unpaid');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases->items());
        $this->assertEquals(1000, $purchases->items()[0]->due_amount);
    }
}

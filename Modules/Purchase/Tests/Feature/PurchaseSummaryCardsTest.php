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
            ->assertSet('belumDibayar.count', 2) // Unpaid includes both PUR-001 (not yet due) and PUR-002 (overdue)
            ->assertSet('belumDibayar.total', 3000)
            ->assertSet('telatBayar.count', 1) // Only PUR-002
            ->assertSet('telatBayar.total', 2000)
            ->assertSet('pelunasan.count', 1)
            ->assertSet('pelunasan.total', 3000);
    }

    public function test_purchase_summary_cards_isolates_data_by_tenant_setting_id()
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

        $supplier2 = Supplier::create([
            'supplier_name' => 'Other Supplier',
            'supplier_email' => 'other@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $setting2->id,
        ]);

        // Purchase for the active session tenant (setting_id = 1)
        Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(2),
            'reference' => 'PUR-MINE',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        // Purchase belonging to another tenant (setting_id = 2) - MUST be excluded
        Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(2),
            'reference' => 'PUR-OTHER',
            'supplier_id' => $supplier2->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 9999,
            'paid_amount' => 0,
            'due_amount' => 9999,
            'setting_id' => $setting2->id,
        ]);

        Livewire::test(PurchaseSummaryCards::class)
            ->assertSet('belumDibayar.count', 1)
            ->assertSet('belumDibayar.total', 1000);
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

    public function test_purchase_table_filters_paid_last_30_days_when_event_dispatched()
    {
        // Old PAID invoice (should be excluded)
        Purchase::create([
            'date' => now()->subDays(40),
            'due_date' => now()->subDays(40),
            'reference' => 'PUR-OLD-PAID',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        // Recent PAID invoice (should be included)
        $recentPurchase = Purchase::create([
            'date' => now()->subDays(10),
            'due_date' => now()->subDays(10),
            'reference' => 'PUR-RECENT-PAID',
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'total_amount' => 2000,
            'paid_amount' => 2000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        $component = Livewire::test(PurchaseTable::class, ['settingId' => $this->setting->id])
            ->dispatch('purchase-filter', type: 'paid')
            ->assertSet('paidLast30DaysOnly', true);

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases->items());
        $this->assertEquals($recentPurchase->reference, $purchases->items()[0]->reference);
    }

    public function test_purchase_table_renders_due_date_column_and_safe_placeholder()
    {
        Purchase::create([
            'date' => '2026-05-10',
            'due_date' => '2026-05-20',
            'supplier_id' => $this->supplier->id,
            'supplier_purchase_number' => 'SUP-001',
            'tax_ref_no' => 'TAX-001',
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        Purchase::create([
            'date' => '2026-05-11',
            'due_date' => '2026-05-22',
            'supplier_id' => $this->supplier->id,
            'supplier_purchase_number' => 'SUP-002',
            'tax_ref_no' => 'TAX-002',
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'setting_id' => $this->setting->id,
        ]);

        $component = Livewire::test(PurchaseTable::class, ['settingId' => $this->setting->id])
            ->assertSee('Tanggal Jatuh Tempo')
            ->assertSee('20 May 2026')
            ->assertSee('22 May 2026')
            ->assertSee('SUP-002');

        $this->assertSame('-', $component->instance()->formatDate(null));
    }

    public function test_purchase_table_sorts_by_due_date_without_losing_active_filters()
    {
        $otherSupplier = Supplier::create([
            'supplier_name' => 'Other Supplier',
            'supplier_email' => 'other@supplier.com',
            'supplier_phone' => '1234567891',
            'city' => 'Other City',
            'country' => 'Other Country',
            'address' => 'Other Address',
            'setting_id' => $this->setting->id,
        ]);

        $activePurchase = Purchase::create([
            'date' => '2026-05-01',
            'due_date' => '2026-05-21',
            'supplier_id' => $this->supplier->id,
            'supplier_purchase_number' => 'FILTER-MATCH-ACTIVE',
            'tax_ref_no' => 'TAX-ACTIVE',
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'setting_id' => $this->setting->id,
            'archived_at' => now(),
            'archived_by' => 1,
        ]);

        $olderArchivedPurchase = Purchase::create([
            'date' => '2026-05-02',
            'due_date' => '2026-05-11',
            'supplier_id' => $this->supplier->id,
            'supplier_purchase_number' => 'FILTER-MATCH-OLDER',
            'tax_ref_no' => 'TAX-OLDER',
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 1200,
            'paid_amount' => 0,
            'due_amount' => 1200,
            'setting_id' => $this->setting->id,
            'archived_at' => now(),
            'archived_by' => 1,
        ]);

        Purchase::create([
            'date' => '2026-05-03',
            'due_date' => '2026-05-05',
            'supplier_id' => $otherSupplier->id,
            'supplier_purchase_number' => 'FILTER-OTHER-SUPPLIER',
            'tax_ref_no' => 'TAX-OTHER',
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 900,
            'paid_amount' => 0,
            'due_amount' => 900,
            'setting_id' => $this->setting->id,
            'archived_at' => now(),
            'archived_by' => 1,
        ]);

        Purchase::create([
            'date' => '2026-05-04',
            'due_date' => '2026-05-01',
            'supplier_id' => $this->supplier->id,
            'supplier_purchase_number' => 'FILTER-WRONG-STATUS',
            'tax_ref_no' => 'TAX-WRONG',
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'total_amount' => 800,
            'paid_amount' => 0,
            'due_amount' => 800,
            'setting_id' => $this->setting->id,
            'archived_at' => now(),
            'archived_by' => 1,
        ]);

        $component = Livewire::test(PurchaseTable::class, [
            'settingId' => $this->setting->id,
            'statusFilter' => [Purchase::STATUS_APPROVED],
            'supplierId' => $this->supplier->id,
        ])
            ->set('showArchived', true)
            ->set('search', 'FILTER-MATCH')
            ->call('sortBy', 'due_date');

        $purchases = $component->viewData('purchases');

        $this->assertSame('due_date', $component->get('sortField'));
        $this->assertSame('asc', $component->get('sortDirection'));
        $this->assertSame(['FILTER-MATCH-OLDER', 'FILTER-MATCH-ACTIVE'], array_map(
            static fn (Purchase $purchase) => $purchase->supplier_purchase_number,
            $purchases->items(),
        ));
    }
}

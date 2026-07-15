<?php

namespace Modules\Purchase\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Supplier;
use Tests\TestCase;
use App\Livewire\Purchase\PurchaseTable;
use Modules\Purchase\Livewire\PurchaseSummaryCards;

class GlobalPurchaseSummaryCardsTest extends TestCase
{
    use RefreshDatabase;

    protected $setting1;
    protected $setting2;

    protected function setUp(): void
    {
        parent::setUp();
        
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        
        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();
        session(['setting_id' => $this->setting1->id]);
        
        \Illuminate\Support\Facades\Gate::define('purchasePayments.global.access', function (?\App\Models\User $user = null) {
            return true;
        });
    }

    private function createPurchase($overrides = [])
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier ' . uniqid(),
            'supplier_email' => uniqid() . '@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $overrides['setting_id'] ?? $this->setting1->id,
        ]);

        return Purchase::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting1->id,
        ], $overrides));
    }

    public function test_global_mode_calculates_unpaid_across_settings()
    {
        $this->createPurchase(['setting_id' => $this->setting1->id, 'total_amount' => 10000, 'due_amount' => 10000]);
        $this->createPurchase(['setting_id' => $this->setting2->id, 'total_amount' => 15000, 'due_amount' => 15000]);
        
        // Excluded due to status
        $this->createPurchase(['setting_id' => $this->setting2->id, 'total_amount' => 5000, 'status' => Purchase::STATUS_APPROVED]);

        $component = Livewire::test(PurchaseSummaryCards::class, ['globalMode' => true]);
        
        $component->assertSet('belumDibayar.count', 2);
        $component->assertSet('belumDibayar.total', 25000.0);
    }

    public function test_global_mode_calculates_live_balances_for_unpaid()
    {
        $purchase1 = $this->createPurchase(['total_amount' => 20000]);
        
        PurchasePayment::create([
            'purchase_id' => $purchase1->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-1',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE
        ]);

        $component = Livewire::test(PurchaseSummaryCards::class, ['globalMode' => true]);
        
        // Expected live due amount: 20000 - 5000 = 15000
        $component->assertSet('belumDibayar.count', 1);
        $component->assertSet('belumDibayar.total', 15000.0);
    }

    public function test_global_mode_calculates_overdue_totals()
    {
        $this->createPurchase(['total_amount' => 10000, 'due_date' => Carbon::yesterday()]);
        $this->createPurchase(['total_amount' => 5000, 'due_date' => Carbon::tomorrow()]); // Not overdue

        $component = Livewire::test(PurchaseSummaryCards::class, ['globalMode' => true]);
        
        $component->assertSet('telatBayar.count', 1);
        $component->assertSet('telatBayar.total', 10000.0);
    }

    public function test_global_mode_calculates_pelunasan_totals_correctly()
    {
        // 1. Purchase paid within 30 days (today)
        $purchase1 = $this->createPurchase(['total_amount' => 10000]);
        PurchasePayment::create([
            'purchase_id' => $purchase1->id,
            'amount' => 10000,
            'date' => Carbon::today(), // today
            'reference' => 'PAY-1',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE
        ]);
        
        // 2. Purchase paid exactly 30 days ago (should be included)
        $purchase2 = $this->createPurchase(['total_amount' => 20000]);
        PurchasePayment::create([
            'purchase_id' => $purchase2->id,
            'amount' => 20000,
            'date' => Carbon::today()->subDays(30),
            'reference' => 'PAY-2',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE
        ]);
        
        // 3. Purchase paid exactly 31 days ago (should be excluded)
        $purchase3 = $this->createPurchase(['total_amount' => 30000]);
        PurchasePayment::create([
            'purchase_id' => $purchase3->id,
            'amount' => 30000,
            'date' => Carbon::today()->subDays(31), // > 30 days
            'reference' => 'PAY-3',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE
        ]);
        
        // 4. Purchase paid tomorrow (future date, should be excluded)
        $purchase4 = $this->createPurchase(['total_amount' => 40000]);
        PurchasePayment::create([
            'purchase_id' => $purchase4->id,
            'amount' => 40000,
            'date' => Carbon::today()->addDay(), // future
            'reference' => 'PAY-4',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE
        ]);
        
        // 5. Purchase paid but payment invalidated (should be excluded as it is now unpaid)
        $purchase5 = $this->createPurchase(['total_amount' => 5000]);
        PurchasePayment::create([
            'purchase_id' => $purchase5->id,
            'amount' => 5000,
            'date' => Carbon::today(),
            'reference' => 'PAY-5',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_INVALIDATED
        ]);
        
        // 6. Purchase partially paid (live due > 0) (should be excluded from Pelunasan)
        $purchase6 = $this->createPurchase(['total_amount' => 12000]);
        PurchasePayment::create([
            'purchase_id' => $purchase6->id,
            'amount' => 5000,
            'date' => Carbon::today(),
            'reference' => 'PAY-6',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE
        ]);

        $component = Livewire::test(PurchaseSummaryCards::class, ['globalMode' => true]);
        
        // Only purchase1 and purchase2 are fully paid and within the correct 30 day window
        $component->assertSet('pelunasan.count', 2);
        $component->assertSet('pelunasan.total', 30000.0);
        
        // Test that the card is now rendered
        $component->assertSee('Pelunasan (30 Hari)');
    }

    public function test_global_mode_excludes_archived_purchases_from_totals()
    {
        $purchase = $this->createPurchase(['total_amount' => 10000]);
        $purchase->delete(); // Archive it

        $component = Livewire::test(PurchaseSummaryCards::class, ['globalMode' => true]);
        
        $component->assertSet('belumDibayar.count', 0);
        $component->assertSet('belumDibayar.total', 0.0);
    }

    public function test_card_filtering_on_global_table()
    {
        $overdue = $this->createPurchase(['due_date' => Carbon::yesterday(), 'total_amount' => 10000]);
        $notOverdue = $this->createPurchase(['due_date' => Carbon::tomorrow(), 'total_amount' => 10000]);
        
        $table = Livewire::test(PurchaseTable::class, ['globalMode' => true]);
        
        $table->assertSee($overdue->reference);
        $table->assertSee($notOverdue->reference);
        
        // Emit filter event
        $table->call('applyPurchaseFilter', 'overdue');
        
        $table->assertSee($overdue->reference);
        $table->assertDontSee($notOverdue->reference);
    }

    public function test_unauthorized_user_cannot_access_global_mode()
    {
        // Deny access
        \Illuminate\Support\Facades\Gate::define('purchasePayments.global.access', function (?\App\Models\User $user = null) {
            return false;
        });

        // Test component mounting throws 403
        Livewire::test(PurchaseSummaryCards::class, ['globalMode' => true])
            ->assertForbidden();
            
        // Test component mutating globalMode throws exception since it's locked
        $component = Livewire::test(PurchaseSummaryCards::class, ['globalMode' => false]);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [globalMode]');
        $component->set('globalMode', true);
    }
}

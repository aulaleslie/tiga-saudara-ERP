<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Supplier;
use Tests\TestCase;

class GlobalPurchasePaymentTableTest extends TestCase
{
    use RefreshDatabase;

    protected $setting1;
    protected $setting2;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();
        session(['setting_id' => $this->setting1->id]);

        // Create authenticated user
        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

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

    public function test_global_mode_shows_purchases_across_all_settings()
    {
        $purchase1 = $this->createPurchase(['setting_id' => $this->setting1->id]);
        $purchase2 = $this->createPurchase(['setting_id' => $this->setting2->id]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($purchase1->reference)
            ->assertSee($purchase2->reference);
    }

    public function test_normal_mode_hides_purchases_from_other_settings()
    {
        $purchase1 = $this->createPurchase(['setting_id' => $this->setting1->id]);
        $purchase2 = $this->createPurchase(['setting_id' => $this->setting2->id]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => false, 'settingId' => $this->setting1->id])
            ->assertSee($purchase1->reference)
            ->assertDontSee($purchase2->reference . '</a>', false); // HTML exact match or assert count
    }

    public function test_global_mode_only_shows_received_status()
    {
        $received = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);
        $approved = $this->createPurchase(['status' => Purchase::STATUS_APPROVED]);
        $pending = $this->createPurchase(['status' => 'Pending']);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($received->reference)
            ->assertDontSee($approved->reference)
            ->assertDontSee($pending->reference);
    }

    public function test_global_mode_shows_paid_and_unpaid_purchases_by_default()
    {
        $unpaid = $this->createPurchase(['due_amount' => 10000, 'payment_status' => 'UNPAID']);
        $paid = $this->createPurchase(['due_amount' => 0, 'payment_status' => 'PAID']);

        \Modules\Purchase\Entities\PurchasePayment::create([
            'purchase_id' => $paid->id,
            'amount' => 10000,
            'date' => now(),
            'reference' => 'PAY-123',
            'payment_method' => 'Cash',
            'status' => \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE
        ]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($unpaid->reference)
            ->assertSee($paid->reference);
    }
    
    public function test_global_mode_shows_paid_purchases_when_filter_applied_with_date_boundaries()
    {
        $unpaid = $this->createPurchase(['due_amount' => 10000, 'payment_status' => 'UNPAID']);
        
        // 1. Paid today (should show)
        $paidToday = $this->createPurchase(['due_amount' => 0, 'payment_status' => 'PAID']);
        \Modules\Purchase\Entities\PurchasePayment::create([
            'purchase_id' => $paidToday->id,
            'amount' => 10000,
            'date' => Carbon::today(),
            'reference' => 'PAY-1',
            'payment_method' => 'Cash',
            'status' => \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE
        ]);
        
        // 2. Paid exactly 30 days ago (should show)
        $paid30DaysAgo = $this->createPurchase(['due_amount' => 0, 'payment_status' => 'PAID']);
        \Modules\Purchase\Entities\PurchasePayment::create([
            'purchase_id' => $paid30DaysAgo->id,
            'amount' => 10000,
            'date' => Carbon::today()->subDays(30),
            'reference' => 'PAY-2',
            'payment_method' => 'Cash',
            'status' => \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE
        ]);
        
        // 3. Paid exactly 31 days ago (should NOT show)
        $paid31DaysAgo = $this->createPurchase(['due_amount' => 0, 'payment_status' => 'PAID']);
        \Modules\Purchase\Entities\PurchasePayment::create([
            'purchase_id' => $paid31DaysAgo->id,
            'amount' => 10000,
            'date' => Carbon::today()->subDays(31),
            'reference' => 'PAY-3',
            'payment_method' => 'Cash',
            'status' => \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE
        ]);
        
        // 4. Paid tomorrow (future, should NOT show)
        $paidTomorrow = $this->createPurchase(['due_amount' => 0, 'payment_status' => 'PAID']);
        \Modules\Purchase\Entities\PurchasePayment::create([
            'purchase_id' => $paidTomorrow->id,
            'amount' => 10000,
            'date' => Carbon::today()->addDay(),
            'reference' => 'PAY-4',
            'payment_method' => 'Cash',
            'status' => \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE
        ]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->call('applyPurchaseFilter', 'paid')
            ->assertSee($paidToday->reference)
            ->assertSee($paid30DaysAgo->reference)
            ->assertDontSee($paid31DaysAgo->reference)
            ->assertDontSee($paidTomorrow->reference)
            ->assertDontSee($unpaid->reference);
    }

    public function test_global_mode_excludes_archived_purchases()
    {
        $active = $this->createPurchase();
        $archived = $this->createPurchase();
        $archived->delete(); // Soft delete for archival

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($active->reference)
            ->assertDontSee($archived->reference);
    }
    
    public function test_unauthorized_user_cannot_access_global_mode()
    {
        // Deny access
        \Illuminate\Support\Facades\Gate::define('purchasePayments.global.access', function (?\App\Models\User $user = null) {
            return false;
        });

        // Test component mounting throws 403
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertForbidden();
            
        // Test component mutating globalMode throws exception since it's locked
        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => false]);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [globalMode]');
        $component->set('globalMode', true);
    }
}

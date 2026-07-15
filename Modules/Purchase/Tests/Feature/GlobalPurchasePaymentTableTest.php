<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    protected function setUp(): void
    {
        parent::setUp();
        
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        
        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();
        session(['setting_id' => $this->setting1->id]);
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

    public function test_global_mode_only_shows_purchases_with_outstanding_balance()
    {
        $unpaid = $this->createPurchase(['due_amount' => 10000, 'payment_status' => 'UNPAID']);
        $paid = $this->createPurchase(['due_amount' => 0, 'payment_status' => 'PAID']);
        
        \Modules\Purchase\Entities\PurchasePayment::create([
            'purchase_id' => $paid->id,
            'amount' => 10000,
            'date' => now(),
            'reference' => 'PAY-123',
            'payment_method' => 'Cash'
        ]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($unpaid->reference)
            ->assertDontSee($paid->reference);
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
}

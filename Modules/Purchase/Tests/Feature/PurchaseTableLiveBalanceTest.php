<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use App\Livewire\Purchase\PurchaseTable;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Supplier;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseTableLiveBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected $setting1;
    protected $setting2;
    protected $user;
    protected $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();
        session(['setting_id' => $this->setting1->id]);

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::findOrCreate('purchases.received.correct', 'web');

        \Illuminate\Support\Facades\Gate::define('purchasePayments.global.access', fn() => true);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier Alpha',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting1->id,
        ]);
    }

    protected function createPurchase(array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting1->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ], $overrides));
    }

    /** @test */
    public function it_displays_live_outstanding_balance_in_normal_mode_ignoring_stale_header(): void
    {
        $purchase = $this->createPurchase([
            'reference' => 'PO-STALE-001',
            'total_amount' => 1000000,
            'paid_amount' => 100000,
            'due_amount' => 900000,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 400000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        Livewire::test(PurchaseTable::class, ['globalMode' => false])
            ->assertSee('PO-STALE-001')
            ->assertSee(format_currency(600000))
            ->assertDontSee(format_currency(900000));
    }

    /** @test */
    public function it_filters_by_due_amount_only_using_live_balance_in_normal_mode(): void
    {
        $poPositiveLive = $this->createPurchase([
            'reference' => 'PO-POS-LIVE',
            'total_amount' => 1000000,
            'paid_amount' => 1000000,
            'due_amount' => 0,
        ]);

        $poZeroLive = $this->createPurchase([
            'reference' => 'PO-ZERO-LIVE',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);
        PurchasePayment::create([
            'purchase_id' => $poZeroLive->id,
            'amount' => 1000000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-FULL',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        Livewire::test(PurchaseTable::class, ['globalMode' => false])
            ->set('dueAmountOnly', true)
            ->assertSee('PO-POS-LIVE')
            ->assertDontSee('PO-ZERO-LIVE');
    }

    /** @test */
    public function it_filters_by_overdue_only_using_live_balance_in_normal_mode(): void
    {
        $pastDueDate = Carbon::yesterday()->toDateString();

        $poSettled = $this->createPurchase([
            'reference' => 'PO-OVERDUE-SETTLED',
            'due_date' => $pastDueDate,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);
        PurchasePayment::create([
            'purchase_id' => $poSettled->id,
            'amount' => 1000000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-OVERDUE-1',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $poUnsettled = $this->createPurchase([
            'reference' => 'PO-OVERDUE-UNSETTLED',
            'due_date' => $pastDueDate,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        Livewire::test(PurchaseTable::class, ['globalMode' => false])
            ->set('overdueOnly', true)
            ->assertSee('PO-OVERDUE-UNSETTLED')
            ->assertDontSee('PO-OVERDUE-SETTLED');
    }

    /** @test */
    public function it_sorts_by_live_due_amount_across_pages_with_opposite_stored_ordering(): void
    {
        $p1 = $this->createPurchase([
            'reference' => 'PO-PAGE-1',
            'total_amount' => 1000000,
            'paid_amount' => 100000,
            'due_amount' => 900000,
        ]);
        PurchasePayment::create([
            'purchase_id' => $p1->id,
            'amount' => 700000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-P1',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $p2 = $this->createPurchase([
            'reference' => 'PO-PAGE-2',
            'total_amount' => 1000000,
            'paid_amount' => 500000,
            'due_amount' => 500000,
        ]);
        PurchasePayment::create([
            'purchase_id' => $p2->id,
            'amount' => 500000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-P2',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $p3 = $this->createPurchase([
            'reference' => 'PO-PAGE-3',
            'total_amount' => 1000000,
            'paid_amount' => 900000,
            'due_amount' => 100000,
        ]);
        PurchasePayment::create([
            'purchase_id' => $p3->id,
            'amount' => 200000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-P3',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        Livewire::test(PurchaseTable::class, ['globalMode' => false])
            ->set('perPage', 2)
            ->call('sortBy', 'due_amount')
            ->assertSee('PO-PAGE-1')
            ->assertSee('PO-PAGE-2')
            ->assertDontSee('PO-PAGE-3')
            ->call('gotoPage', 2)
            ->assertSee('PO-PAGE-3')
            ->assertDontSee('PO-PAGE-1')
            ->assertDontSee('PO-PAGE-2');

        Livewire::test(PurchaseTable::class, ['globalMode' => false])
            ->set('perPage', 2)
            ->set('sortField', 'due_amount')
            ->set('sortDirection', 'desc')
            ->assertSee('PO-PAGE-3')
            ->assertSee('PO-PAGE-2')
            ->assertDontSee('PO-PAGE-1')
            ->call('gotoPage', 2)
            ->assertSee('PO-PAGE-1')
            ->assertDontSee('PO-PAGE-3')
            ->assertDontSee('PO-PAGE-2');
    }

    /** @test */
    public function it_sorts_by_live_due_amount_across_pages_with_equal_zero_and_equal_positive_ties(): void
    {
        $p1 = $this->createPurchase([
            'reference' => 'PO-ZERO-1',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
        ]);
        PurchasePayment::create([
            'purchase_id' => $p1->id,
            'amount' => 500000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-Z1',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $p2 = $this->createPurchase([
            'reference' => 'PO-ZERO-2',
            'total_amount' => 600000,
            'paid_amount' => 0,
            'due_amount' => 600000,
        ]);
        PurchasePayment::create([
            'purchase_id' => $p2->id,
            'amount' => 600000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-Z2',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $p3 = $this->createPurchase([
            'reference' => 'PO-POS-1',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);
        PurchasePayment::create([
            'purchase_id' => $p3->id,
            'amount' => 500000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-P3',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $p4 = $this->createPurchase([
            'reference' => 'PO-POS-2',
            'total_amount' => 700000,
            'paid_amount' => 0,
            'due_amount' => 700000,
        ]);
        PurchasePayment::create([
            'purchase_id' => $p4->id,
            'amount' => 200000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-P4',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        Livewire::test(PurchaseTable::class, ['globalMode' => false])
            ->set('perPage', 2)
            ->set('sortField', 'due_amount')
            ->set('sortDirection', 'asc')
            ->assertSee('PO-ZERO-1')
            ->assertSee('PO-ZERO-2')
            ->assertDontSee('PO-POS-1')
            ->assertDontSee('PO-POS-2')
            ->call('gotoPage', 2)
            ->assertSee('PO-POS-1')
            ->assertSee('PO-POS-2')
            ->assertDontSee('PO-ZERO-1')
            ->assertDontSee('PO-ZERO-2');

        Livewire::test(PurchaseTable::class, ['globalMode' => false])
            ->set('perPage', 2)
            ->set('sortField', 'due_amount')
            ->set('sortDirection', 'desc')
            ->assertSee('PO-POS-2')
            ->assertSee('PO-POS-1')
            ->assertDontSee('PO-ZERO-1')
            ->assertDontSee('PO-ZERO-2')
            ->call('gotoPage', 2)
            ->assertSee('PO-ZERO-2')
            ->assertSee('PO-ZERO-1')
            ->assertDontSee('PO-POS-1')
            ->assertDontSee('PO-POS-2');
    }
}

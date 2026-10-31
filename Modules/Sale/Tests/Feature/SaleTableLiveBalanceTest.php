<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use App\Livewire\Sale\SaleTable;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SalePaymentCreditApplication;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Customer;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SaleTableLiveBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected $setting1;
    protected $setting2;
    protected $user;
    protected $customer;

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

        \Illuminate\Support\Facades\Gate::define('salePayments.global.access', fn() => true);

        $this->customer = Customer::create([
            'customer_name' => 'Customer Omega',
            'contact_name' => 'Contact Person',
            'setting_id' => $this->setting1->id,
        ]);
    }

    protected function createSale(array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer Omega',
            'setting_id' => $this->setting1->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ], $overrides));
    }

    protected function createCredit(float $amount): CustomerCredit
    {
        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RET-' . uniqid(),
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer Omega',
            'total_amount' => $amount,
            'paid_amount' => 0,
            'due_amount' => $amount,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        return CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'status' => 'open',
        ]);
    }

    /** @test */
    public function it_displays_live_outstanding_balance_in_normal_mode_ignoring_stale_header_with_active_credit(): void
    {
        // Sale with stale stored due = 900,000, but live due = 500,000 (total 1M, active pay 300k, active credit 200k)
        $sale = $this->createSale([
            'reference' => 'SO-STALE-001',
            'total_amount' => 1000000,
            'paid_amount' => 100000,
            'due_amount' => 900000,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 300000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = $this->createCredit(500000);

        SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 200000,
        ]);

        Livewire::test(SaleTable::class, ['globalMode' => false])
            ->assertSee('SO-STALE-001')
            ->assertSee(format_currency(500000))
            ->assertDontSee(format_currency(900000));
    }

    /** @test */
    public function it_filters_by_due_amount_only_using_live_balance_in_normal_mode(): void
    {
        // Sale 1: Stored due 0, but active payments 0 -> Live due 1M
        $salePositiveLive = $this->createSale([
            'reference' => 'SO-POS-LIVE',
            'total_amount' => 1000000,
            'paid_amount' => 1000000,
            'due_amount' => 0,
        ]);

        // Sale 2: Stored due 1M, but fully settled with payment + credit -> Live due 0
        $saleZeroLive = $this->createSale([
            'reference' => 'SO-ZERO-LIVE',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $saleZeroLive->id,
            'amount' => 600000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-FULL',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = $this->createCredit(400000);

        SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 400000,
        ]);

        Livewire::test(SaleTable::class, ['globalMode' => false])
            ->set('dueAmountOnly', true)
            ->assertSee('SO-POS-LIVE')
            ->assertDontSee('SO-ZERO-LIVE');
    }

    /** @test */
    public function it_excludes_invalidated_payments_from_live_balance_in_normal_mode(): void
    {
        $sale = $this->createSale([
            'reference' => 'SO-INVALID-001',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 450000, // Stored due is stale 450,000
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 550000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-INV',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        Livewire::test(SaleTable::class, ['globalMode' => false])
            ->assertSee('SO-INVALID-001')
            ->assertSee(format_currency(1000000))
            ->assertDontSee(format_currency(450000));
    }

    /** @test */
    public function it_retains_recent_partial_payments_under_paid_last_30_days_filter_in_normal_mode(): void
    {
        // Sale with partial payment within the last 30 days (date is older, payment is recent)
        $sale = $this->createSale([
            'date' => Carbon::today()->subDays(45)->toDateString(),
            'reference' => 'SO-PARTIAL-RECENT',
            'total_amount' => 1000000,
            'paid_amount' => 400000,
            'due_amount' => 600000,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 400000,
            'date' => Carbon::today()->subDays(5)->toDateString(),
            'reference' => 'PAY-PARTIAL-RECENT',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // In normal mode: paidLast30DaysOnly should include recent partial payments
        Livewire::test(SaleTable::class, ['globalMode' => false])
            ->set('paidLast30DaysOnly', true)
            ->assertSee('SO-PARTIAL-RECENT');

        // In global mode: paidLast30DaysOnly requires fully settled invoices (live_due <= 0)
        Livewire::test(SaleTable::class, ['globalMode' => true])
            ->set('paidLast30DaysOnly', true)
            ->assertDontSee('SO-PARTIAL-RECENT');
    }
}

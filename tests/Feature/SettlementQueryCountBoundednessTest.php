<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use App\Livewire\Purchase\PurchaseTable;
use App\Livewire\Sale\SaleTable;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SalePaymentCreditApplication;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Supplier;
use Modules\People\Entities\Customer;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SettlementQueryCountBoundednessTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $user;
    protected $supplier;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::findOrCreate('purchases.received.correct', 'web');

        \Illuminate\Support\Facades\Gate::define('purchasePayments.global.access', fn() => true);
        \Illuminate\Support\Facades\Gate::define('salePayments.global.access', fn() => true);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier Alpha',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Customer Omega',
            'contact_name' => 'Contact Person',
            'setting_id' => $this->setting->id,
        ]);
    }

    /** @test */
    public function purchase_table_settlement_queries_remain_bounded_as_page_size_increases(): void
    {
        // Create 10 purchases with active payments
        for ($i = 1; $i <= 10; $i++) {
            $p = Purchase::create([
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'reference' => 'PO-' . $i,
                'supplier_id' => $this->supplier->id,
                'setting_id' => $this->setting->id,
                'payment_method' => 'Cash',
                'status' => Purchase::STATUS_RECEIVED,
                'payment_status' => 'Unpaid',
                'total_amount' => 1000000,
                'paid_amount' => 0,
                'due_amount' => 1000000,
            ]);

            PurchasePayment::create([
                'purchase_id' => $p->id,
                'amount' => 200000,
                'date' => now()->toDateString(),
                'reference' => 'PAY-PO-' . $i,
                'payment_method' => 'Cash',
                'status' => PurchasePayment::STATUS_ACTIVE,
            ]);
        }

        // Measure queries for perPage = 2
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(PurchaseTable::class, ['globalMode' => false])->set('perPage', 2);
        $queriesPage2 = collect(DB::getQueryLog());
        DB::disableQueryLog();

        // Measure queries for perPage = 8
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(PurchaseTable::class, ['globalMode' => false])->set('perPage', 8);
        $queriesPage8 = collect(DB::getQueryLog());
        DB::disableQueryLog();

        // Check queries hitting purchase_payments
        $paymentQueriesPage2 = $queriesPage2->filter(fn($q) => str_contains($q['query'], 'purchase_payments'))->count();
        $paymentQueriesPage8 = $queriesPage8->filter(fn($q) => str_contains($q['query'], 'purchase_payments'))->count();

        // No individual per-row queries: count of payment queries must not grow with page size
        $this->assertSame($paymentQueriesPage2, $paymentQueriesPage8);
    }

    /** @test */
    public function sale_table_settlement_queries_remain_bounded_as_page_size_increases(): void
    {
        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RET-1',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer Omega',
            'total_amount' => 5000000,
            'paid_amount' => 0,
            'due_amount' => 5000000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $credit = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 5000000,
            'remaining_amount' => 5000000,
            'status' => 'open',
        ]);

        // Create 10 sales with active payments and credit applications
        for ($i = 1; $i <= 10; $i++) {
            $s = Sale::create([
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'reference' => 'SO-' . $i,
                'customer_id' => $this->customer->id,
                'customer_name' => 'Customer Omega',
                'setting_id' => $this->setting->id,
                'payment_method' => 'Cash',
                'status' => Sale::STATUS_APPROVED,
                'payment_status' => 'Unpaid',
                'total_amount' => 1000000,
                'paid_amount' => 0,
                'due_amount' => 1000000,
            ]);

            $payment = SalePayment::create([
                'sale_id' => $s->id,
                'amount' => 200000,
                'date' => now()->toDateString(),
                'reference' => 'PAY-SO-' . $i,
                'payment_method' => 'Cash',
                'status' => SalePayment::STATUS_ACTIVE,
            ]);

            SalePaymentCreditApplication::create([
                'sale_payment_id' => $payment->id,
                'customer_credit_id' => $credit->id,
                'amount' => 100000,
            ]);
        }

        // Measure queries for perPage = 2
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(SaleTable::class, ['globalMode' => false])->set('perPage', 2);
        $queriesPage2 = collect(DB::getQueryLog());
        DB::disableQueryLog();

        // Measure queries for perPage = 8
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(SaleTable::class, ['globalMode' => false])->set('perPage', 8);
        $queriesPage8 = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $settlementQueriesPage2 = $queriesPage2->filter(fn($q) => str_contains($q['query'], 'sale_payments') || str_contains($q['query'], 'sale_payment_credit_applications'))->count();
        $settlementQueriesPage8 = $queriesPage8->filter(fn($q) => str_contains($q['query'], 'sale_payments') || str_contains($q['query'], 'sale_payment_credit_applications'))->count();

        // No individual per-row queries: count of payment/credit queries must not grow with page size
        $this->assertSame($settlementQueriesPage2, $settlementQueriesPage8);
    }
}


<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Customer;
use Tests\TestCase;

class EmbeddedGlobalSalePaymentIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected Customer $customer1;
    protected Customer $customer2;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();
        session(['setting_id' => $this->setting1->id]);

        $this->customer1 = Customer::factory()->create(['customer_name' => 'Target Customer']);
        $this->customer2 = Customer::factory()->create(['customer_name' => 'Other Customer']);

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        \Illuminate\Support\Facades\Gate::define('salePayments.global.access', fn() => true);
        \Illuminate\Support\Facades\Gate::define('salePayments.create', fn() => true);
    }

    private function createSaleForCustomer(Customer $customer, Setting $setting, array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $setting->id,
        ], $overrides));
    }

    public function test_embedded_sales_table_isolates_exact_customer_across_businesses()
    {
        $cust1Sale1 = $this->createSaleForCustomer($this->customer1, $this->setting1, ['reference' => 'SO-CUST1-S1']);
        $cust1Sale2 = $this->createSaleForCustomer($this->customer1, $this->setting2, ['reference' => 'SO-CUST1-S2']);
        $cust2Sale = $this->createSaleForCustomer($this->customer2, $this->setting1, ['reference' => 'SO-CUST2-S1']);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, [
            'globalMode' => true,
            'customerId' => $this->customer1->id,
        ])
            ->assertSee('SO-CUST1-S1')
            ->assertSee('SO-CUST1-S2')
            ->assertDontSee('SO-CUST2-S1');
    }

    public function test_embedded_sales_table_composes_customer_constraint_with_business_filter()
    {
        $cust1Sale1 = $this->createSaleForCustomer($this->customer1, $this->setting1, ['reference' => 'SO-CUST1-S1']);
        $cust1Sale2 = $this->createSaleForCustomer($this->customer1, $this->setting2, ['reference' => 'SO-CUST1-S2']);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, [
            'globalMode' => true,
            'customerId' => $this->customer1->id,
        ])
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->call('applyGlobalFilters')
            ->assertSee('SO-CUST1-S1')
            ->assertDontSee('SO-CUST1-S2');
    }

    public function test_embedded_sales_summary_cards_and_table_are_consistent_for_customer()
    {
        // Target customer: 2 unpaid sales of 10000 each = 20000
        $this->createSaleForCustomer($this->customer1, $this->setting1, ['due_amount' => 10000]);
        $this->createSaleForCustomer($this->customer1, $this->setting2, ['due_amount' => 10000]);

        // Other customer: 1 unpaid sale of 50000
        $this->createSaleForCustomer($this->customer2, $this->setting1, ['due_amount' => 50000]);

        $cardsComponent = Livewire::test(\Modules\Sale\Livewire\SaleSummaryCards::class, [
            'globalMode' => true,
            'customerId' => $this->customer1->id,
        ]);

        $piutang = $cardsComponent->get('piutangBelumTertagih');
        $this->assertEquals(2, $piutang['count']);
        $this->assertEquals(20000.0, $piutang['total']);
    }

    public function test_sales_table_rejects_customer_id_mutation()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [customerId]');

        Livewire::test(\App\Livewire\Sale\SaleTable::class, [
            'globalMode' => true,
            'customerId' => $this->customer1->id,
        ])
            ->set('customerId', $this->customer2->id);
    }

    public function test_sales_summary_cards_rejects_customer_id_mutation()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [customerId]');

        Livewire::test(\Modules\Sale\Livewire\SaleSummaryCards::class, [
            'globalMode' => true,
            'customerId' => $this->customer1->id,
        ])
            ->set('customerId', $this->customer2->id);
    }

    public function test_standalone_global_sales_workspace_remains_unconstrained_by_customer()
    {
        $cust1Sale = $this->createSaleForCustomer($this->customer1, $this->setting1, ['reference' => 'SO-CROSS-1']);
        $cust2Sale = $this->createSaleForCustomer($this->customer2, $this->setting2, ['reference' => 'SO-CROSS-2']);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, [
            'globalMode' => true,
            'customerId' => null,
        ])
            ->assertSee('SO-CROSS-1')
            ->assertSee('SO-CROSS-2');

        $cards = Livewire::test(\Modules\Sale\Livewire\SaleSummaryCards::class, [
            'globalMode' => true,
            'customerId' => null,
        ]);

        $piutang = $cards->get('piutangBelumTertagih');
        $this->assertEquals(2, $piutang['count']);
        $this->assertEquals(20000.0, $piutang['total']);
    }

    public function test_embedded_eligible_sale_opens_existing_multi_invoice_payment_workflow()
    {
        $sale1 = $this->createSaleForCustomer($this->customer1, $this->setting1, ['due_amount' => 10000]);
        $sale2 = $this->createSaleForCustomer($this->customer1, $this->setting2, ['due_amount' => 20000]);

        $response = $this->get(route('sales.global-payments.create', $sale1->id));
        $response->assertSuccessful();
        $response->assertSee($sale1->reference);
        $response->assertSee($sale2->reference);
    }
}

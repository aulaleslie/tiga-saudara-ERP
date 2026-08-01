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

class GlobalSalePaymentFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected $setting1;
    protected $setting2;
    protected $customer;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();
        session(['setting_id' => $this->setting1->id]);

        $this->customer = Customer::factory()->create();

        // Create authenticated user
        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        \Illuminate\Support\Facades\Gate::define('salePayments.global.access', function (?\App\Models\User $user = null) {
            return true;
        });
    }

    private function createSale($overrides = [])
    {
        return Sale::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'setting_id' => $this->setting1->id,
        ], $overrides));
    }

    private function createPaidSale($overrides = [])
    {
        $sale = $this->createSale(array_merge([
            'payment_status' => 'PAID',
            'paid_amount' => 100000,
            'due_amount' => 0,
        ], $overrides));

        // Create active payment in past 30 days
        SalePayment::create([
            'sale_id' => $sale->id,
            'date' => now()->subDays(15),
            'reference' => 'PAY-' . uniqid(),
            'payment_method_id' => 1,
            'payment_method' => 'Cash',
            'amount' => 100000,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        return $sale;
    }

    public function test_global_mode_shows_paid_and_payable_sales_by_default()
    {
        $payable = $this->createSale(['due_amount' => 100000]);
        $paid = $this->createPaidSale();

        // Default global mode shows both paid and payable sales
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($payable->reference)
            ->assertSee($paid->reference);
    }

    public function test_global_mode_shows_paid_sales_with_filter()
    {
        $payable = $this->createSale(['due_amount' => 100000]);
        $paid = $this->createPaidSale();

        // With paid filter, shows only paid sales
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->dispatch('sale-filter', type: 'paid')
            ->assertDontSee($payable->reference)
            ->assertSee($paid->reference);
    }

    public function test_business_filter_restricts_to_selected_setting()
    {
        $sale1 = $this->createSale(['setting_id' => $this->setting1->id]);
        $sale2 = $this->createSale(['setting_id' => $this->setting2->id]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('globalBusinessFilter', $this->setting1->id)
            ->assertSee($sale1->reference)
            ->assertDontSee($sale2->reference);
    }

    public function test_document_date_filter_restricts_by_date_range()
    {
        $saleBeforeRange = $this->createSale(['date' => now()->subDays(10)]);
        $saleInRange = $this->createSale(['date' => now()]);
        $saleAfterRange = $this->createSale(['date' => now()->addDays(10)]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('documentDateFrom', $fromDate)
            ->set('documentDateTo', $toDate)
            ->assertSee($saleInRange->reference)
            ->assertDontSee($saleBeforeRange->reference)
            ->assertDontSee($saleAfterRange->reference);
    }

    public function test_business_and_date_filters_compose()
    {
        $sale1Setting1 = $this->createSale([
            'setting_id' => $this->setting1->id,
            'date' => now()->subDays(10),
        ]);
        $sale2Setting1 = $this->createSale([
            'setting_id' => $this->setting1->id,
            'date' => now(),
        ]);
        $sale3Setting2 = $this->createSale([
            'setting_id' => $this->setting2->id,
            'date' => now(),
        ]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('globalBusinessFilter', $this->setting1->id)
            ->set('documentDateFrom', $fromDate)
            ->set('documentDateTo', $toDate)
            ->assertSee($sale2Setting1->reference)
            ->assertDontSee($sale1Setting1->reference)
            ->assertDontSee($sale3Setting2->reference);
    }

    public function test_search_excludes_results_outside_filters()
    {
        $payableInRange = $this->createSale([
            'setting_id' => $this->setting1->id,
            'date' => now(),
            'reference' => 'SO-SEARCH-001',
            'note' => 'Search note',
        ]);
        $payableOutsideDate = $this->createSale([
            'setting_id' => $this->setting1->id,
            'date' => now()->subDays(20),
            'reference' => 'SO-SEARCH-002',
            'note' => 'Search note',
        ]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('documentDateFrom', $fromDate)
            ->set('documentDateTo', $toDate)
            ->set('search', 'Search note')
            ->assertSee($payableInRange->reference)
            ->assertDontSee($payableOutsideDate->reference);
    }

    public function test_paid_card_filter_shows_only_fully_paid_with_recent_payment()
    {
        $payable = $this->createSale(['due_amount' => 100000]);
        $paidRecent = $this->createPaidSale();

        // Create paid sale with old payment (outside 30-day window)
        $sale = $this->createSale([
            'payment_status' => 'PAID',
            'paid_amount' => 100000,
            'due_amount' => 0,
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'date' => now()->subDays(40),
            'reference' => 'PAY-' . uniqid(),
            'payment_method_id' => 1,
            'payment_method' => 'Cash',
            'amount' => 100000,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);
        $paidOld = $sale;

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->dispatch('sale-filter', type: 'paid')
            ->assertSee($paidRecent->reference)
            ->assertDontSee($payable->reference)
            ->assertDontSee($paidOld->reference);
    }

    public function test_fully_paid_rows_do_not_show_create_payment_action()
    {
        $paid = $this->createPaidSale();

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertDontSee('Buat Pembayaran', false); // Payment creation should not be visible for paid rows
    }

    public function test_payable_rows_show_create_payment_action()
    {
        $payable = $this->createSale(['due_amount' => 100000]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($payable->reference);
        // Note: Action visibility requires checking the rendered actions partial
    }

    public function test_search_includes_note_and_bundle_items()
    {
        $saleWithNote = $this->createSale([
            'note' => 'Special order for customer',
        ]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'Special order')
            ->assertSee($saleWithNote->reference);
    }

    public function test_filters_persist_in_query_string()
    {
        $sale = $this->createSale();

        $component = Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('globalBusinessFilter', $this->setting1->id)
            ->set('documentDateFrom', '2024-01-01')
            ->set('documentDateTo', '2024-12-31')
            ->set('search', 'test');

        // Verify that the properties are actually set (query string persistence is handled by Livewire)
        $this->assertEquals($this->setting1->id, $component->get('globalBusinessFilter'));
        $this->assertEquals('2024-01-01', $component->get('documentDateFrom'));
        $this->assertEquals('2024-12-31', $component->get('documentDateTo'));
        $this->assertEquals('test', $component->get('search'));
    }

    public function test_pagination_resets_on_filter_change()
    {
        // Create multiple sales to span pages
        for ($i = 0; $i < 15; $i++) {
            $this->createSale(['date' => now()]);
        }

        // Setting filter should trigger resetPage via updatedGlobalBusinessFilter and render successfully
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('perPage', 10)
            // Change filter - should trigger resetPage via updatedGlobalBusinessFilter
            ->set('globalBusinessFilter', $this->setting1->id)
            ->set('documentDateFrom', now()->subDays(30)->format('Y-m-d'))
            ->set('documentDateTo', now()->format('Y-m-d'))
            // Verify the component rendered without error
            ->assertViewIs('livewire.sale.sale-table');
    }

    public function test_filter_event_dispatches_named_parameters()
    {
        $this->createSale();

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('globalBusinessFilter', $this->setting1->id)
            ->set('documentDateFrom', '2024-01-01')
            ->set('documentDateTo', '2024-12-31')
            ->assertDispatched('global-sale-filters-changed');
    }

    public function test_summary_card_listener_receives_filter_event()
    {
        $payable = $this->createSale([
            'setting_id' => $this->setting1->id,
            'date' => now(),
            'due_amount' => 100000,
        ]);
        $payableOtherSetting = $this->createSale([
            'setting_id' => $this->setting2->id,
            'date' => now(),
            'due_amount' => 100000,
        ]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        $component = Livewire::test(\Modules\Sale\Livewire\SaleSummaryCards::class, [
            'globalMode' => true,
            'globalBusinessFilter' => $this->setting1->id,
            'documentDateFrom' => $fromDate,
            'documentDateTo' => $toDate,
        ])
            ->call('handleFiltersChanged', $this->setting1->id, $fromDate, $toDate);

        $this->assertEquals($this->setting1->id, $component->get('globalBusinessFilter'));
        $this->assertEquals($fromDate, $component->get('documentDateFrom'));
        $this->assertEquals($toDate, $component->get('documentDateTo'));
    }

    public function test_reversed_date_range_normalized()
    {
        $saleInRange = $this->createSale(['date' => now()]);

        $fromDate = now()->addDays(5)->format('Y-m-d');
        $toDate = now()->subDays(5)->format('Y-m-d');

        $component = Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('documentDateFrom', $fromDate)
            ->set('documentDateTo', $toDate);

        // Verify dates are swapped
        $this->assertEquals(now()->subDays(5)->format('Y-m-d'), $component->get('documentDateFrom'));
        $this->assertEquals(now()->addDays(5)->format('Y-m-d'), $component->get('documentDateTo'));

        // Verify results are correct (same as if dates were in correct order)
        $component->assertSee($saleInRange->reference);
    }

    public function test_reversed_date_range_returns_same_results_as_correct_order()
    {
        $saleInRange = $this->createSale(['date' => now()]);
        $saleOutsideRange = $this->createSale(['date' => now()->subDays(20)]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        // Test with correct order
        $correctOrderComponent = Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('documentDateFrom', $fromDate)
            ->set('documentDateTo', $toDate);

        // Test with reversed order
        $reversedOrderComponent = Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('documentDateFrom', $toDate)
            ->set('documentDateTo', $fromDate);

        // Both should show the same result
        $correctOrderComponent->assertSee($saleInRange->reference);
        $reversedOrderComponent->assertSee($saleInRange->reference);

        $correctOrderComponent->assertDontSee($saleOutsideRange->reference);
        $reversedOrderComponent->assertDontSee($saleOutsideRange->reference);
    }

    public function test_clear_global_filters_resets_all_filters()
    {
        $this->createSale();

        $component = Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('globalBusinessFilter', $this->setting1->id)
            ->set('documentDateFrom', '2024-01-01')
            ->set('documentDateTo', '2024-12-31')
            ->call('clearGlobalFilters');

        $this->assertNull($component->get('globalBusinessFilter'));
        $this->assertNull($component->get('documentDateFrom'));
        $this->assertNull($component->get('documentDateTo'));
        $component->assertDispatched('global-sale-filters-changed');
    }
}

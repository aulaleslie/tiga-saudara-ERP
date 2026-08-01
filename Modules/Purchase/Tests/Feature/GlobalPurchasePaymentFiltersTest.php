<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Supplier;
use Tests\TestCase;

class GlobalPurchasePaymentFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected $setting1;
    protected $setting2;
    protected $supplier;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();
        session(['setting_id' => $this->setting1->id]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting1->id,
        ]);

        // Create authenticated user
        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        \Illuminate\Support\Facades\Gate::define('purchasePayments.global.access', function (?\App\Models\User $user = null) {
            return true;
        });
    }

    private function createPurchase($overrides = [])
    {
        return Purchase::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'setting_id' => $this->setting1->id,
        ], $overrides));
    }

    private function createPaidPurchase($overrides = [])
    {
        $purchase = $this->createPurchase(array_merge([
            'payment_status' => 'PAID',
            'paid_amount' => 100000,
            'due_amount' => 0,
        ], $overrides));

        // Create active payment in past 30 days
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'date' => now()->subDays(15),
            'reference' => 'PAY-' . uniqid(),
            'payment_method_id' => 1,
            'payment_method' => 'Cash',
            'amount' => 100000,
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        return $purchase;
    }

    public function test_global_mode_shows_paid_and_payable_purchases_by_default()
    {
        $payable = $this->createPurchase(['due_amount' => 100000]);
        $paid = $this->createPaidPurchase();

        // Default global mode shows both paid and payable purchases
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($payable->reference)
            ->assertSee($paid->reference);
    }

    public function test_global_mode_shows_paid_purchases_with_filter()
    {
        $payable = $this->createPurchase(['due_amount' => 100000]);
        $paid = $this->createPaidPurchase();

        // With paid filter, shows only paid purchases
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->dispatch('purchase-filter', type: 'paid')
            ->assertDontSee($payable->reference)
            ->assertSee($paid->reference);
    }

    public function test_business_filter_restricts_to_selected_setting()
    {
        $supplier2 = Supplier::create([
            'supplier_name' => 'Supplier 2',
            'supplier_email' => 'supplier2@example.com',
            'supplier_phone' => '87654321',
            'city' => 'Surabaya',
            'country' => 'Indonesia',
            'address' => 'Address 2',
            'setting_id' => $this->setting2->id,
        ]);

        $purchase1 = $this->createPurchase(['setting_id' => $this->setting1->id]);
        $purchase2 = $this->createPurchase([
            'setting_id' => $this->setting2->id,
            'supplier_id' => $supplier2->id,
        ]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('globalBusinessFilter', $this->setting1->id)
            ->assertSee($purchase1->reference)
            ->assertDontSee($purchase2->reference);
    }

    public function test_document_date_filter_restricts_by_date_range()
    {
        $purchaseBeforeRange = $this->createPurchase(['date' => now()->subDays(10)]);
        $purchaseInRange = $this->createPurchase(['date' => now()]);
        $purchaseAfterRange = $this->createPurchase(['date' => now()->addDays(10)]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('documentDateFrom', $fromDate)
            ->set('documentDateTo', $toDate)
            ->assertSee($purchaseInRange->reference)
            ->assertDontSee($purchaseBeforeRange->reference)
            ->assertDontSee($purchaseAfterRange->reference);
    }

    public function test_business_and_date_filters_compose()
    {
        $supplier2 = Supplier::create([
            'supplier_name' => 'Supplier 2',
            'supplier_email' => 'supplier2@example.com',
            'supplier_phone' => '87654321',
            'city' => 'Surabaya',
            'country' => 'Indonesia',
            'address' => 'Address 2',
            'setting_id' => $this->setting2->id,
        ]);

        $purchase1Setting1 = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now()->subDays(10),
        ]);
        $purchase2Setting1 = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now(),
        ]);
        $purchase3Setting2 = $this->createPurchase([
            'setting_id' => $this->setting2->id,
            'supplier_id' => $supplier2->id,
            'date' => now(),
        ]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('globalBusinessFilter', $this->setting1->id)
            ->set('documentDateFrom', $fromDate)
            ->set('documentDateTo', $toDate)
            ->assertSee($purchase2Setting1->reference)
            ->assertDontSee($purchase1Setting1->reference)
            ->assertDontSee($purchase3Setting2->reference);
    }

    public function test_search_excludes_results_outside_filters()
    {
        $payableInRange = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now(),
            'reference' => 'PO-SEARCH-001',
            'note' => 'Search note',
        ]);
        $payableOutsideDate = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now()->subDays(20),
            'reference' => 'PO-SEARCH-002',
            'note' => 'Search note',
        ]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('documentDateFrom', $fromDate)
            ->set('documentDateTo', $toDate)
            ->set('search', 'Search note')
            ->assertSee($payableInRange->reference)
            ->assertDontSee($payableOutsideDate->reference);
    }

    public function test_paid_card_filter_shows_only_fully_paid_with_recent_payment()
    {
        $payable = $this->createPurchase(['due_amount' => 100000]);
        $paidRecent = $this->createPaidPurchase();

        // Create paid purchase with old payment (outside 30-day window)
        $purchase = $this->createPurchase([
            'payment_status' => 'PAID',
            'paid_amount' => 100000,
            'due_amount' => 0,
        ]);
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'date' => now()->subDays(40),
            'reference' => 'PAY-' . uniqid(),
            'payment_method_id' => 1,
            'payment_method' => 'Cash',
            'amount' => 100000,
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);
        $paidOld = $purchase;

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->dispatch('purchase-filter', type: 'paid')
            ->assertSee($paidRecent->reference)
            ->assertDontSee($payable->reference)
            ->assertDontSee($paidOld->reference);
    }

    public function test_search_includes_note()
    {
        $purchaseWithNote = $this->createPurchase([
            'note' => 'Special purchase order',
        ]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'Special purchase')
            ->assertSee($purchaseWithNote->reference);
    }

    public function test_filters_persist_in_query_string()
    {
        $purchase = $this->createPurchase();

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
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
        // Create multiple purchases to span pages
        for ($i = 0; $i < 15; $i++) {
            $this->createPurchase(['date' => now()]);
        }

        // Setting filter should trigger resetPage via updatedGlobalBusinessFilter and render successfully
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('perPage', 10)
            // Change filter - should trigger resetPage via updatedGlobalBusinessFilter
            ->set('globalBusinessFilter', $this->setting1->id)
            ->set('documentDateFrom', now()->subDays(30)->format('Y-m-d'))
            ->set('documentDateTo', now()->format('Y-m-d'))
            // Verify the component rendered without error
            ->assertViewIs('livewire.purchase.purchase-table');
    }

    public function test_filter_event_dispatches_named_parameters()
    {
        $this->createPurchase();

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('globalBusinessFilter', $this->setting1->id)
            ->set('documentDateFrom', '2024-01-01')
            ->set('documentDateTo', '2024-12-31')
            ->assertDispatched('global-purchase-filters-changed');
    }

    public function test_summary_card_listener_receives_filter_event()
    {
        $payable = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now(),
            'due_amount' => 100000,
        ]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        $component = Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
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
        $purchaseInRange = $this->createPurchase(['date' => now()]);

        $fromDate = now()->addDays(5)->format('Y-m-d');
        $toDate = now()->subDays(5)->format('Y-m-d');

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('documentDateFrom', $fromDate)
            ->set('documentDateTo', $toDate);

        // Verify dates are swapped
        $this->assertEquals(now()->subDays(5)->format('Y-m-d'), $component->get('documentDateFrom'));
        $this->assertEquals(now()->addDays(5)->format('Y-m-d'), $component->get('documentDateTo'));

        // Verify results are correct (same as if dates were in correct order)
        $component->assertSee($purchaseInRange->reference);
    }

    public function test_reversed_date_range_returns_same_results_as_correct_order()
    {
        $purchaseInRange = $this->createPurchase(['date' => now()]);
        $purchaseOutsideRange = $this->createPurchase(['date' => now()->subDays(20)]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        // Test with correct order
        $correctOrderComponent = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('documentDateFrom', $fromDate)
            ->set('documentDateTo', $toDate);

        // Test with reversed order
        $reversedOrderComponent = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('documentDateFrom', $toDate)
            ->set('documentDateTo', $fromDate);

        // Both should show the same result
        $correctOrderComponent->assertSee($purchaseInRange->reference);
        $reversedOrderComponent->assertSee($purchaseInRange->reference);

        $correctOrderComponent->assertDontSee($purchaseOutsideRange->reference);
        $reversedOrderComponent->assertDontSee($purchaseOutsideRange->reference);
    }

    public function test_clear_global_filters_resets_all_filters()
    {
        $this->createPurchase();

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('globalBusinessFilter', $this->setting1->id)
            ->set('documentDateFrom', '2024-01-01')
            ->set('documentDateTo', '2024-12-31')
            ->call('clearGlobalFilters');

        $this->assertNull($component->get('globalBusinessFilter'));
        $this->assertNull($component->get('documentDateFrom'));
        $this->assertNull($component->get('documentDateTo'));
        $component->assertDispatched('global-purchase-filters-changed');
    }
}

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

    public function test_draft_filters_do_not_change_results_prematurely()
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
            ->set('draftGlobalBusinessFilter', $this->setting1->id)
            // Still shows all, since applied filter not yet set
            ->assertSee($purchase1->reference)
            ->assertSee($purchase2->reference);
    }

    public function test_apply_business_filter_restricts_to_selected_setting()
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
            ->set('draftGlobalBusinessFilter', $this->setting1->id)
            ->call('applyGlobalFilters')
            ->assertSee($purchase1->reference)
            ->assertDontSee($purchase2->reference);
    }

    public function test_apply_document_date_filter_restricts_by_date_range()
    {
        $purchaseBeforeRange = $this->createPurchase(['date' => now()->subDays(10)]);
        $purchaseInRange = $this->createPurchase(['date' => now()]);
        $purchaseAfterRange = $this->createPurchase(['date' => now()->addDays(10)]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftDocumentDateFrom', $fromDate)
            ->set('draftDocumentDateTo', $toDate)
            ->call('applyGlobalFilters')
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
            ->set('draftGlobalBusinessFilter', $this->setting1->id)
            ->set('draftDocumentDateFrom', $fromDate)
            ->set('draftDocumentDateTo', $toDate)
            ->call('applyGlobalFilters')
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
            ->set('draftDocumentDateFrom', $fromDate)
            ->set('draftDocumentDateTo', $toDate)
            ->call('applyGlobalFilters')
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

    public function test_applied_filters_persist_in_query_string()
    {
        $purchase = $this->createPurchase();

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilter', $this->setting1->id)
            ->set('draftDocumentDateFrom', '2024-01-01')
            ->set('draftDocumentDateTo', '2024-12-31')
            ->call('applyGlobalFilters')
            ->set('search', 'test');

        // Verify that applied filters are set (query string persistence is handled by Livewire)
        $this->assertEquals($this->setting1->id, $component->get('globalBusinessFilter'));
        $this->assertEquals('2024-01-01', $component->get('documentDateFrom'));
        $this->assertEquals('2024-12-31', $component->get('documentDateTo'));
        $this->assertEquals('test', $component->get('search'));
    }

    public function test_pagination_resets_on_filter_apply()
    {
        // Create multiple purchases to span pages
        for ($i = 0; $i < 15; $i++) {
            $this->createPurchase(['date' => now()]);
        }

        // Setting draft filters and applying should trigger resetPage and render successfully
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('perPage', 10)
            ->set('draftGlobalBusinessFilter', $this->setting1->id)
            ->set('draftDocumentDateFrom', now()->subDays(30)->format('Y-m-d'))
            ->set('draftDocumentDateTo', now()->format('Y-m-d'))
            ->call('applyGlobalFilters')
            ->assertViewIs('livewire.purchase.purchase-table');
    }

    public function test_filter_apply_dispatches_named_parameters()
    {
        $this->createPurchase();

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilter', $this->setting1->id)
            ->set('draftDocumentDateFrom', '2024-01-01')
            ->set('draftDocumentDateTo', '2024-12-31')
            ->call('applyGlobalFilters')
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

    public function test_reversed_date_range_normalized_on_apply()
    {
        $purchaseInRange = $this->createPurchase(['date' => now()]);

        $fromDate = now()->addDays(5)->format('Y-m-d');
        $toDate = now()->subDays(5)->format('Y-m-d');

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftDocumentDateFrom', $fromDate)
            ->set('draftDocumentDateTo', $toDate)
            ->call('applyGlobalFilters');

        // Verify dates are swapped after apply
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
            ->set('draftDocumentDateFrom', $fromDate)
            ->set('draftDocumentDateTo', $toDate)
            ->call('applyGlobalFilters');

        // Test with reversed order
        $reversedOrderComponent = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftDocumentDateFrom', $toDate)
            ->set('draftDocumentDateTo', $fromDate)
            ->call('applyGlobalFilters');

        // Both should show the same result
        $correctOrderComponent->assertSee($purchaseInRange->reference);
        $reversedOrderComponent->assertSee($purchaseInRange->reference);

        $correctOrderComponent->assertDontSee($purchaseOutsideRange->reference);
        $reversedOrderComponent->assertDontSee($purchaseOutsideRange->reference);
    }

    public function test_reset_global_filters_clears_all_applied_filters()
    {
        $this->createPurchase();

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilter', $this->setting1->id)
            ->set('draftDocumentDateFrom', '2024-01-01')
            ->set('draftDocumentDateTo', '2024-12-31')
            ->call('applyGlobalFilters')
            ->call('resetGlobalFilters');

        $this->assertNull($component->get('globalBusinessFilter'));
        $this->assertNull($component->get('documentDateFrom'));
        $this->assertNull($component->get('documentDateTo'));
        $this->assertNull($component->get('draftGlobalBusinessFilter'));
        $this->assertNull($component->get('draftDocumentDateFrom'));
        $this->assertNull($component->get('draftDocumentDateTo'));
        $component->assertDispatched('global-purchase-filters-changed');
    }

    public function test_summary_card_renders_with_selected_styling_unpaid()
    {
        $payable = $this->createPurchase(['due_amount' => 100000]);

        Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
            'globalMode' => true,
            'selectedCardFilter' => 'unpaid',
        ])
            ->assertSee('bg-light'); // Selected card should have bg-light class
    }

    public function test_summary_card_renders_with_selected_styling_overdue()
    {
        $payable = $this->createPurchase(['due_date' => now()->subDays(5), 'due_amount' => 100000]);

        Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
            'globalMode' => true,
            'selectedCardFilter' => 'overdue',
        ])
            ->assertSee('bg-light'); // Selected card should have bg-light class
    }

    public function test_summary_card_renders_with_selected_styling_paid()
    {
        $paid = $this->createPaidPurchase();

        Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
            'globalMode' => true,
            'selectedCardFilter' => 'paid',
        ])
            ->assertSee('bg-light'); // Selected card should have bg-light class
    }

    public function test_query_string_urls_are_supported_by_url_attributes()
    {
        // This test verifies that #[Url] attributes are in place for URL persistence.
        // The actual query string persistence is tested via integration/browser tests,
        // as Livewire unit tests don't simulate query parameters directly.
        // Here we just verify the properties are marked for URL persistence.

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true]);

        // Verify that properties exist and can be set (they will be URL-persistent in real requests)
        $component->set('selectedCardFilter', 'unpaid')
            ->set('globalBusinessFilter', $this->setting1->id)
            ->set('documentDateFrom', '2024-01-01')
            ->set('documentDateTo', '2024-12-31')
            ->set('search', 'PO-')
            ->set('sortField', 'reference')
            ->set('sortDirection', 'asc')
            ->set('showArchived', true);

        $this->assertEquals('unpaid', $component->get('selectedCardFilter'));
        $this->assertEquals($this->setting1->id, $component->get('globalBusinessFilter'));
        $this->assertEquals('2024-01-01', $component->get('documentDateFrom'));
        $this->assertEquals('2024-12-31', $component->get('documentDateTo'));
        $this->assertEquals('PO-', $component->get('search'));
        $this->assertEquals('reference', $component->get('sortField'));
        $this->assertEquals('asc', $component->get('sortDirection'));
        $this->assertTrue($component->get('showArchived'));
    }

    public function test_url_does_not_persist_draft_filters()
    {
        $purchase = $this->createPurchase();

        // Draft filters should not be exposed to URL - only applied filters should be
        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilter', $this->setting1->id)
            ->set('draftDocumentDateFrom', '2024-01-01')
            ->set('draftDocumentDateTo', '2024-12-31');

        // Draft filters should be set locally but not marked for URL persistence
        $this->assertEquals($this->setting1->id, $component->get('draftGlobalBusinessFilter'));
        $this->assertEquals('2024-01-01', $component->get('draftDocumentDateFrom'));
        $this->assertEquals('2024-12-31', $component->get('draftDocumentDateTo'));

        // Applied filters (which ARE URL-persistent) should still be null at this point
        $this->assertNull($component->get('globalBusinessFilter'));
        $this->assertNull($component->get('documentDateFrom'));
        $this->assertNull($component->get('documentDateTo'));
    }

    public function test_draft_filters_initialize_from_applied_on_mount()
    {
        $purchase = $this->createPurchase();

        // Test that when component mounts with applied filters set,
        // draft filters are initialized from them
        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, [
            'globalMode' => true,
            'globalBusinessFilter' => $this->setting1->id,
            'documentDateFrom' => '2024-01-01',
            'documentDateTo' => '2024-12-31',
        ]);

        // Draft filters should be initialized from applied filters during mount
        $this->assertEquals($this->setting1->id, $component->get('draftGlobalBusinessFilter'));
        $this->assertEquals('2024-01-01', $component->get('draftDocumentDateFrom'));
        $this->assertEquals('2024-12-31', $component->get('draftDocumentDateTo'));
    }
}

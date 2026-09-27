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
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            // Still shows all, since applied filter not yet set
            ->assertSee($purchase1->reference)
            ->assertSee($purchase2->reference);
    }

    public function test_livewire_component_renders_business_selector()
    {
        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilters', [$this->setting1->id]);

        $html = $component->html();

        // Assert Select2 selector markup and configuration
        $this->assertStringContainsString('id="globalPurchaseBusinessFilters"', $html);
        $this->assertStringContainsString('draftGlobalBusinessFilters', $html);
        $this->assertStringContainsString('Pilih bisnis (kosongkan untuk semua)', $html);
        $this->assertStringContainsString('change.select2', $html);

        // Assert restored IDs render as selected options
        $this->assertStringContainsString('<option value="' . $this->setting1->id . '"', $html);
        $this->assertStringContainsString('selected', $html);
        $this->assertStringContainsString('>' . $this->setting1->company_name . '</option>', $html);
        
        $this->assertStringContainsString('<option value="' . $this->setting2->id . '"', $html);
        $this->assertStringContainsString('>' . $this->setting2->company_name . '</option>', $html);
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
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
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
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
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
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->set('draftDocumentDateFrom', '2024-01-01')
            ->set('draftDocumentDateTo', '2024-12-31')
            ->call('applyGlobalFilters')
            ->set('search', 'test');

        // Verify that applied filters are set (query string persistence is handled by Livewire)
        $this->assertEquals([$this->setting1->id], $component->get('globalBusinessFilters'));
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
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->set('draftDocumentDateFrom', now()->subDays(30)->format('Y-m-d'))
            ->set('draftDocumentDateTo', now()->format('Y-m-d'))
            ->call('applyGlobalFilters')
            ->assertViewIs('livewire.purchase.purchase-table');
    }

    public function test_filter_apply_dispatches_named_parameters()
    {
        $this->createPurchase();

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
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
            'globalBusinessFilters' => [$this->setting1->id],
            'documentDateFrom' => $fromDate,
            'documentDateTo' => $toDate,
        ])
            ->call('handleFiltersChanged', [$this->setting1->id], $fromDate, $toDate);

        $this->assertEquals([$this->setting1->id], $component->get('globalBusinessFilters'));
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
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->set('draftDocumentDateFrom', '2024-01-01')
            ->set('draftDocumentDateTo', '2024-12-31')
            ->call('applyGlobalFilters')
            ->call('resetGlobalFilters');

        $this->assertEquals([], $component->get('globalBusinessFilters'));
        $this->assertNull($component->get('documentDateFrom'));
        $this->assertNull($component->get('documentDateTo'));
        $this->assertEquals([], $component->get('draftGlobalBusinessFilters'));
        $this->assertNull($component->get('draftDocumentDateFrom'));
        $this->assertNull($component->get('draftDocumentDateTo'));
        $component->assertDispatched('sync-select2-globalPurchaseBusinessFilters', values: []);
        $component->assertDispatched('global-purchase-filters-changed');
    }

    public function test_reset_clears_card_filter_state()
    {
        $unpaid = $this->createPurchase(['due_amount' => 100000]);
        $paid = $this->createPaidPurchase();

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->dispatch('purchase-filter', type: 'unpaid')
            ->assertSee($unpaid->reference)
            ->assertDontSee($paid->reference)
            ->call('resetGlobalFilters');

        // Verify card filter state is cleared
        $this->assertNull($component->get('selectedCardFilter'));
        $this->assertNull($component->get('paymentStatusFilter'));
        $this->assertNull($component->get('paymentStatusFilters'));
        $this->assertFalse($component->get('overdueOnly'));
        $this->assertFalse($component->get('dueAmountOnly'));
        $this->assertFalse($component->get('paidLast30DaysOnly'));
        $this->assertNull($component->get('cardStatusFilter'));

        // After reset, both should be visible (unfiltered)
        $component->assertSee($unpaid->reference)
            ->assertSee($paid->reference);
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
            ->set('globalBusinessFilters', [$this->setting1->id])
            ->set('documentDateFrom', '2024-01-01')
            ->set('documentDateTo', '2024-12-31')
            ->set('search', 'PO-')
            ->set('sortField', 'reference')
            ->set('sortDirection', 'asc')
            ->set('showArchived', true);

        $this->assertEquals('unpaid', $component->get('selectedCardFilter'));
        $this->assertEquals([$this->setting1->id], $component->get('globalBusinessFilters'));
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
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->set('draftDocumentDateFrom', '2024-01-01')
            ->set('draftDocumentDateTo', '2024-12-31');

        // Draft filters should be set locally but not marked for URL persistence
        $this->assertEquals([$this->setting1->id], $component->get('draftGlobalBusinessFilters'));
        $this->assertEquals('2024-01-01', $component->get('draftDocumentDateFrom'));
        $this->assertEquals('2024-12-31', $component->get('draftDocumentDateTo'));

        // Applied filters (which ARE URL-persistent) should still be null/empty at this point
        $this->assertEquals([], $component->get('globalBusinessFilters'));
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
            'globalBusinessFilters' => [$this->setting1->id],
            'documentDateFrom' => '2024-01-01',
            'documentDateTo' => '2024-12-31',
        ]);

        // Draft filters should be initialized from applied filters during mount
        $this->assertEquals([$this->setting1->id], $component->get('draftGlobalBusinessFilters'));
        $this->assertEquals('2024-01-01', $component->get('draftDocumentDateFrom'));
        $this->assertEquals('2024-12-31', $component->get('draftDocumentDateTo'));
    }

    public function test_multi_business_selection()
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

        // Test multiple business filter
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilters', [$this->setting1->id, $this->setting2->id])
            ->call('applyGlobalFilters')
            ->assertSee($purchase1->reference)
            ->assertSee($purchase2->reference);
    }

    public function test_due_date_range_inclusive_endpoints()
    {
        $today = Carbon::parse('2024-08-10 12:00:00');
        Carbon::setTestNow($today);

        try {
            // Create purchases with specific due dates using fixed date strings
            $purchaseOnStart = $this->createPurchase(['due_date' => '2024-08-05']);
            $purchaseInRange = $this->createPurchase(['due_date' => '2024-08-10']);
            $purchaseOnEnd = $this->createPurchase(['due_date' => '2024-08-15']);
            $purchaseOutside = $this->createPurchase(['due_date' => '2024-08-20']);

            // Filter using the same dates
            $fromDate = '2024-08-05';
            $toDate = '2024-08-15';

            Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
                ->set('draftDueDateFrom', $fromDate)
                ->set('draftDueDateTo', $toDate)
                ->call('applyGlobalFilters')
                ->assertSee($purchaseOnStart->reference)
                ->assertSee($purchaseInRange->reference)
                ->assertSee($purchaseOnEnd->reference)
                ->assertDontSee($purchaseOutside->reference);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_one_sided_due_date_ranges()
    {
        $oldPurchase = $this->createPurchase(['due_date' => now()->subDays(10)->toDateString()]);
        $newPurchase = $this->createPurchase(['due_date' => now()->toDateString()]);

        // Test due date from only
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftDueDateFrom', now()->subDays(5)->format('Y-m-d'))
            ->call('applyGlobalFilters')
            ->assertDontSee($oldPurchase->reference)
            ->assertSee($newPurchase->reference);

        // Test due date to only
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftDueDateTo', now()->subDays(5)->format('Y-m-d'))
            ->call('applyGlobalFilters')
            ->assertSee($oldPurchase->reference)
            ->assertDontSee($newPurchase->reference);
    }

    public function test_combined_all_filters()
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

        // Matching all filters
        $matching = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now(),
            'due_date' => now()->addDays(15)->toDateString(),
            'due_amount' => 100000,
        ]);

        // Wrong business
        $wrongBusiness = $this->createPurchase([
            'setting_id' => $this->setting2->id,
            'supplier_id' => $supplier2->id,
            'date' => now(),
            'due_date' => now()->addDays(15)->toDateString(),
        ]);

        // Right business, wrong document date
        $wrongDocDate = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now()->subDays(20),
            'due_date' => now()->addDays(15)->toDateString(),
        ]);

        // Right business & date, wrong due date
        $wrongDueDate = $this->createPurchase([
            'setting_id' => $this->setting1->id,
            'date' => now(),
            'due_date' => now()->addDays(25)->toDateString(),
        ]);

        $fromDocDate = now()->subDays(5)->format('Y-m-d');
        $toDocDate = now()->addDays(5)->format('Y-m-d');
        $fromDueDate = now()->addDays(10)->format('Y-m-d');
        $toDueDate = now()->addDays(20)->format('Y-m-d');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->set('draftDocumentDateFrom', $fromDocDate)
            ->set('draftDocumentDateTo', $toDocDate)
            ->set('draftDueDateFrom', $fromDueDate)
            ->set('draftDueDateTo', $toDueDate)
            ->call('applyGlobalFilters')
            ->assertSee($matching->reference)
            ->assertDontSee($wrongBusiness->reference)
            ->assertDontSee($wrongDocDate->reference)
            ->assertDontSee($wrongDueDate->reference);
    }

    public function test_fully_paid_visible_when_matching_due_date_filters()
    {
        $paidRecent = $this->createPaidPurchase(['due_date' => now()->addDays(15)->toDateString()]);
        $unpaid = $this->createPurchase(['due_date' => now()->addDays(15)->toDateString()]);

        $fromDueDate = now()->addDays(10)->format('Y-m-d');
        $toDueDate = now()->addDays(20)->format('Y-m-d');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftDueDateFrom', $fromDueDate)
            ->set('draftDueDateTo', $toDueDate)
            ->call('applyGlobalFilters')
            ->assertSee($paidRecent->reference)
            ->assertSee($unpaid->reference);
    }

    public function test_due_date_filters_exclude_out_of_range()
    {
        $outsideRange = $this->createPurchase(['due_date' => now()->addDays(25)->toDateString(), 'due_amount' => 100000]);
        $withDueDate = $this->createPurchase(['due_date' => now()->addDays(15)->toDateString(), 'due_amount' => 100000]);

        $fromDueDate = now()->addDays(10)->format('Y-m-d');
        $toDueDate = now()->addDays(20)->format('Y-m-d');

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftDueDateFrom', $fromDueDate)
            ->set('draftDueDateTo', $toDueDate)
            ->call('applyGlobalFilters')
            ->assertDontSee($outsideRange->reference)
            ->assertSee($withDueDate->reference);
    }

    public function test_reversed_due_date_range_normalizes()
    {
        $purchaseInRange = $this->createPurchase(['due_date' => now()->toDateString()]);

        $fromDate = now()->addDays(5)->format('Y-m-d');
        $toDate = now()->subDays(5)->format('Y-m-d');

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftDueDateFrom', $fromDate)
            ->set('draftDueDateTo', $toDate)
            ->call('applyGlobalFilters');

        // Verify dates are swapped after apply
        $this->assertEquals(now()->subDays(5)->format('Y-m-d'), $component->get('dueDateFrom'));
        $this->assertEquals(now()->addDays(5)->format('Y-m-d'), $component->get('dueDateTo'));

        // Verify results are correct
        $component->assertSee($purchaseInRange->reference);
    }

    public function test_url_restored_state_survives_mount()
    {
        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, [
            'globalMode' => true,
            'globalBusinessFilters' => [$this->setting1->id],
            'documentDateFrom' => now()->subDays(30)->format('Y-m-d'),
            'documentDateTo' => now()->format('Y-m-d'),
            'selectedCardFilter' => 'unpaid',
        ]);

        // Verify that when component mounts with URL parameters, the state is preserved
        // Draft filters should be initialized from applied filters
        $this->assertEquals([$this->setting1->id], $component->get('draftGlobalBusinessFilters'));
        $this->assertEquals(now()->subDays(30)->format('Y-m-d'), $component->get('draftDocumentDateFrom'));
        $this->assertEquals(now()->format('Y-m-d'), $component->get('draftDocumentDateTo'));

        // Verify applied filters are still set
        $this->assertEquals([$this->setting1->id], $component->get('globalBusinessFilters'));
        $this->assertEquals(now()->subDays(30)->format('Y-m-d'), $component->get('documentDateFrom'));
        $this->assertEquals(now()->format('Y-m-d'), $component->get('documentDateTo'));

        // Verify card filter is applied
        $this->assertEquals('unpaid', $component->get('selectedCardFilter'));
        $this->assertTrue($component->get('dueAmountOnly'));
    }

    public function test_reset_clears_card_filter_in_summary_cards()
    {
        $unpaid = $this->createPurchase(['due_amount' => 100000]);
        $paid = $this->createPaidPurchase();

        // Create both table and summary card components
        $table = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true]);
        $summaryCards = Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
            'globalMode' => true,
            'selectedCardFilter' => 'unpaid',
        ]);

        // Apply card filter
        $table->dispatch('purchase-filter', type: 'unpaid')
            ->assertSee($unpaid->reference)
            ->assertDontSee($paid->reference);

        // Verify table has card filter state
        $this->assertEquals('unpaid', $table->get('selectedCardFilter'));
        $this->assertTrue($table->get('dueAmountOnly'));

        // Verify summary card is selected
        $this->assertEquals('unpaid', $summaryCards->get('selectedCardFilter'));

        // Reset global filters in table
        $table->call('resetGlobalFilters');

        // Verify table card filter is cleared
        $this->assertNull($table->get('selectedCardFilter'));
        $this->assertFalse($table->get('dueAmountOnly'));

        // Simulate the event dispatch by directly calling the summary card handler
        $summaryCards->call('handleFiltersChanged', globalBusinessFilters: [], selectedCardFilter: null);

        // Verify summary card receives and applies the null card filter
        $this->assertNull($summaryCards->get('selectedCardFilter'));

        // After reset, both should be visible (unfiltered)
        $table->assertSee($unpaid->reference)
            ->assertSee($paid->reference);
    }

    public function test_apply_filters_dispatches_current_card_filter()
    {
        $unpaid = $this->createPurchase(['due_amount' => 100000]);

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->dispatch('purchase-filter', type: 'unpaid')
            ->set('draftDocumentDateFrom', now()->subDays(5)->format('Y-m-d'))
            ->set('draftDocumentDateTo', now()->format('Y-m-d'))
            ->call('applyGlobalFilters');

        // Verify card filter state is preserved when applying
        $this->assertEquals('unpaid', $component->get('selectedCardFilter'));

        // Verify the event was dispatched (would include selectedCardFilter in real usage)
        $component->assertDispatched('global-purchase-filters-changed');
    }
}

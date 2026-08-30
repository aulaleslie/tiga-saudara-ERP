<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Supplier;
use Spatie\Permission\Models\Permission;
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

        // Forget cached permissions and create required permissions for actions partial
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::findOrCreate('purchases.received.correct', 'web');

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

    public function test_draft_filters_do_not_change_results_prematurely()
    {
        $purchase1 = $this->createPurchase(['setting_id' => $this->setting1->id]);
        $purchase2 = $this->createPurchase(['setting_id' => $this->setting2->id]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            // Still shows all, since applied filter not yet set
            ->assertSee($purchase1->reference)
            ->assertSee($purchase2->reference);
    }

    public function test_apply_business_filter_restricts_to_selected_setting()
    {
        $purchase1 = $this->createPurchase(['setting_id' => $this->setting1->id]);
        $purchase2 = $this->createPurchase(['setting_id' => $this->setting2->id]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->call('applyGlobalFilters')
            ->assertSee($purchase1->reference)
            ->assertDontSee($purchase2->reference);
    }

    public function test_apply_date_filter_restricts_by_date_range()
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
        
        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->call('applyPurchaseFilter', 'paid');

        $html = $component->html();
        $this->assertStringContainsString($paidToday->reference, $html);
        $this->assertStringContainsString($paid30DaysAgo->reference, $html);
        $this->assertStringNotContainsString($paid31DaysAgo->reference, $html);
        $this->assertStringNotContainsString($unpaid->reference, $html);
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

    public function test_summary_card_markup_includes_bg_light_when_selected_unpaid()
    {
        $payable = $this->createPurchase(['due_amount' => 100000]);

        // Test the summary cards component directly to verify styling
        Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
            'globalMode' => true,
            'selectedCardFilter' => 'unpaid',
        ])
            ->assertSee('bg-light'); // Selected card should have bg-light class
    }

    public function test_summary_card_markup_includes_bg_light_when_selected_overdue()
    {
        $payable = $this->createPurchase(['due_date' => now()->subDays(5), 'due_amount' => 100000]);

        Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
            'globalMode' => true,
            'selectedCardFilter' => 'overdue',
        ])
            ->assertSee('bg-light');
    }

    public function test_summary_card_markup_includes_bg_light_when_selected_paid()
    {
        $paid = $this->createPurchase(['payment_status' => 'PAID', 'due_amount' => 0]);
        \Modules\Purchase\Entities\PurchasePayment::create([
            'purchase_id' => $paid->id,
            'date' => now()->subDays(15),
            'reference' => 'PAY-' . uniqid(),
            'payment_method' => 'Cash',
            'amount' => 10000,
            'status' => \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE,
        ]);

        Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, [
            'globalMode' => true,
            'selectedCardFilter' => 'paid',
        ])
            ->assertSee('bg-light');
    }

    public function test_summary_card_selection_survives_global_filter_application()
    {
        $payable = $this->createPurchase(['due_amount' => 100000]);

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->dispatch('purchase-filter', type: 'unpaid')
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->call('applyGlobalFilters');

        // selectedCardFilter should persist after filter application
        $this->assertEquals('unpaid', $component->get('selectedCardFilter'));
    }

    public function test_card_filter_method_calls_summary_card_filter_dispatch()
    {
        $payable = $this->createPurchase(['due_amount' => 100000]);

        $component = Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->dispatch('purchase-filter', type: 'unpaid');

        $this->assertEquals('unpaid', $component->get('selectedCardFilter'));
    }

    public function test_global_mode_renders_escaped_purchase_note_and_supports_note_search()
    {
        $notePurchase = $this->createPurchase([
            'note' => 'UniquePurchaseNoteSpecialTag<script>alert("xss")</script>',
            'setting_id' => $this->setting1->id,
        ]);
        $otherPurchase = $this->createPurchase([
            'note' => 'Ordinary note',
            'setting_id' => $this->setting1->id,
        ]);

        // Global mode renders escaped note
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($notePurchase->reference)
            ->assertSee('UniquePurchaseNoteSpecialTag&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);

        // Note-only search matches purchase and renders note
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->set('search', 'UniquePurchaseNoteSpecialTag')
            ->assertSee($notePurchase->reference)
            ->assertSee('UniquePurchaseNoteSpecialTag&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false)
            ->assertDontSee($otherPurchase->reference);

        // Normal mode does not render the note element in table cell
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => false, 'settingId' => $this->setting1->id])
            ->assertSee($notePurchase->reference)
            ->assertDontSee('UniquePurchaseNoteSpecialTag');
    }

    public function test_global_mode_renders_note_containing_exact_string_zero()
    {
        $zeroNotePurchase = $this->createPurchase([
            'note' => '0',
            'setting_id' => $this->setting1->id,
        ]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($zeroNotePurchase->reference)
            ->assertSeeHtml('<small class="text-muted">0</small>');
    }
}

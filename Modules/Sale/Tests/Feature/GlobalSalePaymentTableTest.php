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

class GlobalSalePaymentTableTest extends TestCase
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

        \Illuminate\Support\Facades\Gate::define('salePayments.global.access', function (?\App\Models\User $user = null) {
            return true;
        });
    }

    private function createSale($overrides = [])
    {
        $customer = Customer::factory()->create();

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
            'setting_id' => $this->setting1->id,
        ], $overrides));
    }

    public function test_global_mode_shows_sales_across_all_settings()
    {
        $sale1 = $this->createSale(['setting_id' => $this->setting1->id]);
        $sale2 = $this->createSale(['setting_id' => $this->setting2->id]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($sale1->reference)
            ->assertSee($sale2->reference);
    }

    public function test_normal_mode_hides_sales_from_other_settings()
    {
        $sale1 = $this->createSale(['setting_id' => $this->setting1->id]);
        $sale2 = $this->createSale(['setting_id' => $this->setting2->id]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => false, 'settingId' => $this->setting1->id])
            ->assertSee($sale1->reference)
            ->assertDontSee($sale2->reference . '</a>', false);
    }

    public function test_global_mode_only_shows_approved_status_by_default()
    {
        $approved = $this->createSale(['status' => Sale::STATUS_APPROVED]);
        $drafted = $this->createSale(['status' => Sale::STATUS_DRAFTED]);
        $pending = $this->createSale(['status' => Sale::STATUS_WAITING_APPROVAL]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($approved->reference)
            ->assertDontSee($drafted->reference)
            ->assertDontSee($pending->reference);
    }

    public function test_global_mode_shows_paid_and_unpaid_sales_by_default()
    {
        $unpaid = $this->createSale(['due_amount' => 10000, 'payment_status' => 'UNPAID']);
        $paid = $this->createSale(['due_amount' => 0, 'payment_status' => 'PAID']);

        SalePayment::create([
            'sale_id' => $paid->id,
            'amount' => 10000,
            'date' => now(),
            'reference' => 'PAY-123',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE
        ]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($unpaid->reference)
            ->assertSee($paid->reference);
    }

    public function test_draft_filters_do_not_change_results_prematurely()
    {
        $sale1 = $this->createSale(['setting_id' => $this->setting1->id]);
        $sale2 = $this->createSale(['setting_id' => $this->setting2->id]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            // Still shows all, since applied filter not yet set
            ->assertSee($sale1->reference)
            ->assertSee($sale2->reference);
    }

    public function test_apply_business_filter_restricts_to_selected_setting()
    {
        $sale1 = $this->createSale(['setting_id' => $this->setting1->id]);
        $sale2 = $this->createSale(['setting_id' => $this->setting2->id]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->call('applyGlobalFilters')
            ->assertSee($sale1->reference)
            ->assertDontSee($sale2->reference);
    }

    public function test_apply_date_filter_restricts_by_date_range()
    {
        $saleBeforeRange = $this->createSale(['date' => now()->subDays(10)]);
        $saleInRange = $this->createSale(['date' => now()]);
        $saleAfterRange = $this->createSale(['date' => now()->addDays(10)]);

        $fromDate = now()->subDays(5)->format('Y-m-d');
        $toDate = now()->addDays(5)->format('Y-m-d');

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('draftDocumentDateFrom', $fromDate)
            ->set('draftDocumentDateTo', $toDate)
            ->call('applyGlobalFilters')
            ->assertSee($saleInRange->reference)
            ->assertDontSee($saleBeforeRange->reference)
            ->assertDontSee($saleAfterRange->reference);
    }

    public function test_global_mode_shows_paid_sales_when_filter_applied_with_date_boundaries()
    {
        $unpaid = $this->createSale(['due_amount' => 10000, 'payment_status' => 'UNPAID']);

        // 1. Paid today (should show)
        $paidToday = $this->createSale(['due_amount' => 0, 'payment_status' => 'PAID']);
        SalePayment::create([
            'sale_id' => $paidToday->id,
            'amount' => 10000,
            'date' => Carbon::today(),
            'reference' => 'PAY-1',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE
        ]);

        // 2. Paid exactly 30 days ago (should show)
        $paid30DaysAgo = $this->createSale(['due_amount' => 0, 'payment_status' => 'PAID']);
        SalePayment::create([
            'sale_id' => $paid30DaysAgo->id,
            'amount' => 10000,
            'date' => Carbon::today()->subDays(30),
            'reference' => 'PAY-2',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE
        ]);

        // 3. Paid exactly 31 days ago (should NOT show)
        $paid31DaysAgo = $this->createSale(['due_amount' => 0, 'payment_status' => 'PAID']);
        SalePayment::create([
            'sale_id' => $paid31DaysAgo->id,
            'amount' => 10000,
            'date' => Carbon::today()->subDays(31),
            'reference' => 'PAY-3',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE
        ]);

        $component = Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->call('applySaleFilter', 'paid');

        $html = $component->html();
        $this->assertStringContainsString($paidToday->reference, $html);
        $this->assertStringContainsString($paid30DaysAgo->reference, $html);
        $this->assertStringNotContainsString($paid31DaysAgo->reference, $html);
        $this->assertStringNotContainsString($unpaid->reference, $html);
    }

    public function test_global_mode_excludes_archived_sales()
    {
        $active = $this->createSale();
        $archived = $this->createSale();
        $archived->delete(); // Soft delete for archival

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($active->reference)
            ->assertDontSee($archived->reference);
    }

    public function test_unauthorized_user_cannot_access_global_mode()
    {
        // Deny access
        \Illuminate\Support\Facades\Gate::define('salePayments.global.access', function (?\App\Models\User $user = null) {
            return false;
        });

        // Test component mounting throws 403
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertForbidden();
    }

    public function test_summary_card_markup_includes_bg_light_when_selected_unpaid()
    {
        $payable = $this->createSale(['due_amount' => 100000]);

        // Test the summary cards component directly to verify styling
        Livewire::test(\Modules\Sale\Livewire\SaleSummaryCards::class, [
            'globalMode' => true,
            'selectedCardFilter' => 'unpaid',
        ])
            ->assertSee('bg-light'); // Selected card should have bg-light class
    }

    public function test_summary_card_markup_includes_bg_light_when_selected_overdue()
    {
        $payable = $this->createSale(['due_date' => now()->subDays(5), 'due_amount' => 100000]);

        Livewire::test(\Modules\Sale\Livewire\SaleSummaryCards::class, [
            'globalMode' => true,
            'selectedCardFilter' => 'overdue',
        ])
            ->assertSee('bg-light');
    }

    public function test_summary_card_markup_includes_bg_light_when_selected_paid()
    {
        $paid = $this->createSale(['payment_status' => 'PAID', 'due_amount' => 0]);
        SalePayment::create([
            'sale_id' => $paid->id,
            'date' => now()->subDays(15),
            'reference' => 'PAY-' . uniqid(),
            'payment_method' => 'Cash',
            'amount' => 10000,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        Livewire::test(\Modules\Sale\Livewire\SaleSummaryCards::class, [
            'globalMode' => true,
            'selectedCardFilter' => 'paid',
        ])
            ->assertSee('bg-light');
    }

    public function test_summary_card_selection_survives_global_filter_application()
    {
        $payable = $this->createSale(['due_amount' => 100000]);

        $component = Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->dispatch('sale-filter', type: 'unpaid')
            ->set('draftGlobalBusinessFilters', [$this->setting1->id])
            ->call('applyGlobalFilters');

        // selectedCardFilter should persist after filter application
        $this->assertEquals('unpaid', $component->get('selectedCardFilter'));
    }

    public function test_card_filter_method_calls_summary_card_filter_dispatch()
    {
        $payable = $this->createSale(['due_amount' => 100000]);

        $component = Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->dispatch('sale-filter', type: 'unpaid');

        $this->assertEquals('unpaid', $component->get('selectedCardFilter'));
    }

    public function test_global_mode_renders_escaped_sale_note_and_supports_note_search()
    {
        $noteSale = $this->createSale([
            'note' => 'UniqueSaleNoteSpecialTag<script>alert("xss")</script>',
            'setting_id' => $this->setting1->id,
        ]);
        $otherSale = $this->createSale([
            'note' => 'Ordinary note',
            'setting_id' => $this->setting1->id,
        ]);

        // Global mode renders escaped note
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($noteSale->reference)
            ->assertSee('UniqueSaleNoteSpecialTag&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);

        // Note-only search matches sale and renders note
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'UniqueSaleNoteSpecialTag')
            ->assertSee($noteSale->reference)
            ->assertSee('UniqueSaleNoteSpecialTag&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false)
            ->assertDontSee($otherSale->reference);

        // Normal mode renders escaped note in Catatan column
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => false, 'settingId' => $this->setting1->id])
            ->assertSee($noteSale->reference)
            ->assertSee('UniqueSaleNoteSpecialTag&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);
    }

    public function test_global_mode_renders_note_containing_exact_string_zero()
    {
        $zeroNoteSale = $this->createSale([
            'note' => '0',
            'setting_id' => $this->setting1->id,
        ]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($zeroNoteSale->reference)
            ->assertSeeHtml('<div class="document-note-container text-muted small"')
            ->assertSee('<span>0</span>', false);
    }

    public function test_global_mode_renders_long_or_multiline_sale_note_preview_with_expansion_controls()
    {
        $longText = str_repeat('A', 130);
        $longSale = $this->createSale([
            'note' => $longText,
            'setting_id' => $this->setting1->id,
        ]);

        $multilineText = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5";
        $multilineSale = $this->createSale([
            'note' => $multilineText,
            'setting_id' => $this->setting1->id,
        ]);

        $blankSale = $this->createSale([
            'note' => '',
            'setting_id' => $this->setting1->id,
        ]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($longSale->reference)
            ->assertSee(str_repeat('A', 120) . '...', false)
            ->assertSee('Lihat selengkapnya')
            ->assertSee('Tampilkan lebih sedikit')
            ->assertSee(':aria-expanded="expanded ? \'true\' : \'false\'"', false)
            ->assertSee('aria-controls="sale-note-' . $longSale->id . '-preview sale-note-' . $longSale->id . '-full"', false)
            ->assertSee($multilineSale->reference)
            ->assertSee("Line 1\nLine 2\nLine 3...", false)
            ->assertSee($blankSale->reference);
    }

    public function test_global_mode_whitespace_only_sale_note_renders_blank_placeholder()
    {
        $whitespaceSale = $this->createSale([
            'note' => "   \n  \t ",
            'setting_id' => $this->setting1->id,
        ]);

        $html = Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($whitespaceSale->reference)
            ->assertDontSeeHtml('<div class="document-note-container')
            ->html();

        $this->assertMatchesRegularExpression('/<td class="document-note-cell">[\s\S]*?<span class="text-muted">-<\/span>[\s\S]*?<\/td>/s', $html);
    }

    public function test_sale_table_renders_dedicated_catatan_column_after_ref_for_normal_and_global_modes()
    {
        $sale = $this->createSale([
            'note' => 'Header note for column test',
            'setting_id' => $this->setting1->id,
        ]);

        foreach ([true, false] as $isGlobal) {
            $html = Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => $isGlobal, 'settingId' => $this->setting1->id])->html();

            // Header position: Ref followed by Catatan
            $this->assertMatchesRegularExpression('/<th[^>]*>\s*Ref.*<\/th>\s*<th[^>]*>\s*Catatan\s*<\/th>/s', $html);

            // Note is in dedicated document-note-cell immediately following the reference td, and not inside the reference anchor tag
            $this->assertDoesNotMatchRegularExpression('/(?:<a|<span)[^>]*>.*?' . preg_quote($sale->reference, '/') . '.*?<\/(?:a|span)>\s*<div class="document-note-container/s', $html);
            $this->assertMatchesRegularExpression('/<td[^>]*>[\s\S]*?(?:<a|<span)[^>]*>[\s\S]*?' . preg_quote($sale->reference, '/') . '[\s\S]*?<\/(?:a|span)>[\s\S]*?<\/td>\s*<td class="document-note-cell">[\s\S]*?<div class="document-note-container/s', $html);
        }
    }
}

<?php

namespace Modules\Pos\Tests\Feature;

use App\Livewire\Pos\GlobalPosPaymentTable;
use App\Livewire\Pos\PosSummaryCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutPayment;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Entities\PosTransactionLineSerial;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class GlobalPosPaymentTableAndSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected Customer $customer1;
    protected Customer $customer2;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $bankMethod;
    protected Product $product1;
    protected Product $product2;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create(['company_name' => 'Cabang Pusat']);
        $this->setting2 = Setting::factory()->create(['company_name' => 'Cabang Barat']);
        session(['setting_id' => $this->setting1->id]);

        $this->customer1 = Customer::factory()->create([
            'customer_name' => 'Budi Retailer',
            'contact_name' => 'Budi Santoso',
            'customer_phone' => '08123456789',
            'setting_id' => $this->setting1->id,
        ]);

        $this->customer2 = Customer::factory()->create([
            'customer_name' => 'Citra Grosir',
            'contact_name' => 'Citra Dewi',
            'customer_phone' => '08129876543',
            'setting_id' => $this->setting2->id,
        ]);

        $coa = \Modules\Setting\Entities\ChartOfAccount::firstOrCreate(
            ['setting_id' => $this->setting1->id, 'account_number' => '1100-TEST-1'],
            [
                'name' => 'Cash Account Test',
                'account_number' => '1100-TEST-1',
                'category' => 'Kas & Bank',
                'setting_id' => $this->setting1->id,
            ]
        );

        $this->cashMethod = PaymentMethod::firstOrCreate(
            ['name' => 'Cash'],
            ['name' => 'Cash', 'is_cash' => true, 'coa_id' => $coa->id]
        );
        $this->bankMethod = PaymentMethod::firstOrCreate(
            ['name' => 'Bank Transfer'],
            ['name' => 'Bank Transfer', 'is_cash' => false, 'coa_id' => $coa->id]
        );

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        $unit = Unit::create(['name' => 'PCS', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);
        $cat = Category::create([
            'category_code' => 'GEN',
            'category_name' => 'General',
            'created_by' => $this->user->id,
            'setting_id' => $this->setting1->id,
        ]);

        $this->product1 = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Laptop Asus ROG',
            'product_code' => 'ROG-001',
            'barcode' => 'ROG-001',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_unit' => $unit->id,
            'category_id' => $cat->id,
        ]);

        $this->product2 = Product::create([
            'setting_id' => $this->setting2->id,
            'product_name' => 'Mouse Wireless Logitech',
            'product_code' => 'MOU-888',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 20,
            'product_cost' => 100,
            'product_price' => 200,
            'product_unit' => $unit->id,
            'category_id' => $cat->id,
        ]);

        \Illuminate\Support\Facades\Gate::define('posPayments.global.access', fn() => true);
        \Illuminate\Support\Facades\Gate::define('posPayments.global.create', fn() => true);
        \Illuminate\Support\Facades\Gate::define('posPayments.global.history', fn() => true);
    }

    protected function createCompletedPosTransaction(
        Setting $setting,
        Customer $customer,
        float $totalAmount,
        string $code = 'POS-TRX-1',
        $overrides = []
    ): array {
        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $setting->id,
            'name' => 'Terminal ' . uniqid(),
            'code' => 'T-' . uniqid(),
            'is_active' => true,
        ]);

        $location = \Modules\Setting\Entities\Location::where('setting_id', $setting->id)->first()
            ?? \Modules\Setting\Entities\Location::create(['setting_id' => $setting->id, 'name' => 'Loc ' . $setting->id]);

        $session = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opening_float_total' => 0,
        ]);

        $trx = PosTransaction::create(array_merge([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $session->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('', true),
            'payload_hash' => 'hash_' . $code,
            'subtotal' => $totalAmount,
            'grand_total' => $totalAmount,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code,
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'reference' => 'SO-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'paid_amount' => 0,
            'due_amount' => $totalAmount,
            'payment_method' => 'Cash',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'split_key' => 'main',
            'source_setting_id' => $setting->id,
            'source_location_id' => $location->id,
            'tax_bucket' => 'NON_PKP',
            'subtotal' => $totalAmount,
            'grand_total' => $totalAmount,
            'paid_total' => 0,
        ]);

        return ['transaction' => $trx, 'checkout' => $checkout, 'sale' => $sale, 'session' => $session, 'terminal' => $terminal];
    }

    public function test_pos_summary_cards_computes_correct_aggregates()
    {
        // Trx 1: 10,000 Unpaid (Live due: 10,000)
        $t1 = $this->createCompletedPosTransaction($this->setting1, $this->customer1, 10000, 'POS-001');

        // Trx 2: 20,000 Fully Paid with recent payment
        $t2 = $this->createCompletedPosTransaction($this->setting2, $this->customer2, 20000, 'POS-002');
        SalePayment::create([
            'sale_id' => $t2['sale']->id,
            'amount' => 20000,
            'date' => now()->toDateString(),
            'status' => SalePayment::STATUS_ACTIVE,
            'payment_method_id' => $this->cashMethod->id,
            'payment_method' => 'Cash',
            'reference' => 'PAY-002',
        ]);
        $t2['sale']->reconcileFromActivePayments();

        // Trx 3: 5,000 Overdue (due date yesterday)
        $t3 = $this->createCompletedPosTransaction($this->setting1, $this->customer1, 5000, 'POS-003');
        $t3['sale']->update(['due_date' => now()->subDays(2)->toDateString()]);

        Livewire::test(PosSummaryCards::class)
            ->assertSee('15,000') // Total Live Due: 10,000 + 5,000 = 15,000
            ->assertSee('5,000')  // Overdue Amount: 5,000
            ->assertSee('20,000'); // Paid in last 30d: 20,000
    }

    public function test_pos_summary_cards_render_loading_overlay_targeting_toggle_card_filter()
    {
        $html = Livewire::test(PosSummaryCards::class)->html();

        // Capture the overlay's full opening tag: it must target toggleCardFilter
        // specifically (the request PosSummaryCards itself issues before dispatching
        // pos-filter to the sibling GlobalPosPaymentTable component), use the untargeted
        // .flex variant without .delay (immediate feedback is desired for the first
        // request in this two-request sequence), and default to hidden so it does not
        // render visible-by-default before Livewire controls its visibility.
        $this->assertMatchesRegularExpression(
            '/<div\b[^>]*wire:loading\.flex[^>]*>/',
            $html,
            'Expected a wire:loading.flex overlay element.'
        );
        preg_match('/<div\b[^>]*wire:loading\.flex[^>]*>/', $html, $overlayMatch);
        $overlayTag = $overlayMatch[0] ?? '';

        $this->assertStringContainsString(
            'wire:target="toggleCardFilter"',
            $overlayTag,
            'Expected the overlay to target toggleCardFilter so it blocks repeated card clicks during that request.'
        );
        $this->assertStringNotContainsString(
            'wire:loading.delay',
            $overlayTag,
            'Expected no .delay modifier: this is the first request in a two-request sequence and immediate feedback is desired.'
        );
        $this->assertStringContainsString(
            'wire:cloak',
            $overlayTag,
            'Expected wire:cloak to prevent an initialization flash.'
        );
        $this->assertMatchesRegularExpression(
            '/display\s*:\s*none/',
            $overlayTag,
            'Expected the overlay to default to display: none so it is hidden before Livewire controls its visibility.'
        );

        // Overlay must sit over all three cards without hiding them (position-relative
        // wrapper + position-absolute overlay), and expose accessible spinner markup.
        $this->assertStringContainsString('position-relative', $html);
        $this->assertStringContainsString('position-absolute', $html);
        $this->assertStringContainsString('spinner-border', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('Memuat data...', $html);
    }

    public function test_apply_card_filter_dispatches_completion_event_for_workspace_overlay()
    {
        $this->createCompletedPosTransaction($this->setting1, $this->customer1, 10000, 'POS-CARD-01');

        // The workspace-level Alpine overlay (workspace.blade.php) listens for this event
        // to release the loading state it set the instant a summary card was clicked,
        // covering the full two-component (PosSummaryCards -> GlobalPosPaymentTable)
        // request sequence rather than only the table's own wire:loading.
        Livewire::test(GlobalPosPaymentTable::class)
            ->call('applyCardFilter', 'unpaid')
            ->assertDispatched('pos-card-filter-applied');
    }

    public function test_global_pos_payment_table_cross_setting_listing_and_filters()
    {
        $t1 = $this->createCompletedPosTransaction($this->setting1, $this->customer1, 10000, 'POS-PST-01');
        $t2 = $this->createCompletedPosTransaction($this->setting2, $this->customer2, 20000, 'POS-BRT-02');

        // View without filters shows both cross-setting transactions
        Livewire::test(GlobalPosPaymentTable::class)
            ->assertSee('POS-PST-01')
            ->assertSee('POS-BRT-02')
            ->assertSee('CABANG PUSAT')
            ->assertSee('CABANG BARAT');

        // Filter by setting
        Livewire::test(GlobalPosPaymentTable::class)
            ->set('globalBusinessFilters', [$this->setting1->id])
            ->assertSee('POS-PST-01')
            ->assertDontSee('POS-BRT-02');

        // Filter by payment status UNPAID vs PAID
        SalePayment::create([
            'sale_id' => $t2['sale']->id,
            'amount' => 20000,
            'date' => now()->toDateString(),
            'status' => SalePayment::STATUS_ACTIVE,
            'payment_method_id' => $this->cashMethod->id,
            'payment_method' => 'Cash',
            'reference' => 'PAY-BRT-02',
        ]);
        $t2['sale']->reconcileFromActivePayments();

        Livewire::test(GlobalPosPaymentTable::class)
            ->set('paymentStatusFilter', 'Paid')
            ->assertDontSee('POS-PST-01')
            ->assertSee('POS-BRT-02');

        Livewire::test(GlobalPosPaymentTable::class)
            ->set('paymentStatusFilter', 'Unpaid')
            ->assertSee('POS-PST-01')
            ->assertDontSee('POS-BRT-02');
    }

    public function test_table_renders_loading_overlay_and_disables_action_buttons_while_loading()
    {
        $html = Livewire::test(GlobalPosPaymentTable::class)->html();

        // Overlay: wire:loading.flex (NOT .delay) with no wire:target, so it fires
        // immediately for every request against this component (filters, search, card
        // filters, sort, per-page, pagination) rather than being scoped to a single
        // action. Livewire's .delay modifier intentionally waits (default ~200ms) before
        // showing the indicator; pagination requests routinely complete faster than that
        // window, so with .delay the rows update before the spinner is ever shown,
        // giving no loading feedback at all for the most common interaction. Using the
        // untargeted .flex-only variant trades a possible brief spinner flash on very
        // fast requests for guaranteed immediate feedback on slower ones.
        $this->assertMatchesRegularExpression(
            '/<div[^>]*wire:loading\.flex(?![^>]*wire:target)[^>]*>/',
            $html,
            'Expected an untargeted wire:loading.flex overlay element.'
        );

        // Capture the full overlay opening tag to verify its idle (pre-Livewire-init) state
        // is safe: wire:loading.flex only controls visibility once Livewire has processed
        // request state, so without an explicit "display: none" default and wire:cloak (to
        // prevent an initialization flash), the absolutely positioned, z-index: 99 overlay
        // would render visible-by-default and permanently block the table underneath.
        $this->assertMatchesRegularExpression(
            '/<div\b[^>]*wire:loading\.flex[^>]*>/',
            $html,
            'Expected the loading overlay <div> to be present.'
        );
        preg_match('/<div\b[^>]*wire:loading\.flex[^>]*>/', $html, $overlayMatch);
        $overlayTag = $overlayMatch[0] ?? '';

        $this->assertStringNotContainsString(
            'wire:loading.delay',
            $overlayTag,
            'Expected no .delay modifier: pagination/sort/search requests frequently complete within Livewire\'s default delay window, so a delayed indicator would never become visible for them.'
        );

        $this->assertStringContainsString(
            'wire:cloak',
            $overlayTag,
            'Expected the loading overlay to have wire:cloak to prevent an initialization flash.'
        );
        $this->assertMatchesRegularExpression(
            '/display\s*:\s*none/',
            $overlayTag,
            'Expected the loading overlay to default to display: none so it is hidden before Livewire controls its visibility.'
        );

        // Overlay must sit over the table without hiding existing results (position-relative
        // wrapper + position-absolute overlay), and expose an accessible status role plus
        // visually hidden loading text.
        $this->assertStringContainsString('position-relative', $html);
        $this->assertStringContainsString('position-absolute', $html);
        $this->assertStringContainsString('spinner-border', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('Memuat data...', $html);

        // Apply Filter, Reset, Search, and Clear Search must disable themselves while a
        // request is in flight to prevent duplicate submissions. Clear Search only renders
        // when a search term is active.
        $this->assertMatchesRegularExpression(
            '/wire:click="applyFilter"[^>]*wire:loading\.attr="disabled"/',
            $html,
            'Expected Apply Filter button to have wire:loading.attr="disabled".'
        );
        $this->assertMatchesRegularExpression(
            '/wire:click="resetFilters"[^>]*wire:loading\.attr="disabled"/',
            $html,
            'Expected Reset button to have wire:loading.attr="disabled".'
        );
        $this->assertMatchesRegularExpression(
            '/type="submit" class="btn btn-primary" wire:loading\.attr="disabled"/',
            $html,
            'Expected Search submit button to have wire:loading.attr="disabled".'
        );

        $htmlWithSearch = Livewire::test(GlobalPosPaymentTable::class)
            ->set('search', 'anything')
            ->html();

        $this->assertMatchesRegularExpression(
            '/wire:click="clearSearch"[^>]*wire:loading\.attr="disabled"/',
            $htmlWithSearch,
            'Expected Clear Search button to have wire:loading.attr="disabled".'
        );
    }

    public function test_projection_derived_filter_is_applied_before_pagination()
    {
        // Create more rows than one page (perPage = 10) so the paid-status filter
        // must narrow the query itself rather than only the fetched page's collection.
        $unpaidCodes = [];
        for ($i = 1; $i <= 8; $i++) {
            $code = 'POS-UNPAID-' . $i;
            $unpaidCodes[] = $code;
            $this->createCompletedPosTransaction($this->setting1, $this->customer1, 1000, $code);
        }

        $paidCodes = [];
        for ($i = 1; $i <= 5; $i++) {
            $code = 'POS-PAID-' . $i;
            $paidCodes[] = $code;
            $trx = $this->createCompletedPosTransaction($this->setting2, $this->customer2, 1000, $code);
            SalePayment::create([
                'sale_id' => $trx['sale']->id,
                'amount' => 1000,
                'date' => now()->toDateString(),
                'status' => SalePayment::STATUS_ACTIVE,
                'payment_method_id' => $this->cashMethod->id,
                'payment_method' => 'Cash',
                'reference' => 'PAY-' . $code,
            ]);
            $trx['sale']->reconcileFromActivePayments();
        }

        // 13 rows total, perPage = 10, so the unfiltered first page would be full
        // and would not contain all 5 paid rows if filtering happened post-pagination.
        $component = Livewire::test(GlobalPosPaymentTable::class)
            ->set('paymentStatusFilter', 'Paid');

        $paginator = $component->viewData('transactions');

        $this->assertSame(5, $paginator->total(), 'Paginator total must reflect the filtered set, not the unfiltered page.');
        $this->assertCount(5, $paginator->items());

        foreach ($paidCodes as $code) {
            $component->assertSee($code);
        }
        foreach ($unpaidCodes as $code) {
            $component->assertDontSee($code);
        }
    }

    public function test_pagination_uses_bootstrap_theme_navigates_pages_and_resets_on_page_size_change()
    {
        // 12 rows with perPage = 10 forces a real second page. PosTransaction does not
        // allow mass-assigning created_at/updated_at, so all rows share the same
        // insertion timestamp and the default created_at-desc sort ties on insertion
        // order; we read the actual page-1/page-2 split from the paginator itself rather
        // than assuming a specific ordering.
        $codes = [];
        for ($i = 1; $i <= 12; $i++) {
            $code = sprintf('POS-PAGE-%02d', $i);
            $codes[] = $code;
            $this->createCompletedPosTransaction($this->setting1, $this->customer1, 1000, $code);
        }

        $component = Livewire::test(GlobalPosPaymentTable::class);

        // Bootstrap pagination markup, not Livewire's default Tailwind view: Bootstrap
        // links use the `page-link`/`page-item` classes and a `pagination` wrapper;
        // Livewire's bundled Tailwind view does not emit these classes at all.
        $html = $component->html();
        $this->assertStringContainsString('pagination', $html);
        $this->assertStringContainsString('page-link', $html);
        $this->assertStringContainsString('page-item', $html);

        $paginator = $component->viewData('transactions');
        $this->assertSame(12, $paginator->total());
        $this->assertSame(1, $paginator->currentPage());
        $this->assertCount(10, $paginator->items());

        $pageOneCodes = $paginator->pluck('code')->all();

        $component->call('nextPage');
        $pageTwoPaginator = $component->viewData('transactions');
        $this->assertSame(2, $pageTwoPaginator->currentPage());
        $this->assertCount(2, $pageTwoPaginator->items());
        $pageTwoCodes = $pageTwoPaginator->pluck('code')->all();

        // Every row appears on exactly one page, and together they cover the full set.
        $this->assertEmpty(array_intersect($pageOneCodes, $pageTwoCodes), 'Page 1 and page 2 must not share any rows.');
        $this->assertEqualsCanonicalizing($codes, array_merge($pageOneCodes, $pageTwoCodes));

        foreach ($pageTwoCodes as $code) {
            $component->assertSee($code);
        }

        // Changing perPage while on a later page must reset to page 1, not leave the user
        // stranded past the new last page with an empty table.
        $component->set('perPage', 25);
        $afterPerPageChange = $component->viewData('transactions');
        $this->assertSame(1, $afterPerPageChange->currentPage(), 'Changing perPage must reset the paginator to page 1.');
        $this->assertCount(12, $afterPerPageChange->items());

        // Totals and the "Menampilkan ... dari ..." range must stay correct after a
        // structured filter narrows the result set.
        $component->set('globalBusinessFilters', [$this->setting1->id]);
        $filtered = $component->viewData('transactions');
        $this->assertSame(12, $filtered->total());
        $this->assertSame(1, $filtered->firstItem());
        $this->assertSame(12, $filtered->lastItem());
        $component->assertSee('Menampilkan 1 sampai 12 dari 12 transaksi');
    }

    public function test_pagination_is_deterministic_when_sort_field_has_tied_values()
    {
        // Force every row to share the exact same created_at so the primary sort column
        // (created_at) ties across all 12 rows. Without a stable secondary sort, SQL is
        // free to return tied rows in a different order on each request/page, which can
        // put a row on both pages or skip it entirely -- most visibly on MySQL, which
        // (unlike SQLite in simple cases) does not guarantee any particular tie order.
        $ids = [];
        $tiedTimestamp = now()->toDateTimeString();
        for ($i = 1; $i <= 12; $i++) {
            $trx = $this->createCompletedPosTransaction($this->setting1, $this->customer1, 1000, sprintf('POS-TIE-%02d', $i));
            $ids[] = $trx['transaction']->id;
        }

        \Illuminate\Support\Facades\DB::table('pos_transactions')
            ->whereIn('id', $ids)
            ->update(['created_at' => $tiedTimestamp]);

        // Default sort is created_at desc; with every created_at tied, the id-desc
        // secondary sort must fully determine order: highest id first.
        $expectedOrder = array_reverse($ids);
        $expectedPageOne = array_slice($expectedOrder, 0, 10);
        $expectedPageTwo = array_slice($expectedOrder, 10, 2);

        $component = Livewire::test(GlobalPosPaymentTable::class);
        $paginator = $component->viewData('transactions');
        $this->assertSame($expectedPageOne, $paginator->pluck('id')->all());

        $component->call('nextPage');
        $pageTwoPaginator = $component->viewData('transactions');
        $this->assertSame($expectedPageTwo, $pageTwoPaginator->pluck('id')->all());

        // Sanity-check the same guarantee holds in ascending order too.
        $component2 = Livewire::test(GlobalPosPaymentTable::class)
            ->set('sortField', 'created_at')
            ->set('sortDirection', 'asc');
        $ascPaginator = $component2->viewData('transactions');
        $this->assertSame(array_slice($ids, 0, 10), $ascPaginator->pluck('id')->all());
    }

    public function test_search_by_transaction_code_receipt_and_customer_tokenized()
    {
        $this->createCompletedPosTransaction($this->setting1, $this->customer1, 10000, 'TRX-ALPHA-99');
        $this->createCompletedPosTransaction($this->setting2, $this->customer2, 20000, 'TRX-BETA-77');

        // Tokenized AND search: "Budi ALPHA" -> matches TRX-ALPHA-99
        Livewire::test(GlobalPosPaymentTable::class)
            ->set('search', 'Budi ALPHA')
            ->assertSee('TRX-ALPHA-99')
            ->assertDontSee('TRX-BETA-77');

        // Search by receipt number
        Livewire::test(GlobalPosPaymentTable::class)
            ->set('search', 'RCP-TRX-BETA-77')
            ->assertSee('TRX-BETA-77')
            ->assertDontSee('TRX-ALPHA-99');
    }

    public function test_search_by_product_barcode_exact_case_insensitive()
    {
        $t1 = $this->createCompletedPosTransaction($this->setting1, $this->customer1, 1500, 'TRX-LAPTOP-01');
        PosTransactionLine::create([
            'pos_transaction_id' => $t1['transaction']->id,
            'line_no' => 1,
            'product_id' => $this->product1->id,
            'product_name_snapshot' => $this->product1->product_name,
            'product_code_snapshot' => $this->product1->product_code,
            'qty' => 1,
            'unit_price' => 1500,
        ]);

        $t2 = $this->createCompletedPosTransaction($this->setting2, $this->customer2, 200, 'TRX-MOUSE-02');
        PosTransactionLine::create([
            'pos_transaction_id' => $t2['transaction']->id,
            'line_no' => 1,
            'product_id' => $this->product2->id,
            'product_name_snapshot' => $this->product2->product_name,
            'product_code_snapshot' => $this->product2->product_code,
            'qty' => 1,
            'unit_price' => 200,
        ]);

        // Search product barcode "rog-001" (lowercase) -> exact matches ROG-001 on Laptop
        Livewire::test(GlobalPosPaymentTable::class)
            ->set('search', 'rog-001')
            ->assertSee('TRX-LAPTOP-01')
            ->assertDontSee('TRX-MOUSE-02');
    }

    public function test_search_by_captured_historical_pos_barcode_survives_product_barcode_change()
    {
        $t1 = $this->createCompletedPosTransaction($this->setting1, $this->customer1, 1500, 'TRX-HIST-01');
        PosTransactionLine::create([
            'pos_transaction_id' => $t1['transaction']->id,
            'line_no' => 1,
            'product_id' => $this->product1->id,
            'product_name_snapshot' => $this->product1->product_name,
            'product_code_snapshot' => $this->product1->product_code,
            'qty' => 1,
            'unit_price' => 1500,
            'line_meta' => ['barcode' => 'OLD-BARCODE-999'],
        ]);

        $t2 = $this->createCompletedPosTransaction($this->setting2, $this->customer2, 200, 'TRX-HIST-OTHER');
        PosTransactionLine::create([
            'pos_transaction_id' => $t2['transaction']->id,
            'line_no' => 1,
            'product_id' => $this->product2->id,
            'product_name_snapshot' => $this->product2->product_name,
            'product_code_snapshot' => $this->product2->product_code,
            'qty' => 1,
            'unit_price' => 200,
        ]);

        // Simulate the product's barcode having since changed; the historical POS
        // transaction must still be discoverable by the barcode captured at checkout time.
        $this->product1->update(['barcode' => 'NEW-BARCODE-111']);

        Livewire::test(GlobalPosPaymentTable::class)
            ->set('search', 'old-barcode-999')
            ->assertSee('TRX-HIST-01')
            ->assertDontSee('TRX-HIST-OTHER');
    }

    public function test_search_by_exact_normalized_serial_number()
    {
        $t1 = $this->createCompletedPosTransaction($this->setting1, $this->customer1, 1500, 'TRX-SN-01');
        $line1 = PosTransactionLine::create([
            'pos_transaction_id' => $t1['transaction']->id,
            'line_no' => 1,
            'product_id' => $this->product1->id,
            'product_name_snapshot' => $this->product1->product_name,
            'product_code_snapshot' => $this->product1->product_code,
            'qty' => 1,
            'unit_price' => 1500,
        ]);

        $location1 = \Modules\Setting\Entities\Location::where('setting_id', $this->setting1->id)->first()
            ?? \Modules\Setting\Entities\Location::create(['setting_id' => $this->setting1->id, 'name' => 'Loc ' . $this->setting1->id]);

        ProductSerialNumber::create([
            'product_id' => $this->product1->id,
            'location_id' => $location1->id,
            'serial_number' => 'SN-ASUS-999-XYZ',
            'status' => 'sold',
        ]);

        PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $line1->id,
            'serial_number' => 'SN-ASUS-999-XYZ',
        ]);

        $this->createCompletedPosTransaction($this->setting2, $this->customer2, 2000, 'TRX-SN-OTHER');

        // Search exact trimmed case-insensitive serial
        Livewire::test(GlobalPosPaymentTable::class)
            ->set('search', '  sn-asus-999-xyz  ')
            ->assertSee('TRX-SN-01')
            ->assertDontSee('TRX-SN-OTHER');
    }
}

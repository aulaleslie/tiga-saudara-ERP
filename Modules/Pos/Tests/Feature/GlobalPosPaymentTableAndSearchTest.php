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

        // Overlay: wire:loading.delay.flex with no wire:target, so it fires for every
        // request against this component (filters, search, card filters, sort, per-page,
        // pagination) rather than being scoped to a single action.
        $this->assertMatchesRegularExpression(
            '/<div[^>]*wire:loading\.delay\.flex(?![^>]*wire:target)[^>]*>/',
            $html,
            'Expected an untargeted wire:loading.delay.flex overlay element.'
        );

        // Capture the full overlay opening tag to verify its idle (pre-Livewire-init) state
        // is safe: wire:loading.delay.flex only controls visibility once Livewire has
        // processed request state, so without an explicit "display: none" default and
        // wire:cloak (to prevent an initialization flash), the absolutely positioned,
        // z-index: 99 overlay would render visible-by-default and permanently block the
        // table underneath.
        $this->assertMatchesRegularExpression(
            '/<div\b[^>]*wire:loading\.delay\.flex[^>]*>/',
            $html,
            'Expected the loading overlay <div> to be present.'
        );
        preg_match('/<div\b[^>]*wire:loading\.delay\.flex[^>]*>/', $html, $overlayMatch);
        $overlayTag = $overlayMatch[0] ?? '';

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

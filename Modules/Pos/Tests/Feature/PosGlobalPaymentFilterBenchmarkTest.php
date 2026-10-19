<?php

namespace Modules\Pos\Tests\Feature;

use App\Livewire\Pos\GlobalPosPaymentTable;
use App\Livewire\Pos\PosSummaryCards;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Pos\Services\PosSettlementProjectionService;
use Tests\TestCase;

class PosGlobalPaymentFilterBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected Customer $customer1;
    protected Customer $customer2;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $bankMethod;
    protected Product $product;
    protected $adminUser;
    protected $cashier1;
    protected $cashier2;
    protected PosTerminal $terminal1;
    protected PosTerminal $terminal2;
    protected PosSession $session1;
    protected PosSession $session2;
    protected Location $location1;
    protected Location $location2;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00'));

        $this->setting1 = Setting::factory()->create(['company_name' => 'Cabang Pusat']);
        $this->setting2 = Setting::factory()->create(['company_name' => 'Cabang Barat']);
        session(['setting_id' => $this->setting1->id]);

        $this->customer1 = Customer::factory()->create([
            'customer_name' => 'Budi Retailer',
            'setting_id' => $this->setting1->id,
        ]);
        $this->customer2 = Customer::factory()->create([
            'customer_name' => 'Citra Grosir',
            'setting_id' => $this->setting2->id,
        ]);

        $coa = ChartOfAccount::firstOrCreate(
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

        $this->adminUser = \App\Models\User::factory()->create(['name' => 'Admin User']);
        $this->cashier1 = \App\Models\User::factory()->create(['name' => 'Cashier Alpha']);
        $this->cashier2 = \App\Models\User::factory()->create(['name' => 'Cashier Beta']);
        $this->actingAs($this->adminUser);

        $this->location1 = Location::create(['setting_id' => $this->setting1->id, 'name' => 'Gudang Pusat']);
        $this->location2 = Location::create(['setting_id' => $this->setting2->id, 'name' => 'Gudang Barat']);

        $this->terminal1 = PosTerminal::create([
            'setting_id' => $this->setting1->id,
            'name' => 'POS Terminal 01',
            'code' => 'T-01',
            'is_active' => true,
        ]);
        $this->terminal2 = PosTerminal::create([
            'setting_id' => $this->setting2->id,
            'name' => 'POS Terminal 02',
            'code' => 'T-02',
            'is_active' => true,
        ]);

        $this->session1 = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => $this->terminal1->id,
            'cashier_user_id' => $this->cashier1->id,
            'status' => 'OPEN',
            'opened_at' => now()->subDays(5),
            'opening_float_total' => 0,
        ]);
        $this->session2 = PosSession::create([
            'setting_id' => $this->setting2->id,
            'terminal_id' => $this->terminal2->id,
            'cashier_user_id' => $this->cashier2->id,
            'status' => 'OPEN',
            'opened_at' => now()->subDays(3),
            'opening_float_total' => 0,
        ]);

        $unit = Unit::create(['name' => 'PCS', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);
        $cat = Category::create([
            'category_code' => 'GEN',
            'category_name' => 'General',
            'created_by' => $this->adminUser->id,
            'setting_id' => $this->setting1->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Produk Tes',
            'product_code' => 'PRD-001',
            'barcode' => 'PRD-001',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 1000,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_unit' => $unit->id,
            'category_id' => $cat->id,
        ]);

        Gate::define('posPayments.global.access', fn() => true);
        Gate::define('posPayments.global.create', fn() => true);
        Gate::define('posPayments.global.history', fn() => true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Create an ordinary completed POS transaction (no returns).
     */
    protected function createOrdinaryPosTransaction(
        int $index,
        Setting $setting,
        Customer $customer,
        \App\Models\User $cashier,
        PosTerminal $terminal,
        PosSession $session,
        Location $location,
        string $paymentProfile = 'unpaid'
    ): array {
        $code = sprintf('POS-ORD-%04d', $index);
        $totalAmount = 100000.0 + ($index * 1000.0);
        $createdAt = now()->subDays($index % 10)->setTime(10 + ($index % 8), 0, 0);

        $trx = PosTransaction::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'source_pos_session_id' => $session->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'customer_id' => $customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('ord_', true),
            'payload_hash' => 'hash_' . $code,
            'subtotal' => $totalAmount,
            'grand_total' => $totalAmount,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        $dueDate = match ($paymentProfile) {
            'overdue' => now()->subDays(2)->toDateString(),
            default => now()->addDays(7)->toDateString(),
        };

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'reference' => 'SO-' . $code,
            'date' => $createdAt->toDateString(),
            'due_date' => $dueDate,
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
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
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

        if ($paymentProfile === 'paid') {
            SalePayment::create([
                'sale_id' => $sale->id,
                'amount' => $totalAmount,
                'date' => now()->subDays(1)->toDateString(),
                'status' => SalePayment::STATUS_ACTIVE,
                'payment_method_id' => $this->cashMethod->id,
                'payment_method' => 'Cash',
                'reference' => 'PAY-' . $code,
            ]);
            $sale->reconcileFromActivePayments();
        } elseif ($paymentProfile === 'partial') {
            SalePayment::create([
                'sale_id' => $sale->id,
                'amount' => $totalAmount / 2,
                'date' => now()->subDays(1)->toDateString(),
                'status' => SalePayment::STATUS_ACTIVE,
                'payment_method_id' => $this->cashMethod->id,
                'payment_method' => 'Cash',
                'reference' => 'PAY-' . $code,
            ]);
            $sale->reconcileFromActivePayments();
        }

        return ['trx' => $trx, 'checkout' => $checkout, 'sale' => $sale];
    }

    /**
     * Create a split transaction with completed replacement return.
     */
    protected function createSplitReplacementPosTransaction(
        int $index,
        Setting $setting,
        Customer $customer,
        \App\Models\User $cashier,
        PosTerminal $terminal,
        PosSession $session,
        Location $location,
        string $paymentProfile = 'unpaid'
    ): array {
        $code = sprintf('POS-SPL-%04d', $index);
        $part1Amount = 60000.0;
        $part2Amount = 40000.0;
        $totalAmount = $part1Amount + $part2Amount;
        $createdAt = now()->subDays($index % 10)->setTime(11 + ($index % 8), 0, 0);

        $trx = PosTransaction::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'source_pos_session_id' => $session->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'customer_id' => $customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('spl_', true),
            'payload_hash' => 'hash_' . $code,
            'subtotal' => $totalAmount,
            'grand_total' => $totalAmount,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        $dueDate = match ($paymentProfile) {
            'overdue' => now()->subDays(2)->toDateString(),
            default => now()->addDays(14)->toDateString(),
        };

        // Sale 1
        $sale1 = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'reference' => 'SO-' . $code . '-A',
            'date' => $createdAt->toDateString(),
            'due_date' => $dueDate,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => $part1Amount,
            'due_amount' => $part1Amount,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $cs1 = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'split_key' => 'part_a',
            'source_setting_id' => $setting->id,
            'source_location_id' => $location->id,
            'tax_bucket' => 'NON_PKP',
            'subtotal' => $part1Amount,
            'grand_total' => $part1Amount,
            'paid_total' => 0,
        ]);

        // Sale 2
        $sale2 = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'reference' => 'SO-' . $code . '-B',
            'date' => $createdAt->toDateString(),
            'due_date' => $dueDate,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => $part2Amount,
            'due_amount' => $part2Amount,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'split_key' => 'part_b',
            'source_setting_id' => $setting->id,
            'source_location_id' => $location->id,
            'tax_bucket' => 'NON_PKP',
            'subtotal' => $part2Amount,
            'grand_total' => $part2Amount,
            'paid_total' => 0,
        ]);

        // Completed non-reversed replacement return linked to this checkout
        $posReturn = PosReturn::create([
            'pos_checkout_id' => $checkout->id,
            'pos_transaction_id' => $trx->id,
            'transaction_code' => $trx->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash_' . $code,
            'total_amount' => 15000,
            'reference' => 'RET-' . $code,
            'status' => PosReturn::STATUS_COMPLETED,
            'is_reversed' => false,
            'customer_id' => $customer->id,
            'setting_id' => $setting->id,
            'created_by' => $cashier->id,
        ]);

        $returnLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $cs1->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale1->id,
            'sale_detail_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $this->product->id,
            'product_name' => 'Replacement Item',
            'product_code' => 'REP-ITEM',
            'quantity' => 1,
            'unit_price' => 15000,
            'line_total' => 15000,
        ]);

        // Replacement sale under setting 2
        $replacementSale = Sale::create([
            'setting_id' => $this->setting2->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'reference' => 'REP-' . $code,
            'date' => $createdAt->toDateString(),
            'due_date' => $dueDate,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'total_amount' => 15000,
            'paid_amount' => 15000,
            'due_amount' => 0,
            'payment_method' => 'Cash',
            'note' => 'Replacement for return ' . $posReturn->reference,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $replacementSale->id,
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $replacementSale->id,
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'dispatched_quantity' => 1,
            'pos_return_line_id' => $returnLine->id,
            'quantity' => 1,
        ]);

        if ($paymentProfile === 'paid') {
            SalePayment::create([
                'sale_id' => $sale1->id,
                'amount' => $part1Amount,
                'date' => now()->subDays(1)->toDateString(),
                'status' => SalePayment::STATUS_ACTIVE,
                'payment_method_id' => $this->cashMethod->id,
                'payment_method' => 'Cash',
                'reference' => 'PAY-' . $code . '-A',
            ]);
            $sale1->reconcileFromActivePayments();

            SalePayment::create([
                'sale_id' => $sale2->id,
                'amount' => $part2Amount,
                'date' => now()->subDays(1)->toDateString(),
                'status' => SalePayment::STATUS_ACTIVE,
                'payment_method_id' => $this->cashMethod->id,
                'payment_method' => 'Cash',
                'reference' => 'PAY-' . $code . '-B',
            ]);
            $sale2->reconcileFromActivePayments();
        }

        return ['trx' => $trx, 'checkout' => $checkout, 'sale1' => $sale1, 'sale2' => $sale2, 'replacement' => $replacementSale];
    }

    /**
     * Build the benchmark dataset.
     */
    protected function buildFixtureDataset(int $totalCount, float $replacementRatio = 0.3): array
    {
        $replacementCount = (int) round($totalCount * $replacementRatio);
        $ordinaryCount = $totalCount - $replacementCount;

        $profiles = ['unpaid', 'paid', 'overdue', 'partial'];

        for ($i = 1; $i <= $ordinaryCount; $i++) {
            $setting = ($i % 2 === 1) ? $this->setting1 : $this->setting2;
            $customer = ($i % 2 === 1) ? $this->customer1 : $this->customer2;
            $cashier = ($i % 2 === 1) ? $this->cashier1 : $this->cashier2;
            $terminal = ($i % 2 === 1) ? $this->terminal1 : $this->terminal2;
            $session = ($i % 2 === 1) ? $this->session1 : $this->session2;
            $location = ($i % 2 === 1) ? $this->location1 : $this->location2;
            $profile = $profiles[$i % count($profiles)];

            $this->createOrdinaryPosTransaction($i, $setting, $customer, $cashier, $terminal, $session, $location, $profile);
        }

        for ($j = 1; $j <= $replacementCount; $j++) {
            $setting = ($j % 2 === 1) ? $this->setting1 : $this->setting2;
            $customer = ($j % 2 === 1) ? $this->customer1 : $this->customer2;
            $cashier = ($j % 2 === 1) ? $this->cashier1 : $this->cashier2;
            $terminal = ($j % 2 === 1) ? $this->terminal1 : $this->terminal2;
            $session = ($j % 2 === 1) ? $this->session1 : $this->session2;
            $location = ($j % 2 === 1) ? $this->location1 : $this->location2;
            $profile = $profiles[$j % count($profiles)];

            $this->createSplitReplacementPosTransaction($j, $setting, $customer, $cashier, $terminal, $session, $location, $profile);
        }

        return [
            'total' => $totalCount,
            'ordinary' => $ordinaryCount,
            'replacement' => $replacementCount,
        ];
    }

    /**
     * Execute and measure a closure multiple times (warmup + samples).
     *
     * @return array{
     *     sql_count: int,
     *     sql_time_ms: float,
     *     elapsed_ms: float,
     *     projections: int,
     *     peak_mem_mb: float,
     *     sample_count: int,
     *     warmup_count: int
     * }
     */
    protected function measure(callable $action, ?callable $setup = null, int $warmup = 1, int $samples = 3): array
    {
        // Warmup runs
        for ($w = 0; $w < $warmup; $w++) {
            $context = $setup ? $setup() : null;
            $action($context);
        }

        $sqlCounts = [];
        $sqlTimes = [];
        $elapsedTimes = [];
        $projectionCounts = [];
        $peakMems = [];

        for ($s = 0; $s < $samples; $s++) {
            $context = $setup ? $setup() : null;

            gc_collect_cycles();
            $memBefore = memory_get_usage(true);

            PosSettlementProjectionService::resetProjectionCount();
            DB::enableQueryLog();
            DB::flushQueryLog();

            $start = hrtime(true);
            $action($context);
            $end = hrtime(true);

            $queryLog = DB::getQueryLog();
            DB::disableQueryLog();

            $elapsedMs = ($end - $start) / 1e6;
            $sqlCount = count($queryLog);
            $sqlTimeMs = array_sum(array_column($queryLog, 'time'));
            $projections = PosSettlementProjectionService::$projectionCount;
            $peakMemMb = (memory_get_peak_usage(true) - $memBefore) / (1024 * 1024);

            $sqlCounts[] = $sqlCount;
            $sqlTimes[] = $sqlTimeMs;
            $elapsedTimes[] = $elapsedMs;
            $projectionCounts[] = $projections;
            $peakMems[] = max(0.0, $peakMemMb);
        }

        return [
            'sql_count' => (int) round(array_sum($sqlCounts) / count($sqlCounts)),
            'sql_time_ms' => round(array_sum($sqlTimes) / count($sqlTimes), 2),
            'elapsed_ms' => round(array_sum($elapsedTimes) / count($elapsedTimes), 2),
            'projections' => (int) round(array_sum($projectionCounts) / count($projectionCounts)),
            'peak_mem_mb' => round(array_sum($peakMems) / count($peakMems), 2),
            'sample_count' => $samples,
            'warmup_count' => $warmup,
        ];
    }

    /**
     * Run all benchmark scenarios against the current dataset.
     */
    protected function executeAllScenarios(): array
    {
        $results = [];

        // Scenario 1: Table - No Filter (initial workspace load)
        $results['table_no_filter'] = $this->measure(function () {
            Livewire::test(GlobalPosPaymentTable::class)->assertOk();
        });

        // Scenario 2: Table - Business & Date Filter (action on existing workspace)
        $results['table_business_date'] = $this->measure(
            action: function ($component) {
                $component->call('applyFilter');
                $component->assertOk();
            },
            setup: function () {
                $c = Livewire::test(GlobalPosPaymentTable::class);
                $c->set('draftGlobalBusinessFilters', [$this->setting1->id]);
                $c->set('draftTransactionDateFrom', now()->subDays(5)->toDateString());
                $c->set('draftTransactionDateTo', now()->toDateString());
                return $c;
            }
        );

        // Scenario 3: Table - Cashier & Terminal Filter (action on existing workspace)
        $results['table_cashier_terminal'] = $this->measure(
            action: function ($component) {
                $component->call('applyFilter');
                $component->assertOk();
            },
            setup: function () {
                $c = Livewire::test(GlobalPosPaymentTable::class);
                $c->set('draftCashierUserId', $this->cashier1->id);
                $c->set('draftTerminalId', $this->terminal1->id);
                return $c;
            }
        );

        // Scenario 4: Table - Payment Status Filter (projection-derived, on existing workspace)
        $results['table_payment_status'] = $this->measure(
            action: function ($component) {
                $component->call('applyFilter');
                $component->assertOk();
            },
            setup: function () {
                $c = Livewire::test(GlobalPosPaymentTable::class);
                $c->set('draftPaymentStatusFilter', 'unpaid');
                return $c;
            }
        );

        // Scenario 5: Table - Due Date Filter (projection-derived, on existing workspace)
        $results['table_due_date'] = $this->measure(
            action: function ($component) {
                $component->call('applyFilter');
                $component->assertOk();
            },
            setup: function () {
                $c = Livewire::test(GlobalPosPaymentTable::class);
                $c->set('draftDueDateFrom', now()->toDateString());
                $c->set('draftDueDateTo', now()->addDays(14)->toDateString());
                return $c;
            }
        );

        // Scenario 6: Table - Card Selection (unpaid / overdue, on existing workspace)
        $results['table_card_selection'] = $this->measure(
            action: function ($component) {
                $component->call('applyCardFilter', 'unpaid');
                $component->assertOk();
            },
            setup: function () {
                return Livewire::test(GlobalPosPaymentTable::class);
            }
        );

        // Scenario 7: Summary - Default Mount (initial workspace load)
        $results['summary_default'] = $this->measure(function () {
            Livewire::test(PosSummaryCards::class)->assertOk();
        });

        // Scenario 8: Summary - Business & Date Filter (receiving filter change on active summary)
        $results['summary_business_date'] = $this->measure(
            action: function ($summary) {
                $summary->call('handleFiltersChanged', [$this->setting1->id], now()->subDays(5)->toDateString(), now()->toDateString(), null, null, null);
                $summary->assertOk();
            },
            setup: function () {
                return Livewire::test(PosSummaryCards::class);
            }
        );

        // Scenario 9: Full Event Chain - Toggle Card Filter on active workspace
        $results['chain_toggle_card'] = $this->measure(
            action: function ($ctx) {
                $ctx['summary']->call('toggleCardFilter', 'unpaid');
                $ctx['table']->call('applyCardFilter', 'unpaid');
                $ctx['table']->assertOk();
            },
            setup: function () {
                return [
                    'summary' => Livewire::test(PosSummaryCards::class),
                    'table' => Livewire::test(GlobalPosPaymentTable::class),
                ];
            }
        );

        // Scenario 10: Full Event Chain - Apply Filter on active workspace
        $results['chain_apply_filter'] = $this->measure(
            action: function ($ctx) {
                $ctx['table']->call('applyFilter');
                $ctx['table']->assertOk();
                $ctx['summary']->call('handleFiltersChanged', [$this->setting1->id], null, null, null, null, null);
                $ctx['summary']->assertOk();
            },
            setup: function () {
                $table = Livewire::test(GlobalPosPaymentTable::class);
                $table->set('draftGlobalBusinessFilters', [$this->setting1->id]);
                $table->set('draftPaymentStatusFilter', 'unpaid');
                $summary = Livewire::test(PosSummaryCards::class);
                return ['table' => $table, 'summary' => $summary];
            }
        );

        return $results;
    }

    /**
     * Format environment and benchmark table for console/report.
     */
    protected function formatReport(array $fixture, array $results): string
    {
        $env = sprintf(
            "Environment: PHP %s | OS: %s | DB: %s (%s) | Laravel: %s",
            PHP_VERSION,
            PHP_OS,
            config('database.default'),
            DB::connection()->getDriverName(),
            app()->version()
        );

        $out = "\n================================================================================\n";
        $out .= "POS GLOBAL PAYMENT BENCHMARK REPORT\n";
        $out .= $env . "\n";
        $out .= sprintf(
            "Fixture Size: %d transactions (%d ordinary, %d split/replacement)\n",
            $fixture['total'],
            $fixture['ordinary'],
            $fixture['replacement']
        );
        $out .= "================================================================================\n";
        $out .= sprintf(
            "%-36s | %-6s | %-10s | %-11s | %-11s | %-8s\n",
            'Scenario', 'SQL#', 'SQL Time', 'Elapsed', 'Projections', 'PeakMem'
        );
        $out .= str_repeat('-', 95) . "\n";

        foreach ($results as $name => $metrics) {
            $out .= sprintf(
                "%-36s | %6d | %8.2f ms | %8.2f ms | %11d | %6.2f MB\n",
                $name,
                $metrics['sql_count'],
                $metrics['sql_time_ms'],
                $metrics['elapsed_ms'],
                $metrics['projections'],
                $metrics['peak_mem_mb']
            );
        }
        $out .= "================================================================================\n";

        return $out;
    }

    public function test_benchmark_small_dataset_10_records(): void
    {
        $fixture = $this->buildFixtureDataset(10, 0.3);
        $results = $this->executeAllScenarios();

        $report = $this->formatReport($fixture, $results);
        echo $report;

        $this->assertCount(10, $results);
        $this->assertSame(10, $fixture['total']);
        $this->assertGreaterThan(0, $results['table_no_filter']['sql_count']);
    }

    public function test_benchmark_larger_dataset_50_records(): void
    {
        $fixture = $this->buildFixtureDataset(50, 0.3);
        $results = $this->executeAllScenarios();

        $report = $this->formatReport($fixture, $results);
        echo $report;

        $this->assertCount(10, $results);
        $this->assertSame(50, $fixture['total']);
        $this->assertGreaterThan(0, $results['table_no_filter']['sql_count']);
    }
}

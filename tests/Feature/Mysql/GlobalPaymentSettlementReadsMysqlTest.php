<?php

namespace Tests\Feature\Mysql;

use App\Models\User;
use App\Support\ConsistentReadSnapshot;
use App\Support\GlobalPaymentSearchQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosGlobalPaymentReadService;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\Sale\Services\GlobalSaleSummaryQuery;
use RuntimeException;
use Tests\Feature\SalesReturn\BuildsStandardFlow;
use Tests\TestCase;

/**
 * Isolated MySQL 8.0 verification for candidate-union search, window-count pagination,
 * single-query Sales summary aggregation, and two-connection REPEATABLE READ snapshots.
 *
 * @group mysql
 */
class GlobalPaymentSettlementReadsMysqlTest extends TestCase
{
    use DatabaseTruncation, BuildsStandardFlow;

    private const CONNECTION = 'mysql_test';
    private const WRITER_CONNECTION = 'mysql_test_writer';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== self::CONNECTION) {
            $this->markTestSkipped('Requires isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }

        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00'));
        $this->seedBase();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        Config::set(
            'database.connections.' . self::WRITER_CONNECTION,
            Config::get('database.connections.' . self::CONNECTION)
        );
        DB::purge(self::WRITER_CONNECTION);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge(self::WRITER_CONNECTION);
            if (config('database.default') === self::CONNECTION) {
                $this->truncateDatabaseTables();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_mysql_candidate_union_sale_search_json_contains_and_token_intersection(): void
    {
        $product = $this->makeProduct(true, 'MYSQL-SKU');
        $product->update([
            'product_name' => 'MySQL Search Inverter',
            'barcode' => 'MYSQL-BAR-909',
        ]);

        $saleTracking = Sale::create([
            'setting_id' => 1,
            'date' => '2026-11-10',
            'reference' => 'SL-MYSQL-TRACK',
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'note' => 'MySqlNoteAlpha',
        ]);
        SaleDetails::create([
            'sale_id' => $saleTracking->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $serialNum = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-MYSQL-NUM-11',
            'location_id' => 1,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);
        $serialStr = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-MYSQL-STR-22',
            'location_id' => 1,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SalesOrderSerialTracking::create([
            'sale_id' => $saleTracking->id,
            'product_serial_number_id' => $serialNum->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now(),
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $saleTracking->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $saleTracking->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => 1,
            'serial_numbers' => json_encode(['SN-MYSQL-100%_JSON']),
        ]);

        // Separate sale using numeric vs stringified JSON serial_number_ids on MySQL
        $saleNumericJson = Sale::create([
            'setting_id' => 1,
            'date' => '2026-11-11',
            'reference' => 'SL-MYSQL-JSON-NUM',
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'payment_method' => 'Cash',
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
        ]);
        SaleDetails::create([
            'sale_id' => $saleNumericJson->id,
            'product_id' => null,
            'product_name' => 'Detached Numeric Serial Item',
            'product_code' => 'DET-NUM',
            'quantity' => 1,
            'price' => 5000,
            'unit_price' => 5000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serialNum->id],
        ]);

        $saleStringJson = Sale::create([
            'setting_id' => 1,
            'date' => '2026-11-12',
            'reference' => 'SL-MYSQL-JSON-STR',
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'payment_method' => 'Cash',
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
        ]);
        $strDetail = SaleDetails::create([
            'sale_id' => $saleStringJson->id,
            'product_id' => null,
            'product_name' => 'Detached String Serial Item',
            'product_code' => 'DET-STR',
            'quantity' => 1,
            'price' => 5000,
            'unit_price' => 5000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        DB::table('sale_details')
            ->where('id', $strDetail->id)
            ->update(['serial_number_ids' => json_encode([(string) $serialStr->id])]);

        // Cross-field multi-token AND
        $crossIds = GlobalPaymentSearchQuery::applySaleSearch(Sale::query(), 'MySqlNoteAlpha MYSQL-SKU')
            ->pluck('id')->all();
        $this->assertSame([$saleTracking->id], $crossIds);

        // Full-input barcode
        $barcodeIds = GlobalPaymentSearchQuery::applySaleSearch(Sale::query(), 'mysql-bar-909')
            ->pluck('id')->all();
        $this->assertSame([$saleTracking->id], $barcodeIds);

        // MySQL JSON_CONTAINS on dispatch_details.serial_numbers (case + % wildcard safe)
        $dispatchJsonIds = GlobalPaymentSearchQuery::applySaleSearch(Sale::query(), 'sn-mysql-100%_json')
            ->pluck('id')->all();
        $this->assertSame([$saleTracking->id], $dispatchJsonIds);

        // Deferred candidate discovery: building the search query executes 0 SQL statements outside snapshots.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $numQuery = GlobalPaymentSearchQuery::applySaleSearch(Sale::query()->orderBy('id'), 'sn-mysql-num-11');
        $buildQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, $buildQueries, 'applySaleSearch must not execute eager serial lookup queries at build time.');

        // MySQL HEAD parity: numeric JSON [id] matches, while stringified JSON ["id"] is rejected on MySQL.
        $numIds = $numQuery->pluck('id')->all();
        $this->assertSame([$saleTracking->id, $saleNumericJson->id], $numIds);

        $strIds = GlobalPaymentSearchQuery::applySaleSearch(Sale::query(), 'sn-mysql-str-22')
            ->pluck('id')->all();
        $this->assertSame([], $strIds);

        // POS shared helper parity on MySQL: numeric [id] matches completed POS checkout, stringified ["id"] does not.
        $terminal = PosTerminal::create([
            'setting_id' => 1,
            'name' => 'MySQL Terminal',
            'code' => 'MTERM-' . uniqid(),
            'is_active' => true,
        ]);
        $session = PosSession::create([
            'setting_id' => 1,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opening_float_total' => 0,
        ]);

        $linkPosSale = function (Sale $linkedSale, string $code) use ($terminal, $session): PosTransaction {
            $trx = PosTransaction::create([
                'setting_id' => 1,
                'customer_id' => 1,
                'created_by' => $this->user->id,
                'owner_user_id' => $this->user->id,
                'last_saved_by' => $this->user->id,
                'source_pos_session_id' => $session->id,
                'code' => $code,
                'status' => PosTransaction::STATUS_COMPLETED,
            ]);
            $checkout = PosCheckout::create([
                'setting_id' => 1,
                'pos_transaction_id' => $trx->id,
                'pos_session_id' => $session->id,
                'terminal_id' => $terminal->id,
                'cashier_user_id' => $this->user->id,
                'customer_id' => 1,
                'status' => PosCheckout::STATUS_POSTED,
                'idempotency_key' => uniqid('mysql_', true),
                'payload_hash' => 'hash_' . $code,
                'subtotal' => 10000,
                'grand_total' => 10000,
                'paid_total' => 0,
                'payment_method_id' => null,
                'receipt_number' => 'RCP-' . $code,
            ]);
            $trx->update(['completed_checkout_id' => $checkout->id]);
            PosCheckoutSale::create([
                'pos_checkout_id' => $checkout->id,
                'sale_id' => $linkedSale->id,
                'split_key' => 'main',
                'source_setting_id' => 1,
                'source_location_id' => 1,
                'tax_bucket' => 'NON_PKP',
                'subtotal' => 10000,
                'grand_total' => 10000,
                'paid_total' => 0,
            ]);

            return $trx;
        };

        $posNumTrx = $linkPosSale($saleNumericJson, 'POS-MYSQL-NUM-' . uniqid());
        $linkPosSale($saleStringJson, 'POS-MYSQL-STR-' . uniqid());

        $this->assertSame(
            [$posNumTrx->id],
            GlobalPaymentSearchQuery::applyPosTransactionSearch(PosTransaction::query(), 'sn-mysql-num-11')
                ->pluck('id')->all()
        );
        $this->assertSame(
            [],
            GlobalPaymentSearchQuery::applyPosTransactionSearch(PosTransaction::query(), 'sn-mysql-str-22')
                ->pluck('id')->all()
        );
    }

    public function test_mysql_long_input_exceeding_61_distinct_tokens_without_join_limit(): void
    {
        $tokens = [];
        for ($i = 1; $i <= 65; $i++) {
            $tokens[] = sprintf('tok%02d', $i);
        }

        $noteTokens = implode(' ', array_slice($tokens, 0, 25));
        $customerTokens = implode(' ', array_slice($tokens, 25, 25));
        $productAllTokens = implode(' ', array_slice($tokens, 50, 15));
        $productMissingOneTokens = implode(' ', array_slice($tokens, 50, 14));

        $prod65 = $this->makeProduct(false, 'MYSQL-65');
        $prod65->update(['product_name' => $productAllTokens]);

        $prod64 = $this->makeProduct(false, 'MYSQL-64');
        $prod64->update(['product_name' => $productMissingOneTokens]);

        $longCustomer = \Modules\People\Entities\Customer::create([
            'setting_id' => 1,
            'customer_name' => $customerTokens,
            'customer_email' => 'tok65-' . uniqid() . '@test.com',
            'customer_phone' => '0899' . random_int(10000000, 99999999),
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        $saleAll65 = Sale::create([
            'setting_id' => 1,
            'date' => '2026-11-10',
            'reference' => 'SL-MYSQL-65',
            'customer_id' => $longCustomer->id,
            'customer_name' => $longCustomer->customer_name,
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'note' => $noteTokens,
        ]);
        SaleDetails::create([
            'sale_id' => $saleAll65->id,
            'product_id' => $prod65->id,
            'product_name' => $productAllTokens,
            'product_code' => $prod65->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $saleOnly64 = Sale::create([
            'setting_id' => 1,
            'date' => '2026-11-10',
            'reference' => 'SL-MYSQL-64',
            'customer_id' => $longCustomer->id,
            'customer_name' => $longCustomer->customer_name,
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'note' => $noteTokens,
        ]);
        SaleDetails::create([
            'sale_id' => $saleOnly64->id,
            'product_id' => $prod64->id,
            'product_name' => $productMissingOneTokens,
            'product_code' => $prod64->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Must execute cleanly on MySQL 8 without hitting error 1116 ("MySQL can only use 61 tables in a join").
        $matchedIds = GlobalPaymentSearchQuery::applySaleSearch(Sale::query(), implode(' ', $tokens))
            ->pluck('id')->all();

        $this->assertSame([$saleAll65->id], $matchedIds);
    }

    public function test_mysql_single_query_summarize_and_window_count_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Sale::create([
                'setting_id' => 1,
                'date' => '2026-11-0' . $i,
                'due_date' => '2026-11-10',
                'reference' => 'MYSQL-WIN-' . $i,
                'customer_id' => 1,
                'customer_name' => 'Test Customer',
                'payment_method' => 'Cash',
                'total_amount' => 1000 * $i,
                'paid_amount' => 0,
                'due_amount' => 1000 * $i,
                'status' => Sale::STATUS_DISPATCHED,
                'payment_status' => 'UNPAID',
            ]);
        }

        $summarySvc = app(GlobalSaleSummaryQuery::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $summary = $summarySvc->summarize(null, [], null, null, null, null);
        $summaryQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $summaryQueries);
        $this->assertSame(5, $summary['outstanding']['count']);
        $this->assertEquals(15000.0, $summary['outstanding']['total']);

        // Page 1 window count: no separate COUNT(*)
        $baseQuery = Sale::query()
            ->globalPaymentEligible()
            ->whereNull('sales.archived_at')
            ->whereLiveDueAmountGreaterThan(0)
            ->orderByDesc('sales.id');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page1 = $summarySvc->paginateFinancialSales(
            $baseQuery,
            2,
            1,
            fn (array $ids) => Sale::query()->whereIn('id', $ids)->orderByDesc('id')->get()
        );
        $p1Queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(5, $page1->total());
        $this->assertCount(2, $page1->items());
        $this->assertSame(0, collect($p1Queries)->filter(fn ($q) => str_contains(strtolower($q['query']), 'count(*) as aggregate'))->count());

        // Out-of-range page > 1 falls back to count and hydrates 0 models
        DB::flushQueryLog();
        DB::enableQueryLog();
        $pageOor = $summarySvc->paginateFinancialSales(
            $baseQuery,
            2,
            9,
            fn (array $ids) => Sale::query()->whereIn('id', $ids)->get()
        );
        $oorQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(5, $pageOor->total());
        $this->assertCount(0, $pageOor->items());
        $this->assertSame(1, collect($oorQueries)->filter(fn ($q) => str_contains(strtolower($q['query']), 'count(*) as aggregate'))->count());
    }

    public function test_mysql_two_connection_repeatable_read_snapshot_and_enclosing_tx_preservation(): void
    {
        $sale = Sale::create([
            'setting_id' => 1,
            'date' => '2026-11-12',
            'due_date' => '2026-11-20',
            'reference' => 'SL-SNAP-RACE',
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
        ]);

        // Also seed a POS transaction & checkout so another connection can alter POS split mapping concurrently
        $terminal = PosTerminal::create([
            'setting_id' => 1,
            'name' => 'MySQL Snap Terminal',
            'code' => 'T-MYSQL-SNAP',
            'is_active' => true,
        ]);
        $session = PosSession::create([
            'setting_id' => 1,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opening_float_total' => 0,
        ]);
        $trx = PosTransaction::create([
            'setting_id' => 1,
            'customer_id' => 1,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $session->id,
            'code' => 'TRX-MYSQL-SNAP',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $checkout = PosCheckout::create([
            'setting_id' => 1,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => 1,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => 'idem-snap-' . uniqid(),
            'payload_hash' => 'hash-snap-' . uniqid(),
            'subtotal' => 10000,
            'grand_total' => 10000,
            'paid_total' => 0,
            'sale_id' => $sale->id,
        ]);
        $trx->forceFill(['completed_checkout_id' => $checkout->id])->save();
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'split_key' => 'main',
            'source_setting_id' => 1,
            'source_location_id' => 1,
            'tax_bucket' => 'NON_PKP',
            'subtotal' => 10000,
            'grand_total' => 10000,
            'paid_total' => 0,
        ]);

        $summarySvc = app(GlobalSaleSummaryQuery::class);
        $posReadSvc = app(PosGlobalPaymentReadService::class);
        $conn = DB::connection(self::CONNECTION);
        $writer = DB::connection(self::WRITER_CONNECTION);

        $this->assertSame(0, $conn->transactionLevel());

        $observed = ConsistentReadSnapshot::run(function () use ($summarySvc, $posReadSvc, $writer, $sale, $checkout): array {
            $stage1SaleSummary = $summarySvc->summarize(null, [], null, null, null, null);
            $stage1PosSummary = $posReadSvc->calculateSummaryCards(null, [], null, null, null, null);

            // Another connection concurrently commits BOTH a SalePayment and a POS split mapping change
            $writer->table('sale_payments')->insert([
                'sale_id' => $sale->id,
                'amount' => 10000,
                'date' => '2026-11-14',
                'reference' => 'RACE-PAY-1',
                'status' => SalePayment::STATUS_ACTIVE,
                'payment_method' => 'Cash',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $writer->table('pos_checkout_sales')->insert([
                'pos_checkout_id' => $checkout->id,
                'sale_id' => $sale->id,
                'split_key' => 'extra_race_split',
                'source_setting_id' => 1,
                'source_location_id' => 1,
                'tax_bucket' => 'NON_PKP',
                'subtotal' => 5000,
                'grand_total' => 5000,
                'paid_total' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Stage 2 in the same snapshot must still see the pre-commit state (no torn read!)
            $stage2SaleSummary = $summarySvc->summarize(null, [], null, null, null, null);
            $stage2PosSummary = $posReadSvc->calculateSummaryCards(null, [], null, null, null, null);

            return [
                's1_sale' => $stage1SaleSummary,
                's2_sale' => $stage2SaleSummary,
                's1_pos' => $stage1PosSummary,
                's2_pos' => $stage2PosSummary,
            ];
        }, $conn);

        $this->assertSame(0, $conn->transactionLevel());
        $this->assertSame(1, $observed['s1_sale']['outstanding']['count']);
        $this->assertSame(1, $observed['s2_sale']['outstanding']['count']);
        $this->assertEquals(10000.0, $observed['s2_sale']['outstanding']['total']);
        $this->assertSame(1, $observed['s1_pos']['outstanding']['count']);
        $this->assertSame(1, $observed['s2_pos']['outstanding']['count']);
        $this->assertEquals(10000.0, $observed['s2_pos']['outstanding']['total']);

        // Subsequent fresh read after snapshot closes observes both concurrent commits
        $freshSaleSummary = $summarySvc->summarize(null, [], null, null, null, null);
        $this->assertSame(0, $freshSaleSummary['outstanding']['count']);
        $this->assertSame(1, $freshSaleSummary['recent']['count']);

        // Enclosing transaction is preserved and not committed by ConsistentReadSnapshot
        $conn->beginTransaction();
        $inner = ConsistentReadSnapshot::run(function () use ($conn, $sale): int {
            $conn->table('sale_payments')->where('sale_id', $sale->id)->update(['amount' => 9999]);
            return $conn->transactionLevel();
        }, $conn);
        $this->assertSame(1, $inner);
        $this->assertSame(1, $conn->transactionLevel());
        $conn->rollBack();
        $this->assertEquals(10000.0, (float) $conn->table('sale_payments')->where('sale_id', $sale->id)->value('amount'));

        // Failure rolls back snapshot transaction cleanly without leaking open transaction
        try {
            ConsistentReadSnapshot::run(function () use ($conn): void {
                $conn->select('SELECT 1');
                throw new RuntimeException('Simulated MySQL failure');
            }, $conn);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated MySQL failure', $e->getMessage());
        }
        $this->assertSame(0, $conn->transactionLevel());
    }

    public function test_mysql_sale_table_search_and_non_financial_reads_share_snapshot(): void
    {
        \Illuminate\Support\Facades\Gate::define('salePayments.global.access', fn () => true);

        $product = $this->makeProduct(true, 'MYSQL-SNAP');
        $sale = Sale::create([
            'setting_id' => 1,
            'date' => '2026-11-10',
            'reference' => 'SL-MYSQL-SNAP-' . uniqid(),
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'payment_method' => 'Cash',
            'total_amount' => 15000,
            'paid_amount' => 0,
            'due_amount' => 15000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
        ]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 15000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $conn = DB::connection(self::CONNECTION);
        $writer = DB::connection(self::WRITER_CONNECTION);
        $this->assertSame(0, $conn->transactionLevel());

        $newSerialCode = 'SN-MYSQL-SNAP-' . uniqid();

        $insideSnapshot = ConsistentReadSnapshot::run(function () use ($conn, $writer, $sale, $product, $newSerialCode): array {
            $stage1SearchIds = GlobalPaymentSearchQuery::applySaleSearch(Sale::on(self::CONNECTION), $newSerialCode)
                ->pluck('id')->all();
            $stage1Due = (float) Sale::on(self::CONNECTION)->whereKey($sale->id)->value('due_amount');

            // Concurrent writer commits both a new serial number assignment and a settlement payment
            $now = now()->toDateTimeString();
            $serialId = $writer->table('product_serial_numbers')->insertGetId([
                'product_id' => $product->id,
                'location_id' => 1,
                'serial_number' => $newSerialCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $writer->table('sales_order_serial_tracking')->insert([
                'sale_id' => $sale->id,
                'product_serial_number_id' => $serialId,
                'quantity_allocated' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $writer->table('sale_payments')->insert([
                'sale_id' => $sale->id,
                'amount' => 5000,
                'date' => '2026-11-11',
                'reference' => 'PAY-SNAP-' . $sale->id,
                'payment_method' => 'Cash',
                'status' => SalePayment::STATUS_ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $writer->table('sales')->where('id', $sale->id)->update([
                'paid_amount' => 5000,
                'due_amount' => 10000,
                'payment_status' => 'PARTIAL',
                'updated_at' => $now,
            ]);

            // Stage 2 inside the same REPEATABLE READ snapshot must not see newly committed serial or payment
            $stage2SearchIds = GlobalPaymentSearchQuery::applySaleSearch(Sale::on(self::CONNECTION), $newSerialCode)
                ->pluck('id')->all();
            $stage2RefRow = Sale::on(self::CONNECTION)->whereKey($sale->id)->first();
            $stage2Component = \Livewire\Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
                ->set('search', $newSerialCode);

            return [
                'stage1_ids' => $stage1SearchIds,
                'stage1_due' => $stage1Due,
                'stage2_ids' => $stage2SearchIds,
                'stage2_due' => (float) $stage2RefRow->due_amount,
                'stage2_html' => $stage2Component->html(),
            ];
        }, $conn);

        $this->assertSame([], $insideSnapshot['stage1_ids']);
        $this->assertEquals(15000.0, $insideSnapshot['stage1_due']);
        $this->assertSame([], $insideSnapshot['stage2_ids']);
        $this->assertEquals(15000.0, $insideSnapshot['stage2_due']);
        $this->assertStringNotContainsString($sale->reference, $insideSnapshot['stage2_html']);

        // Outside the previous snapshot, SaleTable must open its own transaction before running BOTH search discovery and pagination
        $observedLevels = [];
        DB::listen(function ($query) use (&$observedLevels): void {
            if ($query->connectionName !== self::CONNECTION) {
                return;
            }
            if (preg_match('/\b(sales|sale_details|product_serial_numbers|sales_order_serial_tracking|dispatch_details)\b/i', $query->sql)) {
                $observedLevels[] = $query->connection->transactionLevel();
            }
        });

        \Livewire\Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', $newSerialCode)
            ->assertSee($sale->reference);

        \Livewire\Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('sortField', 'reference')
            ->set('search', $newSerialCode)
            ->assertSee($sale->reference);

        $this->assertNotEmpty($observedLevels);
        $this->assertSame([], array_values(array_filter($observedLevels, fn (int $lvl): bool => $lvl < 1)));
        $this->assertSame(0, $conn->transactionLevel());
    }
}

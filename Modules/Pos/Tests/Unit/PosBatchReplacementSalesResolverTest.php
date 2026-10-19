<?php

namespace Modules\Pos\Tests\Unit;

use App\Scopes\ArchivingScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReachableSalesResolver;
use Modules\Pos\Services\PosSettlementProjectionService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PosBatchReplacementSalesResolverTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected Customer $customer;
    protected PaymentMethod $cashMethod;
    protected Product $product;
    protected Location $location1;
    protected Location $location2;
    protected $cashier;
    protected PosTerminal $terminal;
    protected PosSession $session;
    protected PosReachableSalesResolver $resolver;
    protected PosSettlementProjectionService $projectionService;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00'));

        $this->setting1 = Setting::factory()->create(['company_name' => 'Setting Pusat']);
        $this->setting2 = Setting::factory()->create(['company_name' => 'Setting Barat']);
        session(['setting_id' => $this->setting1->id]);

        $this->customer = Customer::factory()->create(['setting_id' => $this->setting1->id]);

        $coa = ChartOfAccount::firstOrCreate(
            ['setting_id' => $this->setting1->id, 'account_number' => '1100-TEST-B'],
            ['name' => 'Cash Account Test', 'account_number' => '1100-TEST-B', 'category' => 'Kas & Bank', 'setting_id' => $this->setting1->id]
        );
        $this->cashMethod = PaymentMethod::firstOrCreate(
            ['name' => 'Cash'],
            ['name' => 'Cash', 'is_cash' => true, 'coa_id' => $coa->id]
        );

        $this->cashier = \App\Models\User::factory()->create();
        $this->actingAs($this->cashier);

        $this->location1 = Location::create(['setting_id' => $this->setting1->id, 'name' => 'Lokasi 1']);
        $this->location2 = Location::create(['setting_id' => $this->setting2->id, 'name' => 'Lokasi 2']);

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Terminal 1',
            'code' => 'T-01',
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opening_float_total' => 0,
        ]);

        $unit = Unit::create(['name' => 'PCS', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);
        $cat = Category::create([
            'category_code' => 'GEN',
            'category_name' => 'General',
            'created_by' => $this->cashier->id,
            'setting_id' => $this->setting1->id,
        ]);
        $this->product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Item Test',
            'product_code' => 'ITM-01',
            'barcode' => 'ITM-01',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => $unit->id,
            'category_id' => $cat->id,
        ]);

        $this->resolver = new PosReachableSalesResolver();
        $this->projectionService = new PosSettlementProjectionService($this->resolver);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_batch_and_fallback_output_equivalent_for_inline_checkout_with_replacement(): void
    {
        $code = 'INL-001';
        $total = 50000.0;

        $sale = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'SO-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => $total,
            'due_amount' => $total,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('', true),
            'payload_hash' => 'hash_' . $code,
            'subtotal' => $total,
            'grand_total' => $total,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code,
            'sale_id' => $sale->id,
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        // Replacement sale
        $replacementSale = Sale::create([
            'setting_id' => $this->setting2->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'REP-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'total_amount' => 15000,
            'paid_amount' => 15000,
            'due_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        $posReturn = PosReturn::create([
            'pos_checkout_id' => $checkout->id,
            'pos_transaction_id' => $trx->id,
            'transaction_code' => $trx->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash_ret_' . $code,
            'total_amount' => 15000,
            'reference' => 'RET-' . $code,
            'status' => PosReturn::STATUS_COMPLETED,
            'is_reversed' => false,
            'customer_id' => $this->customer->id,
            'setting_id' => $this->setting1->id,
            'created_by' => $this->cashier->id,
        ]);

        $returnLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 0,
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $this->product->id,
            'product_name' => 'Item Test',
            'product_code' => 'ITM-01',
            'quantity' => 1,
            'unit_price' => 15000,
            'line_total' => 15000,
        ]);

        $dispatch = Dispatch::create(['sale_id' => $replacementSale->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $replacementSale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location2->id,
            'dispatched_quantity' => 1,
            'pos_return_line_id' => $returnLine->id,
            'quantity' => 1,
        ]);

        // Fallback resolution (single-record)
        $fallbackDiagnostics = $this->resolver->resolveWithDiagnostics($trx);
        $fallbackProjection = $this->projectionService->project($trx);

        // Batched resolution
        $context = $this->resolver->prepareReplacementSales([$trx]);
        $batchDiagnostics = $this->resolver->resolveWithDiagnostics($trx, $context);
        $batchProjection = $this->projectionService->project($trx, $context);

        $this->assertEquals($fallbackDiagnostics['is_valid'], $batchDiagnostics['is_valid']);
        $this->assertEquals($fallbackDiagnostics['anomalies'], $batchDiagnostics['anomalies']);
        $this->assertEquals(
            $fallbackDiagnostics['sales']->pluck('id')->all(),
            $batchDiagnostics['sales']->pluck('id')->all(),
            'Sale order must match between fallback and batch.'
        );
        $this->assertEquals($fallbackProjection['effective_paid'], $batchProjection['effective_paid']);
        $this->assertEquals($fallbackProjection['live_due'], $batchProjection['live_due']);
        $this->assertEquals($fallbackProjection['payment_status'], $batchProjection['payment_status']);
    }

    public function test_batch_and_fallback_output_equivalent_for_split_checkout_with_replacement(): void
    {
        $code = 'SPL-001';
        $part1Amount = 30000.0;
        $part2Amount = 20000.0;

        $sale1 = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'SO-' . $code . '-1',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => $part1Amount,
            'due_amount' => $part1Amount,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
        ]);
        $sale2 = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'SO-' . $code . '-2',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => $part2Amount,
            'due_amount' => $part2Amount,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('', true),
            'payload_hash' => 'hash_' . $code,
            'subtotal' => $part1Amount + $part2Amount,
            'grand_total' => $part1Amount + $part2Amount,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code,
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        $cs1 = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'split_key' => 'part1',
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'tax_bucket' => 'NON_PKP',
            'subtotal' => $part1Amount,
            'grand_total' => $part1Amount,
            'paid_total' => 0,
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'split_key' => 'part2',
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'tax_bucket' => 'NON_PKP',
            'subtotal' => $part2Amount,
            'grand_total' => $part2Amount,
            'paid_total' => 0,
        ]);

        // Replacement sale
        $replacementSale = Sale::create([
            'setting_id' => $this->setting2->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'REP-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'total_amount' => 12000,
            'paid_amount' => 12000,
            'due_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        $posReturn = PosReturn::create([
            'pos_checkout_id' => $checkout->id,
            'pos_transaction_id' => $trx->id,
            'transaction_code' => $trx->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash_ret_' . $code,
            'total_amount' => 12000,
            'reference' => 'RET-' . $code,
            'status' => PosReturn::STATUS_COMPLETED,
            'is_reversed' => false,
            'customer_id' => $this->customer->id,
            'setting_id' => $this->setting1->id,
            'created_by' => $this->cashier->id,
        ]);

        $returnLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $cs1->id,
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale1->id,
            'sale_detail_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $this->product->id,
            'product_name' => 'Item Test',
            'product_code' => 'ITM-01',
            'quantity' => 1,
            'unit_price' => 12000,
            'line_total' => 12000,
        ]);

        $dispatch = Dispatch::create(['sale_id' => $replacementSale->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $replacementSale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location2->id,
            'dispatched_quantity' => 1,
            'pos_return_line_id' => $returnLine->id,
            'quantity' => 1,
        ]);

        // Fallback resolution
        $fallbackDiagnostics = $this->resolver->resolveWithDiagnostics($trx);
        $fallbackProjection = $this->projectionService->project($trx);

        // Batched resolution
        $context = $this->resolver->prepareReplacementSales([$trx]);
        $batchDiagnostics = $this->resolver->resolveWithDiagnostics($trx, $context);
        $batchProjection = $this->projectionService->project($trx, $context);

        $this->assertEquals($fallbackDiagnostics['is_valid'], $batchDiagnostics['is_valid']);
        $this->assertEquals($fallbackDiagnostics['anomalies'], $batchDiagnostics['anomalies']);
        $this->assertEquals(
            $fallbackDiagnostics['sales']->pluck('id')->all(),
            $batchDiagnostics['sales']->pluck('id')->all()
        );
        $this->assertEquals($fallbackProjection['total_amount'], $batchProjection['total_amount']);
        $this->assertEquals($fallbackProjection['live_due'], $batchProjection['live_due']);
    }

    public function test_batch_preparation_preserves_missing_and_duplicate_mapping_diagnostics(): void
    {
        $code = 'ANOM-001';
        $total = 40000.0;

        $sale = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'SO-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => $total,
            'due_amount' => $total,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('', true),
            'payload_hash' => 'hash_' . $code,
            'subtotal' => $total,
            'grand_total' => $total,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code,
            'sale_id' => $sale->id,
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        $posReturn = PosReturn::create([
            'pos_checkout_id' => $checkout->id,
            'pos_transaction_id' => $trx->id,
            'transaction_code' => $trx->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash_ret_' . $code,
            'total_amount' => 10000,
            'reference' => 'RET-' . $code,
            'status' => PosReturn::STATUS_COMPLETED,
            'is_reversed' => false,
            'customer_id' => $this->customer->id,
            'setting_id' => $this->setting1->id,
            'created_by' => $this->cashier->id,
        ]);

        $returnLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 0,
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $this->product->id,
            'product_name' => 'Item Test',
            'product_code' => 'ITM-01',
            'quantity' => 1,
            'unit_price' => 10000,
            'line_total' => 10000,
        ]);

        $replacementSale = Sale::create([
            'setting_id' => $this->setting2->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'REP-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        $dispatch = Dispatch::create(['sale_id' => $replacementSale->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $replacementSale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location2->id,
            'dispatched_quantity' => 1,
            'pos_return_line_id' => $returnLine->id,
            'quantity' => 1,
        ]);

        // Add split mapping anomalies: null sale_id (missing mapping) and duplicate sale_id mapping
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => 'split_1',
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'tax_bucket' => 'NON_TAX',
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => 'split_2',
            'sale_id' => null, // Missing mapping (null sale_id)
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'tax_bucket' => 'NON_TAX',
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => 'split_3',
            'sale_id' => $sale->id, // Duplicate sale_id
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'tax_bucket' => 'NON_TAX',
        ]);

        $fallback = $this->resolver->resolveWithDiagnostics($trx);

        $context = $this->resolver->prepareReplacementSales([$trx]);
        $batched = $this->resolver->resolveWithDiagnostics($trx, $context);

        $this->assertSame($fallback['is_valid'], $batched['is_valid']);
        $this->assertFalse($batched['is_valid']);
        $this->assertEquals($fallback['anomalies'], $batched['anomalies']);
        $this->assertStringContainsString('null or empty sale_id', implode(' ', $batched['anomalies']));
        $this->assertStringContainsString('Duplicate sale_id', implode(' ', $batched['anomalies']));
        $this->assertEquals($fallback['sales']->pluck('id')->all(), $batched['sales']->pluck('id')->all());
    }

    public function test_batch_preparation_loads_archived_replacement_sales(): void
    {
        $code = 'ARC-001';
        $total = 50000.0;

        $sale = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'SO-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => $total,
            'due_amount' => $total,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('', true),
            'payload_hash' => 'hash_' . $code,
            'subtotal' => $total,
            'grand_total' => $total,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code,
            'sale_id' => $sale->id,
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        $replacementSale = Sale::create([
            'setting_id' => $this->setting2->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'REP-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'total_amount' => 20000,
            'paid_amount' => 20000,
            'due_amount' => 0,
            'payment_method' => 'Cash',
            'archived_at' => now(),
        ]);

        $posReturn = PosReturn::create([
            'pos_checkout_id' => $checkout->id,
            'pos_transaction_id' => $trx->id,
            'transaction_code' => $trx->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash_ret_' . $code,
            'total_amount' => 20000,
            'reference' => 'RET-' . $code,
            'status' => PosReturn::STATUS_COMPLETED,
            'is_reversed' => false,
            'customer_id' => $this->customer->id,
            'setting_id' => $this->setting1->id,
            'created_by' => $this->cashier->id,
        ]);

        $returnLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 0,
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $this->product->id,
            'product_name' => 'Item Test',
            'product_code' => 'ITM-01',
            'quantity' => 1,
            'unit_price' => 20000,
            'line_total' => 20000,
        ]);

        $dispatch = Dispatch::create(['sale_id' => $replacementSale->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $replacementSale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location2->id,
            'dispatched_quantity' => 1,
            'pos_return_line_id' => $returnLine->id,
            'quantity' => 1,
        ]);

        $context = $this->resolver->prepareReplacementSales([$trx]);
        $batched = $this->resolver->resolveWithDiagnostics($trx, $context);

        $this->assertTrue($batched['sales']->contains('id', $replacementSale->id));
        $this->assertNotNull($batched['sales']->firstWhere('id', $replacementSale->id)->archived_at);
    }

    public function test_batch_preparation_associates_returns_by_checkout_id_or_transaction_id(): void
    {
        $code1 = 'ASSOC-001';
        $code2 = 'ASSOC-002';

        $sale1 = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'SO-' . $code1,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => 10000,
            'due_amount' => 10000,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
        ]);
        $sale2 = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'SO-' . $code2,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => 20000,
            'due_amount' => 20000,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        $trx1 = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'code' => $code1,
            'status' => PosTransaction::STATUS_COMPLETED,
        ]);
        $trx2 = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'code' => $code2,
            'status' => PosTransaction::STATUS_COMPLETED,
        ]);

        $checkout1 = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx1->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('', true),
            'payload_hash' => 'hash_' . $code1,
            'subtotal' => 10000,
            'grand_total' => 10000,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code1,
            'sale_id' => $sale1->id,
        ]);
        $trx1->update(['completed_checkout_id' => $checkout1->id]);

        $checkout2 = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx2->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('', true),
            'payload_hash' => 'hash_' . $code2,
            'subtotal' => 20000,
            'grand_total' => 20000,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code2,
            'sale_id' => $sale2->id,
        ]);
        $trx2->update(['completed_checkout_id' => $checkout2->id]);

        $repSale1 = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'REP-1',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'total_amount' => 5000,
            'paid_amount' => 5000,
            'due_amount' => 0,
            'payment_method' => 'Cash',
        ]);
        $repSale2 = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'REP-2',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'total_amount' => 6000,
            'paid_amount' => 6000,
            'due_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        // Return 1 associated via pos_checkout_id only (transaction id 0/null)
        $ret1 = PosReturn::create([
            'pos_checkout_id' => $checkout1->id,
            'pos_transaction_id' => 0,
            'transaction_code' => $trx1->code,
            'receipt_number' => $checkout1->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash_r1',
            'total_amount' => 5000,
            'reference' => 'RET-1',
            'status' => PosReturn::STATUS_COMPLETED,
            'is_reversed' => false,
            'customer_id' => $this->customer->id,
            'setting_id' => $this->setting1->id,
            'created_by' => $this->cashier->id,
        ]);
        $line1 = PosReturnLine::create([
            'pos_return_id' => $ret1->id,
            'pos_checkout_sale_id' => 0,
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale1->id,
            'sale_detail_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $this->product->id,
            'product_name' => 'Item',
            'product_code' => 'ITM',
            'quantity' => 1,
            'unit_price' => 5000,
            'line_total' => 5000,
        ]);
        $disp1 = Dispatch::create(['sale_id' => $repSale1->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $disp1->id,
            'sale_id' => $repSale1->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location1->id,
            'dispatched_quantity' => 1,
            'pos_return_line_id' => $line1->id,
            'quantity' => 1,
        ]);

        // Return 2 associated via pos_transaction_id only (checkout id 0)
        $ret2 = PosReturn::create([
            'pos_checkout_id' => 0,
            'pos_transaction_id' => $trx2->id,
            'transaction_code' => $trx2->code,
            'receipt_number' => $checkout2->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash_r2',
            'total_amount' => 6000,
            'reference' => 'RET-2',
            'status' => PosReturn::STATUS_COMPLETED,
            'is_reversed' => false,
            'customer_id' => $this->customer->id,
            'setting_id' => $this->setting1->id,
            'created_by' => $this->cashier->id,
        ]);
        $line2 = PosReturnLine::create([
            'pos_return_id' => $ret2->id,
            'pos_checkout_sale_id' => 0,
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale2->id,
            'sale_detail_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $this->product->id,
            'product_name' => 'Item',
            'product_code' => 'ITM',
            'quantity' => 1,
            'unit_price' => 6000,
            'line_total' => 6000,
        ]);
        $disp2 = Dispatch::create(['sale_id' => $repSale2->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $disp2->id,
            'sale_id' => $repSale2->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location1->id,
            'dispatched_quantity' => 1,
            'pos_return_line_id' => $line2->id,
            'quantity' => 1,
        ]);

        $context = $this->resolver->prepareReplacementSales([$trx1, $trx2]);

        $res1 = $this->resolver->resolveSales($trx1, $context);
        $res2 = $this->resolver->resolveSales($trx2, $context);

        $this->assertTrue($res1->contains('id', $repSale1->id));
        $this->assertFalse($res1->contains('id', $repSale2->id));

        $this->assertTrue($res2->contains('id', $repSale2->id));
        $this->assertFalse($res2->contains('id', $repSale1->id));
    }

    public function test_explicit_empty_results_issue_zero_queries(): void
    {
        $code = 'EMPTY-001';
        $total = 30000.0;

        $sale = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'SO-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => $total,
            'due_amount' => $total,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('', true),
            'payload_hash' => 'hash_' . $code,
            'subtotal' => $total,
            'grand_total' => $total,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code,
            'sale_id' => $sale->id,
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        $eagerTrx = PosTransaction::query()
            ->with(PosSettlementProjectionService::reachableSalesEagerLoad())
            ->findOrFail($trx->id);

        $context = $this->resolver->prepareReplacementSales([$eagerTrx]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $projection = $this->projectionService->project($eagerTrx, $context);

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queryLog, 'Projecting with prepared empty context must issue 0 queries.');
        $this->assertEquals(30000.0, $projection['live_due']);
    }

    public function test_query_growth_remains_bounded_per_batch_as_transaction_count_grows(): void
    {
        $transactions = [];

        for ($i = 1; $i <= 20; $i++) {
            $code = sprintf('GROWTH-%03d', $i);
            $total = 10000.0;

            $sale = Sale::create([
                'setting_id' => $this->setting1->id,
                'customer_id' => $this->customer->id,
                'customer_name' => $this->customer->customer_name,
                'reference' => 'SO-' . $code,
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'status' => Sale::STATUS_DISPATCHED,
                'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
                'total_amount' => $total,
                'due_amount' => $total,
                'paid_amount' => 0,
                'payment_method' => 'Cash',
            ]);

            $trx = PosTransaction::create([
                'setting_id' => $this->setting1->id,
                'customer_id' => $this->customer->id,
                'created_by' => $this->cashier->id,
                'owner_user_id' => $this->cashier->id,
                'last_saved_by' => $this->cashier->id,
                'source_pos_session_id' => $this->session->id,
                'code' => $code,
                'status' => PosTransaction::STATUS_COMPLETED,
            ]);

            $checkout = PosCheckout::create([
                'setting_id' => $this->setting1->id,
                'pos_transaction_id' => $trx->id,
                'pos_session_id' => $this->session->id,
                'terminal_id' => $this->terminal->id,
                'cashier_user_id' => $this->cashier->id,
                'customer_id' => $this->customer->id,
                'status' => PosCheckout::STATUS_POSTED,
                'idempotency_key' => uniqid('', true),
                'payload_hash' => 'hash_' . $code,
                'subtotal' => $total,
                'grand_total' => $total,
                'paid_total' => 0,
                'payment_method_id' => $this->cashMethod->id,
                'receipt_number' => 'RCP-' . $code,
                'sale_id' => $sale->id,
            ]);
            $trx->update(['completed_checkout_id' => $checkout->id]);

            if ($i % 3 === 0) {
                // Add replacement return
                $repSale = Sale::create([
                    'setting_id' => $this->setting1->id,
                    'customer_id' => $this->customer->id,
                    'customer_name' => $this->customer->customer_name,
                    'reference' => 'REP-' . $code,
                    'date' => now()->toDateString(),
                    'due_date' => now()->addDays(7)->toDateString(),
                    'status' => Sale::STATUS_DISPATCHED,
                    'payment_status' => Sale::PAYMENT_STATUS_PAID,
                    'total_amount' => 5000,
                    'paid_amount' => 5000,
                    'due_amount' => 0,
                    'payment_method' => 'Cash',
                ]);
                $ret = PosReturn::create([
                    'pos_checkout_id' => $checkout->id,
                    'pos_transaction_id' => $trx->id,
                    'transaction_code' => $trx->code,
                    'receipt_number' => $checkout->receipt_number,
                    'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
                    'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
                    'source_snapshot' => [],
                    'source_snapshot_hash' => 'hash_' . $code,
                    'total_amount' => 5000,
                    'reference' => 'RET-' . $code,
                    'status' => PosReturn::STATUS_COMPLETED,
                    'is_reversed' => false,
                    'customer_id' => $this->customer->id,
                    'setting_id' => $this->setting1->id,
                    'created_by' => $this->cashier->id,
                ]);
                $line = PosReturnLine::create([
                    'pos_return_id' => $ret->id,
                    'pos_checkout_sale_id' => 0,
                    'source_setting_id' => $this->setting1->id,
                    'source_location_id' => $this->location1->id,
                    'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
                    'sale_id' => $sale->id,
                    'sale_detail_id' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                    'product_id' => $this->product->id,
                    'product_name' => 'Item',
                    'product_code' => 'ITM',
                    'quantity' => 1,
                    'unit_price' => 5000,
                    'line_total' => 5000,
                ]);
                $disp = Dispatch::create(['sale_id' => $repSale->id, 'status' => Dispatch::STATUS_APPROVED]);
                DispatchDetail::create([
                    'dispatch_id' => $disp->id,
                    'sale_id' => $repSale->id,
                    'product_id' => $this->product->id,
                    'location_id' => $this->location1->id,
                    'dispatched_quantity' => 1,
                    'pos_return_line_id' => $line->id,
                    'quantity' => 1,
                ]);
            }

            $transactions[] = $trx;
        }

        // Test 5 transactions
        DB::enableQueryLog();
        DB::flushQueryLog();
        $context5 = $this->resolver->prepareReplacementSales(array_slice($transactions, 0, 5));
        $queryCount5 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Test 20 transactions
        DB::enableQueryLog();
        DB::flushQueryLog();
        $context20 = $this->resolver->prepareReplacementSales($transactions);
        $queryCount20 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Both batches must execute at most 10 queries total (bounded, not proportional to transaction count)
        $this->assertLessThanOrEqual(10, $queryCount5, 'Preparation for 5 records must be bounded (<= 10 queries).');
        $this->assertLessThanOrEqual(10, $queryCount20, 'Preparation for 20 records must be bounded (<= 10 queries).');
        $this->assertEquals($queryCount5, $queryCount20, 'Batch discovery queries must remain constant as transaction count grows.');
    }

    public function test_consecutive_operations_do_not_reuse_stale_context(): void
    {
        $code = 'STALE-001';
        $total = 20000.0;

        $sale = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'SO-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'total_amount' => $total,
            'due_amount' => $total,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('', true),
            'payload_hash' => 'hash_' . $code,
            'subtotal' => $total,
            'grand_total' => $total,
            'paid_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'receipt_number' => 'RCP-' . $code,
            'sale_id' => $sale->id,
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        // Operation 1: No returns exist yet
        $context1 = $this->resolver->prepareReplacementSales([$trx]);
        $sales1 = $this->resolver->resolveSales($trx, $context1);
        $this->assertCount(1, $sales1);

        // Mutate: Add a completed replacement return
        $repSale = Sale::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'REP-' . $code,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'total_amount' => 5000,
            'paid_amount' => 5000,
            'due_amount' => 0,
            'payment_method' => 'Cash',
        ]);
        $ret = PosReturn::create([
            'pos_checkout_id' => $checkout->id,
            'pos_transaction_id' => $trx->id,
            'transaction_code' => $trx->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash_' . $code,
            'total_amount' => 5000,
            'reference' => 'RET-' . $code,
            'status' => PosReturn::STATUS_COMPLETED,
            'is_reversed' => false,
            'customer_id' => $this->customer->id,
            'setting_id' => $this->setting1->id,
            'created_by' => $this->cashier->id,
        ]);
        $line = PosReturnLine::create([
            'pos_return_id' => $ret->id,
            'pos_checkout_sale_id' => 0,
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $this->product->id,
            'product_name' => 'Item',
            'product_code' => 'ITM',
            'quantity' => 1,
            'unit_price' => 5000,
            'line_total' => 5000,
        ]);
        $disp = Dispatch::create(['sale_id' => $repSale->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $disp->id,
            'sale_id' => $repSale->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location1->id,
            'dispatched_quantity' => 1,
            'pos_return_line_id' => $line->id,
            'quantity' => 1,
        ]);

        // Operation 2: Consecutive call must observe fresh state
        $context2 = $this->resolver->prepareReplacementSales([$trx]);
        $sales2 = $this->resolver->resolveSales($trx, $context2);

        $this->assertCount(2, $sales2, 'Second consecutive operation must immediately observe the new replacement sale.');
        $this->assertTrue($sales2->contains('id', $repSale->id));
    }
}

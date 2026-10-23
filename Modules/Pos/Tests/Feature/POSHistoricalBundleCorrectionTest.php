<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosHistoricalBundleCorrectionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;

class POSHistoricalBundleCorrectionTest extends PosTransactionFeatureTestCase
{
    protected Setting $settingA;
    protected Setting $settingB;
    protected Location $locationA;
    protected Location $locationB;
    protected $terminalA;
    protected $sessionA;
    protected $customer;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingA = $this->createSetting('Owner A (Parent)', false);
        $this->settingB = $this->createSetting('Owner B (Component)', false);

        [$this->terminalA, $this->locationA] = $this->createTerminalWithLocation($this->settingA);
        [, $this->locationB] = $this->createTerminalWithLocation($this->settingB);

        $this->user = $this->createUserForSetting($this->settingA, 'Repair Operator', []);
        $this->sessionA = $this->openSession($this->settingA, $this->terminalA, $this->user);
        $this->customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Customer Test',
            'customer_email' => 'customer@test.com',
            'customer_phone' => '08123456789',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
        ]);
    }

    /**
     * Build an incident-shaped historical fixture mirroring TNC-TXN-2026-10-0638.
     *
     * @return array<string, mixed>
     */
    protected function createIncidentFixture(string $transactionCode = 'TNC-TXN-2026-10-0638-TEST'): array
    {
        // 1. Products
        $parentProduct = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_name' => 'SONY ALPHA A7 IV CAMERA',
            'product_code' => 'SKU-CAMERA-' . uniqid(),
            'sale_price' => 630000,
            'stock_qty' => 5,
        ]);

        $compProduct = $this->createStockedProduct($this->settingB, $this->locationB, [
            'product_name' => 'MEMORY MICRO SD SANDISK ULTRA 32GB (C10)',
            'product_code' => 'SKU-MICROSD-' . uniqid(),
            'sale_price' => 260000,
            'stock_qty' => 41,
        ]);
        $compProduct->update(['product_quantity' => 46]);
        $compStock = ProductStock::where('product_id', $compProduct->id)
            ->where('location_id', $this->locationB->id)
            ->first();

        $bundle = ProductBundle::create([
            'parent_product_id' => $parentProduct->id,
            'name' => 'Camera + MicroSD Bundle',
            'setting_id' => $this->settingA->id,
            'is_active' => true,
        ]);
        $bItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compProduct->id,
            'quantity' => 1,
            'informational_item_price' => 260000,
        ]);

        // 2. Sale 1 (Parent Sale)
        $sale1 = Sale::create([
            'reference' => 'SO-PARENT-' . uniqid(),
            'date' => now(),
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 630000,
            'paid_amount' => 630000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'setting_id' => $this->settingA->id,
        ]);
        $parentSaleDetail = SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $dispatch1 = Dispatch::create([
            'sale_id' => $sale1->id,
            'status' => Dispatch::STATUS_APPROVED,
            'dispatch_date' => now(),
        ]);
        $parentDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale1->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'product_id' => $parentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->locationA->id,
        ]);

        // 3. Sale 2 (Component Sale, unpaid)
        $sale2 = Sale::create([
            'reference' => 'PD-JL-2026-10-00653-' . uniqid(),
            'date' => now(),
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 260000,
            'paid_amount' => 0,
            'due_amount' => 260000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'setting_id' => $this->settingB->id,
        ]);
        $carrierDetail = SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 260000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $bundleItem = SaleBundleItem::create([
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bItem->id,
            'product_id' => $compProduct->id,
            'name' => $compProduct->product_name,
            'quantity' => 1,
            'price' => 260000,
            'sub_total' => 260000,
            'cost_unit_snapshot' => 162719.24,
            'cost_snapshot_source' => 'CURRENT_AVERAGE_PRICE',
            'cost_snapshot_setting_id' => $this->settingB->id,
            'cost_snapshot_setting_is_pkp' => false,
            'cost_snapshot_at' => now(),
        ]);
        $dispatch2 = Dispatch::create([
            'sale_id' => $sale2->id,
            'status' => Dispatch::STATUS_APPROVED,
            'dispatch_date' => now(),
        ]);
        $compDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'product_id' => $compProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->locationB->id,
            'bundle_id' => $bundle->id,
        ]);

        // 4. POS Transaction & Checkout
        $posTxn = $this->createPosTransaction([
            'code' => $transactionCode,
        ]);
        $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'qty' => 1,
            'unit_price' => 890000,
            'subtotal' => 890000,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    [
                        'product_id' => $compProduct->id,
                        'name' => $compProduct->product_name,
                        'quantity' => 1,
                    ],
                ],
            ],
        ]);
        $checkout = PosCheckout::create([
            'setting_id' => $this->settingA->id,
            'pos_transaction_id' => $posTxn->id,
            'pos_session_id' => $this->sessionA->id,
            'terminal_id' => $this->terminalA->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 890000,
            'receipt_number' => 'RCP-INC-TEST-' . uniqid(),
            'idempotency_key' => 'IDEM-INC-TEST-' . uniqid(),
            'payload_hash' => 'HASH-INC-TEST-' . uniqid(),
        ]);
        $posTxn->update(['completed_checkout_id' => $checkout->id]);

        $parentCheckoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'grand_total' => 630000,
            'subtotal' => 630000,
            'split_key' => 'SPLIT-CAM-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'source_setting_id' => $this->settingB->id,
            'source_location_id' => $this->locationB->id,
            'grand_total' => 260000,
            'subtotal' => 260000,
            'split_key' => 'SPLIT-MSD-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        // 5. Existing POS Return 111 (Completed camera return only)
        $posReturn = PosReturn::create([
            'reference' => 'TNC-POSRT-2610-0005-' . uniqid(),
            'receipt_number' => 'RCP-RET-' . uniqid(),
            'setting_id' => $this->settingA->id,
            'pos_transaction_id' => $posTxn->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transactionCode,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer Test',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 630000,
            'source_snapshot' => ['items' => []],
            'source_snapshot_hash' => md5('dummy'),
            'approved_by' => $this->user->id,
            'approved_at' => now(),
            'received_by' => $this->user->id,
            'received_at' => now(),
            'settled_by' => $this->user->id,
            'settled_at' => now(),
        ]);
        $posReturnLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $parentCheckoutSale->id,
            'sale_id' => $sale1->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'unit_price' => 630000,
            'line_total' => 630000,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        // Original parent SaleReturn
        $parentSaleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'TNC-SLRN-2610-0001-' . uniqid(),
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer Test',
            'setting_id' => $this->settingA->id,
            'location_id' => $this->locationA->id,
            'total_amount' => 630000,
            'paid_amount' => 630000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'approved_by' => $this->user->id,
            'approved_at' => now(),
            'received_by' => $this->user->id,
            'received_at' => now(),
            'settled_by' => $this->user->id,
            'settled_at' => now(),
        ]);
        SaleReturnDetail::create([
            'sale_return_id' => $parentSaleReturn->id,
            'pos_return_line_id' => $posReturnLine->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'location_id' => $this->locationA->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        return [
            'transaction_code' => $transactionCode,
            'parent_product' => $parentProduct,
            'comp_product' => $compProduct,
            'comp_stock' => $compStock,
            'sale1' => $sale1,
            'sale2' => $sale2,
            'carrier_detail' => $carrierDetail,
            'bundle_item' => $bundleItem,
            'comp_dispatch_detail' => $compDispatchDetail,
            'pos_txn' => $posTxn,
            'pos_return' => $posReturn,
            'parent_sale_return' => $parentSaleReturn,
        ];
    }

    /** @test */
    public function dry_run_discovers_incident_and_makes_no_database_changes(): void
    {
        $fixture = $this->createIncidentFixture();
        $service = app(PosHistoricalBundleCorrectionService::class);

        $beforeSalesCount = SaleReturn::count();
        $beforeSale2Due = (float) $fixture['sale2']->fresh()->due_amount;
        $beforeStockQty = (int) $fixture['comp_stock']->fresh()->quantity;
        $beforeDispatchQty = (int) $fixture['comp_dispatch_detail']->fresh()->dispatched_quantity;

        $candidate = $service->discover($fixture['transaction_code']);

        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CORRECTABLE, $candidate['classification']);
        $this->assertSame(260000.0, (float) $candidate['debt_reduction']);
        $this->assertSame(0.0, (float) $candidate['cash_refund']);
        $this->assertSame($fixture['product_id'] ?? $fixture['comp_product']->id, $candidate['product_id']);
        $this->assertSame(1, $candidate['quantity']);
        $this->assertSame(41, $candidate['current_stock']['location_quantity']);
        $this->assertSame(42, $candidate['expected_stock']['location_quantity']);
        $this->assertSame(1, $candidate['current_dispatch']['dispatched_quantity']);
        $this->assertSame(0, $candidate['expected_dispatch']['dispatched_quantity']);

        // Verify zero database mutations
        $this->assertSame($beforeSalesCount, SaleReturn::count());
        $this->assertSame($beforeSale2Due, (float) $fixture['sale2']->fresh()->due_amount);
        $this->assertSame($beforeStockQty, (int) $fixture['comp_stock']->fresh()->quantity);
        $this->assertSame($beforeDispatchQty, (int) $fixture['comp_dispatch_detail']->fresh()->dispatched_quantity);
    }

    /** @test */
    public function command_dry_run_matches_service_and_exits_cleanly(): void
    {
        $fixture = $this->createIncidentFixture();

        $this->artisan('pos:repair-historical-bundle-omission', [
            'transaction' => $fixture['transaction_code'],
        ])
            ->expectsOutputToContain('Mode: DRY-RUN (no data changed)')
            ->expectsOutputToContain('Status: [CORRECTABLE]')
            ->expectsOutputToContain('Debt Reduction: Rp 260,000.00')
            ->expectsOutputToContain('Cash Refund: Rp 0.00')
            ->assertExitCode(0);
    }

    /** @test */
    public function apply_without_confirmation_is_rejected_with_error(): void
    {
        $fixture = $this->createIncidentFixture();

        $this->artisan('pos:repair-historical-bundle-omission', [
            'transaction' => $fixture['transaction_code'],
            '--apply' => true,
            '--actor' => 1,
            '--reason' => 'Administrative repair without physical return confirmation',
        ])
            ->expectsOutputToContain('Physical return of the missing component must be explicitly confirmed with --confirm-physical-return')
            ->assertExitCode(1);

        $this->assertSame(1, SaleReturn::count(), 'No new SaleReturn created when confirmation missing.');
    }

    /** @test */
    public function apply_rejects_missing_or_unrelated_transaction_with_conflict(): void
    {
        $this->artisan('pos:repair-historical-bundle-omission', [
            'transaction' => 'NON-EXISTENT-TXN-CODE',
        ])
            ->expectsOutputToContain('Status: [CONFLICT]')
            ->assertExitCode(2);
    }

    /** @test */
    public function apply_rejects_manually_tampered_or_already_zeroed_evidence_without_corrective_record(): void
    {
        $fixture = $this->createIncidentFixture();

        // Tamper with due amount directly without a corrective return
        $fixture['sale2']->update(['due_amount' => 0]);

        $service = app(PosHistoricalBundleCorrectionService::class);
        $candidate = $service->discover($fixture['transaction_code']);

        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT, $candidate['classification']);
        $this->assertStringContainsString('telah nol tanpa catatan koreksi resmi', $candidate['reason']);
    }

    /** @test */
    public function apply_executes_atomic_reversal_stock_receiving_dispatch_decrement_and_debt_clearing(): void
    {
        $fixture = $this->createIncidentFixture();

        $beforeParentSaleReturn = $fixture['parent_sale_return']->fresh();
        $beforeProdQty = (int) $fixture['comp_product']->fresh()->product_quantity;
        $beforeStockNonTax = (int) $fixture['comp_stock']->fresh()->quantity_non_tax;
        $beforeStockTotal = (int) $fixture['comp_stock']->fresh()->quantity;

        $this->artisan('pos:repair-historical-bundle-omission', [
            'transaction' => $fixture['transaction_code'],
            '--apply' => true,
            '--confirm-physical-return' => true,
            '--actor' => 1,
            '--reason' => 'Administrative repair of omitted microSD',
        ])
            ->expectsOutputToContain('Mode: APPLY')
            ->expectsOutputToContain('Status: [REPAIRED]')
            ->expectsOutputToContain('Corrective SaleReturn created:')
            ->assertExitCode(0);

        // 1. Corrective SaleReturn created and linked
        $correctiveSaleReturn = SaleReturn::where('pos_return_id', $fixture['pos_return']->id)
            ->where('id', '!=', $fixture['parent_sale_return']->id)
            ->first();

        $this->assertNotNull($correctiveSaleReturn, 'Corrective SaleReturn must exist.');
        $this->assertSame('COMPLETED', strtoupper($correctiveSaleReturn->status));
        $this->assertSame('PAID', strtoupper($correctiveSaleReturn->payment_status));
        $this->assertSame(260000.0, (float) $correctiveSaleReturn->total_amount);
        $this->assertSame(0.0, (float) $correctiveSaleReturn->due_amount);
        $this->assertSame(0.0, (float) $correctiveSaleReturn->paid_amount);
        $this->assertSame($fixture['sale2']->id, (int) $correctiveSaleReturn->sale_id);

        // 2. Corrective SaleReturnDetail created
        $detail = $correctiveSaleReturn->saleReturnDetails->first();
        $this->assertNotNull($detail);
        $this->assertSame($fixture['comp_product']->id, $detail->product_id);
        $this->assertSame(1, $detail->quantity);
        $this->assertSame(260000.0, (float) $detail->sub_total);
        $this->assertNotNull($detail->cost_effective_at);
        $this->assertSame(162719.24, (float) $detail->cost_unit_snapshot);
        $this->assertTrue((bool) data_get($detail->execution_context, 'is_historical_correction'));

        // 3. Stock receiving in owner non-PKP bucket
        $freshStock = $fixture['comp_stock']->fresh();
        $this->assertSame($beforeStockNonTax + 1, (int) $freshStock->quantity_non_tax);
        $this->assertSame($beforeStockTotal + 1, (int) $freshStock->quantity);
        $this->assertSame($beforeProdQty + 1, (int) $fixture['comp_product']->fresh()->product_quantity);

        // 4. Ledger transaction record created
        $ledgerRow = DB::table('transactions')
            ->where('product_id', $fixture['comp_product']->id)
            ->where('reason', 'like', '%' . $correctiveSaleReturn->reference . '%')
            ->first();

        $this->assertNotNull($ledgerRow);
        $this->assertSame('SALE_RETURN_GOOD_NON_TAX', $ledgerRow->type);
        $this->assertSame(1, (int) $ledgerRow->quantity);
        $this->assertSame(1, (int) $ledgerRow->quantity_non_tax);
        $this->assertSame(0, (int) $ledgerRow->quantity_tax);

        // 5. Dispatch detail decremented
        $freshDispatch = $fixture['comp_dispatch_detail']->fresh();
        $this->assertSame(0, (int) $freshDispatch->dispatched_quantity);

        // 6. SaleBundleItem cleared
        $freshBundleItem = $fixture['bundle_item']->fresh();
        $this->assertSame(0, (int) $freshBundleItem->quantity);
        $this->assertSame(0.0, (float) $freshBundleItem->sub_total);

        // 7. Component Sale debt reduced to zero
        $freshSale2 = $fixture['sale2']->fresh();
        $this->assertSame(0.0, (float) $freshSale2->total_amount);
        $this->assertSame(0.0, (float) $freshSale2->due_amount);
        $this->assertSame(0.0, (float) $freshSale2->paid_amount);
        $this->assertSame('PAID', strtoupper($freshSale2->payment_status));
        $this->assertSame(Sale::STATUS_RETURNED, $freshSale2->status);
        $this->assertNotNull($freshSale2->archived_at);

        // 8. Untouched Camera records (NO replay!)
        $freshParentSaleReturn = $fixture['parent_sale_return']->fresh();
        $this->assertSame($beforeParentSaleReturn->updated_at->toIso8601String(), $freshParentSaleReturn->updated_at->toIso8601String());
        $this->assertSame(630000.0, (float) $freshParentSaleReturn->total_amount);
    }

    /** @test */
    public function repeated_apply_is_idempotent_and_creates_no_duplicate_records(): void
    {
        $fixture = $this->createIncidentFixture();

        // First apply
        $this->artisan('pos:repair-historical-bundle-omission', [
            'transaction' => $fixture['transaction_code'],
            '--apply' => true,
            '--confirm-physical-return' => true,
            '--actor' => 1,
            '--reason' => 'First apply',
        ])->assertExitCode(0);

        $initialSaleReturnCount = SaleReturn::count();
        $initialStock = (int) $fixture['comp_stock']->fresh()->quantity;
        $initialLedgerCount = DB::table('transactions')->where('product_id', $fixture['comp_product']->id)->count();

        // Second apply
        $this->artisan('pos:repair-historical-bundle-omission', [
            'transaction' => $fixture['transaction_code'],
            '--apply' => true,
            '--confirm-physical-return' => true,
            '--actor' => 1,
            '--reason' => 'Second apply retry',
        ])
            ->expectsOutputToContain('Status: [ALREADY_CORRECTED]')
            ->assertExitCode(0);

        $this->assertSame($initialSaleReturnCount, SaleReturn::count(), 'No duplicate SaleReturn created on retry.');
        $this->assertSame($initialStock, (int) $fixture['comp_stock']->fresh()->quantity, 'Stock must not be incremented again.');
        $this->assertSame($initialLedgerCount, DB::table('transactions')->where('product_id', $fixture['comp_product']->id)->count(), 'No duplicate ledger rows.');
    }

    /** @test */
    public function discovery_rejects_intentional_parent_only_return_with_conflict(): void
    {
        $fixture = $this->createIncidentFixture();

        // Mark the return line as intentionally parent-only
        PosReturnLine::where('pos_return_id', $fixture['pos_return']->id)->update([
            'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
        ]);

        $service = app(PosHistoricalBundleCorrectionService::class);
        $candidate = $service->discover($fixture['transaction_code']);

        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT, $candidate['classification']);
        $this->assertStringContainsString("memiliki scope 'parent_only' yang disengaja", $candidate['reason']);

        // Apply must also fail and reject
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("memiliki scope 'parent_only' yang disengaja");
        $service->apply($fixture['transaction_code'], 1, 'Attempt apply on parent_only', true);
    }

    /** @test */
    public function discovery_rejects_unrelated_transaction_code_with_conflict(): void
    {
        $service = app(PosHistoricalBundleCorrectionService::class);
        $candidate = $service->discover('TNC-TXN-2026-10-9999');

        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT, $candidate['classification']);
        $this->assertStringContainsString('bukan merupakan target insiden historical bundle omission', $candidate['reason']);
    }

    /** @test */
    public function discovery_and_apply_reject_quantity_greater_than_one(): void
    {
        $fixture = $this->createIncidentFixture();

        // Change quantity to 2
        $fixture['bundle_item']->update([
            'quantity' => 2,
            'sub_total' => 520000,
        ]);
        $fixture['sale2']->update([
            'total_amount' => 520000,
            'due_amount' => 520000,
        ]);

        $service = app(PosHistoricalBundleCorrectionService::class);
        $candidate = $service->discover($fixture['transaction_code']);

        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT, $candidate['classification']);
        $this->assertStringContainsString('bukan tepat 1 unit', $candidate['reason']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bukan tepat 1 unit');
        $service->apply($fixture['transaction_code'], 1, 'Attempt apply on quantity 2', true);
    }

    /** @test */
    public function discovery_and_apply_reject_component_sale_with_prior_payments(): void
    {
        $fixture = $this->createIncidentFixture();

        // Record a partial payment on the component sale
        $fixture['sale2']->update([
            'paid_amount' => 100000,
            'due_amount' => 160000,
        ]);

        $service = app(PosHistoricalBundleCorrectionService::class);
        $candidate = $service->discover($fixture['transaction_code']);

        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT, $candidate['classification']);
        $this->assertStringContainsString('memiliki pembayaran tercatat', $candidate['reason']);

        // Attempt apply: must fail without altering paid_amount
        try {
            $service->apply($fixture['transaction_code'], 1, 'Attempt apply on partially paid sale', true);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('memiliki pembayaran tercatat', $e->getMessage());
        }

        $freshSale2 = $fixture['sale2']->fresh();
        $this->assertSame(100000.0, (float) $freshSale2->paid_amount, 'Paid amount must never be erased.');
    }

    /** @test */
    public function duplicate_return_check_detects_separate_already_received_sales_return_for_component_dispatch(): void
    {
        $fixture = $this->createIncidentFixture();
        $service = app(PosHistoricalBundleCorrectionService::class);

        // Create a separate, independent Sales Return for the component dispatch (e.g. manual return, not linked to POS return)
        $separateSaleReturn = SaleReturn::create([
            'reference' => 'TNC-SLRN-SEPARATE-' . uniqid(),
            'pos_return_id' => null, // Not linked to original POS return!
            'sale_id' => $fixture['sale2']->id,
            'sale_reference' => $fixture['sale2']->reference,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'setting_id' => $this->settingB->id,
            'location_id' => $this->locationB->id,
            'date' => now(),
            'status' => SaleReturn::STATUS_COMPLETED,
            'payment_method' => 'Cash',
            'payment_status' => 'Unpaid',
            'total_amount' => 260000,
            'paid_amount' => 0,
            'due_amount' => 260000,
        ]);

        SaleReturnDetail::create([
            'sale_return_id' => $separateSaleReturn->id,
            'sale_detail_id' => $fixture['carrier_detail']->id,
            'product_id' => $fixture['comp_product']->id,
            'product_name' => $fixture['comp_product']->product_name,
            'product_code' => $fixture['comp_product']->product_code,
            'dispatch_detail_id' => $fixture['comp_dispatch_detail']->id,
            'component_sale_bundle_item_id' => $fixture['bundle_item']->id,
            'quantity' => 1,
            'price' => 260000,
            'unit_price' => 260000,
            'sub_total' => 260000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'location_id' => $this->locationB->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        // Discovery must classify as CONFLICT, preventing double receipt
        $candidate = $service->discover($fixture['transaction_code']);
        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT, $candidate['classification']);
        $this->assertStringContainsString('telah tercatat pada dokumen retur penjualan', $candidate['reason']);

        // Apply must also fail under lock
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('telah tercatat pada dokumen retur penjualan lain');
        $service->apply($fixture['transaction_code'], 1, 'Attempt apply with existing separate return', true);
    }

    /** @test */
    public function payment_ledger_check_detects_active_payment_even_when_sale_header_is_stale(): void
    {
        $fixture = $this->createIncidentFixture();
        $service = app(PosHistoricalBundleCorrectionService::class);

        // Insert an active payment into the payment ledger for component sale,
        // but leave sale header paid_amount = 0 and due_amount = total_amount (stale header).
        SalePayment::create([
            'sale_id' => $fixture['sale2']->id,
            'amount' => 100000,
            'date' => now(),
            'reference' => 'INV/PAY-' . uniqid(),
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Re-verify sale header still has paid_amount = 0 (stale)
        $fixture['sale2']->refresh();
        $this->assertSame(0.0, (float) $fixture['sale2']->paid_amount);

        // Discovery must consult ledger and detect CONFLICT despite stale header
        $candidate = $service->discover($fixture['transaction_code']);
        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT, $candidate['classification']);
        $this->assertStringContainsString('memiliki pembayaran tercatat', $candidate['reason']);

        // Apply must also fail under lock
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('memiliki pembayaran tercatat pada buku besar pembayaran');
        $service->apply($fixture['transaction_code'], 1, 'Attempt apply with active ledger payment', true);
    }

    /** @test */
    public function apply_requires_explicit_actor_and_reason_options(): void
    {
        $fixture = $this->createIncidentFixture();

        // Missing actor
        $this->artisan('pos:repair-historical-bundle-omission', [
            'transaction' => $fixture['transaction_code'],
            '--apply' => true,
            '--confirm-physical-return' => true,
            '--reason' => 'Valid reason',
        ])
            ->expectsOutputToContain('An authorized actor ID must be explicitly specified with --actor=<id>')
            ->assertExitCode(1);

        // Missing reason
        $this->artisan('pos:repair-historical-bundle-omission', [
            'transaction' => $fixture['transaction_code'],
            '--apply' => true,
            '--confirm-physical-return' => true,
            '--actor' => 1,
        ])
            ->expectsOutputToContain('A specific audit reason must be explicitly specified with --reason="<text>"')
            ->assertExitCode(1);
    }

    private function createPosTransaction(array $attributes): PosTransaction
    {
        return PosTransaction::create(array_merge([
            'setting_id' => $this->settingA->id,
            'code' => 'TXN-' . uniqid(),
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->sessionA->id,
            'status' => 'completed',
        ], $attributes));
    }

    private function createPosTransactionLine(array $attributes): PosTransactionLine
    {
        static $ln = 500;
        $name = $attributes['product_name'] ?? 'Product Line';
        unset($attributes['product_name']);

        return PosTransactionLine::create(array_merge([
            'line_no' => $ln++,
            'product_name_snapshot' => $name,
            'product_code_snapshot' => 'SKU',
        ], $attributes));
    }

    /** @test */
    public function duplicate_return_check_detects_separate_return_linked_only_by_component_dispatch(): void
    {
        $fixture = $this->createIncidentFixture();
        $service = app(PosHistoricalBundleCorrectionService::class);

        $separateSaleReturn = SaleReturn::create([
            'reference' => 'TNC-SLRN-DISPATCH-ONLY-' . uniqid(),
            'pos_return_id' => null,
            'sale_id' => $fixture['sale2']->id,
            'sale_reference' => $fixture['sale2']->reference,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'setting_id' => $this->settingB->id,
            'location_id' => $this->locationB->id,
            'date' => now(),
            'status' => SaleReturn::STATUS_AWAITING_SETTLEMENT,
            'payment_method' => 'Cash',
            'payment_status' => 'Unpaid',
            'total_amount' => 260000,
            'paid_amount' => 0,
            'due_amount' => 260000,
        ]);

        // Linked to the component dispatch only, without the bundle item identity
        SaleReturnDetail::create([
            'sale_return_id' => $separateSaleReturn->id,
            'sale_detail_id' => $fixture['carrier_detail']->id,
            'product_id' => $fixture['comp_product']->id,
            'product_name' => $fixture['comp_product']->product_name,
            'product_code' => $fixture['comp_product']->product_code,
            'dispatch_detail_id' => $fixture['comp_dispatch_detail']->id,
            'component_sale_bundle_item_id' => null,
            'quantity' => 1,
            'price' => 260000,
            'unit_price' => 260000,
            'sub_total' => 260000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'location_id' => $this->locationB->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $candidate = $service->discover($fixture['transaction_code']);
        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT, $candidate['classification']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('telah tercatat pada dokumen retur penjualan lain');
        $service->apply($fixture['transaction_code'], 1, 'Attempt apply with dispatch-linked separate return', true);
    }

    /** @test */
    public function payment_ledger_check_detects_credit_application_even_when_sale_header_is_stale(): void
    {
        $fixture = $this->createIncidentFixture();
        $service = app(PosHistoricalBundleCorrectionService::class);

        // Zero-amount active payment settled entirely by customer credit
        $payment = SalePayment::create([
            'sale_id' => $fixture['sale2']->id,
            'amount' => 0,
            'date' => now(),
            'reference' => 'INV/PAY-CREDIT-' . uniqid(),
            'payment_method' => 'Credit',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);
        $creditSourceReturn = SaleReturn::create([
            'reference' => 'TNC-SLRN-CREDIT-' . uniqid(),
            'sale_id' => $fixture['sale2']->id,
            'sale_reference' => $fixture['sale2']->reference,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'setting_id' => $this->settingB->id,
            'date' => now(),
            'status' => SaleReturn::STATUS_COMPLETED,
            'payment_method' => 'Cash',
            'payment_status' => 'Paid',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 0,
        ]);
        $creditId = DB::table('customer_credits')->insertGetId([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $creditSourceReturn->id,
            'amount' => 100000,
            'remaining_amount' => 0,
            'status' => 'closed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sale_payment_credit_applications')->insert([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $creditId,
            'amount' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0.0, (float) $fixture['sale2']->fresh()->paid_amount);

        $candidate = $service->discover($fixture['transaction_code']);
        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT, $candidate['classification']);
        $this->assertStringContainsString('memiliki pembayaran tercatat', $candidate['reason']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('memiliki pembayaran tercatat pada buku besar pembayaran');
        $service->apply($fixture['transaction_code'], 1, 'Attempt apply with credit-settled ledger payment', true);
    }

    /** @test */
    public function duplicate_return_check_includes_archived_completed_component_return(): void
    {
        $fixture = $this->createIncidentFixture();
        $service = app(PosHistoricalBundleCorrectionService::class);

        $archivedReturn = SaleReturn::create([
            'reference' => 'TNC-SLRN-ARCHIVED-' . uniqid(),
            'pos_return_id' => null,
            'sale_id' => $fixture['sale2']->id,
            'sale_reference' => $fixture['sale2']->reference,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'setting_id' => $this->settingB->id,
            'location_id' => $this->locationB->id,
            'date' => now(),
            'status' => SaleReturn::STATUS_COMPLETED,
            'payment_method' => 'Cash',
            'payment_status' => 'Paid',
            'total_amount' => 260000,
            'paid_amount' => 0,
            'due_amount' => 0,
        ]);
        SaleReturnDetail::create([
            'sale_return_id' => $archivedReturn->id,
            'sale_detail_id' => $fixture['carrier_detail']->id,
            'product_id' => $fixture['comp_product']->id,
            'product_name' => $fixture['comp_product']->product_name,
            'product_code' => $fixture['comp_product']->product_code,
            'dispatch_detail_id' => $fixture['comp_dispatch_detail']->id,
            'component_sale_bundle_item_id' => $fixture['bundle_item']->id,
            'quantity' => 1,
            'price' => 260000,
            'unit_price' => 260000,
            'sub_total' => 260000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'location_id' => $this->locationB->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        // Archiving keeps the return's receiving effects
        DB::table('sale_returns')->where('id', $archivedReturn->id)->update(['archived_at' => now()]);
        $this->assertNull(SaleReturn::find($archivedReturn->id), 'Default scope must hide the archived return.');

        $candidate = $service->discover($fixture['transaction_code']);
        $this->assertSame(PosHistoricalBundleCorrectionService::CLASSIFICATION_CONFLICT, $candidate['classification']);
        $this->assertStringContainsString('telah tercatat pada dokumen retur penjualan', $candidate['reason']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('telah tercatat pada dokumen retur penjualan lain');
        $service->apply($fixture['transaction_code'], 1, 'Attempt apply with archived component return', true);
    }
}

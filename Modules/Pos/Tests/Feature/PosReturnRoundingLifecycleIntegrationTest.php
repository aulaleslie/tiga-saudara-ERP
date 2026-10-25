<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnRoundingValuationService;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\People\Entities\Customer;
use Spatie\Permission\Models\Permission;

class PosReturnRoundingLifecycleIntegrationTest extends PosTransactionFeatureTestCase
{
    protected $user;
    protected $approver;
    protected $submissionService;
    protected $snapshotService;
    protected $lifecycleService;
    protected $plannerService;
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->lifecycleService = app(PosReturnLifecycleService::class);
        $this->plannerService = app(PosReturnApprovalPreviewPlannerService::class);

        $this->setting = $this->createSetting('POS Rounding Lifecycle Test');
        $this->setting->update(['row_total_rounding_increment' => 100.0]);

        Permission::findOrCreate('pos.returns.view', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Clerk', [
            'pos.access',
            'pos.returns.view',
            'pos.returns.create',
        ]);

        $this->approver = $this->createUserForSetting($this->setting, 'POS Return Approver', [
            'pos.access',
            'pos.returns.view',
            'pos.returns.approve',
        ]);

        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /**
     * Helper to set up a synthetic 9218-shaped transaction.
     * Line 1: Notebook (qty 1 @ 15,000 = 15,000)
     * Line 2: 120 Markers (qty 120 @ unit price 6,583.33 = charged total 790,000)
     * Total sale: 805,000.
     */
    protected function createSynthetic9218Fixture(float $paidAmount = 805000.0): array
    {
        $notebook = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Notebook',
            'product_code' => 'NB-01',
            'sale_price' => 15000,
        ]);

        $markers = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Marker Whiteboard 120 Pcs',
            'product_code' => 'MK-120',
            'sale_price' => 6583.33,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-9218-SYNTHETIC',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $ptl1 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $notebook->id,
            'product_name_snapshot' => 'Notebook',
            'product_code_snapshot' => 'NB-01',
            'qty' => 1,
            'unit_price' => 15000,
        ]);

        $ptl2 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 2,
            'product_id' => $markers->id,
            'product_name_snapshot' => 'Marker Whiteboard 120 Pcs',
            'product_code_snapshot' => 'MK-120',
            'qty' => 120,
            'unit_price' => 6583.33,
            'line_meta' => [
                'line_total_minor' => 79000000,
                'line_gross_minor' => 79000000,
            ],
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 805000,
            'receipt_number' => 'RCP-9218',
            'idempotency_key' => 'IDEM-9218',
            'payload_hash' => 'HASH-9218',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $dueAmount = max(0.0, 805000.0 - $paidAmount);
        $paymentStatus = $paidAmount >= 805000 ? 'PAID' : ($paidAmount > 0 ? 'PARTIAL' : 'UNPAID');

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 805000,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => $paymentStatus,
            'payment_method' => 'CASH',
            'reference' => 'SO-9218',
        ]);

        if ($paidAmount > 0) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'amount' => $paidAmount,
                'date' => now()->toDateString(),
                'reference' => 'PAY-9218',
                'payment_method' => 'CASH',
                'note' => 'Initial checkout payment',
            ]);
        }

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 805000,
            'split_key' => 'SPLIT-9218',
            'tax_bucket' => 'NON_TAX',
        ]);

        $detail1 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $notebook->id,
            'product_name' => 'Notebook',
            'product_code' => 'NB-01',
            'quantity' => 1,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 15000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $detail2 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $markers->id,
            'product_name' => 'Marker Whiteboard 120 Pcs',
            'product_code' => 'MK-120',
            'quantity' => 120,
            'price' => 6583.33,
            'unit_price' => 6583.33,
            'sub_total' => 790000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
        ]);

        $dd1 = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail1->id,
            'product_id' => $notebook->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $dd2 = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail2->id,
            'product_id' => $markers->id,
            'dispatched_quantity' => 120,
            'location_id' => $this->location->id,
        ]);

        return compact('transaction', 'checkout', 'sale', 'ptl1', 'ptl2', 'detail1', 'detail2', 'notebook', 'markers', 'dispatch', 'dd1', 'dd2');
    }

    /** @test */
    public function it_preserves_untouched_marker_line_subtotal_and_checkout_snapshot_when_returning_notebook()
    {
        $fixture = $this->createSynthetic9218Fixture();
        $sale = $fixture['sale'];
        $checkout = $fixture['checkout'];
        $detail1 = $fixture['detail1'];
        $detail2 = $fixture['detail2'];

        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        $this->actingAsInSetting($this->user, $this->setting);
        $draft = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $detail1->id,
                    'pos_transaction_line_id' => $fixture['ptl1']->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending = $this->submissionService->submitDraftForApproval($draft);

        $plan = $this->plannerService->plan($pending);
        $this->lifecycleService->executeApprovalFromPreview($pending->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // Verify untouched marker line subtotal is EXACTLY 790000.00, NOT 789999.60
        $detail2->refresh();
        $this->assertEquals(120, (int) $detail2->quantity);
        $this->assertEquals(790000.0, (float) $detail2->sub_total);

        // Verify sale header
        $sale->refresh();
        $this->assertEquals(790000.0, (float) $sale->total_amount);
        $this->assertEquals(790000.0, (float) $sale->paid_amount);
        $this->assertEquals(0.0, (float) $sale->due_amount);

        // Verify checkout snapshot unchanged
        $checkout->refresh();
        $this->assertEquals(805000.0, (float) $checkout->grand_total);
        $this->assertEquals('RCP-9218', $checkout->receipt_number);
    }

    /** @test */
    public function it_calculates_cumulative_rounded_return_and_exact_sale_balance_for_partial_marker_return()
    {
        $fixture = $this->createSynthetic9218Fixture();
        $sale = $fixture['sale'];
        $detail2 = $fixture['detail2'];

        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        // Return 1 marker out of 120 (Rp790,000 charged)
        $this->actingAsInSetting($this->user, $this->setting);
        $draft = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $detail2->id,
                    'pos_transaction_line_id' => $fixture['ptl2']->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $draftLine = $draft->lines->first();
        // 1 * 790000 / 120 = 6583.33 -> rounded to Rp100 increment = Rp6600
        $this->assertEquals(6600.0, (float) $draftLine->expected_cash_amount);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending = $this->submissionService->submitDraftForApproval($draft);

        $plan = $this->plannerService->plan($pending);
        $this->lifecycleService->executeApprovalFromPreview($pending->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // Marker detail subtotal: 790000 - 6600 = 783400.00
        $detail2->refresh();
        $this->assertEquals(119, (int) $detail2->quantity);
        $this->assertEquals(783400.0, (float) $detail2->sub_total);

        // Sale header: 805000 - 6600 = 798400.00
        $sale->refresh();
        $this->assertEquals(798400.0, (float) $sale->total_amount);
        $this->assertEquals(798400.0, (float) $sale->paid_amount);

        // Verify rounding context was frozen on the Sale
        $context = $sale->return_rounding_context;
        $this->assertNotNull($context);
        $this->assertEquals(PosReturnRoundingValuationService::POLICY_VERSION, $context['policy_version']);
        $this->assertEquals(100.0, (float) $context['frozen_increment']);
        $this->assertEquals(1.0, (float) $context['commercial_lines']['ptl_' . $fixture['ptl2']->id]['committed_quantity']);
        $this->assertEquals(6600.0, (float) $context['commercial_lines']['ptl_' . $fixture['ptl2']->id]['committed_amount']);
    }

    /** @test */
    public function it_rejects_stale_preview_if_setting_increment_changes_before_execution()
    {
        $fixture = $this->createSynthetic9218Fixture();
        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        $this->actingAsInSetting($this->user, $this->setting);
        $draft = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['detail2']->id,
                    'pos_transaction_line_id' => $fixture['ptl2']->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending = $this->submissionService->submitDraftForApproval($draft);

        // User generates preview plan with increment 100 (amount 6600)
        $plan = $this->plannerService->plan($pending);

        // Setting changes to Rp500 increment before approval execution
        $this->setting->update(['row_total_rounding_increment' => 500.0]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nilai moneter retur kas telah berubah');

        $this->lifecycleService->executeApprovalFromPreview($pending->id, PosReturn::OPTION_CASH_RETURN, $plan);
    }

    /** @test */
    public function it_preserves_frozen_increment_on_subsequent_partial_return_after_setting_change()
    {
        $fixture = $this->createSynthetic9218Fixture();
        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        // First return: 1 marker under increment 100 -> commits 6600
        $this->actingAsInSetting($this->user, $this->setting);
        $draft1 = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['detail2']->id,
                    'pos_transaction_line_id' => $fixture['ptl2']->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);
        $this->actingAsInSetting($this->approver, $this->setting);
        $pending1 = $this->submissionService->submitDraftForApproval($draft1);
        $plan1 = $this->plannerService->plan($pending1);
        $this->lifecycleService->executeApprovalFromPreview($pending1->id, PosReturn::OPTION_CASH_RETURN, $plan1);

        // Business changes increment setting to Rp500
        $this->setting->update(['row_total_rounding_increment' => 500.0]);

        // Second return: 1 more marker. It should reuse the FROZEN increment 100, not 500!
        // Cumulative qty = 2. Target for 2 markers at Rp100 = round(790000 * 2 / 120, 100) = round(13166.67, 100) = 13200.
        // Delta = 13200 - 6600 = 6600! (If it used Rp500, target would be 13000, delta 6400).
        $snapshot2 = $this->snapshotService->build($fixture['transaction']->id);
        $this->actingAsInSetting($this->user, $this->setting);
        $draft2 = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot2,
            'source_snapshot_hash' => $snapshot2['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['detail2']->id,
                    'pos_transaction_line_id' => $fixture['ptl2']->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $draft2Line = $draft2->lines->first();
        $this->assertEquals(6600.0, (float) $draft2Line->expected_cash_amount);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending2 = $this->submissionService->submitDraftForApproval($draft2);
        $plan2 = $this->plannerService->plan($pending2);
        $this->lifecycleService->executeApprovalFromPreview($pending2->id, PosReturn::OPTION_CASH_RETURN, $plan2);

        $fixture['detail2']->refresh();
        $this->assertEquals(118, (int) $fixture['detail2']->quantity);
        $this->assertEquals(776800.0, (float) $fixture['detail2']->sub_total); // 790000 - 13200 = 776800
    }

    /** @test */
    public function it_releases_allocation_on_reversal_and_preserves_caps_for_new_return()
    {
        $fixture = $this->createSynthetic9218Fixture();
        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        $this->actingAsInSetting($this->user, $this->setting);
        $draft = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['detail2']->id,
                    'pos_transaction_line_id' => $fixture['ptl2']->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending = $this->submissionService->submitDraftForApproval($draft);

        // Approve the return into awaiting receiving
        $plan = $this->plannerService->plan($pending);
        $this->lifecycleService->approve($pending->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // Verify context was populated
        $fixture['sale']->refresh();
        $this->assertEquals(1.0, (float) ($fixture['sale']->return_rounding_context['commercial_lines']['ptl_' . $fixture['ptl2']->id]['committed_quantity'] ?? 0));

        // Cancel the return while approved / awaiting receiving
        $this->lifecycleService->cancel($pending->id, 'Customer changed mind');

        $pending->refresh();
        $this->assertEquals(PosReturn::STATUS_CANCELLED, $pending->status);
        $this->assertTrue((bool) $pending->is_reversed);

        // Sale context has released committed allocations back to 0
        $fixture['sale']->refresh();
        $context = $fixture['sale']->return_rounding_context;
        $this->assertTrue(empty($context['commercial_lines']) || (float) ($context['commercial_lines']['ptl_' . $fixture['ptl2']->id]['committed_quantity'] ?? 0) === 0.0);
        $this->assertTrue(empty($context['commercial_lines']) || (float) ($context['commercial_lines']['ptl_' . $fixture['ptl2']->id]['committed_amount'] ?? 0) === 0.0);
    }

    /** @test */
    public function it_reconciles_settlement_for_unpaid_and_mixed_paid_sales()
    {
        // Mixed paid sale: total 805,000, paid 802,000, due 3,000
        $fixture = $this->createSynthetic9218Fixture(paidAmount: 802000.0);
        $sale = $fixture['sale'];
        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        $this->actingAsInSetting($this->user, $this->setting);
        $draft = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['detail2']->id,
                    'pos_transaction_line_id' => $fixture['ptl2']->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending = $this->submissionService->submitDraftForApproval($draft);
        $plan = $this->plannerService->plan($pending);
        $this->lifecycleService->executeApprovalFromPreview($pending->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // Return committed amount is Rp6,600.
        // Since due was 3,000: actual refund is 3,600, and debt reduction is 3,000.
        // Sum = 3,600 + 3,000 = 6,600!
        $sale->refresh();
        $this->assertEquals(798400.0, (float) $sale->total_amount);
        $this->assertEquals(798400.0, (float) $sale->paid_amount);
        $this->assertEquals(0.0, (float) $sale->due_amount);

        // Check SaleReturn settlement
        $pending->refresh();
        $saleReturn = $pending->saleReturns->first();
        $this->assertNotNull($saleReturn);
        $saleReturn->refresh();
        $this->assertEquals(6600.0, (float) $saleReturn->total_amount);
        $this->assertEquals(3600.0, (float) $saleReturn->paid_amount);
        $this->assertEquals('PAID', strtoupper((string) $saleReturn->payment_status));
    }

    /** @test */
    public function it_preserves_line_subtotal_and_sale_balance_authoritatively_for_zero_rounded_return(): void
    {
        // 1. Setup synthetic 9218 fixture where increment is Rp 100,000
        $this->setting->update(['row_total_rounding_increment' => 100000.0]);
        $fixture = $this->createSynthetic9218Fixture();

        $this->actingAsInSetting($this->user, $this->setting);
        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        // Returning 1 marker out of 120 (proportional is 6,583.33, rounded with 100k increment is 0)
        $draft = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['detail2']->id,
                    'pos_transaction_line_id' => $fixture['ptl2']->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending = $this->submissionService->submitDraftForApproval($draft);
        $plan = $this->plannerService->plan($pending);

        $this->assertFalse($plan['is_blocked']);
        $this->assertEquals(0.0, (float) ($plan['actual_refund'] ?? 0));
        $this->assertEquals(0.0, (float) ($plan['commercial_reduction'] ?? 0));

        $this->lifecycleService->executeApprovalFromPreview($pending->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // Finding 1 Verification:
        // Detail 2 must retain its exact Rp790,000 subtotal, NOT drop to Rp783,416.67
        $fixture['detail2']->refresh();
        $this->assertEquals(119, (int) $fixture['detail2']->quantity);
        $this->assertEquals(790000.0, (float) $fixture['detail2']->sub_total, 'Detail subtotal must remain 790,000 on zero-rounded return');

        // Sale balance must remain intact at 805,000 (total_amount and paid_amount)
        $sale = $fixture['sale']->fresh();
        $this->assertEquals(805000.0, (float) $sale->total_amount, 'Sale total must remain 805,000');
        $this->assertEquals(805000.0, (float) $sale->paid_amount, 'Sale paid amount must remain 805,000');
        $this->assertEquals(0.0, (float) $sale->due_amount);

        // SaleReturn must be settled
        $pending->refresh();
        $saleReturn = $pending->saleReturns->first();
        $this->assertNotNull($saleReturn);
        $this->assertEquals(0.0, (float) $saleReturn->due_amount);
        $this->assertEquals('PAID', strtoupper((string) $saleReturn->payment_status));
        $this->assertNotNull($saleReturn->settled_at);
    }

    /** @test */
    public function it_maintains_cumulative_rounding_history_for_sequential_bundle_returns(): void
    {
        $bundleProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Camera Kit Bundle',
            'product_code' => 'BND-CAM',
            'sale_price' => 890000,
        ]);
        $compProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'MicroSD 64GB',
            'product_code' => 'SD-64',
            'sale_price' => 260000,
        ]);

        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'setting_id' => $this->setting->id,
            'name' => 'Camera Kit Bundle',
            'parent_product_id' => $bundleProduct->id,
        ]);
        $bItem = \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compProduct->id,
            'quantity' => 1,
        ]);

        // Sale has 2 bundles @ 890,000 each = 1,780,000. Rounding increment is 100,000.
        $this->setting->update(['row_total_rounding_increment' => 100000.0]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1780000,
            'paid_amount' => 1780000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleProduct->id,
            'product_name' => 'Camera Kit Bundle',
            'product_code' => 'BND-CAM',
            'quantity' => 2,
            'price' => 890000,
            'unit_price' => 890000,
            'sub_total' => 1780000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        \Modules\Sale\Entities\SaleBundleItem::create([
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bItem->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'product_id' => $compProduct->id,
            'name' => $compProduct->product_name,
            'quantity' => 2,
            'price' => 260000,
            'sub_total' => 520000,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
        ]);

        \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'product_id' => $bundleProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
        ]);

        $posTxn = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-BND-SEQ-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'total_amount' => 1780000,
        ]);

        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $posTxn->id,
            'line_no' => 1,
            'product_id' => $bundleProduct->id,
            'product_name_snapshot' => 'Camera Kit Bundle',
            'product_code_snapshot' => 'BND-CAM',
            'qty' => 2,
            'unit_price' => 890000,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->name,
            ],
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $posTxn->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1780000,
            'receipt_number' => 'RCP-BND-SEQ-' . uniqid(),
            'idempotency_key' => 'IDEM-BND-SEQ-' . uniqid(),
            'payload_hash' => 'HASH-BND-SEQ-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1780000,
            'subtotal' => 1780000,
            'split_key' => 'SPLIT-BND-SEQ-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $posTxn->update(['completed_checkout_id' => $checkout->id]);

        $this->actingAsInSetting($this->user, $this->setting);
        $snapshot = $this->snapshotService->build($posTxn->id);

        // 1st Return: return 1 bundle out of 2.
        // Proportional for 1/2 of 1,780,000 = 890,000.
        // Rounded with 100k increment = 900,000.
        $draft1 = $this->submissionService->store([
            'pos_transaction_id' => $posTxn->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'pos_transaction_line_id' => $ptl->id,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending1 = $this->submissionService->submitDraftForApproval($draft1);
        $plan1 = $this->plannerService->plan($pending1);
        $this->assertFalse($plan1['is_blocked']);
        $this->lifecycleService->executeApprovalFromPreview($pending1->id, PosReturn::OPTION_CASH_RETURN, $plan1);

        // Verify sale return rounding context has recorded the commercial line under ptl key
        $sale->refresh();
        $context = $sale->return_rounding_context;
        $this->assertNotNull($context);
        $commKey = 'ptl_' . $ptl->id;
        $this->assertArrayHasKey($commKey, $context['commercial_lines']);
        $this->assertEquals(1.0, (float) $context['commercial_lines'][$commKey]['committed_quantity']);
        $this->assertEquals(900000.0, (float) $context['commercial_lines'][$commKey]['committed_amount']);

        // 2nd Return: return the remaining 1 bundle.
        // Total baseline: 1,780,000.
        // Cumulative returned qty: 1 + 1 = 2 (Full exhaustion).
        // Target cumulative = 1,780,000.
        // Current return amount must reconcile: 1,780,000 - 900,000 = 880,000!
        $this->actingAsInSetting($this->user, $this->setting);
        $snapshot2 = $this->snapshotService->build($posTxn->id);
        $draft2 = $this->submissionService->store([
            'pos_transaction_id' => $posTxn->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot2,
            'source_snapshot_hash' => $snapshot2['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'pos_transaction_line_id' => $ptl->id,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending2 = $this->submissionService->submitDraftForApproval($draft2);
        $plan2 = $this->plannerService->plan($pending2);

        // Finding 2 Verification:
        // Preview planning must NOT start with 0 cumulative history!
        // It must recognize the previously committed 900,000 and plan exactly 880,000 for the final bundle!
        $this->assertFalse($plan2['is_blocked']);
        $this->assertEquals(880000.0, (float) $plan2['actual_refund'], 'Second bundle return must reconcile to 880,000, not 900,000');

        $this->lifecycleService->executeApprovalFromPreview($pending2->id, PosReturn::OPTION_CASH_RETURN, $plan2);

        // Final state: 900,000 + 880,000 = 1,780,000 total return
        $sale->refresh();
        $this->assertEquals(2.0, (float) $sale->return_rounding_context['commercial_lines'][$commKey]['committed_quantity']);
        $this->assertEquals(1780000.0, (float) $sale->return_rounding_context['commercial_lines'][$commKey]['committed_amount']);
    }

    /** @test */
    public function it_blocks_approval_and_aborts_execution_when_rounding_valuation_is_invalid(): void
    {
        $fixture = $this->createSynthetic9218Fixture();

        $this->actingAsInSetting($this->user, $this->setting);
        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        $draft = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['detail2']->id,
                    'pos_transaction_line_id' => $fixture['ptl2']->id,
                    'quantity' => 10,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $pending = $this->submissionService->submitDraftForApproval($draft);

        // Seed an inconsistent historical return in the sale's rounding context
        // where committed_amount exceeds baseline returnable charge
        $sale = $fixture['sale']->fresh();
        $sale->return_rounding_context = [
            'policy_version' => '1.0',
            'pos_business_id' => $this->setting->id,
            'frozen_increment' => 100.0,
            'commercial_lines' => [
                'ptl_' . $fixture['ptl2']->id => [
                    'commercial_line_id' => $fixture['ptl2']->id,
                    'baseline_quantity' => 120,
                    'baseline_returnable_amount' => 790000.0,
                    'committed_quantity' => 10,
                    'committed_amount' => 99999999.0, // Invalid: exceeds baseline
                    'historical_returned_quantity' => 0,
                    'historical_returned_amount' => 0,
                    'last_allocation_reference' => 'CORRUPT',
                ]
            ]
        ];
        $sale->save();

        // Finding 3 Verification:
        // 1. Preview planning must be blocked with rounding_valuation_failed
        $plan = $this->plannerService->plan($pending);
        $this->assertTrue($plan['is_blocked']);
        $blockerCodes = collect($plan['blockers'])->pluck('code')->all();
        $this->assertContains('rounding_valuation_failed', $blockerCodes);

        // 2. Forged approval plan execution must be rejected under lock
        $forgedPlan = array_merge($plan, [
            'is_blocked' => false,
            'blockers' => [],
            'groups' => [
                'test_group' => [
                    'source_sale' => ['id' => $sale->id],
                    'planned_header' => [
                        'sale_id' => $sale->id,
                        'total_amount' => 10000,
                        'actual_refund' => 10000,
                        'debt_reduction' => 0,
                    ],
                    'planned_details' => [
                        [
                            'pos_return_line_id' => $draft->lines->first()->id,
                            'sale_detail_id' => $fixture['detail2']->id,
                            'product_id' => $fixture['markers']->id,
                            'quantity' => 10,
                            'unit_price' => 6583.33,
                            'subtotal' => 65833.3,
                            'planned_cash_amount' => 10000,
                        ]
                    ]
                ]
            ]
        ]);
        try {
            $this->lifecycleService->executeApprovalFromPreview($pending->id, PosReturn::OPTION_CASH_RETURN, $forgedPlan);
            $this->fail('Expected RuntimeException was not thrown on invalid rounding valuation under lock.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('baseline returnable charge', $e->getMessage());
        }

        // Return must remain pending approval
        $pending->refresh();
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $pending->status);
    }

    /** @test */
    public function it_ensures_discounted_sales_preserve_rounding_consistency_and_decrease_total_by_exact_committed_return(): void
    {
        $this->setting->update(['row_total_rounding_increment' => 100.0]);

        $notebook = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Notebook',
            'product_code' => 'NB-' . uniqid(),
            'sale_price' => 15000,
        ]);
        $markers = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Box of Markers',
            'product_code' => 'MK-' . uniqid(),
            'sale_price' => 6583.33,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'customer_name' => 'Discount Customer',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 10,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 724500, // 805,000 - 10% (80,500) = 724,500
            'paid_amount' => 724500,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'reference' => 'SO-DISC-' . uniqid(),
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 724500,
            'date' => now()->toDateString(),
            'reference' => 'PAY-DISC-' . uniqid(),
            'payment_method' => 'Cash',
        ]);

        $detail1 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $notebook->id,
            'product_name' => 'Notebook',
            'product_code' => $notebook->product_code,
            'quantity' => 1,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 15000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $detail2 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $markers->id,
            'product_name' => 'Box of Markers',
            'product_code' => $markers->product_code,
            'quantity' => 120,
            'price' => 6583.33,
            'unit_price' => 6583.33,
            'sub_total' => 790000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
        ]);
        \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail1->id,
            'product_id' => $notebook->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail2->id,
            'product_id' => $markers->id,
            'dispatched_quantity' => 120,
            'location_id' => $this->location->id,
        ]);

        $posTxn = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-DISC-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'total_amount' => 724500,
        ]);

        $ptl1 = PosTransactionLine::create([
            'pos_transaction_id' => $posTxn->id,
            'line_no' => 1,
            'product_id' => $notebook->id,
            'product_name_snapshot' => 'Notebook',
            'product_code_snapshot' => $notebook->product_code,
            'qty' => 1,
            'unit_price' => 15000,
            'line_meta' => [
                'line_gross_minor' => 1500000,
                'bill_discount_amount' => 1500,
            ],
        ]);

        // Markers charged_total = 790,000 - 79,000 (10% bill discount) = 711,000
        $ptl2 = PosTransactionLine::create([
            'pos_transaction_id' => $posTxn->id,
            'line_no' => 2,
            'product_id' => $markers->id,
            'product_name_snapshot' => 'Box of Markers',
            'product_code_snapshot' => $markers->product_code,
            'qty' => 120,
            'unit_price' => 6583.33,
            'line_meta' => [
                'line_gross_minor' => 79000000,
                'bill_discount_amount' => 79000,
            ],
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $posTxn->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 724500,
            'receipt_number' => 'RCP-DISC-' . uniqid(),
            'idempotency_key' => 'IDEM-DISC-' . uniqid(),
            'payload_hash' => 'HASH-DISC-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 724500,
            'subtotal' => 805000,
            'split_key' => 'SPLIT-DISC-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $posTxn->update(['completed_checkout_id' => $checkout->id]);

        $this->actingAsInSetting($this->user, $this->setting);
        $snapshot = $this->snapshotService->build($posTxn->id);

        // Return 1 marker: proportional is 711,000 / 120 = 5,925.
        // Half-up to 100 increment = 5,900!
        $draft = $this->submissionService->store([
            'pos_transaction_id' => $posTxn->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $detail2->id,
                    'pos_transaction_line_id' => $ptl2->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending = $this->submissionService->submitDraftForApproval($draft);
        $this->lifecycleService->acceptRenewedMonetaryReview($pending->id, $this->approver->id);
        $pending = $pending->fresh(['lines']);

        $plan = $this->plannerService->plan($pending);
        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));

        // Assert planned return is Rp5,900
        $this->assertEquals(5900.0, (float) $plan['actual_refund']);
        $this->assertEquals(5900.0, (float) $plan['commercial_reduction']);

        // Execute approval
        $this->lifecycleService->executeApprovalFromPreview($pending->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // Review finding 1 verification:
        // Remaining total MUST decrease by exactly the committed return amount:
        // Rp724,500 - Rp5,900 = Rp718,600 (NOT Rp718,575!)
        $sale->refresh();
        $this->assertEquals(718600.0, (float) $sale->total_amount, 'Remaining sale total must decrease from 724,500 to 718,600 by exactly committed 5,900 return');
        $this->assertEquals(718600.0, (float) $sale->paid_amount, 'Paid amount must reconcile with corrected total');
        $this->assertEquals(0.0, (float) $sale->due_amount);
    }

    /** @test */
    public function it_ensures_whole_bundle_valuation_does_not_double_count_component_allocations(): void
    {
        $bundleProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Camera Kit',
            'product_code' => 'CAM-KIT-' . uniqid(),
            'sale_price' => 6600,
        ]);
        $compProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Strap',
            'product_code' => 'STP-' . uniqid(),
            'sale_price' => 1000,
        ]);

        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'setting_id' => $this->setting->id,
            'name' => 'Camera Kit Bundle',
            'parent_product_id' => $bundleProduct->id,
        ]);
        $bItem = \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compProduct->id,
            'quantity' => 1,
            'informational_item_price' => 1000,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'customer_name' => 'Bundle Customer',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 6600,
            'paid_amount' => 6600,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'reference' => 'SO-BND-' . uniqid(),
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 6600,
            'date' => now()->toDateString(),
            'reference' => 'PAY-BND-' . uniqid(),
            'payment_method' => 'Cash',
        ]);

        $parentDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleProduct->id,
            'product_name' => 'Camera Kit',
            'product_code' => $bundleProduct->product_code,
            'quantity' => 1,
            'price' => 6600,
            'unit_price' => 6600,
            'sub_total' => 6600,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $carrierDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $compProduct->id,
            'product_name' => 'Strap',
            'product_code' => $compProduct->product_code,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        \Modules\Sale\Entities\SaleBundleItem::create([
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bItem->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $compProduct->id,
            'name' => $compProduct->product_name,
            'quantity' => 1,
            'price' => 1000,
            'sub_total' => 1000,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
        ]);
        \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $bundleProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $carrierDetail->id,
            'product_id' => $compProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $posTxn = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-BND-VAL-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'total_amount' => 6600,
        ]);

        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $posTxn->id,
            'line_no' => 1,
            'product_id' => $bundleProduct->id,
            'product_name_snapshot' => 'Camera Kit',
            'product_code_snapshot' => $bundleProduct->product_code,
            'qty' => 1,
            'unit_price' => 6600,
            'line_meta' => [
                'line_gross_minor' => 660000,
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
            ],
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $posTxn->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 6600,
            'receipt_number' => 'RCP-BND-VAL-' . uniqid(),
            'idempotency_key' => 'IDEM-BND-VAL-' . uniqid(),
            'payload_hash' => 'HASH-BND-VAL-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 6600,
            'subtotal' => 6600,
            'split_key' => 'SPLIT-BND-VAL-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $posTxn->update(['completed_checkout_id' => $checkout->id]);

        $this->actingAsInSetting($this->user, $this->setting);
        $snapshot = $this->snapshotService->build($posTxn->id);

        $draft = $this->submissionService->store([
            'pos_transaction_id' => $posTxn->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail->id,
                    'pos_transaction_line_id' => $ptl->id,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
                [
                    'sale_detail_id' => $carrierDetail->id,
                    'bundle_parent_sale_detail_id' => $parentDetail->id,
                    'product_id' => $compProduct->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending = $this->submissionService->submitDraftForApproval($draft);
        $plan = $this->plannerService->plan($pending);
        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));

        // Review finding 2 verification:
        // Whole-bundle return valuation must NOT double-count component allocations!
        // Total planned cash return across lines must be Rp6,600 (NOT Rp7,600!)
        $this->assertEquals(6600.0, (float) $plan['commercial_reduction'], 'Whole-bundle valuation must sum to exactly 6,600, not double-count 1,000 component allocation to 7,600');
        $this->assertEquals(6600.0, (float) $plan['actual_refund']);

        // Execute approval
        $this->lifecycleService->executeApprovalFromPreview($pending->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // Verify sale is fully reduced from 6600 to 0
        $sale->refresh();
        $this->assertEquals(0.0, (float) $sale->total_amount);
        $this->assertEquals(0.0, (float) $sale->paid_amount);
        $this->assertEquals(0.0, (float) $sale->due_amount);
    }

    /** @test */
    public function it_preserves_tax_inclusive_subtotal_semantics_without_creating_order_discount_when_returning_untaxed_item(): void
    {
        $this->setting->update(['is_pkp' => true]);

        $tax = \Modules\Setting\Entities\Tax::create([
            'name' => 'PPN 11%',
            'value' => 11.0,
            'is_active' => true,
        ]);

        $fixture = $this->createSynthetic9218Fixture();
        $sale = $fixture['sale'];
        $detail1 = $fixture['detail1'];
        $detail2 = $fixture['detail2'];

        // Detail 2 is a taxable line: Rp790.000 subtotal including Rp78.288 tax
        $detail2->update([
            'tax_id' => $tax->id,
            'product_tax_amount' => 78288,
        ]);

        // Sale header setup
        $sale->update([
            'tax_id' => $tax->id,
            'tax_percentage' => 11.0,
            'tax_amount' => 78288,
            'discount_amount' => 0,
            'discount_percentage' => 0,
        ]);

        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        $this->actingAsInSetting($this->user, $this->setting);
        $draft = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $detail1->id,
                    'pos_transaction_line_id' => $fixture['ptl1']->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending = $this->submissionService->submitDraftForApproval($draft);

        $plan = $this->plannerService->plan($pending);
        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));

        $this->lifecycleService->executeApprovalFromPreview($pending->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // Untouched taxable line
        $detail2->refresh();
        $this->assertEquals(120, (int) $detail2->quantity);
        $this->assertEquals(790000.0, (float) $detail2->sub_total);
        $this->assertEquals(78288.0, (float) $detail2->product_tax_amount);

        // Verification of review finding:
        // Expected order discount: Rp0 (NOT Rp78.288)
        $sale->refresh();
        $this->assertEquals(790000.0, (float) $sale->total_amount);
        $this->assertEquals(790000.0, (float) $sale->paid_amount);
        $this->assertEquals(0.0, (float) $sale->due_amount);
        $this->assertEquals(0.0, (float) $sale->discount_amount, 'Order discount must remain Rp0, not turn included tax of Rp78.288 into a discount');
        $this->assertEquals(78288.0, (float) $sale->tax_amount);
    }

    /** @test */
    public function it_preserves_zero_tax_authoritatively_when_returning_all_taxable_items_leaving_only_untaxed_item(): void
    {
        $this->setting->update(['is_pkp' => true]);

        $tax = \Modules\Setting\Entities\Tax::create([
            'name' => 'PPN 11%',
            'value' => 11.0,
            'is_active' => true,
        ]);

        $fixture = $this->createSynthetic9218Fixture();
        $sale = $fixture['sale'];
        $detail1 = $fixture['detail1'];
        $detail2 = $fixture['detail2'];

        // Detail 2 is a taxable line: Rp790.000 subtotal including Rp78.288 tax
        $detail2->update([
            'tax_id' => $tax->id,
            'product_tax_amount' => 78288,
        ]);

        // Sale header setup
        $sale->update([
            'tax_id' => $tax->id,
            'tax_percentage' => 11.0,
            'tax_amount' => 78288,
            'discount_amount' => 0,
            'discount_percentage' => 0,
        ]);

        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        $this->actingAsInSetting($this->user, $this->setting);
        // Return all 120 taxable markers, leaving only the untaxed Rp15.000 notebook
        $draft = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $detail2->id,
                    'pos_transaction_line_id' => $fixture['ptl2']->id,
                    'quantity' => 120,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);
        $pending = $this->submissionService->submitDraftForApproval($draft);

        $plan = $this->plannerService->plan($pending);
        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));

        $this->lifecycleService->executeApprovalFromPreview($pending->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // Remaining untaxed notebook line
        $detail1->refresh();
        $this->assertEquals(1, (int) $detail1->quantity);
        $this->assertEquals(15000.0, (float) $detail1->sub_total);
        $this->assertEquals(0.0, (float) $detail1->product_tax_amount);

        // Returned taxable markers line
        $detail2->refresh();
        $this->assertEquals(0, (int) $detail2->quantity);
        $this->assertEquals(0.0, (float) $detail2->sub_total);
        $this->assertEquals(0.0, (float) $detail2->product_tax_amount);

        // Verification of review finding:
        // Expected tax: Rp0 (NOT Rp1.486,49)
        $sale->refresh();
        $this->assertEquals(15000.0, (float) $sale->total_amount);
        $this->assertEquals(15000.0, (float) $sale->paid_amount);
        $this->assertEquals(0.0, (float) $sale->due_amount);
        $this->assertEquals(0.0, (float) $sale->discount_amount);
        $this->assertEquals(0.0, (float) $sale->tax_amount, 'Remaining tax must be Rp0 when remaining items are untaxed, not Rp1.486,49');
    }
}


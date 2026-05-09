<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnApprovalPreviewPlannerTest extends PosTransactionFeatureTestCase
{
    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

    protected PosReturnApprovalPreviewPlannerService $planner;

    protected $setting;

    protected $secondSetting;

    protected $location;

    protected $secondLocation;

    protected $terminal;

    protected $session;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->planner = app(PosReturnApprovalPreviewPlannerService::class);
        $this->setting = $this->createSetting('POS Return Approval Preview Planner Test');
        $this->secondSetting = $this->createSetting('POS Return Approval Preview Planner Test 2');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        [, $this->secondLocation] = $this->createTerminalWithLocation($this->secondSetting);

        foreach (['pos.returns.create', 'pos.returns.approve'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Approval Preview Planner User', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.approve',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_groups_preview_targets_by_generated_sale_and_source_owner_location_when_no_linked_sales_returns_exist(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        [$firstSale, $firstDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'A');
        [$secondSale, $secondDetail] = $this->createSaleGraph($checkout, $this->secondSetting->id, $this->secondLocation->id, 'B');

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $firstDetail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
            [
                'sale_detail_id' => $secondDetail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked']);
        $this->assertCount(2, $plan['groups']);
        $this->assertSame([], $posReturn->fresh()->saleReturns->all());
        $this->assertSame(
            [$firstSale->id, $secondSale->id],
            collect($plan['groups'])->pluck('source_sale.id')->sort()->values()->all()
        );
        $this->assertContains('no_linked_sale_returns', collect($plan['info'])->pluck('code')->all());
    }

    /** @test */
    public function it_resolves_serial_dispatch_from_product_serial_numbers_dispatch_detail_id(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'SERIAL-' . uniqid(),
            'product_name' => 'Preview Serial Product',
            'serial_number_required' => true,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-PREVIEW-' . uniqid());
        [$sale, $detail, $dispatchDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'SER', $product, [
            'serial_number_ids' => [$serial->id],
        ]);
        $serial->update(['dispatch_detail_id' => $dispatchDetail->id, 'status' => ProductSerialNumber::STATUS_SOLD]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $detail->id,
                'returned_serial_id' => $serial->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);
        $posReturn->lines()->update(['dispatch_detail_id' => null]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked']);
        $detailPlan = $plan['groups'][0]['planned_details'][0];
        $this->assertSame($dispatchDetail->id, $detailPlan['dispatch_detail_id']);
        $this->assertSame('returned_serial.dispatch_detail_id', $detailPlan['dispatch_resolution']);
    }

    /** @test */
    public function it_reports_missing_and_ambiguous_dispatch_blockers_for_non_serial_lines(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$missingTransaction, $missingCheckout] = $this->createTransactionWithCheckout();
        [$missingSale, $missingDetail] = $this->createSaleGraph($missingCheckout, $this->setting->id, $this->location->id, 'MISS');
        $missingPosReturn = $this->makePendingReturn($missingTransaction->id, [
            [
                'sale_detail_id' => $missingDetail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);
        $missingPosReturn->lines()->update(['dispatch_detail_id' => null]);
        DispatchDetail::query()->where('sale_id', $missingSale->id)->delete();

        $missingPlan = $this->planner->plan($missingPosReturn->fresh());
        $this->assertTrue($missingPlan['is_blocked']);
        $this->assertContains('dispatch_missing', collect($missingPlan['blockers'])->pluck('code')->all());

        [$ambiguousTransaction, $ambiguousCheckout] = $this->createTransactionWithCheckout();
        [$ambiguousSale, $ambiguousDetail, $ambiguousDispatch] = $this->createSaleGraph($ambiguousCheckout, $this->setting->id, $this->location->id, 'AMB');
        DispatchDetail::query()->create([
            'dispatch_id' => $ambiguousDispatch->dispatch_id,
            'sale_id' => $ambiguousSale->id,
            'product_id' => $ambiguousDetail->product_id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $ambiguousPosReturn = $this->makePendingReturn($ambiguousTransaction->id, [
            [
                'sale_detail_id' => $ambiguousDetail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);
        $ambiguousPosReturn->lines()->update(['dispatch_detail_id' => null]);
        DispatchDetail::query()->where('sale_detail_id', $ambiguousDetail->id)->delete();

        $ambiguousPlan = $this->planner->plan($ambiguousPosReturn->fresh());
        $this->assertTrue($ambiguousPlan['is_blocked']);
        $this->assertContains('dispatch_ambiguous', collect($ambiguousPlan['blockers'])->pluck('code')->all());
    }

    /** @test */
    public function it_blocks_mixed_cash_return_and_product_replacement_preview(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'MIX-' . uniqid(),
            'product_name' => 'Mixed Preview Product',
            'serial_number_required' => true,
        ]);
        $serialA = $this->createSerialNumber($product, $this->location, 'SN-MIX-A-' . uniqid());
        $serialB = $this->createSerialNumber($product, $this->location, 'SN-MIX-B-' . uniqid());
        $replacement = $this->createSerialNumber($product, $this->location, 'SN-MIX-R-' . uniqid());
        $replacement->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        [, $detailA] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'MIXA', $product, [
            'serial_number_ids' => [$serialA->id],
        ]);
        [, $detailB] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'MIXB', $product, [
            'serial_number_ids' => [$serialB->id],
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $detailA->id,
                'returned_serial_id' => $serialA->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
            [
                'sale_detail_id' => $detailB->id,
                'returned_serial_id' => $serialB->id,
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'replacement_serial_id' => $replacement->id,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($plan['is_blocked']);
        $this->assertContains('mixed_options', collect($plan['blockers'])->pluck('code')->all());
    }

    /** @test */
    public function it_reports_header_option_mismatch_as_warning_when_line_intent_is_resolvable(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'WARN-' . uniqid(),
            'product_name' => 'Warning Preview Product',
            'serial_number_required' => true,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-WARN-' . uniqid());
        $replacement = $this->createSerialNumber($product, $this->location, 'SN-WARN-R-' . uniqid());
        [, $detail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'WARN', $product, [
            'serial_number_ids' => [$serial->id],
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $detail->id,
                'returned_serial_id' => $serial->id,
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'replacement_serial_id' => $replacement->id,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked']);
        $this->assertContains('header_option_mismatch', collect($plan['warnings'])->pluck('code')->all());
    }

    /** @test */
    public function it_reports_snapshot_drift_as_blocker_without_mutating(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        [, $detail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'DRIFT');
        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $detail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $detail->update(['quantity' => 2, 'sub_total' => 1200]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($plan['is_blocked']);
        $this->assertContains('snapshot_drift', collect($plan['blockers'])->pluck('code')->all());
        $this->assertDatabaseHas('pos_returns', [
            'id' => $posReturn->id,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
        ]);
    }

    /** @test */
    public function it_reports_live_source_identity_mismatch_as_blocker(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        [$sale, $detail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'IDENT');
        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $detail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $otherProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'IDENT-OTHER-' . uniqid(),
            'product_name' => 'Identity Drift Product',
            'sale_price' => 600,
        ]);
        $detail->update([
            'product_id' => $otherProduct->id,
            'product_name' => $otherProduct->product_name,
            'product_code' => $otherProduct->product_code,
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($plan['is_blocked']);
        $this->assertContains('source_identity_mismatch', collect($plan['blockers'])->pluck('code')->all());
    }

    protected function createTransactionWithCheckout(): array
    {
        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-PREVIEW-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1200,
            'receipt_number' => 'RCP-PREVIEW-' . uniqid(),
            'idempotency_key' => 'IDEM-PREVIEW-' . uniqid(),
            'payload_hash' => 'HASH-PREVIEW-' . uniqid(),
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        return [$transaction, $checkout];
    }

    protected function createSaleGraph(PosCheckout $checkout, int $sourceSettingId, int $sourceLocationId, string $suffix, $product = null, array $detailOverrides = []): array
    {
        $product = $product ?: $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . $suffix . '-' . uniqid(),
            'product_name' => 'Preview Product ' . $suffix,
            'sale_price' => 600,
        ]);

        $sale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 600,
            'paid_amount' => 600,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . $suffix . '-' . uniqid(),
        ]);

        PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $sourceSettingId,
            'source_location_id' => $sourceLocationId,
            'grand_total' => 600,
            'split_key' => 'SPLIT-' . $suffix . '-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $detail = SaleDetails::query()->create(array_merge([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 600,
            'unit_price' => 600,
            'sub_total' => 600,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ], $detailOverrides));

        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $sourceLocationId,
            'tax_id' => $detail->tax_id,
        ]);

        return [$sale, $detail, $dispatchDetail];
    }

    protected function makePendingReturn(int $transactionId, array $lines): PosReturn
    {
        $snapshot = $this->snapshotService->build($transactionId);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => $lines,
        ]);

        return $this->submissionService->submitDraftForApproval($posReturn);
    }
}
<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnDispatchQuantityAdjustmentTest extends PosTransactionFeatureTestCase
{
    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

    protected PosReturnLifecycleService $lifecycleService;

    protected $setting;

    protected $location;

    protected $terminal;

    protected $session;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->lifecycleService = app(PosReturnLifecycleService::class);
        $this->setting = $this->createSetting('POS Return Dispatch Reduction Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        foreach (['pos.returns.create', 'pos.returns.approve', 'pos.returns.receive'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Dispatch Reduction Clerk', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.approve',
            'pos.returns.receive',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_reduces_dispatch_quantities_only_after_receiving_split_owner_returns(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-DISP-' . uniqid(),
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
            'grand_total' => 1000,
            'receipt_number' => 'RCP-DISP-' . uniqid(),
            'idempotency_key' => 'IDEM-DISP-' . uniqid(),
            'payload_hash' => 'HASH-DISP-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        [$detailA, $dispatchA] = $this->createSaleLineWithDispatch($checkout, 'A');
        [$detailB, $dispatchB] = $this->createSaleLineWithDispatch($checkout, 'B');

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_detail_id' => $detailA->id, 'quantity' => 1],
                ['sale_detail_id' => $detailB->id, 'quantity' => 1],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn);
        $posReturn->lines()
            ->orderBy('id')
            ->get()
            ->values()
            ->each(function ($line, $index) use ($dispatchA, $dispatchB): void {
                $line->update([
                    'dispatch_detail_id' => $index === 0 ? $dispatchA->id : $dispatchB->id,
                ]);
            });

        $this->assertSame(2, (int) $dispatchA->fresh()->dispatched_quantity);
        $this->assertSame(2, (int) $dispatchB->fresh()->dispatched_quantity);

        $plan = app(\Modules\Pos\Services\PosReturnApprovalPreviewPlannerService::class)->plan($posReturn->fresh());
        $this->lifecycleService->approve($posReturn->id, null, $plan);
        $this->assertSame(2, (int) $dispatchA->fresh()->dispatched_quantity);
        $this->assertSame(2, (int) $dispatchB->fresh()->dispatched_quantity);

        $this->lifecycleService->receive($posReturn->id);

        $this->assertSame(1, (int) $dispatchA->fresh()->dispatched_quantity);
        $this->assertSame(1, (int) $dispatchB->fresh()->dispatched_quantity);
    }

    protected function createSaleLineWithDispatch(PosCheckout $checkout, string $suffix): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-DISP-' . $suffix . '-' . uniqid(),
            'sale_price' => 500,
            'stock_qty' => 10,
        ]);
        $sale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-DISP-' . $suffix . '-' . uniqid(),
        ]);
        PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-DISP-' . $suffix . '-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);
        $detail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $dispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);

        return [$detail, $dispatchDetail];
    }
}
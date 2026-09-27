<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnApprovalPreviewMutationSafetyTest extends PosTransactionFeatureTestCase
{
    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

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
        $this->setting = $this->createSetting('POS Return Approval Preview Mutation Safety Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        foreach (['pos.returns.create', 'pos.returns.approve', 'pos.returns.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Approval Preview Safety User', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.approve',
            'pos.returns.view',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function opening_ready_preview_does_not_mutate_lifecycle_or_inventory_tables(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $fixture = $this->makePendingSerialReturnFixture();
        $posReturn = $fixture['pos_return'];
        $dispatchDetail = $fixture['dispatch_detail'];
        $product = $fixture['product'];
        $serial = $fixture['serial'];

        $before = $this->captureMutationState($posReturn->id, $product->id, $dispatchDetail->id, $serial->id, $this->location->id);

        $response = $this->get(route('pos.returns.approval-preview', $posReturn));

        $response->assertOk()->assertSee('Planned Sales Return Targets');

        $after = $this->captureMutationState($posReturn->id, $product->id, $dispatchDetail->id, $serial->id, $this->location->id);
        $this->assertSame($before, $after);
    }

    /** @test */
    public function opening_blocked_preview_also_leaves_lifecycle_and_inventory_tables_unchanged(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $fixture = $this->makePendingSerialReturnFixture();
        $posReturn = $fixture['pos_return'];
        $dispatchDetail = $fixture['dispatch_detail'];
        $product = $fixture['product'];
        $serial = $fixture['serial'];
        $fixture['checkout']->update([
            'grand_total' => 1200,
            'receipt_number' => 'RCP-BLOCKED-' . uniqid(),
        ]);

        $before = $this->captureMutationState($posReturn->id, $product->id, $dispatchDetail->id, $serial->id, $this->location->id);

        $response = $this->get(route('pos.returns.approval-preview', $posReturn));

        $response->assertOk()->assertSee('Preview diblokir');

        $after = $this->captureMutationState($posReturn->id, $product->id, $dispatchDetail->id, $serial->id, $this->location->id);
        $this->assertSame($before, $after);
    }

    /** @test */
    public function final_approval_revalidates_stale_preview_and_keeps_mutation_tables_unchanged(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $fixture = $this->makePendingSerialReturnFixture();
        $posReturn = $fixture['pos_return'];
        $dispatchDetail = $fixture['dispatch_detail'];
        $product = $fixture['product'];
        $serial = $fixture['serial'];

        $before = $this->captureMutationState($posReturn->id, $product->id, $dispatchDetail->id, $serial->id, $this->location->id);

        $fixture['checkout']->update([
            'grand_total' => 1200,
            'receipt_number' => 'RCP-STALE-' . uniqid(),
        ]);

        $response = $this->post(route('pos.returns.approve', $posReturn));

        $response->assertRedirect(route('pos.returns.approval-preview', $posReturn));

        $after = $this->captureMutationState($posReturn->id, $product->id, $dispatchDetail->id, $serial->id, $this->location->id);
        $this->assertSame($before, $after);
    }

    protected function makePendingSerialReturnFixture(): array
    {
        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SAFE-' . uniqid(),
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
            'grand_total' => 600,
            'receipt_number' => 'RCP-SAFE-' . uniqid(),
            'idempotency_key' => 'IDEM-SAFE-' . uniqid(),
            'payload_hash' => 'HASH-SAFE-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'SAFE-' . uniqid(),
            'product_name' => 'Safe Preview Product',
            'sale_price' => 600,
            'serial_number_required' => true,
            'stock_qty' => 2,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-SAFE-' . uniqid());
        $serial->update(['status' => ProductSerialNumber::STATUS_SOLD]);

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
            'reference' => 'SO-SAFE-' . uniqid(),
        ]);
        PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 600,
            'split_key' => 'SPLIT-SAFE-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);
        $saleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 600,
            'unit_price' => 600,
            'sub_total' => 600,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'serial_number_ids' => [$serial->id],
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
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        $serial->update(['dispatch_detail_id' => $dispatchDetail->id]);

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'returned_serial_id' => $serial->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn);

        return [
            'pos_return' => $posReturn,
            'product' => $product,
            'serial' => $serial,
            'sale_detail' => $saleDetail,
            'dispatch_detail' => $dispatchDetail,
            'checkout' => $checkout,
        ];
    }

    protected function captureMutationState(int $posReturnId, int $productId, int $dispatchDetailId, int $serialId, int $locationId): array
    {
        $posReturn = PosReturn::query()->findOrFail($posReturnId);
        $product = DB::table('products')->where('id', $productId)->first();
        $productStock = ProductStock::query()->where('product_id', $productId)->where('location_id', $locationId)->first();
        $dispatchDetail = DB::table('dispatch_details')->where('id', $dispatchDetailId)->first();
        $serial = DB::table('product_serial_numbers')->where('id', $serialId)->first();

        return [
            'pos_return' => [
                'status' => $posReturn->status,
                'approval_status' => $posReturn->approval_status,
                'approved_by' => $posReturn->approved_by,
                'approved_at' => optional($posReturn->approved_at)?->toDateTimeString(),
            ],
            'counts' => [
                'sale_returns' => DB::table('sale_returns')->count(),
                'sale_return_details' => DB::table('sale_return_details')->count(),
                'transactions' => DB::table('transactions')->count(),
                'sale_return_payments' => DB::table('sale_return_payments')->count(),
                'dispatches' => DB::table('dispatches')->count(),
                'dispatch_details' => DB::table('dispatch_details')->count(),
            ],
            'product' => [
                'product_quantity' => (int) ($product->product_quantity ?? 0),
                'stock_quantity' => (float) ($productStock?->quantity ?? 0),
            ],
            'dispatch_detail' => [
                'dispatched_quantity' => (float) ($dispatchDetail->dispatched_quantity ?? 0),
            ],
            'serial' => [
                'dispatch_detail_id' => $serial->dispatch_detail_id,
                'location_id' => $serial->location_id,
                'status' => $serial->status,
            ],
        ];
    }
}
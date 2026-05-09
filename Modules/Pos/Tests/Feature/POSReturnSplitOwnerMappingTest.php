<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnSplitOwnerMappingTest extends PosTransactionFeatureTestCase
{
    protected $setting;

    protected $secondSetting;

    protected $location;

    protected $secondLocation;

    protected $terminal;

    protected $session;

    protected $user;

    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->setting = $this->createSetting('POS Return Split Owner Test');
        $this->secondSetting = $this->createSetting('POS Return Split Owner Test 2');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        [, $this->secondLocation] = $this->createTerminalWithLocation($this->secondSetting);

        Permission::findOrCreate('pos.returns.create', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Split Owner Clerk', [
            'pos.access',
            'pos.returns.create',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_preserves_original_sale_owner_location_tax_and_dispatch_context_for_split_owner_transactions(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SPLIT-' . uniqid(),
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
            'receipt_number' => 'RCP-SPLIT-' . uniqid(),
            'idempotency_key' => 'IDEM-SPLIT-' . uniqid(),
            'payload_hash' => 'HASH-SPLIT-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        [$firstSale, $firstDetail, $firstDispatchDetail] = $this->createOwnerMappedSale($checkout, $this->setting->id, $this->location->id, 'A');
        [$secondSale, $secondDetail, $secondDispatchDetail] = $this->createOwnerMappedSale($checkout, $this->secondSetting->id, $this->secondLocation->id, 'B');

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $firstDetail->id,
                    'quantity' => 1,
                    'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
                [
                    'sale_detail_id' => $secondDetail->id,
                    'quantity' => 1,
                    'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);

        $posReturn->load(['lines', 'saleReturns']);

        $this->assertCount(2, $posReturn->lines);
        $this->assertCount(2, $posReturn->saleReturns);

        $firstLine = $posReturn->lines->firstWhere('sale_id', $firstSale->id);
        $secondLine = $posReturn->lines->firstWhere('sale_id', $secondSale->id);

        $this->assertSame($firstDispatchDetail->id, $firstLine->dispatch_detail_id);
        $this->assertSame($this->setting->id, $firstLine->source_setting_id);
        $this->assertSame($this->location->id, $firstLine->source_location_id);
        $this->assertSame($firstDetail->tax_id, $firstLine->tax_id);

        $this->assertSame($secondDispatchDetail->id, $secondLine->dispatch_detail_id);
        $this->assertSame($this->secondSetting->id, $secondLine->source_setting_id);
        $this->assertSame($this->secondLocation->id, $secondLine->source_location_id);
        $this->assertSame($secondDetail->tax_id, $secondLine->tax_id);

        $this->assertTrue($posReturn->saleReturns->contains(fn ($saleReturn) => (int) $saleReturn->sale_id === (int) $firstSale->id && (int) $saleReturn->location_id === (int) $this->location->id));
        $this->assertTrue($posReturn->saleReturns->contains(fn ($saleReturn) => (int) $saleReturn->sale_id === (int) $secondSale->id && (int) $saleReturn->location_id === (int) $this->secondLocation->id));
    }

    protected function createOwnerMappedSale(PosCheckout $checkout, int $sourceSettingId, int $sourceLocationId, string $suffix): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-SPLIT-' . $suffix . '-' . uniqid(),
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
            'reference' => 'SO-SPLIT-' . $suffix . '-' . uniqid(),
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
        $detail = SaleDetails::query()->create([
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
            'tax_id' => null,
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
            'location_id' => $sourceLocationId,
            'tax_id' => null,
        ]);

        return [$sale, $detail, $dispatchDetail];
    }
}
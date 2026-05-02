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

class POSReturnSerialSplitOwnerTest extends PosTransactionFeatureTestCase
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
        $this->setting = $this->createSetting('POS Return Serial Split Owner Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.returns.create', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Serial Clerk', [
            'pos.access',
            'pos.returns.create',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_preserves_serial_identity_from_the_original_split_owner_sale_detail_into_return_lines_and_sale_return_details(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SERIAL-' . uniqid(),
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
            'grand_total' => 800,
            'receipt_number' => 'RCP-SERIAL-' . uniqid(),
            'idempotency_key' => 'IDEM-SERIAL-' . uniqid(),
            'payload_hash' => 'HASH-SERIAL-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-SERIAL-' . uniqid(),
            'sale_price' => 800,
            'serial_number_required' => true,
            'stock_qty' => 2,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-' . uniqid());
        $sale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 800,
            'paid_amount' => 800,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SERIAL-' . uniqid(),
        ]);
        PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 800,
            'split_key' => 'SPLIT-SERIAL-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);
        $detail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 800,
            'unit_price' => 800,
            'sub_total' => 800,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serial->id],
        ]);
        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $detail->id,
                    'quantity' => 1,
                    'serial_number_ids' => [$serial->id],
                ],
            ],
        ]);

        $returnLine = $posReturn->lines()->firstOrFail();
        $saleReturnDetail = $posReturn->saleReturns()->firstOrFail()->saleReturnDetails()->firstOrFail();

        $this->assertSame([$serial->id], $returnLine->serial_number_ids);
        $this->assertSame([$serial->id], $saleReturnDetail->serial_number_ids);
        $this->assertSame($detail->id, $returnLine->sale_detail_id);
    }
}
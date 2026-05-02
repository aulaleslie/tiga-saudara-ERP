<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Pos\Entities\PosCheckoutSale;
use Spatie\Permission\Models\Permission;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;

class POSReturnReceivingWorkflowTest extends PosTransactionFeatureTestCase
{
    protected $user;
    protected $approver;
    protected $receiver;
    protected $submissionService;
    protected $snapshotService;
    protected $lifecycleService;
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
        
        $this->setting = $this->createSetting('POS Return Receiving Test');
        
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');
        Permission::findOrCreate('pos.returns.receive', 'web');
        
        $this->user = $this->createUserForSetting($this->setting, 'POS Return Clerk', [
            'pos.access',
            'pos.returns.create',
        ]);

        $this->approver = $this->createUserForSetting($this->setting, 'POS Return Approver', [
            'pos.access',
            'pos.returns.approve',
        ]);

        $this->receiver = $this->createUserForSetting($this->setting, 'POS Return Receiver', [
            'pos.access',
            'pos.returns.receive',
        ]);
        
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    protected function createApprovedReturn(string $returnOption = PosReturn::OPTION_CASH_RETURN)
    {
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);
        
        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1000,
            'receipt_number' => 'RCP-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . uniqid(),
            'sale_price' => 500,
            'stock_qty' => 10,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
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

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $data = [
            'pos_transaction_id' => $transaction->id,
            'return_option' => $returnOption,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                ]
            ]
        ];

        $this->actingAsInSetting($this->user, $this->setting);
        $posReturn = $this->submissionService->store($data);

        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->approve($posReturn->id);

        return $posReturn->refresh();
    }

    /** @test */
    public function it_can_receive_an_approved_pos_return()
    {
        $posReturn = $this->createApprovedReturn();
        $this->assertEquals(PosReturn::STATUS_APPROVED, $posReturn->status);

        $this->actingAsInSetting($this->receiver, $this->setting);
        $this->lifecycleService->receive($posReturn->id);

        $posReturn->refresh();
        // Since it's cash_return, it should move to STATUS_AWAITING_SETTLEMENT
        $this->assertEquals(PosReturn::STATUS_AWAITING_SETTLEMENT, $posReturn->status);
        $this->assertEquals($this->receiver->id, $posReturn->received_by);
        $this->assertNotNull($posReturn->received_at);
        
        // Check linked Sales Returns
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->assertEquals('AWAITING SETTLEMENT', $saleReturn->status);
            $this->assertNotNull($saleReturn->received_at);
        }

        // Check Inventory
        $posReturn->load('lines.product');
        foreach ($posReturn->lines as $line) {
            // Product quantity should increase by 1 (we returned 1)
            // Initial was 10 (from createStockedProduct)
            $this->assertEquals(11, $line->product->product_quantity);
            
            // Dispatch Detail quantity should decrease by 1
            $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::find($line->dispatch_detail_id);
            $this->assertEquals(1, $dispatchDetail->dispatched_quantity); // Initial was 2
        }
    }

    /** @test */
    public function it_blocks_receiving_if_not_approved()
    {
        // 1. Create a return that is NOT approved (Pending Approval)
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);
        
        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1000,
            'receipt_number' => 'RCP-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);
        $product = $this->createStockedProduct($this->setting, $this->location, ['product_code' => 'PRD-' . uniqid(), 'sale_price' => 500]);
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . uniqid(),
        ]);
        PosCheckoutSale::create(['pos_checkout_id' => $checkout->id, 'sale_id' => $sale->id, 'source_setting_id' => $this->setting->id, 'source_location_id' => $this->location->id, 'grand_total' => 1000, 'split_key' => 'SPLIT-' . uniqid(), 'tax_bucket' => 'NON_TAX']);
        $saleDetail = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 2, 'price' => 500, 'unit_price' => 500, 'sub_total' => 1000, 'product_name' => $product->product_name, 'product_code' => $product->product_code, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $snapshot = $this->snapshotService->build($transaction->id);
        $data = ['pos_transaction_id' => $transaction->id, 'return_option' => PosReturn::OPTION_CASH_RETURN, 'source_snapshot' => $snapshot, 'source_snapshot_hash' => $snapshot['hash'], 'lines' => [['sale_detail_id' => $saleDetail->id, 'quantity' => 1]]];
        $this->actingAsInSetting($this->user, $this->setting);
        $posReturn = $this->submissionService->store($data);

        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->status);

        $this->actingAsInSetting($this->receiver, $this->setting);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Only approved returns can be received.");
        
        $this->lifecycleService->receive($posReturn->id);
    }

    /** @test */
    public function it_can_settle_a_cash_return()
    {
        // 1. Create an approved return that has been RECEIVED
        $posReturn = $this->createApprovedReturn();
        $this->actingAsInSetting($this->receiver, $this->setting);
        $this->lifecycleService->receive($posReturn->id);
        
        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_AWAITING_SETTLEMENT, $posReturn->status);

        // 2. Settle it
        $this->actingAsInSetting($this->user, $this->setting);
        $this->lifecycleService->settlePaymentReturn($posReturn->id);

        // 3. Assert status
        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $posReturn->status);

        // Check linked Sales Returns
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->assertEquals('COMPLETED', $saleReturn->status);
            $this->assertEquals('PAID', $saleReturn->payment_status);
            $this->assertNotNull($saleReturn->settled_at);
        }
    }

    /** @test */
    public function it_can_settle_a_replacement_return()
    {
        // 1. Create an approved replacement return
        $posReturn = $this->createApprovedReturn(PosReturn::OPTION_PRODUCT_REPLACEMENT);
        
        $this->actingAsInSetting($this->receiver, $this->setting);
        $this->lifecycleService->receive($posReturn->id);
        
        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_AWAITING_DISPATCH, $posReturn->status);

        // 2. Dispatch replacement
        $this->actingAsInSetting($this->user, $this->setting);
        $this->lifecycleService->dispatchReplacement($posReturn->id);

        // 3. Assert status
        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $posReturn->status);

        // Check linked Sales Returns
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->assertEquals('COMPLETED', $saleReturn->status);
            $this->assertNotNull($saleReturn->settled_at);
            
            // Check Dispatch Record
            $this->assertTrue(
                Dispatch::where('sale_id', $saleReturn->sale_id)
                    ->where('status', Dispatch::STATUS_APPROVED)
                    ->exists()
            );
            
            // Check Stock Adjustment (Initial 10, Returned 1 => 11, Replaced 1 => 10)
            $saleReturn->load('saleReturnDetails.product');
            foreach ($saleReturn->saleReturnDetails as $detail) {
                $this->assertEquals(10, $detail->product->product_quantity);
            }
        }
    }
}

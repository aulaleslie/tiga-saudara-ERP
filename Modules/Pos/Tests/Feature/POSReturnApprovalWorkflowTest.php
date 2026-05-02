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

class POSReturnApprovalWorkflowTest extends PosTransactionFeatureTestCase
{
    protected $user;
    protected $approver;
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
        
        $this->setting = $this->createSetting('POS Return Approval Test');
        
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');
        
        $this->user = $this->createUserForSetting($this->setting, 'POS Return Clerk', [
            'pos.access',
            'pos.returns.create',
        ]);

        $this->approver = $this->createUserForSetting($this->setting, 'POS Return Approver', [
            'pos.access',
            'pos.returns.approve',
        ]);
        
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    protected function createPendingReturn()
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

        $snapshot = $this->snapshotService->build($transaction->id);

        $data = [
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
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
        return $this->submissionService->store($data);
    }

    /** @test */
    public function it_can_approve_a_pending_pos_return()
    {
        $posReturn = $this->createPendingReturn();
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->status);

        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->approve($posReturn->id);

        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_APPROVED, $posReturn->status);
        $this->assertEquals(PosReturn::APPROVAL_STATUS_APPROVED, $posReturn->approval_status);
        $this->assertEquals($this->approver->id, $posReturn->approved_by);
        $this->assertNotNull($posReturn->approved_at);
        
        // Linked Sales Returns should also be approved
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->assertEquals('AWAITING RECEIVING', $saleReturn->status);
            $this->assertEquals('APPROVED', $saleReturn->approval_status);
        }
    }

    /** @test */
    public function it_can_reject_a_pending_pos_return_with_reason()
    {
        $posReturn = $this->createPendingReturn();
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->status);

        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->reject($posReturn->id, 'Damaged product not eligible');

        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_REJECTED, $posReturn->status);
        $this->assertEquals(PosReturn::APPROVAL_STATUS_REJECTED, $posReturn->approval_status);
        $this->assertEquals($this->approver->id, $posReturn->rejected_by);
        $this->assertNotNull($posReturn->rejected_at);
        $this->assertEquals('Damaged product not eligible', $posReturn->rejection_reason);

        // Linked Sales Returns should also be rejected
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->assertEquals('REJECTED', $saleReturn->status);
            $this->assertEquals('REJECTED', $saleReturn->approval_status);
        }
    }

    /** @test */
    public function it_blocks_approval_if_not_pending_approval()
    {
        $posReturn = $this->createPendingReturn();
        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->approve($posReturn->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Only pending approval returns can be approved.");
        
        $this->lifecycleService->approve($posReturn->id);
    }

    /** @test */
    public function it_blocks_rejection_if_not_pending_approval()
    {
        $posReturn = $this->createPendingReturn();
        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->reject($posReturn->id, 'Rejected');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Only pending approval returns can be rejected.");
        
        $this->lifecycleService->reject($posReturn->id, 'Rejected again');
    }

    /** @test */
    public function it_enforces_permissions_for_approval_and_rejection()
    {
        $this->assertFalse($this->user->can('pos.returns.approve'));
        $this->assertTrue($this->approver->can('pos.returns.approve'));
    }
}

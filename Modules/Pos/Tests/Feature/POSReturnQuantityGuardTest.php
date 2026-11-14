<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnSnapshotService;
use App\Support\PosReturn\PosReturnQuantityGuard;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Pos\Entities\PosCheckoutSale;
use Spatie\Permission\Models\Permission;
use Modules\People\Entities\Customer;

class POSReturnQuantityGuardTest extends PosTransactionFeatureTestCase
{
    protected $user;
    protected $submissionService;
    protected $snapshotService;
    protected $quantityGuard;
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
        $this->quantityGuard = app(PosReturnQuantityGuard::class);
        
        $this->setting = $this->createSetting('Quantity Guard Test');
        
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');
        
        $this->user = $this->createUserForSetting($this->setting, 'POS Return Clerk', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.approve',
        ]);
        
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_correctly_calculates_returnable_quantity_for_partial_returns()
    {
        // Setup
        $transaction = $this->setupTransaction('TXN-QTY-1');
        $product = $this->createStockedProduct($this->setting, $this->location);
        $sale = $this->setupSale($transaction, 1000);
        
        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Initial check
        $this->assertEquals(10, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id));

        // Submit return for 3 units
        $snapshot = $this->snapshotService->build($transaction->id);
        $this->actingAsInSetting($this->user, $this->setting);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 3,
                ]
            ]
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn);
        $posReturn->update([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        // Check after partial return
        $this->assertEquals(7, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id));
        $this->assertTrue($this->quantityGuard->isValid(null, 7, ['sale_detail_id' => $saleDetail->id]));
        $this->assertFalse($this->quantityGuard->isValid(null, 8, ['sale_detail_id' => $saleDetail->id]));
    }

    /** @test */
    public function it_releases_returnable_quantity_when_return_is_reversed()
    {
        // Setup
        $transaction = $this->setupTransaction('TXN-QTY-2');
        $product = $this->createStockedProduct($this->setting, $this->location);
        $sale = $this->setupSale($transaction, 1000);
        
        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Submit return for 5 units
        $snapshot = $this->snapshotService->build($transaction->id);
        $this->actingAsInSetting($this->user, $this->setting);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 5,
                ]
            ]
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn);
        $posReturn->update([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        $this->assertEquals(5, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id));

        // Mark as reversed
        $posReturn->update(['is_reversed' => true]);

        // Should be back to 10
        $this->assertEquals(10, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id));
    }

    /** @test */
    public function it_handles_successive_partial_cash_returns_without_double_subtraction(): void
    {
        $transaction = $this->setupTransaction('TXN-QTY-SUCCESSIVE');
        $product = $this->createStockedProduct($this->setting, $this->location);
        $sale = $this->setupSale($transaction, 1000);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
        ]);
        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 10,
            'location_id' => $this->location->id,
        ]);

        // Initial check: 10 available
        $this->assertEquals(10, $this->quantityGuard->getReturnableQuantity($dispatchDetail->id, $saleDetail->id));

        // Return 1: 4 units cash return, executed (reduces saleDetail and dispatchDetail by 4)
        $snapshot = $this->snapshotService->build($transaction->id);
        $this->actingAsInSetting($this->user, $this->setting);
        $return1 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 4,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $return1 = $this->submissionService->submitDraftForApproval($return1);
        $return1->update([
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);
        // Simulate lifecycle execution quantity reduction
        $saleDetail->update(['quantity' => 6]);
        $dispatchDetail->update(['dispatched_quantity' => 6]);

        // After return 1: remaining returnable must be 6 (not 6 - 4 = 2!)
        $this->assertEquals(6, $this->quantityGuard->getReturnableQuantity($dispatchDetail->id, $saleDetail->id));
        $this->assertTrue($this->quantityGuard->isValid($dispatchDetail->id, 6, ['sale_detail_id' => $saleDetail->id]));
        $this->assertFalse($this->quantityGuard->isValid($dispatchDetail->id, 7, ['sale_detail_id' => $saleDetail->id]));

        $checkoutSaleId = PosCheckoutSale::where('sale_id', $sale->id)->value('id');

        // Return 2: 4 units cash return, executed
        $return2 = PosReturn::create([
            'reference' => 'POSRT-SUCC-2',
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $transaction->code,
            'receipt_number' => 'RCP-' . $transaction->code,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'total_amount' => 400,
            'created_by' => $this->user->id,
        ]);
        PosReturnLine::create([
            'pos_return_id' => $return2->id,
            'pos_checkout_sale_id' => $checkoutSaleId,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 4,
            'unit_price' => 100,
            'line_total' => 400,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);
        $saleDetail->update(['quantity' => 2]);
        $dispatchDetail->update(['dispatched_quantity' => 2]);

        // After return 2: remaining returnable must be 2 (not 2 - 8 = 0!)
        $this->assertEquals(2, $this->quantityGuard->getReturnableQuantity($dispatchDetail->id, $saleDetail->id));
        $this->assertTrue($this->quantityGuard->isValid($dispatchDetail->id, 2, ['sale_detail_id' => $saleDetail->id]));
        $this->assertFalse($this->quantityGuard->isValid($dispatchDetail->id, 3, ['sale_detail_id' => $saleDetail->id]));

        // Return 3: 2 units cash return, executed
        $return3 = PosReturn::create([
            'reference' => 'POSRT-SUCC-3',
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $transaction->code,
            'receipt_number' => 'RCP-' . $transaction->code,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'total_amount' => 200,
            'created_by' => $this->user->id,
        ]);
        PosReturnLine::create([
            'pos_return_id' => $return3->id,
            'pos_checkout_sale_id' => $checkoutSaleId,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'unit_price' => 100,
            'line_total' => 200,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);
        $saleDetail->update(['quantity' => 0]);
        $dispatchDetail->update(['dispatched_quantity' => 0]);

        // After return 3: reaches zero exactly once
        $this->assertEquals(0, $this->quantityGuard->getReturnableQuantity($dispatchDetail->id, $saleDetail->id));
        $this->assertFalse($this->quantityGuard->isValid($dispatchDetail->id, 1, ['sale_detail_id' => $saleDetail->id]));
    }

    /** @test */
    public function it_allows_independent_pending_returns_within_available_quantity_and_blocks_overlapping_claims(): void
    {
        $transaction = $this->setupTransaction('TXN-QTY-INDEP');
        $product = $this->createStockedProduct($this->setting, $this->location);
        $sale = $this->setupSale($transaction, 1000);
        $checkoutSaleId = PosCheckoutSale::where('sale_id', $sale->id)->value('id');

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);
        $this->actingAsInSetting($this->user, $this->setting);

        // Return A: 4 units pending
        $returnA = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 4,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $returnA = $this->submissionService->submitDraftForApproval($returnA);

        // Return B: 3 units pending (distinct independent request on same transaction)
        $returnB = PosReturn::create([
            'reference' => 'POSRT-INDEP-B',
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $transaction->code,
            'receipt_number' => 'RCP-' . $transaction->code,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'total_amount' => 300,
            'created_by' => $this->user->id,
        ]);
        PosReturnLine::create([
            'pos_return_id' => $returnB->id,
            'pos_checkout_sale_id' => $checkoutSaleId,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 3,
            'unit_price' => 100,
            'line_total' => 300,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        // Excluding return A: 10 - 3 (from B) = 7 available. Return A's 4 units are valid.
        $this->assertEquals(7, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id, $returnA->id));
        $this->assertTrue($this->quantityGuard->isValid(null, 4, ['sale_detail_id' => $saleDetail->id, 'exclude_return_id' => $returnA->id]));

        // Excluding return B: 10 - 4 (from A) = 6 available. Return B's 3 units are valid.
        $this->assertEquals(6, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id, $returnB->id));
        $this->assertTrue($this->quantityGuard->isValid(null, 3, ['sale_detail_id' => $saleDetail->id, 'exclude_return_id' => $returnB->id]));

        // A third claim claiming 3 units: 10 - 4 - 3 = 3 available. 3 units are valid.
        $this->assertEquals(3, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id));
        $this->assertTrue($this->quantityGuard->isValid(null, 3, ['sale_detail_id' => $saleDetail->id]));

        // A third claim claiming 4 units: 4 > 3 -> overlapping claim blocked!
        $this->assertFalse($this->quantityGuard->isValid(null, 4, ['sale_detail_id' => $saleDetail->id]));
    }

    /** @test */
    public function it_scopes_refundable_and_returnable_quantities_by_product_when_parent_and_components_share_a_sale_detail()
    {
        $transaction = $this->setupTransaction('TXN-QTY-PARENT-CARRIER');
        $parentProduct = $this->createStockedProduct($this->setting, $this->location);
        $componentA = $this->createStockedProduct($this->setting, $this->location);
        $componentB = $this->createStockedProduct($this->setting, $this->location);
        $sale = $this->setupSale($transaction, 1000);

        $parentSaleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);
        $checkoutSaleId = PosCheckoutSale::where('sale_id', $sale->id)->value('id');

        $pendingReturn = PosReturn::create([
            'reference' => 'POSRT-SHARED-CARRIER-PENDING',
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $transaction->code,
            'receipt_number' => 'RCP-' . $transaction->code,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'total_amount' => 500,
            'created_by' => $this->user->id,
        ]);

        // Parent line + Component A + Component B lines all share $parentSaleDetail->id
        PosReturnLine::create([
            'pos_return_id' => $pendingReturn->id,
            'pos_checkout_sale_id' => $checkoutSaleId,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 500,
            'line_total' => 500,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $pendingReturn->id,
            'pos_checkout_sale_id' => $checkoutSaleId,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'component_quantity_per_bundle' => 1,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $componentA->id,
            'product_name' => $componentA->product_name,
            'product_code' => $componentA->product_code,
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        // Parent refundable & returnable quantities must only deduct the parent product (1 of 2),
        // ignoring Component A sharing the same sale_detail_id.
        $this->assertEquals(1.0, $this->quantityGuard->getRefundableQuantity($parentSaleDetail->id));
        $this->assertEquals(1.0, $this->quantityGuard->getReturnableQuantity(null, $parentSaleDetail->id));
        $this->assertTrue($this->quantityGuard->isValid(null, 1, [
            'sale_detail_id' => $parentSaleDetail->id,
            'product_id' => $parentProduct->id,
        ]));
        $this->assertFalse($this->quantityGuard->isValid(null, 2, [
            'sale_detail_id' => $parentSaleDetail->id,
            'product_id' => $parentProduct->id,
        ]));

        // Component A has 1 of 2 remaining; Component B (not yet claimed) still has its full 4.
        $this->assertEquals(1.0, $this->quantityGuard->getRefundableQuantity($parentSaleDetail->id, null, 2.0, $componentA->id));
        $this->assertEquals(4.0, $this->quantityGuard->getRefundableQuantity($parentSaleDetail->id, null, 4.0, $componentB->id));

        // When excluding a completed cash return that contains both parent and component lines on
        // the same sale_detail_id, completedCashReduction must also be scoped by product_id.
        $completedReturn = PosReturn::create([
            'reference' => 'POSRT-SHARED-CARRIER-COMPLETED',
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $transaction->code,
            'receipt_number' => 'RCP-' . $transaction->code,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'is_reversed' => false,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'total_amount' => 500,
            'created_by' => $this->user->id,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $completedReturn->id,
            'pos_checkout_sale_id' => $checkoutSaleId,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 500,
            'line_total' => 500,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $completedReturn->id,
            'pos_checkout_sale_id' => $checkoutSaleId,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'component_quantity_per_bundle' => 1,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $componentA->id,
            'product_name' => $componentA->product_name,
            'product_code' => $componentA->product_code,
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        // Remaining DB qty = 2; adding back excluded completed parent qty (1) minus pending parent qty (1) = 2 (NOT 3).
        $this->assertEquals(2.0, $this->quantityGuard->getRefundableQuantity($parentSaleDetail->id, $completedReturn->id));
        $this->assertEquals(2.0, $this->quantityGuard->getReturnableQuantity(null, $parentSaleDetail->id, $completedReturn->id));
    }

    protected function setupTransaction($code)
    {
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => $code,
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
            'receipt_number' => 'RCP-' . $code,
            'idempotency_key' => 'IDEM-' . $code,
            'payload_hash' => 'HASH-' . $code,
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);
        
        return $transaction;
    }

    protected function setupSale($transaction, $total)
    {
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => $total,
            'paid_amount' => $total,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . $transaction->code,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => $total,
            'split_key' => 'SPLIT-' . $transaction->code,
            'tax_bucket' => 'NON_TAX',
        ]);
        
        return $sale;
    }
}

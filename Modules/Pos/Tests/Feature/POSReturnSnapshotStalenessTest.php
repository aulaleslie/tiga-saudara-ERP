<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Pos\Entities\PosCheckoutSale;
use Spatie\Permission\Models\Permission;
use Modules\People\Entities\Customer;

class POSReturnSnapshotStalenessTest extends PosTransactionFeatureTestCase
{
    protected $user;
    protected $submissionService;
    protected $snapshotService;
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
        
        $this->setting = $this->createSetting('Snapshot Staleness Test');
        
        Permission::findOrCreate('pos.returns.create', 'web');
        
        $this->user = $this->createUserForSetting($this->setting, 'POS Return Clerk', [
            'pos.access',
            'pos.returns.create',
        ]);
        
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_rejects_submission_if_source_snapshot_hash_is_stale_due_to_new_return()
    {
        // 1. Setup POS Transaction and Posted Checkout
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-STALE',
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
            'receipt_number' => 'RCP-STALE',
            'idempotency_key' => 'IDEM-STALE',
            'payload_hash' => 'HASH-STALE',
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-001',
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
            'reference' => 'SO-STALE',
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-STALE',
            'tax_bucket' => 'NON_TAX',
        ]);

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

        // 2. Build Initial Snapshot
        $initialSnapshot = $this->snapshotService->build($transaction->id);
        $initialHash = $initialSnapshot['hash'];

        // 3. Create a return in the background for 2 units
        $this->actingAsInSetting($this->user, $this->setting);
        $firstReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $initialSnapshot,
            'source_snapshot_hash' => $initialHash,
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 2,
                ]
            ]
        ]);
        $firstReturn = $this->submissionService->submitDraftForApproval($firstReturn);
        $firstReturn->update([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        // 4. Try to submit another return using an invalid/stale snapshot hash
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Source snapshot is stale. Please refresh the page.");

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $initialSnapshot,
            'source_snapshot_hash' => 'mismatched-stale-hash',
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                ]
            ]
        ]);
    }

    /** @test */
    public function it_allows_submitting_partial_return_using_original_snapshot_when_quantity_remains(): void
    {
        [$transaction, $saleDetail, $initialSnapshot, $initialHash] = $this->createFixtureTransaction(10);

        // First return for 2 units
        $this->actingAsInSetting($this->user, $this->setting);
        $firstReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $initialSnapshot,
            'source_snapshot_hash' => $initialHash,
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 2,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $firstReturn = $this->submissionService->submitDraftForApproval($firstReturn);
        $firstReturn->update([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        // Second return for 1 unit using original snapshot - should be ALLOWED because 8 units remain
        $secondReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $initialSnapshot,
            'source_snapshot_hash' => $initialHash,
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $secondReturn = $this->submissionService->submitDraftForApproval($secondReturn->fresh());
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $secondReturn->status);
    }

    /** @test */
    public function it_allows_submitting_unrelated_item_return_when_another_item_was_returned(): void
    {
        [$transaction, $detail1, $snapshot, $hash, $detail2] = $this->createTwoItemFixture(5, 5);

        // Return 2 units of Item 1
        $this->actingAsInSetting($this->user, $this->setting);
        $return1 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $hash,
            'lines' => [
                [
                    'sale_detail_id' => $detail1->id,
                    'quantity' => 2,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $return1 = $this->submissionService->submitDraftForApproval($return1);
        $return1->update([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        // Submit return for Item 2 using initial snapshot (unrelated drift on item 1)
        $return2 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $hash,
            'lines' => [
                [
                    'sale_detail_id' => $detail2->id,
                    'quantity' => 2,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $return2 = $this->submissionService->submitDraftForApproval($return2->fresh());
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $return2->status);
    }

    /** @test */
    public function it_blocks_submission_when_affected_source_identity_is_corrupted_or_mismatched(): void
    {
        [$transaction, $saleDetail, $initialSnapshot, $initialHash] = $this->createFixtureTransaction(10);
        $this->actingAsInSetting($this->user, $this->setting);

        // Attempt return for non-existent sale detail ID
        $this->expectException(\Exception::class);
        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $initialSnapshot,
            'source_snapshot_hash' => $initialHash,
            'lines' => [
                [
                    'sale_detail_id' => 999999,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_blocks_submission_when_quantity_is_exhausted(): void
    {
        [$transaction, $saleDetail, $initialSnapshot, $initialHash] = $this->createFixtureTransaction(2);
        $this->actingAsInSetting($this->user, $this->setting);

        // Exhaust all 2 units
        $return1 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $initialSnapshot,
            'source_snapshot_hash' => $initialHash,
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 2,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $return1 = $this->submissionService->submitDraftForApproval($return1);
        $return1->update([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        // Attempt another return of 1 unit
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Kuantitas retur melebihi batas yang diizinkan');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $initialSnapshot,
            'source_snapshot_hash' => $initialHash,
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_blocks_submission_when_overlapping_claims_exceed_available_quantity(): void
    {
        [$transaction, $saleDetail, $initialSnapshot, $initialHash] = $this->createFixtureTransaction(5);
        $this->actingAsInSetting($this->user, $this->setting);

        // Return A claims 3 units pending
        $returnA = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $initialSnapshot,
            'source_snapshot_hash' => $initialHash,
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 3,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $this->submissionService->submitDraftForApproval($returnA);

        // Return B attempts to claim 3 units (3 + 3 = 6 > 5) -> must be blocked
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Kuantitas retur melebihi batas yang diizinkan');

        $returnB = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $initialSnapshot,
            'source_snapshot_hash' => $initialHash,
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 3,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $this->submissionService->submitDraftForApproval($returnB);
    }

    protected function createFixtureTransaction(int $qty = 10): array
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
            'grand_total' => $qty * 100,
            'receipt_number' => 'RCP-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . uniqid(),
            'sale_price' => 100,
            'stock_qty' => $qty + 10,
        ]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => $qty * 100,
            'paid_amount' => $qty * 100,
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
            'grand_total' => $qty * 100,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => $qty * 100,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        return [$transaction, $saleDetail, $snapshot, $snapshot['hash']];
    }

    protected function createTwoItemFixture(int $qty1 = 5, int $qty2 = 5): array
    {
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-2ITEM-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $total = ($qty1 * 100) + ($qty2 * 200);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => $total,
            'receipt_number' => 'RCP-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product1 = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD1-' . uniqid(),
            'sale_price' => 100,
            'stock_qty' => $qty1 + 10,
        ]);

        $product2 = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD2-' . uniqid(),
            'sale_price' => 200,
            'stock_qty' => $qty2 + 10,
        ]);

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
            'reference' => 'SO-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => $total,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $detail1 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product1->id,
            'quantity' => $qty1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => $qty1 * 100,
            'product_name' => $product1->product_name,
            'product_code' => $product1->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $detail2 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product2->id,
            'quantity' => $qty2,
            'price' => 200,
            'unit_price' => 200,
            'sub_total' => $qty2 * 200,
            'product_name' => $product2->product_name,
            'product_code' => $product2->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        return [$transaction, $detail1, $snapshot, $snapshot['hash'], $detail2];
    }
}

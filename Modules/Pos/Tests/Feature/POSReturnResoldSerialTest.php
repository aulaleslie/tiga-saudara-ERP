<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnResoldSerialTest extends PosTransactionFeatureTestCase
{
    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

    protected PosReturnLifecycleService $lifecycleService;

    protected PosReturnApprovalPreviewPlannerService $plannerService;

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
        $this->plannerService = app(PosReturnApprovalPreviewPlannerService::class);
        $this->setting = $this->createSetting('POS Return Resold Serial Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Resale Clerk', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.approve',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /**
     * Helper to create a single-product serialized POS sale and dispatch.
     *
     * @return array{0: PosTransaction, 1: Sale, 2: SaleDetails, 3: DispatchDetail}
     */
    private function createSerializedSaleFixture(Product $product, ProductSerialNumber $serial, int $price = 500000): array
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SER-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => $price,
            'receipt_number' => 'RCP-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => $price,
            'paid_amount' => $price,
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
            'grand_total' => $price,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $price,
            'unit_price' => $price,
            'sub_total' => $price,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serial->id],
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode([$serial->serial_number]),
        ]);

        $serial->update([
            'dispatch_detail_id' => $dispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'qty' => 1,
            'unit_price' => $price,
            'line_no' => 1,
        ]);

        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => $serial->serial_number,
        ]);

        return [$transaction, $sale, $saleDetail, $dispatchDetail];
    }

    /**
     * Helper to create a bundle sale fixture where the component is serialized.
     *
     * @return array{0: PosTransaction, 1: Sale, 2: SaleDetails, 3: DispatchDetail, 4: ProductBundle, 5: Product}
     */
    private function createBundleSerializedComponentSaleFixture(
        Product $parent,
        Product $comp,
        ProductBundle $bundle,
        ProductBundleItem $bundleItem,
        ProductSerialNumber $compSerial,
        int $bundlePrice = 1000000
    ): array {
        $this->actingAsInSetting($this->user, $this->setting);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-BUNDLE-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => $bundlePrice,
            'receipt_number' => 'RCP-BUNDLE-' . uniqid(),
            'idempotency_key' => 'IDEM-BUNDLE-' . uniqid(),
            'payload_hash' => 'HASH-BUNDLE-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => $bundlePrice,
            'paid_amount' => $bundlePrice,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-BUNDLE-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => $bundlePrice,
            'split_key' => 'SPLIT-BUNDLE-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $parentSaleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'quantity' => 1,
            'price' => $bundlePrice,
            'unit_price' => $bundlePrice,
            'sub_total' => $bundlePrice,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $comp->id,
            'name' => $comp->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $comp->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $comp->product_name,
            'product_code' => $comp->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $parentDispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $parentDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $compDispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $compDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $compDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $comp->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode([$compSerial->serial_number]),
        ]);

        $compSerial->update([
            'dispatch_detail_id' => $compDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $parent->id,
            'product_name_snapshot' => $parent->product_name,
            'product_code_snapshot' => $parent->product_code,
            'qty' => 1,
            'unit_price' => $bundlePrice,
            'line_no' => 1,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    ['product_id' => $comp->id, 'product_name' => $comp->product_name, 'quantity' => 1],
                ],
            ],
        ]);

        return [$transaction, $sale, $parentSaleDetail, $compDispatchDetail, $bundle, $comp];
    }

    /** @test */
    public function it_blocks_duplicate_claim_on_same_dispatch_but_allows_return_on_later_resold_dispatch()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Camera',
            'product_code' => 'CAM-001',
            'serial_number_required' => true,
            'stock_qty' => 2,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-CAM-1001');

        // First sale and dispatch
        [$transaction1, $sale1, $saleDetail1, $dispatchDetail1] = $this->createSerializedSaleFixture($product, $serial);

        $snapshot1 = $this->snapshotService->build($transaction1->id);

        // Store and submit first return
        $firstReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction1->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot1,
            'source_snapshot_hash' => $snapshot1['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail1->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serial->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
        $firstReturn = $this->submissionService->submitDraftForApproval($firstReturn->fresh());
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $firstReturn->status);

        // Assert that a duplicate claim for the SAME dispatch is blocked upon submission
        $duplicateSnapshot = $this->snapshotService->build($transaction1->id);
        $duplicateDraft = $this->submissionService->store([
            'pos_transaction_id' => $transaction1->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $duplicateSnapshot,
            'source_snapshot_hash' => $duplicateSnapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail1->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serial->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);

        $duplicateAttemptBlocked = false;
        try {
            $this->submissionService->submitDraftForApproval($duplicateDraft->fresh());
        } catch (\Exception $e) {
            $duplicateAttemptBlocked = str_contains($e->getMessage(), 'Serial yang diretur sudah diklaim');
        }
        $this->assertTrue($duplicateAttemptBlocked, 'Duplicate claim from the same dispatch must be blocked upon submission.');

        // Execute full approval & receiving lifecycle for first return
        $plan = $this->plannerService->plan($firstReturn->fresh());
        $this->assertEmpty($plan['blockers'] ?? []);
        $this->lifecycleService->executeApprovalFromPreview($firstReturn->id, PosReturn::OPTION_CASH_RETURN, $plan);

        $firstReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $firstReturn->status);

        // Assert intermediate state: serial restored to ACTIVE, undispatched, location intact, history logged
        $serial->refresh();
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $serial->status);
        $this->assertNull($serial->dispatch_detail_id);
        $this->assertEquals($this->location->id, $serial->location_id);
        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_SALE_RETURNED,
        ]);

        // Resell the returned serial in a second transaction/dispatch
        [$transaction2, $sale2, $saleDetail2, $dispatchDetail2] = $this->createSerializedSaleFixture($product, $serial);
        $this->assertNotEquals($dispatchDetail1->id, $dispatchDetail2->id);

        $serial->refresh();
        $this->assertEquals(ProductSerialNumber::STATUS_SOLD, $serial->status);
        $this->assertEquals($dispatchDetail2->id, $serial->dispatch_detail_id);

        // Now initiate a return for the second transaction (resold occurrence)
        $snapshot2 = $this->snapshotService->build($transaction2->id);
        $secondReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction2->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot2,
            'source_snapshot_hash' => $snapshot2['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail2->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serial->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);

        $secondReturn = $this->submissionService->submitDraftForApproval($secondReturn->fresh());
        $this->assertNotNull($secondReturn);
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $secondReturn->status);
        $this->assertCount(1, $secondReturn->lines);
        $this->assertEquals($dispatchDetail2->id, $secondReturn->lines->first()->dispatch_detail_id);

        // Execute full approval & receiving lifecycle for second return — must SUCCEED because it's a new dispatch
        $plan2 = $this->plannerService->plan($secondReturn->fresh());
        $this->assertEmpty($plan2['blockers'] ?? []);
        $this->lifecycleService->executeApprovalFromPreview($secondReturn->id, PosReturn::OPTION_CASH_RETURN, $plan2);

        $secondReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $secondReturn->status);
    }

    /** @test */
    public function it_blocks_same_dispatch_component_duplicate_but_allows_return_on_later_resold_component_dispatch()
    {
        $comp = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Lens Component',
            'product_code' => 'LENS-COMP-001',
            'serial_number_required' => true,
            'stock_qty' => 2,
        ]);
        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Camera Bundle Kit',
            'product_code' => 'KIT-PARENT-001',
            'sale_price' => 1500000,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Camera Kit Bundle',
            'is_active' => true,
        ]);
        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 1,
        ]);

        $compSerial = $this->createSerialNumber($comp, $this->location, 'SN-LENS-2001');

        // First bundle sale
        [$txn1, $sale1, $parentDetail1, $compDispatchDetail1] = $this->createBundleSerializedComponentSaleFixture(
            $parent,
            $comp,
            $bundle,
            $bundleItem,
            $compSerial
        );

        $snapshot1 = $this->snapshotService->build($txn1->id);

        // Store and submit first bundle return (synthesizes component return line)
        $firstReturn = $this->submissionService->store([
            'pos_transaction_id' => $txn1->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot1,
            'source_snapshot_hash' => $snapshot1['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail1->id,
                    'quantity' => 1,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
        $firstReturn = $this->submissionService->submitDraftForApproval($firstReturn->fresh());
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $firstReturn->status);

        // Assert duplicate claim for the same component dispatch is blocked
        $dupSnapshot = $this->snapshotService->build($txn1->id);
        $duplicateAttemptBlocked = false;
        try {
            $this->submissionService->store([
                'pos_transaction_id' => $txn1->id,
                'return_option' => PosReturn::OPTION_CASH_RETURN,
                'source_snapshot' => $dupSnapshot,
                'source_snapshot_hash' => $dupSnapshot['hash'],
                'lines' => [
                    [
                        'sale_detail_id' => $parentDetail1->id,
                        'quantity' => 1,
                        'resolution' => PosReturn::OPTION_CASH_RETURN,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            $duplicateAttemptBlocked = str_contains($e->getMessage(), 'Serial yang diretur sudah diklaim')
                || str_contains($e->getMessage(), 'Kuantitas retur melebihi batas');
        }
        $this->assertTrue($duplicateAttemptBlocked, 'Duplicate bundle component claim on same dispatch must be blocked.');

        // Execute full approval & receiving lifecycle for first bundle return
        $plan = $this->plannerService->plan($firstReturn->fresh());
        $this->assertEmpty($plan['blockers'] ?? []);
        $this->lifecycleService->executeApprovalFromPreview($firstReturn->id, PosReturn::OPTION_CASH_RETURN, $plan);

        $firstReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $firstReturn->status);

        // Assert intermediate state: component serial restored to ACTIVE, undispatched, location intact, history logged
        $compSerial->refresh();
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $compSerial->status);
        $this->assertNull($compSerial->dispatch_detail_id);
        $this->assertEquals($this->location->id, $compSerial->location_id);
        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $compSerial->id,
            'event_type' => SerialNumberHistory::EVENT_SALE_RETURNED,
        ]);

        // Resell the same component in a second bundle sale
        [$txn2, $sale2, $parentDetail2, $compDispatchDetail2] = $this->createBundleSerializedComponentSaleFixture(
            $parent,
            $comp,
            $bundle,
            $bundleItem,
            $compSerial
        );
        $this->assertNotEquals($compDispatchDetail1->id, $compDispatchDetail2->id);

        $compSerial->refresh();
        $this->assertEquals(ProductSerialNumber::STATUS_SOLD, $compSerial->status);
        $this->assertEquals($compDispatchDetail2->id, $compSerial->dispatch_detail_id);

        // Second bundle return for the resale
        $snapshot2 = $this->snapshotService->build($txn2->id);
        $secondReturn = $this->submissionService->store([
            'pos_transaction_id' => $txn2->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot2,
            'source_snapshot_hash' => $snapshot2['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail2->id,
                    'quantity' => 1,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);

        $secondReturn = $this->submissionService->submitDraftForApproval($secondReturn->fresh());
        $this->assertNotNull($secondReturn);
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $secondReturn->status);

        $synthesizedCompLine = $secondReturn->lines->firstWhere('returned_serial_id', $compSerial->id);
        $this->assertNotNull($synthesizedCompLine);
        $this->assertEquals($compDispatchDetail2->id, $synthesizedCompLine->dispatch_detail_id);

        // Execute full approval & receiving lifecycle for second bundle return — must SUCCEED because it's a new dispatch
        $plan2 = $this->plannerService->plan($secondReturn->fresh());
        $this->assertEmpty($plan2['blockers'] ?? []);
        $this->lifecycleService->executeApprovalFromPreview($secondReturn->id, PosReturn::OPTION_CASH_RETURN, $plan2);

        $secondReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $secondReturn->status);
    }

    /** @test */
    public function it_rejects_serialized_return_when_source_dispatch_lineage_is_missing()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Gadget',
            'product_code' => 'GDG-001',
            'serial_number_required' => true,
            'stock_qty' => 1,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-GDG-3001');

        $this->actingAsInSetting($this->user, $this->setting);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-NODISP-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 200000,
            'receipt_number' => 'RCP-NODISP-' . uniqid(),
            'idempotency_key' => 'IDEM-NODISP-' . uniqid(),
            'payload_hash' => 'HASH-NODISP-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 200000,
            'paid_amount' => 200000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-NODISP-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 200000,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 200000,
            'unit_price' => 200000,
            'sub_total' => 200000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serial->id],
        ]);

        // Intentionally DO NOT create a DispatchDetail for this sale/product so lineage is unresolved
        $snapshot = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Lineage pengiriman sumber untuk serial tidak valid.');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serial->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_rejects_return_when_submitting_unmatched_serial_for_sale_with_different_dispatched_serial()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Tablet',
            'product_code' => 'TAB-001',
            'serial_number_required' => true,
            'stock_qty' => 2,
        ]);
        $serialA = $this->createSerialNumber($product, $this->location, 'SN-TAB-A');
        $serialB = $this->createSerialNumber($product, $this->location, 'SN-TAB-B');

        // Sale dispatched with Serial A
        [$transaction, $sale, $saleDetail, $dispatchDetail] = $this->createSerializedSaleFixture($product, $serialA);

        $snapshot = $this->snapshotService->build($transaction->id);

        // Attempt return specifying Serial B (different serial of the same product, not part of this sale's dispatch)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Lineage pengiriman sumber untuk serial tidak valid.');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialB->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_rejects_return_when_submitting_unmatched_serial_with_forged_synthesized_component_metadata()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Device',
            'product_code' => 'DEV-001',
            'serial_number_required' => true,
            'stock_qty' => 2,
        ]);
        $serialA = $this->createSerialNumber($product, $this->location, 'SN-DEV-A');
        $serialB = $this->createSerialNumber($product, $this->location, 'SN-DEV-B');

        // Sale dispatched with Serial A
        [$transaction, $sale, $saleDetail, $dispatchDetail] = $this->createSerializedSaleFixture($product, $serialA);

        $snapshot = $this->snapshotService->build($transaction->id);

        // Attempt return specifying Serial B along with forged __synthesized_component and __component_dispatch_detail_id
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Lineage pengiriman sumber untuk serial tidak valid.');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialB->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                    '__synthesized_component' => true,
                    '__component_dispatch_detail_id' => $dispatchDetail->id,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_does_not_confuse_numeric_serial_string_with_different_serial_database_id()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Watch',
            'product_code' => 'WCH-001',
            'serial_number_required' => true,
            'stock_qty' => 2,
        ]);

        // Create an unrelated serial whose ID will match a string serial number of the sale serial
        $unrelatedSerial = $this->createSerialNumber($product, $this->location, 'SN-UNRELATED-999');
        $targetIdString = (string) $unrelatedSerial->id;

        // Create sale serial whose physical serial_number string equals $targetIdString
        $saleSerial = $this->createSerialNumber($product, $this->location, $targetIdString);

        // Sale dispatched with $saleSerial (dispatch_detail.serial_numbers contains [$targetIdString])
        [$transaction, $sale, $saleDetail, $dispatchDetail] = $this->createSerializedSaleFixture($product, $saleSerial);

        $snapshot = $this->snapshotService->build($transaction->id);

        // Submitting $unrelatedSerial (whose database ID is $targetIdString, but physical serial is 'SN-UNRELATED-999')
        // must NOT be accepted as fulfilled by $dispatchDetail
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Lineage pengiriman sumber untuk serial tidak valid.');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $unrelatedSerial->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_rejects_approval_synchronize_when_competing_active_return_has_same_serial_and_same_dispatch()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Phone',
            'product_code' => 'PHN-001',
            'serial_number_required' => true,
            'stock_qty' => 2,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-PHN-5001');

        [$transaction, $sale, $saleDetail, $dispatchDetail] = $this->createSerializedSaleFixture($product, $serial);

        $snapshot = $this->snapshotService->build($transaction->id);

        // First return draft + submit
        $return1 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serial->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
        $return1 = $this->submissionService->submitDraftForApproval($return1->fresh());

        // Synchronize approval plan for return1 (creates linked SaleReturn)
        $persistenceService = app(\Modules\Pos\Services\PosReturnApprovalPlanPersistenceService::class);
        $plan1 = $this->plannerService->plan($return1->fresh());
        $persistenceService->synchronize($return1->fresh(), $plan1);

        // Second return draft created directly for the same transaction/dispatch
        $return2 = PosReturn::create([
            'reference' => 'POSRT-COMPETE-' . uniqid(),
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $sale->reference,
            'receipt_number' => $sale->reference,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'total_amount' => 500000,
            'created_by' => $this->user->id,
        ]);
        PosReturnLine::create([
            'pos_return_id' => $return2->id,
            'pos_checkout_sale_id' => PosCheckoutSale::where('sale_id', $sale->id)->value('id'),
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 500000,
            'line_total' => 500000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'returned_serial_id' => $serial->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'expected_cash_amount' => 500000,
        ]);

        $plan2 = $this->plannerService->plan($return2->fresh());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Satu atau lebih serial pada rencana ini sudah diklaim oleh retur lain');

        $persistenceService->synchronize($return2->fresh(), $plan2);
    }

    /** @test */
    public function it_reproduces_and_allows_return_86_pattern_across_different_dispatches()
    {
        // Pattern: Serial 1010 sold in Dispatch 1 (Return 84 completed), then resold in Dispatch 2 (Return 86)
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'High-end Camera',
            'product_code' => 'CAM-86',
            'serial_number_required' => true,
            'stock_qty' => 1,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-1010');

        // First occurrence (Return 84 / dispatch 421148 equivalent)
        [$txn1, $sale1, $saleDetail1, $dispatch1] = $this->createSerializedSaleFixture($product, $serial, 1200000);
        $snapshot1 = $this->snapshotService->build($txn1->id);

        $return84 = $this->submissionService->store([
            'pos_transaction_id' => $txn1->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot1,
            'source_snapshot_hash' => $snapshot1['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail1->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serial->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
        $return84 = $this->submissionService->submitDraftForApproval($return84->fresh());
        $plan84 = $this->plannerService->plan($return84->fresh());
        $this->lifecycleService->executeApprovalFromPreview($return84->id, PosReturn::OPTION_CASH_RETURN, $plan84);

        $return84->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $return84->status);

        // Second occurrence (Resale / dispatch 421282 equivalent)
        [$txn2, $sale2, $saleDetail2, $dispatch2] = $this->createSerializedSaleFixture($product, $serial, 1200000);
        $this->assertNotEquals($dispatch1->id, $dispatch2->id);

        $snapshot2 = $this->snapshotService->build($txn2->id);
        $return86 = $this->submissionService->store([
            'pos_transaction_id' => $txn2->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot2,
            'source_snapshot_hash' => $snapshot2['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail2->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serial->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
        $return86 = $this->submissionService->submitDraftForApproval($return86->fresh());
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $return86->status);

        // Approval plan execution for Return 86 MUST SUCCEED without throwing claim exception
        $plan86 = $this->plannerService->plan($return86->fresh());
        $this->assertEmpty($plan86['blockers'] ?? []);

        $saleReturnsBefore = \Modules\SalesReturn\Entities\SaleReturn::count();
        $this->lifecycleService->executeApprovalFromPreview($return86->id, PosReturn::OPTION_CASH_RETURN, $plan86);

        $return86->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $return86->status);
        $this->assertEquals($saleReturnsBefore + 1, \Modules\SalesReturn\Entities\SaleReturn::count());
    }

    /** @test */
    public function it_rejects_approval_and_rolls_back_without_creating_sales_returns_when_lineage_is_missing()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Headset',
            'product_code' => 'HDST-001',
            'serial_number_required' => true,
            'stock_qty' => 1,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-HDST-7001');

        [$transaction, $sale, $saleDetail, $dispatchDetail] = $this->createSerializedSaleFixture($product, $serial);

        $posReturn = PosReturn::create([
            'reference' => 'POSRT-NOLINEAGE-' . uniqid(),
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $sale->reference,
            'receipt_number' => $sale->reference,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'total_amount' => 500000,
            'created_by' => $this->user->id,
        ]);

        // PosReturnLine with missing dispatch_detail_id
        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => PosCheckoutSale::where('sale_id', $sale->id)->value('id'),
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 500000,
            'line_total' => 500000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'returned_serial_id' => $serial->id,
            'dispatch_detail_id' => null,
            'expected_cash_amount' => 500000,
        ]);

        $persistenceService = app(\Modules\Pos\Services\PosReturnApprovalPlanPersistenceService::class);
        $plan = $this->plannerService->plan($posReturn->fresh());
        // Simulate plan where dispatch_detail_id is unassigned / empty in planned details
        $plan['blockers'] = [];
        foreach ($plan['groups'] as &$group) {
            foreach ($group['planned_details'] as &$detail) {
                $detail['dispatch_detail_id'] = null;
            }
        }

        $saleReturnsBefore = \Modules\SalesReturn\Entities\SaleReturn::count();

        $thrown = false;
        try {
            $persistenceService->synchronize($posReturn->fresh(), $plan);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('Lineage pengiriman sumber untuk serial tidak valid.', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Missing dispatch lineage must throw RuntimeException.');
        $this->assertSame($saleReturnsBefore, \Modules\SalesReturn\Entities\SaleReturn::count(), 'No SaleReturn rows must be created on lineage failure.');
        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->status);
    }

    /** @test */
    public function it_resolves_a_to_b_to_a_and_subsequent_return_of_a_without_iteration_cap_or_false_cycle()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Tablet',
            'product_code' => 'TAB-001',
            'serial_number_required' => true,
            'stock_qty' => 5,
        ]);
        $serialA = $this->createSerialNumber($product, $this->location, 'SN-TAB-A');
        $serialB = $this->createSerialNumber($product, $this->location, 'SN-TAB-B');
        $serialC = $this->createSerialNumber($product, $this->location, 'SN-TAB-C');

        // Original sale with serial A
        [$transaction, $sale, $saleDetail, $dispatchDetail] = $this->createSerializedSaleFixture($product, $serialA);
        $checkout = PosCheckout::where('pos_transaction_id', $transaction->id)->first();

        // 1. First return: Replace A with B
        $snap1 = $this->snapshotService->build($transaction->id);
        $ret1 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snap1,
            'source_snapshot_hash' => $snap1['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialA->id,
                    'replacement_serial_id' => $serialB->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                ],
            ],
        ]);
        $ret1 = $this->submissionService->submitDraftForApproval($ret1->fresh());
        $plan1 = $this->plannerService->plan($ret1->fresh());
        $this->assertEmpty($plan1['blockers'] ?? []);
        $this->lifecycleService->executeApprovalFromPreview($ret1->id, PosReturn::OPTION_PRODUCT_REPLACEMENT, $plan1);

        $ret1->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $ret1->status);

        // Customer now holds B. Returned A is back in stock as ACTIVE.
        $serialA->refresh();
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $serialA->status);
        $this->assertNull($serialA->dispatch_detail_id);

        $resolver = app(\Modules\Pos\Services\PosReturnSerialReplacementChainResolver::class);
        $this->assertEquals($serialB->id, $resolver->resolveCurrentHeldSerial($serialA->id, $checkout->id, $transaction->id));

        // 2. Second return: Replace B with A (reusing serial A)
        $snap2 = $this->snapshotService->build($transaction->id);
        $this->assertEquals($serialB->id, $snap2['lines'][0]['serial_numbers'][0]['id']);

        $ret2 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snap2,
            'source_snapshot_hash' => $snap2['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialB->id,
                    'replacement_serial_id' => $serialA->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                ],
            ],
        ]);
        $ret2 = $this->submissionService->submitDraftForApproval($ret2->fresh());
        $plan2 = $this->plannerService->plan($ret2->fresh());
        $this->assertEmpty($plan2['blockers'] ?? []);
        $this->lifecycleService->executeApprovalFromPreview($ret2->id, PosReturn::OPTION_PRODUCT_REPLACEMENT, $plan2);

        $ret2->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $ret2->status);

        // Customer now holds A again!
        $this->assertEquals($serialA->id, $resolver->resolveCurrentHeldSerial($serialA->id, $checkout->id, $transaction->id));

        // 3. Third return: Cash return of the newly held occurrence of A
        $snap3 = $this->snapshotService->build($transaction->id);
        $this->assertEquals($serialA->id, $snap3['lines'][0]['serial_numbers'][0]['id']);
        $this->assertEquals(1.0, $snap3['lines'][0]['returnable_quantity']);

        $ret3 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snap3,
            'source_snapshot_hash' => $snap3['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialA->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $ret3 = $this->submissionService->submitDraftForApproval($ret3->fresh());
        $plan3 = $this->plannerService->plan($ret3->fresh());
        $this->assertEmpty($plan3['blockers'] ?? []);
        $this->lifecycleService->executeApprovalFromPreview($ret3->id, PosReturn::OPTION_CASH_RETURN, $plan3);

        $ret3->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $ret3->status);

        // Cash return completed: remaining entitlement is 0
        $snap4 = $this->snapshotService->build($transaction->id);
        $this->assertEquals(0.0, $snap4['lines'][0]['returnable_quantity']);
    }

    /** @test */
    public function it_resolves_chain_beyond_50_hops_without_iteration_cap()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Hop Test Product',
            'product_code' => 'HOP-001',
            'serial_number_required' => true,
            'stock_qty' => 60,
        ]);

        $serials = [];
        for ($i = 0; $i <= 55; $i++) {
            $serials[$i] = $this->createSerialNumber($product, $this->location, "SN-HOP-{$i}");
        }

        [$transaction, $sale, $saleDetail, $dispatchDetail] = $this->createSerializedSaleFixture($product, $serials[0]);
        $checkout = PosCheckout::where('pos_transaction_id', $transaction->id)->first();

        $checkoutSaleId = PosCheckoutSale::where('sale_id', $sale->id)->value('id');

        // Create 55 completed replacement hops in pos_return_lines
        for ($i = 0; $i < 55; $i++) {
            $posReturn = PosReturn::create([
                'reference' => 'RT-HOP-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'setting_id' => $this->setting->id,
                'pos_transaction_id' => $transaction->id,
                'pos_checkout_id' => $checkout->id,
                'transaction_code' => $transaction->code,
                'receipt_number' => $checkout->receipt_number,
                'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
                'status' => PosReturn::STATUS_COMPLETED,
                'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
                'source_snapshot' => ['lines' => []],
                'source_snapshot_hash' => 'hash-' . $i,
                'approved_at' => now()->addMinutes($i),
                'settled_at' => now()->addMinutes($i),
                'created_by' => $this->user->id,
                'total_amount' => 0,
            ]);

            PosReturnLine::create([
                'pos_return_id' => $posReturn->id,
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
                'quantity' => 1,
                'unit_price' => 500000,
                'line_total' => 500000,
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'returned_serial_id' => $serials[$i]->id,
                'replacement_serial_id' => $serials[$i + 1]->id,
            ]);
        }

        $resolver = app(\Modules\Pos\Services\PosReturnSerialReplacementChainResolver::class);
        $leaf = $resolver->resolveCurrentHeldSerial($serials[0]->id, $checkout->id, $transaction->id);

        $this->assertEquals($serials[55]->id, $leaf);
    }

    /** @test */
    public function it_safely_fails_on_repeated_events_or_ambiguous_lineage()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Self Replace Product',
            'product_code' => 'SELF-001',
            'serial_number_required' => true,
            'stock_qty' => 5,
        ]);
        $serialA = $this->createSerialNumber($product, $this->location, 'SN-SELF-A');

        [$transaction, $sale, $saleDetail, $dispatchDetail] = $this->createSerializedSaleFixture($product, $serialA);
        $checkout = PosCheckout::where('pos_transaction_id', $transaction->id)->first();
        $checkoutSaleId = PosCheckoutSale::where('sale_id', $sale->id)->value('id');

        $posReturn = PosReturn::create([
            'reference' => 'RT-SELF-1',
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => ['lines' => []],
            'source_snapshot_hash' => 'hash-self',
            'approved_at' => now(),
            'created_by' => $this->user->id,
            'total_amount' => 0,
        ]);

        // Self-replacement A -> A
        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
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
            'quantity' => 1,
            'unit_price' => 500000,
            'line_total' => 500000,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'returned_serial_id' => $serialA->id,
            'replacement_serial_id' => $serialA->id,
        ]);

        $resolver = app(\Modules\Pos\Services\PosReturnSerialReplacementChainResolver::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ambiguous_lineage: Serial pengganti tidak boleh sama');
        $resolver->resolveCurrentHeldSerial($serialA->id, $checkout->id, $transaction->id);
    }

    /** @test */
    public function it_projects_receipt_with_current_serial_a_after_a_to_b_to_a_and_full_ordered_history()
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Projection Phone',
            'product_code' => 'PROJ-PHONE',
            'serial_number_required' => true,
            'stock_qty' => 5,
        ]);
        $serialA = $this->createSerialNumber($product, $this->location, 'SN-PROJ-A');
        $serialB = $this->createSerialNumber($product, $this->location, 'SN-PROJ-B');

        [$transaction, $sale, $saleDetail, $dispatchDetail] = $this->createSerializedSaleFixture($product, $serialA);
        $checkout = PosCheckout::where('pos_transaction_id', $transaction->id)->first();

        // 1. Return A -> B
        $snap1 = $this->snapshotService->build($transaction->id);
        $ret1 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snap1,
            'source_snapshot_hash' => $snap1['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialA->id,
                    'replacement_serial_id' => $serialB->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                ],
            ],
        ]);
        $ret1 = $this->submissionService->submitDraftForApproval($ret1->fresh());
        $plan1 = $this->plannerService->plan($ret1->fresh());
        $this->lifecycleService->executeApprovalFromPreview($ret1->id, PosReturn::OPTION_PRODUCT_REPLACEMENT, $plan1);

        // 2. Return B -> A
        $snap2 = $this->snapshotService->build($transaction->id);
        $ret2 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snap2,
            'source_snapshot_hash' => $snap2['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialB->id,
                    'replacement_serial_id' => $serialA->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                ],
            ],
        ]);
        $ret2 = $this->submissionService->submitDraftForApproval($ret2->fresh());
        $plan2 = $this->plannerService->plan($ret2->fresh());
        $this->lifecycleService->executeApprovalFromPreview($ret2->id, PosReturn::OPTION_PRODUCT_REPLACEMENT, $plan2);

        // Project receipt
        $receiptService = app(\Modules\Pos\Services\PosReceiptReprintProjectionService::class);
        $projected = $receiptService->getCompletedReprintReceiptData($checkout);

        $this->assertTrue($projected['is_reprint_projected']);
        // Projected line effective serial must be SN-PROJ-A
        $this->assertEquals(['SN-PROJ-A'], $projected['lines'][0]['assigned_serials']);

        // Return history contains 2 entries in order
        $this->assertCount(2, $projected['return_history']);
        $this->assertEquals('Ganti Barang', $projected['return_history'][0]['items'][0]['resolution']);
        $this->assertEquals('Ganti Barang', $projected['return_history'][1]['items'][0]['resolution']);
    }

    /** @test */
    public function it_blocks_returning_an_already_replaced_serial_when_customer_holds_replacement(): void
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Widget Serial Already Replaced',
            'product_code' => 'WIDGET-AR-' . uniqid(),
            'serial_number_required' => true,
            'stock_qty' => 5,
        ]);
        $serialA = $this->createSerialNumber($product, $this->location, 'SN-AR-A-' . uniqid());
        $serialB = $this->createSerialNumber($product, $this->location, 'SN-AR-B-' . uniqid());
        $serialC = $this->createSerialNumber($product, $this->location, 'SN-AR-C-' . uniqid());

        [$transaction, $sale, $saleDetail, $dispatchDetail] = $this->createSerializedSaleFixture($product, $serialA);

        // 1. First return: A -> B completed
        $snap1 = $this->snapshotService->build($transaction->id);
        $ret1 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snap1,
            'source_snapshot_hash' => $snap1['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialA->id,
                    'replacement_serial_id' => $serialB->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                ],
            ],
        ]);
        $ret1 = $this->submissionService->submitDraftForApproval($ret1->fresh());
        $plan1 = $this->plannerService->plan($ret1->fresh());
        $this->lifecycleService->executeApprovalFromPreview($ret1->id, PosReturn::OPTION_PRODUCT_REPLACEMENT, $plan1);

        $ret1->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $ret1->status);

        // Customer now holds serial B. Attempting to return serial A again (A -> C) must be blocked
        $snap2 = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Serial yang diretur sudah diganti oleh serial lain dan tidak lagi dipegang oleh pelanggan.');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snap2,
            'source_snapshot_hash' => $snap2['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialA->id,
                    'replacement_serial_id' => $serialC->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_prevents_replacement_traversal_from_following_another_unit_earlier_history(): void
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Two Unit History Product',
            'product_code' => 'TWO-HIST-' . uniqid(),
            'serial_number_required' => true,
            'stock_qty' => 10,
        ]);

        $serialA = $this->createSerialNumber($product, $this->location, 'SN-UNIT-A-' . uniqid());
        $serialB = $this->createSerialNumber($product, $this->location, 'SN-UNIT-B-' . uniqid());
        $serialC = $this->createSerialNumber($product, $this->location, 'SN-UNIT-C-' . uniqid());

        $this->actingAsInSetting($this->user, $this->setting);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-2U-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1000000,
            'receipt_number' => 'RCP-2U-' . uniqid(),
            'idempotency_key' => 'IDEM-2U-' . uniqid(),
            'payload_hash' => 'HASH-2U-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 1000000,
            'paid_amount' => 1000000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-2U-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000000,
            'split_key' => 'SPLIT-2U-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 500000,
            'unit_price' => 500000,
            'sub_total' => 1000000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serialA->id, $serialC->id],
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode([$serialA->serial_number, $serialC->serial_number]),
        ]);
        $serialA->update([
            'dispatch_detail_id' => $dispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);
        $serialC->update([
            'dispatch_detail_id' => $dispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'qty' => 2,
            'unit_price' => 500000,
            'line_no' => 1,
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => $serialA->serial_number,
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => $serialC->serial_number,
        ]);

        // Event 1: Unit 1 returned: A -> B at t1
        $snap1 = $this->snapshotService->build($transaction->id);
        $ret1 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snap1,
            'source_snapshot_hash' => $snap1['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialA->id,
                    'replacement_serial_id' => $serialB->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                ],
            ],
        ]);
        $ret1 = $this->submissionService->submitDraftForApproval($ret1->fresh());
        $plan1 = $this->plannerService->plan($ret1->fresh());
        $this->lifecycleService->executeApprovalFromPreview($ret1->id, PosReturn::OPTION_PRODUCT_REPLACEMENT, $plan1);

        // Event 2: Unit 2 returned: C -> A at t2 (serial A is back in stock and given to Unit 2)
        $snap2 = $this->snapshotService->build($transaction->id);
        $ret2 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snap2,
            'source_snapshot_hash' => $snap2['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialC->id,
                    'replacement_serial_id' => $serialA->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                ],
            ],
        ]);
        $ret2 = $this->submissionService->submitDraftForApproval($ret2->fresh());
        $plan2 = $this->plannerService->plan($ret2->fresh());
        $this->lifecycleService->executeApprovalFromPreview($ret2->id, PosReturn::OPTION_PRODUCT_REPLACEMENT, $plan2);

        // Test resolver:
        $resolver = app(\Modules\Pos\Services\PosReturnSerialReplacementChainResolver::class);

        // Resolving Unit 2 (started as C, replaced with A) must report A, NOT follow earlier A -> B and report B
        $resolvedUnit2 = $resolver->resolveCurrentHeldSerial(
            $serialC->id,
            $checkout->id,
            $transaction->id,
            $dispatchDetail->id
        );
        $this->assertSame($serialA->id, $resolvedUnit2);

        // Resolving Unit 1 (started as A, replaced with B) must report B
        $resolvedUnit1 = $resolver->resolveCurrentHeldSerial(
            $serialA->id,
            $checkout->id,
            $transaction->id,
            $dispatchDetail->id
        );
        $this->assertSame($serialB->id, $resolvedUnit1);

        // Even without dispatchDetailId, causal ordering from C -> A cannot follow earlier A -> B
        $resolvedUnit2WithoutDispatch = $resolver->resolveCurrentHeldSerial(
            $serialC->id,
            $checkout->id,
            $transaction->id
        );
        $this->assertSame($serialA->id, $resolvedUnit2WithoutDispatch);
    }

    /** @test */
    public function it_resolves_numeric_serial_strings_without_matching_unrelated_database_ids(): void
    {
        $productA = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Target Product A',
            'product_code' => 'TARGET-A-' . uniqid(),
            'serial_number_required' => true,
            'stock_qty' => 5,
        ]);

        $productB = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Unrelated Product B',
            'product_code' => 'UNRELATED-B-' . uniqid(),
            'serial_number_required' => true,
            'stock_qty' => 5,
        ]);

        // Create an unrelated serial for Product B. Its database ID will be an integer e.g. 123.
        $unrelatedSerialB = $this->createSerialNumber($productB, $this->location, 'SN-B-UNRELATED-' . uniqid());

        // Now create a serial for Product A whose serial_number is the string representation of $unrelatedSerialB->id
        $numericSerialString = (string) $unrelatedSerialB->id;
        $targetSerialA = $this->createSerialNumber($productA, $this->location, $numericSerialString);

        // Dispatched serial numbers contains ["123"]
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-NUM-' . uniqid(),
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $productA->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode([$numericSerialString]),
        ]);

        $resolvedIds = $this->submissionService->resolveOriginalSerialIdsForDispatch($dispatchDetail->id);

        // Must resolve Product A's serial ID, NOT Product B's unrelated serial ID that happened to have that ID
        $this->assertContains($targetSerialA->id, $resolvedIds);
        $this->assertNotContains($unrelatedSerialB->id, $resolvedIds);
        $this->assertCount(1, $resolvedIds);
    }
}

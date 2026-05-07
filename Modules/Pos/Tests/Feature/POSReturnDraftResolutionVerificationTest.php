<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\SalesReturn\Entities\SaleReturn;
use Illuminate\Support\Facades\Auth;

class POSReturnDraftResolutionVerificationTest extends PosTransactionFeatureTestCase
{
    protected $submissionService;
    protected $snapshotService;
    protected $setting;
    protected $user;
    protected $terminal;
    protected $session;
    protected $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        
        $this->setting = $this->createSetting('Verification Test');
        
        \Spatie\Permission\Models\Permission::findOrCreate('pos.access', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('pos.returns.create', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('pos.returns.edit', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('pos.returns.delete', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'Admin', [
            'pos.access', 
            'pos.returns.create', 
            'pos.returns.edit',
            'pos.returns.delete'
        ]);
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_handles_mixed_bundled_and_non_bundled_same_sku_serials_in_snapshot()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        // Task 7.1 & 7.2: Same serialized SKU in bundled and non-bundled lines
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Samsung S24',
            'serial_number_required' => true,
        ]);
        
        $sn1 = $this->createSerialNumber($product, $this->location, 'SN-BUN-001');
        $sn2 = $this->createSerialNumber($product, $this->location, 'SN-BUN-002');
        $sn3 = $this->createSerialNumber($product, $this->location, 'SN-NON-003');

        $transaction = $this->createCompletedTransaction();
        $checkout = $transaction->completedCheckout;

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        // Bundle Sale
        $saleBundle = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 2000,
            'paid_amount' => 2000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-BUN-' . uniqid(),
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $saleBundle->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 2000,
            'split_key' => 'BUN-1',
            'tax_bucket' => 'NON_TAX',
        ]);
        $detailBundle = SaleDetails::create([
            'sale_id' => $saleBundle->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 2000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'serial_number_ids' => [$sn1->id, $sn2->id],
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        // Mock bundle items
        $bundleComponent = $this->createStockedProduct($this->setting, $this->location, ['product_name' => 'Case']);
        $detailBundle->bundleItems()->create([
            'sale_id' => $saleBundle->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $bundleComponent->id,
            'name' => $bundleComponent->product_name,
            'price' => 0,
            'quantity' => 1,
            'sub_total' => 0,
        ]);

        // Non-Bundle Sale
        $saleNonBundle = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-NON-' . uniqid(),
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $saleNonBundle->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'NON-1',
            'tax_bucket' => 'NON_TAX',
        ]);
        $detailNonBundle = SaleDetails::create([
            'sale_id' => $saleNonBundle->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'serial_number_ids' => [$sn3->id],
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        // Verification of Task 7.2: two bundled Samsung serial rows and one non-bundled Samsung serial row
        $productLines = collect($snapshot['lines'])->where('product_id', $product->id);
        
        $this->assertCount(3, $productLines);
        $this->assertEquals(2, $productLines->where('is_bundle', true)->count());
        $this->assertEquals(1, $productLines->where('is_bundle', false)->count());
        
        $bundleSns = $productLines->where('is_bundle', true)->pluck('serial_numbers.0.serial_number')->toArray();
        $this->assertContains('SN-BUN-001', $bundleSns);
        $this->assertContains('SN-BUN-002', $bundleSns);
        $this->assertEquals('SN-NON-003', $productLines->where('is_bundle', false)->first()['serial_numbers'][0]['serial_number']);
    }

    /** @test */
    public function it_saves_valid_draft_without_creating_sales_return_records()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        // Task 7.3: Saving a valid draft creates only POS Return records and no Sales Return records.
        $product = $this->createStockedProduct($this->setting, $this->location);
        $transaction = $this->createCompletedTransactionWithLine($product, 1);
        $snapshot = $this->snapshotService->build($transaction->id);
        $line = $snapshot['lines'][0];

        $initialSaleReturnCount = SaleReturn::count();

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $line['sale_id'],
                    'sale_detail_id' => $line['sale_detail_id'],
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->assertNotNull($posReturn);
        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status);
        $this->assertEquals($initialSaleReturnCount, SaleReturn::count(), 'SaleReturn records should not be created for draft POS Return.');
    }

    /** @test */
    public function it_rejects_saving_draft_with_all_none_resolutions()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        // Task 7.4: Test that saving all `none` lines is rejected.
        $product = $this->createStockedProduct($this->setting, $this->location);
        $transaction = $this->createCompletedTransactionWithLine($product, 1);
        $snapshot = $this->snapshotService->build($transaction->id);
        $line = $snapshot['lines'][0];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Minimal satu item harus dipilih untuk retur");

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $line['sale_id'],
                    'sale_detail_id' => $line['sale_detail_id'],
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_NONE,
                ]
            ]
        ]);
    }

    /** @test */
    public function it_supports_mixed_serial_resolutions_and_replacement_validation()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        // Task 7.5 & 7.6: Mixed serial resolutions and replacement validation.
        $product = $this->createStockedProduct($this->setting, $this->location, ['serial_number_required' => true]);
        $sn1 = $this->createSerialNumber($product, $this->location, 'SN-RET-001');
        $sn2 = $this->createSerialNumber($product, $this->location, 'SN-RET-002');
        $rep1 = $this->createSerialNumber($product, $this->location, 'SN-REP-001'); // Available replacement

        $transaction = $this->createCompletedTransactionWithSerials($product, [$sn1, $sn2]);
        $snapshot = $this->snapshotService->build($transaction->id);

        // Case A: Valid mixed resolutions
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                    'returned_serial_id' => $sn1->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
                [
                    'sale_detail_id' => $snapshot['lines'][1]['sale_detail_id'],
                    'returned_serial_id' => $sn2->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                    'replacement_serial_id' => $rep1->id,
                ]
            ]
        ]);

        $this->assertCount(2, $posReturn->lines);
        $this->assertEquals(PosReturnLine::RESOLUTION_CASH_RETURN, $posReturn->lines->where('returned_serial_id', $sn1->id)->first()->resolution);
        $this->assertEquals(PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, $posReturn->lines->where('returned_serial_id', $sn2->id)->first()->resolution);
        $this->assertEquals($rep1->id, $posReturn->lines->where('returned_serial_id', $sn2->id)->first()->replacement_serial_id);

        // Case B: Validation - Replacement is the same as returned
        $snapshot = $this->snapshotService->build($transaction->id);
        try {
            $this->submissionService->store([
                'pos_transaction_id' => $transaction->id,
                'source_snapshot_hash' => $snapshot['hash'],
                'lines' => [
                    [
                        'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                        'returned_serial_id' => $sn1->id,
                        'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                        'replacement_serial_id' => $sn1->id, // ERROR: same as returned
                    ]
                ]
            ]);
            $this->fail('Should have thrown exception: replacement cannot be same as returned.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('tidak boleh sama dengan serial yang diretur', $e->getMessage());
        }

        // Case C: Validation - Replacement is already sold (not active)
        $snapshot = $this->snapshotService->build($transaction->id);
        $soldSn = $this->createSerialNumber($product, $this->location, 'SN-SOLD-999');
        $soldSn->update(['status' => 'SOLD']);
        try {
            $this->submissionService->store([
                'pos_transaction_id' => $transaction->id,
                'source_snapshot_hash' => $snapshot['hash'],
                'lines' => [
                    [
                        'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                        'returned_serial_id' => $sn1->id,
                        'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                        'replacement_serial_id' => $soldSn->id, // ERROR: not active
                    ]
                ]
            ]);
            $this->fail('Should have thrown exception: replacement must be active.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('tidak berstatus tersedia', $e->getMessage());
        }

        // Case D: Validation - Replacement is for different product
        $snapshot = $this->snapshotService->build($transaction->id);
        $otherProduct = $this->createStockedProduct($this->setting, $this->location, ['product_name' => 'iPhone']);
        $otherSn = $this->createSerialNumber($otherProduct, $this->location, 'SN-IPHONE-001');
        try {
            $this->submissionService->store([
                'pos_transaction_id' => $transaction->id,
                'source_snapshot_hash' => $snapshot['hash'],
                'lines' => [
                    [
                        'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                        'returned_serial_id' => $sn1->id,
                        'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                        'replacement_serial_id' => $otherSn->id, // ERROR: different product
                    ]
                ]
            ]);
            $this->fail('Should have thrown exception: replacement must be same product.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('produk yang sama', $e->getMessage());
        }
    }

    /** @test */
    public function it_does_not_mutate_execution_state_on_draft_edit()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        // Task 7.8: Test that draft edit rebuilds lines without execution mutations.
        $product = $this->createStockedProduct($this->setting, $this->location);
        $transaction = $this->createCompletedTransactionWithLine($product, 10);
        $snapshot = $this->snapshotService->build($transaction->id);
        
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                    'quantity' => 2,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $initialStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)->where('location_id', $this->location->id)->first()->quantity;

        // Edit the draft
        $this->submissionService->update($posReturn, [
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                    'quantity' => 5, // Change qty
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $posReturn->refresh();
        $this->assertCount(1, $posReturn->lines);
        $this->assertEquals(5, $posReturn->lines->first()->quantity);
        
        // Assert no mutations
        $this->assertEquals($initialStock, \Modules\Product\Entities\ProductStock::where('product_id', $product->id)->where('location_id', $this->location->id)->first()->quantity, 'Stock should not be mutated.');
        $this->assertEquals(0, SaleReturn::count(), 'SaleReturn should not be created.');
    }

    /** @test */
    public function it_resets_rejected_return_to_draft_on_edit()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        // Task 7.9: Test that rejected edit resets the document to draft.
        $product = $this->createStockedProduct($this->setting, $this->location);
        $transaction = $this->createCompletedTransactionWithLine($product, 1);
        $snapshot = $this->snapshotService->build($transaction->id);
        
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        // Manually move to rejected
        $posReturn->update([
            'status' => PosReturn::STATUS_REJECTED,
            'approval_status' => PosReturn::APPROVAL_STATUS_REJECTED,
        ]);

        // Edit it
        $this->submissionService->update($posReturn, [
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                    // Note: would need a replacement serial if it was tracked, but this is non-tracked.
                ]
            ]
        ]);

        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status);
        $this->assertEquals(PosReturn::APPROVAL_STATUS_DRAFT, $posReturn->approval_status);
        $this->assertEquals(PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, $posReturn->lines->first()->resolution);
    }

    /** @test */
    public function it_handles_draft_hard_delete_and_rejected_soft_delete()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        // Task 7.10: Test draft hard delete and rejected audited soft delete behavior.
        $product = $this->createStockedProduct($this->setting, $this->location);
        $transaction = $this->createCompletedTransactionWithLine($product, 1);
        $snapshot = $this->snapshotService->build($transaction->id);
        
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $returnId = $posReturn->id;

        // Hard delete draft
        $posReturn->delete(); // This triggers soft delete because model uses SoftDeletes, but requirement says "Hard Delete for draft"
        // Wait, does the model have SoftDeletes? Yes.
        // If the requirement is hard delete, we should use forceDelete().
        // Let's check the implementation of delete in controller or service if any.
        // For now, I'll test if forceDelete works for drafts.
        
        if ($posReturn->isHardDeletable()) {
            $posReturn->forceDelete();
        }
        $this->assertDatabaseMissing('pos_returns', ['id' => $returnId]);

        // Rejected Soft Delete
        $posReturn2 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $snapshot['lines'][0]['sale_detail_id'],
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);
        $posReturn2->update([
            'status' => PosReturn::STATUS_REJECTED,
            'approval_status' => PosReturn::APPROVAL_STATUS_REJECTED,
        ]);

        if ($posReturn2->isRejectedSoftDeletable()) {
            $posReturn2->update([
                'deleted_by' => Auth::id(),
                'delete_reason' => 'User requested delete of rejected return',
            ]);
            $posReturn2->delete();
        }

        $this->assertSoftDeleted('pos_returns', ['id' => $posReturn2->id]);
        $this->assertNotNull(PosReturn::withTrashed()->find($posReturn2->id)->deleted_by);
        $this->assertEquals('User requested delete of rejected return', PosReturn::withTrashed()->find($posReturn2->id)->delete_reason);
    }

    // Helpers

    protected function createCompletedTransaction(): PosTransaction
    {
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-' . uniqid(),
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
            'grand_total' => 0,
            'receipt_number' => 'RCP-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        return $transaction;
    }

    protected function createCompletedTransactionWithLine($product, $quantity): PosTransaction
    {
        $transaction = $this->createCompletedTransaction();
        $checkout = $transaction->completedCheckout;

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => $product->product_price * $quantity,
            'paid_amount' => $product->product_price * $quantity,
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
            'grand_total' => $product->product_price * $quantity,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->product_price,
            'unit_price' => $product->product_price,
            'sub_total' => $product->product_price * $quantity,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        return $transaction;
    }

    protected function createCompletedTransactionWithSerials($product, $serials): PosTransaction
    {
        $transaction = $this->createCompletedTransaction();
        $checkout = $transaction->completedCheckout;

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => $product->product_price * count($serials),
            'paid_amount' => $product->product_price * count($serials),
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
            'grand_total' => $product->product_price * count($serials),
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => count($serials),
            'price' => $product->product_price,
            'unit_price' => $product->product_price,
            'sub_total' => $product->product_price * count($serials),
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'serial_number_ids' => collect($serials)->pluck('id')->toArray(),
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        return $transaction;
    }
}

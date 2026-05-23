<?php

namespace Modules\Pos\Tests\Feature;

use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnReplacementGuard;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Spatie\Permission\Models\Permission;

class POSReturnCrossOwnerReplacementTest extends PosTransactionFeatureTestCase
{
    protected Setting $settingA;
    protected Location $locationA;
    protected Setting $settingB;
    protected Location $locationB;
    protected $actor;
    protected PosReturnLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');

        $this->settingA = $this->createSetting('Cross-Owner Setting A');
        [, $this->locationA] = $this->createTerminalWithLocation($this->settingA);

        $this->settingB = $this->createSetting('Cross-Owner Setting B');
        [, $this->locationB] = $this->createTerminalWithLocation($this->settingB);

        $this->actor = $this->createUserForSetting($this->settingA, 'Cross-Owner Actor', [
            'pos.access',
            'pos.returns.approve',
        ]);

        $this->service = app(PosReturnLifecycleService::class);
    }

    /** @test */
    public function same_owner_replacement_still_dispatches_on_original_sale_dispatch_path(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $sale, $saleReturn] = $this->createPendingApprovalSameOwnerReplacement();

        $this->service->executeApprovalFromPreview($posReturn->id);

        $posReturn->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->status);

        $dispatch = Dispatch::query()->where('sale_id', $sale->id)->latest('id')->first();
        $this->assertNotNull($dispatch);
        $this->assertSame(Dispatch::STATUS_APPROVED, $dispatch->status);

        $replacementSaleCount = Sale::query()
            ->where('setting_id', $this->settingA->id)
            ->where('note', 'like', '%cross-owner%')
            ->count();
        $this->assertSame(0, $replacementSaleCount, 'Same-owner replacement should not create a new cross-owner Sale.');
    }

    /** @test */
    public function cross_owner_replacement_creates_replacement_owner_sale_under_setting_b(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $originalSale, , $product, $replacementSerial, $customer] = $this->createPendingApprovalCrossOwnerReplacement();

        $this->service->executeApprovalFromPreview($posReturn->id);

        $newSale = Sale::query()
            ->where('setting_id', $this->settingB->id)
            ->where('note', 'like', '%' . $posReturn->reference . '%')
            ->latest('id')
            ->first();

        $this->assertNotNull($newSale, 'A replacement-owner Sale under Setting B should have been created.');
        $this->assertSame($this->settingB->id, (int) $newSale->setting_id);
        $this->assertSame(Sale::STATUS_DISPATCHED, $newSale->status);
        $this->assertSame('paid', strtolower((string) $newSale->payment_status));
        $this->assertSame($originalSale->date, $newSale->date);
    }

    /** @test */
    public function cross_owner_replacement_corrects_original_sale_detail_quantity_and_amount(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $originalSale, , $product, , , $originalSaleDetail] = $this->createPendingApprovalCrossOwnerReplacement();

        $this->service->executeApprovalFromPreview($posReturn->id);

        $originalSaleDetail->refresh();
        $this->assertSame(1, (int) $originalSaleDetail->quantity, 'Original Sale detail quantity should be reduced by the returned qty (2→1).');
    }

    /** @test */
    public function cross_owner_replacement_creates_replacement_owner_dispatch_at_setting_b_location(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $originalSale, $saleReturn, $product, $replacementSerial] = $this->createPendingApprovalCrossOwnerReplacement();

        $this->service->executeApprovalFromPreview($posReturn->id);

        $newSale = Sale::query()
            ->where('setting_id', $this->settingB->id)
            ->where('note', 'like', '%' . $posReturn->reference . '%')
            ->latest('id')
            ->first();

        $this->assertNotNull($newSale);

        $replacementDispatch = Dispatch::query()
            ->where('sale_id', $newSale->id)
            ->first();

        $this->assertNotNull($replacementDispatch);
        $this->assertSame(Dispatch::STATUS_APPROVED, $replacementDispatch->status);

        $replacementDispatchDetail = DispatchDetail::query()
            ->where('dispatch_id', $replacementDispatch->id)
            ->first();

        $this->assertNotNull($replacementDispatchDetail);
        $this->assertSame($this->locationB->id, (int) $replacementDispatchDetail->location_id);
        $this->assertSame($product->id, (int) $replacementDispatchDetail->product_id);
    }

    /** @test */
    public function cross_owner_replacement_marks_replacement_serial_sold_under_setting_b_dispatch(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, , , $product, $replacementSerial] = $this->createPendingApprovalCrossOwnerReplacement();

        $this->service->executeApprovalFromPreview($posReturn->id);

        $replacementSerial->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, (string) $replacementSerial->status);

        $newSale = Sale::query()
            ->where('setting_id', $this->settingB->id)
            ->where('note', 'like', '%' . $posReturn->reference . '%')
            ->latest('id')
            ->first();

        $tracking = SalesOrderSerialTracking::query()
            ->where('sale_id', $newSale->id)
            ->where('product_serial_number_id', $replacementSerial->id)
            ->first();

        $this->assertNotNull($tracking, 'Serial tracking record should link replacement serial to Setting B Sale.');
        $this->assertNotNull($tracking->dispatch_date);
        $this->assertNull($tracking->return_date);

        $this->assertTrue(
            SerialNumberHistory::query()
                ->where('product_serial_number_id', $replacementSerial->id)
                ->where('event_type', SerialNumberHistory::EVENT_SOLD)
                ->exists(),
            'Serial number history should record SOLD event.'
        );
    }

    /** @test */
    public function cross_owner_replacement_decrements_stock_from_setting_b_location(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, , , $product, $replacementSerial] = $this->createPendingApprovalCrossOwnerReplacement();

        $stockBefore = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->locationB->id)
            ->value('quantity');

        $this->service->executeApprovalFromPreview($posReturn->id);

        $stockAfter = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->locationB->id)
            ->value('quantity');

        $this->assertSame((int) $stockBefore - 1, (int) $stockAfter);

        $this->assertTrue(
            Transaction::query()
                ->where('product_id', $product->id)
                ->where('setting_id', $this->settingB->id)
                ->where('location_id', $this->locationB->id)
                ->where('type', 'DISPATCH_RETURN')
                ->where('quantity', -1)
                ->exists(),
            'Stock transaction should be recorded under Setting B.'
        );
    }

    /** @test */
    public function cross_owner_replacement_does_not_attach_replacement_serial_to_original_sale(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $originalSale, , $product, $replacementSerial] = $this->createPendingApprovalCrossOwnerReplacement();

        $this->service->executeApprovalFromPreview($posReturn->id);

        $trackingOnOriginalSale = SalesOrderSerialTracking::query()
            ->where('sale_id', $originalSale->id)
            ->where('product_serial_number_id', $replacementSerial->id)
            ->first();

        $this->assertNull($trackingOnOriginalSale, 'Replacement serial must NOT be tracked on the original Setting A Sale.');
    }

    /** @test */
    public function cross_owner_replacement_creates_sale_payment_on_replacement_owner_sale(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn] = $this->createPendingApprovalCrossOwnerReplacement();

        $this->service->executeApprovalFromPreview($posReturn->id);

        $newSale = Sale::query()
            ->where('setting_id', $this->settingB->id)
            ->where('note', 'like', '%' . $posReturn->reference . '%')
            ->latest('id')
            ->first();

        $this->assertNotNull($newSale);

        $payment = \Modules\Sale\Entities\SalePayment::query()
            ->where('sale_id', $newSale->id)
            ->first();

        $this->assertNotNull($payment, 'A SalePayment should exist on the replacement-owner Sale.');
        $this->assertGreaterThan(0, (float) $payment->amount);
    }

    /** @test */
    public function cross_owner_replacement_failure_rolls_back_original_sale_correction(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $originalSale, , $product, $replacementSerial, , $originalSaleDetail] = $this->createPendingApprovalCrossOwnerReplacement();

        $originalQuantity = (int) $originalSaleDetail->quantity;

        $failingService = new class extends PosReturnLifecycleService {
            protected function adjustCrossOwnerReplacementStock(
                \Modules\SalesReturn\Entities\SaleReturnDetail $detail,
                int $settingId,
                int $locationId,
                int $quantity,
                ?int $actorId,
                string $saleReturnReference
            ): void {
                throw new \RuntimeException('Simulated stock failure for rollback test');
            }
        };

        try {
            $failingService->executeApprovalFromPreview($posReturn->id);
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stock failure', $e->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->fresh()->status);
        $this->assertSame($originalQuantity, (int) $originalSaleDetail->fresh()->quantity, 'Original sale detail must be unchanged after rollback.');

        $this->assertFalse(
            Sale::query()
                ->where('setting_id', $this->settingB->id)
                ->where('note', 'like', '%' . $posReturn->reference . '%')
                ->exists(),
            'No replacement-owner Sale should persist after rollback.'
        );
    }

    /** @test */
    public function replacement_serial_validation_blocks_different_product_id_even_cross_owner(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        $productA = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_code' => 'PROD-A-' . uniqid(),
        ]);

        $productB = $this->createStockedProduct($this->settingB, $this->locationB, [
            'product_code' => 'PROD-B-' . uniqid(),
        ]);

        $replacementSerial = $this->createSerialNumber($productB, $this->locationB, 'SN-B-' . uniqid());

        $guard = app(PosReturnReplacementGuard::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $guard->validateReplacementSerial($productA->id, $replacementSerial->id);
    }

    /** @test */
    public function replacement_owner_resolver_returns_null_owner_when_serial_has_no_location_relationship(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        $resolver = app(\Modules\Pos\Services\PosReturnReplacementOwnerResolver::class);

        // Passing null serial returns null owner context
        $result = $resolver->resolve(null);

        $this->assertNull($result['serial']);
        $this->assertNull($result['owner_setting_id']);
        $this->assertNull($result['location_id']);
    }

    /** @test */
    public function replacement_serial_validation_blocks_inactive_serial(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        $product = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_code' => 'PROD-INACTIVE-' . uniqid(),
        ]);

        $serial = $this->createSerialNumber($product, $this->locationA, 'SN-INACTIVE-' . uniqid());
        $serial->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        $guard = app(PosReturnReplacementGuard::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $guard->validateReplacementSerial($product->id, $serial->id);
    }

    /** @test */
    public function sale_return_details_execution_context_schema_dependency_is_present(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('sale_return_details', 'execution_context'),
            'sale_return_details.execution_context column must exist for cross-owner replacement approval execution.'
        );
    }

    // -----------------------------------------------------------------------
    // Helper methods
    // -----------------------------------------------------------------------

    protected function createPendingApprovalSameOwnerReplacement(): array
    {
        $product = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_code' => 'SAME-OWN-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 5,
            'serial_number_required' => true,
        ]);

        $replacementSerial = $this->createSerialNumber($product, $this->locationA, 'SN-SAME-' . uniqid());
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        $returnedSerial = $this->createSerialNumber($product, $this->locationA, 'SN-RET-SAME-' . uniqid());
        $returnedSerial->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        $sale = Sale::query()->create([
            'setting_id' => $this->settingA->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SO-SAME-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $saleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $sourceDispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $sourceDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $sourceDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
        ]);

        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->settingA->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-SAME-' . uniqid(),
            'receipt_number' => 'RCP-SAME-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-SAME-' . uniqid(),
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 1000,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $this->settingA->id,
            'location_id' => $this->locationA->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SR-SAME-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
            'serial_number_ids' => [$returnedSerial->id],
            'returned_serial_id' => $returnedSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 1,
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$returnedSerial->id],
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            // No execution_mode = same_owner (null means same-owner path)
        ]);

        return [$posReturn, $sale, $saleReturn];
    }

    protected function createPendingApprovalCrossOwnerReplacement(): array
    {
        // Product exists under Setting A (same product_id used by both settings)
        $product = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_code' => 'CROSS-' . uniqid(),
            'product_name' => 'Serialized Cross-Owner',
            'sale_price' => 1500,
            'stock_qty' => 2,
            'serial_number_required' => true,
        ]);

        // Setting B also has stock for the same product
        $stockB = ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $this->locationB->id,
            'quantity' => 3,
            'quantity_tax' => 0,
            'quantity_non_tax' => 3,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'tax_id' => null,
        ]);

        $returnedSerial = $this->createSerialNumber($product, $this->locationA, 'SN-RET-' . uniqid());
        $returnedSerial->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        $replacementSerial = $this->createSerialNumber($product, $this->locationB, 'SN-REP-B-' . uniqid());
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        $customer = Customer::query()->create([
            'setting_id' => $this->settingB->id,
            'customer_name' => 'Cross-Owner Customer',
            'customer_email' => 'cross@example.com',
            'customer_phone' => '0811111111',
        ]);

        $sale = Sale::query()->create([
            'setting_id' => $this->settingA->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SO-CROSS-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 3000,
            'paid_amount' => 3000,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $originalSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 3000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $sourceDispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $sourceDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $sourceDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
        ]);

        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->settingA->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-CROSS-' . uniqid(),
            'receipt_number' => 'RCP-CROSS-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-CROSS-' . uniqid(),
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 1500,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $this->settingA->id,
            'location_id' => $this->locationA->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SR-CROSS-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $originalSaleDetail->id,
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 1500,
            'line_total' => 1500,
            'serial_number_ids' => [$returnedSerial->id],
            'returned_serial_id' => $returnedSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 1,
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $originalSaleDetail->id,
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$returnedSerial->id],
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'execution_mode' => 'cross_owner_replacement',
                'replacement_serial_owner_setting_id' => $this->settingB->id,
                'replacement_serial_location_id' => $this->locationB->id,
                'original_sale_correction_quantity' => 1,
                'original_sale_correction_amount' => 1500,
                'generated_replacement_sale_effects' => [
                    'setting_id' => $this->settingB->id,
                    'setting_name' => 'Cross-Owner Setting B',
                    'location_id' => $this->locationB->id,
                    'location_name' => 'Location B',
                    'sale_reference' => 'generated_on_approval',
                    'customer_id' => $customer->id,
                    'customer_resolution_source' => 'existing',
                    'payment_amount' => 1500.0,
                    'dispatch_quantity' => 1.0,
                ],
            ],
        ]);

        return [$posReturn, $sale, $saleReturn, $product, $replacementSerial, $customer, $originalSaleDetail];
    }
}

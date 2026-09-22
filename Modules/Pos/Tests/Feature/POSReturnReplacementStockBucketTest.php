<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;

/**
 * Focused regression coverage for POS Return replacement dispatch decrementing
 * the replacement location owner's correct PKP/non-PKP good-stock bucket.
 */
class POSReturnReplacementStockBucketTest extends PosTransactionFeatureTestCase
{
    protected Setting $nonPkpSetting;
    protected Location $nonPkpLocation;
    protected Setting $pkpSetting;
    protected Location $pkpLocation;
    protected $actor;
    protected PosReturnLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');

        $this->nonPkpSetting = $this->createSetting('Bucket Non-PKP Owner', false);
        [, $this->nonPkpLocation] = $this->createTerminalWithLocation($this->nonPkpSetting);

        $this->pkpSetting = $this->createSetting('Bucket PKP Owner', true);
        [, $this->pkpLocation] = $this->createTerminalWithLocation($this->pkpSetting);

        $this->actor = $this->createUserForSetting($this->nonPkpSetting, 'Bucket Actor', [
            'pos.access',
            'pos.returns.approve',
        ]);

        $this->service = app(PosReturnLifecycleService::class);
    }

    /** @test */
    public function non_pkp_owner_replacement_dispatch_decrements_non_tax_bucket(): void
    {
        $this->actingAsInSetting($this->actor, $this->nonPkpSetting);

        $fixture = $this->createReplacementFixture($this->nonPkpSetting, $this->nonPkpLocation);

        $stockBefore = $this->stockFor($fixture['product'], $this->nonPkpLocation);
        $globalBefore = (int) $fixture['product']->fresh()->product_quantity;

        $this->service->executeApprovalFromPreview($fixture['pos_return']->id);

        $stockAfter = $this->stockFor($fixture['product'], $this->nonPkpLocation);

        // Returned unit comes back in, replacement unit goes out of the same
        // non-tax bucket: the bucket nets to its starting value.
        $this->assertSame(
            (int) $stockBefore->quantity_non_tax,
            (int) $stockAfter->quantity_non_tax,
            'Non-PKP owner bucket should net to its starting value after receipt + replacement.'
        );
        $this->assertSame(0, (int) $stockAfter->quantity_tax, 'Tax bucket must stay untouched for a non-PKP owner.');

        $this->assertAggregateMatchesBuckets($stockAfter);
        $this->assertSame($globalBefore, (int) $fixture['product']->fresh()->product_quantity);

        $this->assertDispatchLedgerBucket($fixture['product'], $this->nonPkpLocation, $this->nonPkpSetting, 'quantity_non_tax');
    }

    /** @test */
    public function pkp_owner_replacement_dispatch_decrements_tax_bucket(): void
    {
        $this->actingAsInSetting($this->actor, $this->pkpSetting);

        $fixture = $this->createReplacementFixture($this->pkpSetting, $this->pkpLocation, taxBucket: true);

        $stockBefore = $this->stockFor($fixture['product'], $this->pkpLocation);
        $globalBefore = (int) $fixture['product']->fresh()->product_quantity;

        $this->service->executeApprovalFromPreview($fixture['pos_return']->id);

        $stockAfter = $this->stockFor($fixture['product'], $this->pkpLocation);

        $this->assertSame(
            (int) $stockBefore->quantity_tax,
            (int) $stockAfter->quantity_tax,
            'PKP owner tax bucket should net to its starting value after receipt + replacement.'
        );
        $this->assertSame(0, (int) $stockAfter->quantity_non_tax, 'Non-tax bucket must stay untouched for a PKP owner.');

        $this->assertAggregateMatchesBuckets($stockAfter);
        $this->assertSame($globalBefore, (int) $fixture['product']->fresh()->product_quantity);

        $this->assertDispatchLedgerBucket($fixture['product'], $this->pkpLocation, $this->pkpSetting, 'quantity_tax');
    }

    /** @test */
    public function replacement_dispatch_is_blocked_when_the_owner_bucket_is_insufficient(): void
    {
        $this->actingAsInSetting($this->actor, $this->nonPkpSetting);

        $fixture = $this->createReplacementFixture($this->nonPkpSetting, $this->nonPkpLocation);
        $product = $fixture['product'];

        // Aggregate stays sufficient, but the required non-tax bucket is empty:
        // the whole quantity is parked in the tax bucket this non-PKP owner
        // must not draw from.
        $stock = $this->stockFor($product, $this->nonPkpLocation);
        $aggregate = (int) $stock->quantity;
        $stock->forceFill([
            'quantity_non_tax' => 0,
            'quantity_tax' => $aggregate,
        ])->save();

        $stockBefore = $this->stockFor($product, $this->nonPkpLocation)->toArray();
        $globalBefore = (int) $product->fresh()->product_quantity;
        $ledgerBefore = Transaction::query()->count();

        $mutator = app(\Modules\Pos\Services\PosReturnReplacementStockMutator::class);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($mutator, $product) {
                $mutator->dispatchReplacement(
                    (int) $product->id,
                    (int) $this->nonPkpLocation->id,
                    1,
                    $this->actor->id,
                    'Insufficient bucket guard test'
                );
            });
            $this->fail('Expected the insufficient owner bucket to block replacement dispatch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tidak mencukupi', $e->getMessage());
        }

        $stockAfter = $this->stockFor($product, $this->nonPkpLocation)->toArray();

        foreach (['quantity', 'quantity_tax', 'quantity_non_tax'] as $field) {
            $this->assertSame(
                (int) $stockBefore[$field],
                (int) $stockAfter[$field],
                "No partial stock effect is allowed on {$field} after rollback."
            );
        }

        $this->assertSame($globalBefore, (int) $product->fresh()->product_quantity);
        $this->assertSame($ledgerBefore, Transaction::query()->count(), 'No ledger rows may survive the rollback.');
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $fixture['pos_return']->fresh()->status);
    }

    /** @test */
    public function cross_owner_replacement_moves_each_owner_bucket_independently(): void
    {
        // Original owner is PKP, replacement owner is non-PKP.
        $this->actingAsInSetting($this->actor, $this->pkpSetting);

        $fixture = $this->createReplacementFixture(
            $this->pkpSetting,
            $this->pkpLocation,
            taxBucket: true,
            replacementSetting: $this->nonPkpSetting,
            replacementLocation: $this->nonPkpLocation
        );

        $originalBefore = $this->stockFor($fixture['product'], $this->pkpLocation);
        $replacementBefore = $this->stockFor($fixture['product'], $this->nonPkpLocation);

        $this->service->executeApprovalFromPreview($fixture['pos_return']->id);

        $originalAfter = $this->stockFor($fixture['product'], $this->pkpLocation);
        $replacementAfter = $this->stockFor($fixture['product'], $this->nonPkpLocation);

        $this->assertSame(
            (int) $originalBefore->quantity_tax + 1,
            (int) $originalAfter->quantity_tax,
            'Returned stock must increment the PKP original owner tax bucket.'
        );

        $this->assertSame(
            (int) $replacementBefore->quantity_non_tax - 1,
            (int) $replacementAfter->quantity_non_tax,
            'Replacement dispatch must decrement the non-PKP replacement owner non-tax bucket.'
        );

        $this->assertSame(
            (int) $replacementBefore->quantity_tax,
            (int) $replacementAfter->quantity_tax,
            'The replacement owner tax bucket must remain untouched.'
        );

        $this->assertAggregateMatchesBuckets($originalAfter);
        $this->assertAggregateMatchesBuckets($replacementAfter);
    }

    /** @test */
    public function repeated_return_and_replacement_keeps_buckets_reconciled(): void
    {
        $this->actingAsInSetting($this->actor, $this->nonPkpSetting);

        $first = $this->createReplacementFixture($this->nonPkpSetting, $this->nonPkpLocation);
        $product = $first['product'];

        $baseline = $this->stockFor($product, $this->nonPkpLocation);
        $baselineNonTax = (int) $baseline->quantity_non_tax;
        $baselineGlobal = (int) $product->fresh()->product_quantity;

        $this->service->executeApprovalFromPreview($first['pos_return']->id);

        $afterFirst = $this->stockFor($product, $this->nonPkpLocation);
        $this->assertSame($baselineNonTax, (int) $afterFirst->quantity_non_tax);
        $this->assertAggregateMatchesBuckets($afterFirst);

        // Second cycle on the same product/location: the returned unit from the
        // first cycle is resold and returned again, then replaced again.
        $second = $this->createReplacementFixture($this->nonPkpSetting, $this->nonPkpLocation, product: $product);

        $this->service->executeApprovalFromPreview($second['pos_return']->id);

        $afterSecond = $this->stockFor($product, $this->nonPkpLocation);

        $this->assertSame(
            $baselineNonTax,
            (int) $afterSecond->quantity_non_tax,
            'The owner bucket must still net flat after a second return/replacement cycle.'
        );
        $this->assertSame(0, (int) $afterSecond->quantity_tax);
        $this->assertAggregateMatchesBuckets($afterSecond);
        $this->assertSame($baselineGlobal, (int) $product->fresh()->product_quantity);

        // The regression this guards: aggregate must never drift above the
        // buckets that back it, so sellable serial reconciliation holds.
        $this->assertSame(
            (int) $afterSecond->quantity,
            (int) $afterSecond->quantity_non_tax + (int) $afterSecond->quantity_tax
                + (int) $afterSecond->broken_quantity_non_tax + (int) $afterSecond->broken_quantity_tax
        );
    }

    protected function assertAggregateMatchesBuckets(ProductStock $stock): void
    {
        $this->assertSame(
            (int) $stock->quantity_non_tax + (int) $stock->quantity_tax
                + (int) $stock->broken_quantity_non_tax + (int) $stock->broken_quantity_tax,
            (int) $stock->quantity,
            'Aggregate quantity must equal the sum of all four condition/tax buckets.'
        );
    }

    protected function assertDispatchLedgerBucket(
        Product $product,
        Location $location,
        Setting $setting,
        string $bucketColumn
    ): void {
        $ledger = Transaction::query()
            ->where('type', 'DISPATCH_RETURN')
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($ledger, 'A DISPATCH_RETURN ledger row must be recorded.');
        $this->assertSame((int) $setting->id, (int) $ledger->setting_id, 'Ledger must record the actual owner setting.');
        $this->assertSame(-1, (int) $ledger->quantity);
        $this->assertSame(1, (int) $ledger->{$bucketColumn}, "Ledger must record the movement on {$bucketColumn}.");

        $otherColumn = $bucketColumn === 'quantity_tax' ? 'quantity_non_tax' : 'quantity_tax';
        $this->assertSame(0, (int) $ledger->{$otherColumn});

        $this->assertSame(
            (int) $ledger->previous_quantity - 1,
            (int) $ledger->after_quantity,
            'Global before/after balances must reflect the dispatch.'
        );
        $this->assertSame(
            (int) $ledger->previous_quantity_at_location - 1,
            (int) $ledger->after_quantity_at_location,
            'Location before/after balances must reflect the dispatch.'
        );
    }

    protected function stockFor(Product $product, Location $location): ProductStock
    {
        return ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->firstOrFail();
    }

    /**
     * Build a pending-approval product-replacement POS Return.
     *
     * When $replacementSetting/$replacementLocation are supplied the return is
     * cross-owner: the returned unit comes back to the original owner while the
     * replacement leaves the replacement owner.
     *
     * @return array{product: Product, pos_return: PosReturn, sale: Sale, sale_return: SaleReturn}
     */
    protected function createReplacementFixture(
        Setting $setting,
        Location $location,
        bool $taxBucket = false,
        ?Setting $replacementSetting = null,
        ?Location $replacementLocation = null,
        ?Product $product = null
    ): array {
        $suffix = uniqid();

        $product ??= $this->createStockedProduct($setting, $location, [
            'product_code' => 'BUCKET-' . $suffix,
            'sale_price' => 1000,
            'stock_qty' => 5,
            'serial_number_required' => true,
        ]);

        // createStockedProduct always seeds the non-tax bucket; move it when the
        // owner under test is PKP so buckets match the owner's is_pkp value.
        if ($taxBucket) {
            $stock = $this->stockFor($product, $location);
            if ((int) $stock->quantity_tax === 0) {
                $stock->forceFill([
                    'quantity_tax' => (int) $stock->quantity_non_tax,
                    'quantity_non_tax' => 0,
                ])->save();
            }
        }

        $replacementSetting ??= $setting;
        $replacementLocation ??= $location;

        if ($replacementLocation->id !== $location->id) {
            ProductStock::query()->firstOrCreate([
                'product_id' => $product->id,
                'location_id' => $replacementLocation->id,
            ], [
                'quantity' => 5,
                'quantity_non_tax' => 5,
                'quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity' => 0,
                'tax_id' => null,
            ]);

            $product->forceFill([
                'product_quantity' => (int) $product->product_quantity + 5,
            ])->save();
        }

        $replacementSerial = $this->createSerialNumber($product, $replacementLocation, 'SN-REPL-' . $suffix);
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        $returnedSerial = $this->createSerialNumber($product, $location, 'SN-RET-' . $suffix);
        $returnedSerial->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        $sale = Sale::query()->create([
            'setting_id' => $setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SO-BUCKET-' . $suffix,
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
            'location_id' => $location->id,
            'tax_id' => null,
        ]);

        $posReturn = PosReturn::query()->create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-BUCKET-' . $suffix,
            'receipt_number' => 'RCP-BUCKET-' . $suffix,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . $suffix,
            'reference' => 'PR-BUCKET-' . $suffix,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 1000,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SR-BUCKET-' . $suffix,
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
            'source_setting_id' => $setting->id,
            'source_location_id' => $location->id,
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

        $isCrossOwner = (int) $replacementLocation->id !== (int) $location->id;

        $crossOwnerCustomer = $isCrossOwner
            ? \Modules\People\Entities\Customer::query()->create([
                'setting_id' => $setting->id,
                'customer_name' => 'Bucket Cross-Owner Customer ' . $suffix,
                'customer_email' => 'bucket-' . $suffix . '@example.com',
                'customer_phone' => '0811111111',
            ])
            : null;

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $sourceDispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            // Same-owner dispatch must originate from the return source
            // location; cross-owner dispatch is routed via execution_context.
            'location_id' => $isCrossOwner ? $location->id : $replacementLocation->id,
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
            'execution_context' => $isCrossOwner ? [
                'row_type' => 'parent',
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'execution_mode' => 'cross_owner_replacement',
                'replacement_serial_owner_setting_id' => $replacementSetting->id,
                'replacement_serial_location_id' => $replacementLocation->id,
                'original_sale_correction_quantity' => 1,
                'original_sale_correction_amount' => 1000,
                'generated_replacement_sale_effects' => [
                    'setting_id' => $replacementSetting->id,
                    'location_id' => $replacementLocation->id,
                    'customer_id' => $crossOwnerCustomer?->id,
                    'sale_reference' => 'generated_on_approval',
                ],
            ] : null,
        ]);

        return [
            'product' => $product,
            'pos_return' => $posReturn,
            'sale' => $sale,
            'sale_return' => $saleReturn,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\ReturnDispatchApprovalExecutor;
use Modules\Adjustment\Services\ReturnDispatchPreparationService;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use RuntimeException;
use Tests\TestCase;

class ReturnDispatchApprovalAndInventoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;
    protected Transfer $transfer;
    protected TransferMovement $receiptMovement;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();
        $this->tax = Tax::create(['name' => 'PPN', 'value' => 11, 'is_active' => true, 'is_default' => false]);

        $this->origin = Location::create(['setting_id' => $this->setting->id, 'name' => 'Origin']);
        $this->destination = Location::create(['setting_id' => $this->setting->id, 'name' => 'Destination']);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->product = Product::create([
            'product_name' => 'Taxed Return Item', 'product_code' => 'TRI-001',
            'setting_id' => $this->setting->id, 'unit_id' => $unit->id,
            'product_quantity' => 10, 'product_cost' => 1000, 'product_price' => 1500,
            'serial_number_required' => false, 'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id, 'location_id' => $this->destination->id,
            'quantity' => 10, 'quantity_non_tax' => 4, 'quantity_tax' => 6,
            'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0,
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_AWAITING_RETURN,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $policy = $this->createRoutePolicySnapshot(
            $this->transfer, $this->origin, $this->destination, $this->user,
            1, TransferRoutePolicy::CLASSIFICATION_TAX, true, $this->tax
        );

        $this->receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_APPROVED,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementReturnObligation::create([
            'transfer_id'              => $this->transfer->id,
            'transfer_route_policy_id' => $policy->id,
            'receipt_movement_id'      => $this->receiptMovement->id,
            'product_id'               => $this->product->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => '5.0000',
        ]);
    }

    /** @test */
    public function tax_classified_route_deducts_exclusively_from_the_tax_bucket_and_records_transaction_reference(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batch = $prep->setLineQuantity($batch, $this->product->id, 4, true, $batch->lock_version, $this->user->id);
        $pending = $prep->submit($batch, $batch->lock_version, $this->user->id);

        $approved = $executor->approve($this->transfer, $pending, $this->user->id, 'RET-IDEM-001');

        $stock = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->destination->id)->first();
        $this->assertEquals(6, $stock->quantity);
        $this->assertEquals(4, $stock->quantity_non_tax); // untouched
        $this->assertEquals(2, $stock->quantity_tax); // 6 - 4 deducted from tax bucket only

        $line = $approved->lines->firstWhere('product_id', $this->product->id);
        $this->assertSame('4.0000', (string) $line->applied_quantity_tax);
        $this->assertSame('0.0000', (string) $line->applied_quantity_non_tax);
        $this->assertNotNull($line->inventory_transaction_reference);
        $this->assertTrue(Transaction::where('id', $line->inventory_transaction_reference)->exists());
    }

    /** @test */
    public function approval_is_idempotent_under_the_same_key_and_does_not_double_deduct(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batch = $prep->setLineQuantity($batch, $this->product->id, 3, true, $batch->lock_version, $this->user->id);
        $pending = $prep->submit($batch, $batch->lock_version, $this->user->id);

        $first = $executor->approve($this->transfer, $pending, $this->user->id, 'RET-IDEM-REPLAY');
        $replay = $executor->approve($this->transfer, $pending, $this->user->id, 'RET-IDEM-REPLAY');

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $replay->status);

        $stock = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->destination->id)->first();
        $this->assertEquals(7, $stock->quantity); // deducted once (3), not twice
    }

    /** @test */
    public function fractional_quantity_is_rejected_before_any_inventory_mutation(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batch = $prep->setLineQuantity($batch, $this->product->id, '1.4000', true, $batch->lock_version, $this->user->id);
        $pending = $prep->submit($batch, $batch->lock_version, $this->user->id);

        $stockBefore = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->destination->id)->first()->quantity;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/harus berupa bilangan bulat/');

        try {
            $executor->approve($this->transfer, $pending, $this->user->id);
        } finally {
            $stockAfter = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->destination->id)->first()->quantity;
            $this->assertEquals($stockBefore, $stockAfter);

            $obligation = TransferMovementReturnObligation::where('transfer_id', $this->transfer->id)->first();
            $obligation->load('activeReservations');
            $this->assertSame('5.0000', $obligation->availableCapacity());
        }
    }

    /** @test */
    public function insufficient_destination_stock_rolls_back_the_entire_approval(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        // Obligation allows 5, but tax bucket only has 6 units so request all 5 as tax-side which
        // succeeds; instead force an overflow by depleting the tax bucket first at the DB level
        // after the batch is already submitted (simulating a concurrent depletion).
        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $batch = $prep->setLineQuantity($batch, $this->product->id, 5, true, $batch->lock_version, $this->user->id);
        $pending = $prep->submit($batch, $batch->lock_version, $this->user->id);

        ProductStock::where('product_id', $this->product->id)->where('location_id', $this->destination->id)
            ->update(['quantity_tax' => 2, 'quantity' => 6]);

        $this->expectException(RuntimeException::class);

        try {
            $executor->approve($this->transfer, $pending, $this->user->id);
        } finally {
            $pending->refresh();
            $this->assertEquals(TransferMovement::STATUS_PENDING, $pending->status);

            $stock = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->destination->id)->first();
            $this->assertEquals(2, $stock->quantity_tax); // unchanged by the failed attempt

            $obligation = TransferMovementReturnObligation::where('transfer_id', $this->transfer->id)->first();
            $obligation->load('activeReservations');
            $this->assertSame('5.0000', $obligation->availableCapacity()); // no reservation created
        }
    }
}

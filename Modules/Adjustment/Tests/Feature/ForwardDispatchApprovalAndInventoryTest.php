<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Services\ForwardDispatchApprovalExecutor;
use Modules\Adjustment\Services\ForwardDispatchPreparationService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use RuntimeException;
use Tests\TestCase;

class ForwardDispatchApprovalAndInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;
    protected Product $serializedProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Location',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Location',
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->product = Product::create([
            'product_name'           => 'Non-serialized Item',
            'product_code'           => 'NSI-001',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 10,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->product->id,
            'location_id'             => $this->origin->id,
            'quantity'                => 10,
            'quantity_non_tax'        => 6,
            'quantity_tax'            => 4,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $this->serializedProduct = Product::create([
            'product_name'           => 'Serialized Gadget',
            'product_code'           => 'SG-001',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 2,
            'product_cost'           => 2000,
            'product_price'          => 3000,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->serializedProduct->id,
            'location_id'             => $this->origin->id,
            'quantity'                => 2,
            'quantity_non_tax'        => 2,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'SG-SN-001',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
        ]);
        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'SG-SN-002',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
        ]);
    }

    /** @test */
    public function approve_exact_dispatch_deducts_inventory_and_activates_transit_custody(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->product->id,
            'quantity'    => 5,
        ]);

        TransferProduct::create([
            'transfer_id'    => $transfer->id,
            'product_id'     => $this->serializedProduct->id,
            'quantity'       => 1,
            'serial_numbers' => [['serial_number' => 'SG-SN-001']],
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $approvalExecutor = app(ForwardDispatchApprovalExecutor::class);

        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);
        $movement = $prepService->setLineQuantity($movement, $this->product->id, 5, true, $movement->lock_version, $this->user->id);
        $res = $prepService->applyScan($movement, 'SG-SN-001', $this->setting->id, $movement->lock_version, $this->user->id);
        $this->assertEquals('resolved', $res['status']);
        $movement = $movement->fresh(['lines.serials']);

        $pending = $prepService->submit($movement, $movement->lock_version, $this->user->id);

        $approvedMovement = $approvalExecutor->approve($transfer, $pending, $this->user->id, 'IDEM-APP-001');

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approvedMovement->status);
        $this->assertEquals(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);

        // Check stock deducted (5 non-tax deducted, 1 remaining)
        $stock = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->origin->id)->first();
        $this->assertEquals(5, $stock->quantity);
        $this->assertEquals(1, $stock->quantity_non_tax);
        $this->assertEquals(4, $stock->quantity_tax);

        // Check serialized stock and transit custody
        $psn = ProductSerialNumber::where('serial_number', 'SG-SN-001')->first();
        $this->assertEquals($this->origin->id, $psn->location_id); // live location stays at origin
        $this->assertTrue(TransferActiveSerialClaim::where('product_serial_number_id', $psn->id)->exists());

        // Serial should no longer be available in available query
        $this->assertFalse(ProductSerialNumber::available()->where('id', $psn->id)->exists());

        // Idempotent retry returns approved movement safely
        $replay = $approvalExecutor->approve($transfer, $pending, $this->user->id, 'IDEM-APP-001');
        $this->assertEquals(TransferMovement::STATUS_APPROVED, $replay->status);
    }

    /** @test */
    public function approval_fails_atomically_if_mismatched_or_insufficient_stock(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->product->id,
            'quantity'    => 5,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $approvalExecutor = app(ForwardDispatchApprovalExecutor::class);

        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);
        // Operator entered 4 instead of 5
        $movement = $prepService->setLineQuantity($movement, $this->product->id, 4, true, $movement->lock_version, $this->user->id);
        $pending = $prepService->submit($movement, $movement->lock_version, $this->user->id);

        try {
            $approvalExecutor->approve($transfer, $pending, $this->user->id);
            $this->fail('Approval should have thrown exception due to count mismatch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('physical count does not match', $e->getMessage());
        }

        // Verify status remains PENDING and no stock was deducted
        $this->assertEquals(TransferMovement::STATUS_PENDING, $pending->fresh()->status);
        $this->assertEquals(Transfer::STATUS_APPROVED, $transfer->fresh()->status);
        $stock = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->origin->id)->first();
        $this->assertEquals(10, $stock->quantity);
    }

    /** @test */
    public function approval_rejects_fractional_quantities(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->product->id,
            'quantity'    => 1.5,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $approvalExecutor = app(ForwardDispatchApprovalExecutor::class);

        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);
        $movement = $prepService->setLineQuantity($movement, $this->product->id, 1.5, true, $movement->lock_version, $this->user->id);
        $pending = $prepService->submit($movement, $movement->lock_version, $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fractional quantity [1.5000] is not supported');
        $approvalExecutor->approve($transfer, $pending, $this->user->id);
    }

    /** @test */
    public function approval_rejects_serial_drifted_to_sold_or_missing(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        TransferProduct::create([
            'transfer_id'    => $transfer->id,
            'product_id'     => $this->serializedProduct->id,
            'quantity'       => 1,
            'serial_numbers' => [['serial_number' => 'SG-SN-001']],
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $approvalExecutor = app(ForwardDispatchApprovalExecutor::class);

        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);
        $prepService->applyScan($movement, 'SG-SN-001', $this->setting->id, $movement->lock_version, $this->user->id);
        $movement = $movement->fresh(['lines.serials']);
        $pending = $prepService->submit($movement, $movement->lock_version, $this->user->id);

        // Simulate serial status drifting to SOLD in background
        ProductSerialNumber::where('serial_number', 'SG-SN-001')->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('berstatus tidak tersedia [SOLD]');
        $approvalExecutor->approve($transfer, $pending, $this->user->id);
    }

    /** @test */
    public function submission_and_approval_reject_serialized_line_when_serial_count_does_not_equal_line_quantity(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        TransferProduct::create([
            'transfer_id'    => $transfer->id,
            'product_id'     => $this->serializedProduct->id,
            'quantity'       => 1,
            'serial_numbers' => [],
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $approvalExecutor = app(ForwardDispatchApprovalExecutor::class);

        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);
        // Force set line quantity to 1 without scanning serials
        $movement = $prepService->setLineQuantity($movement, $this->serializedProduct->id, 1, true, $movement->lock_version, $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects 1 serials, found 0');
        $prepService->submit($movement, $movement->lock_version, $this->user->id);
    }

    /** @test */
    public function approval_fails_atomically_on_global_quantity_underflow(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->product->id,
            'quantity'    => 5,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $approvalExecutor = app(ForwardDispatchApprovalExecutor::class);

        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);
        $movement = $prepService->setLineQuantity($movement, $this->product->id, 5, true, $movement->lock_version, $this->user->id);
        $pending = $prepService->submit($movement, $movement->lock_version, $this->user->id);

        // Corrupt global product_quantity to simulate inconsistent aggregate state
        Product::where('id', $this->product->id)->update(['product_quantity' => 2]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Global product quantity underflow');
        $approvalExecutor->approve($transfer, $pending, $this->user->id);
    }
}

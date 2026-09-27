<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementReservation;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\ReturnReceiptComparatorService;
use Modules\Adjustment\Services\ReturnReceiptPreparationService;
use Modules\Adjustment\Services\ReturnReceiptProjectionService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReturnReceiptComparatorAndAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    private const GUARD = 'web';

    protected User $blindUser;
    protected User $privilegedUser;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $productA;
    protected Product $productB;
    protected Product $serializedProduct;
    protected Transfer $transfer;
    protected TransferMovement $dispatchMovement;
    protected ReturnReceiptComparatorService $comparator;
    protected ReturnReceiptPreparationService $preparationService;
    protected ReturnReceiptProjectionService $projectionService;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stock_transfers.v2_dispatch_enabled' => true]);

        $permissions = [
            'stockTransfers.access',
            'stockTransfers.receive.create',
            'stockTransfers.receive.approval',
            TransferStockVisibility::PERMISSION,
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => self::GUARD]);
        }

        $this->setting = Setting::factory()->create(['is_pkp' => true]);

        $this->blindUser = User::factory()->create();
        $this->blindUser->givePermissionTo([
            'stockTransfers.access',
            'stockTransfers.receive.create',
            'stockTransfers.receive.approval',
        ]);

        $this->privilegedUser = User::factory()->create();
        $this->privilegedUser->givePermissionTo([
            'stockTransfers.access',
            'stockTransfers.receive.create',
            'stockTransfers.receive.approval',
            TransferStockVisibility::PERMISSION,
        ]);

        $this->origin = Location::create(['setting_id' => $this->setting->id, 'name' => 'Origin']);
        $this->destination = Location::create(['setting_id' => $this->setting->id, 'name' => 'Destination']);

        $this->tax = Tax::create(['name' => 'PPN 11%', 'value' => 11.0, 'is_default' => true, 'is_active' => true]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->productA = Product::create([
            'product_name' => 'Product Alpha', 'product_code' => 'PA-001',
            'barcode' => 'BAR-PA', 'setting_id' => $this->setting->id, 'unit_id' => $unit->id,
            'product_quantity' => 0, 'product_cost' => 1000, 'product_price' => 1500,
            'serial_number_required' => false, 'stock_managed' => true,
        ]);

        $this->productB = Product::create([
            'product_name' => 'Product Beta', 'product_code' => 'PB-001',
            'barcode' => 'BAR-PB', 'setting_id' => $this->setting->id, 'unit_id' => $unit->id,
            'product_quantity' => 0, 'product_cost' => 2000, 'product_price' => 3000,
            'serial_number_required' => false, 'stock_managed' => true,
        ]);

        $this->serializedProduct = Product::create([
            'product_name' => 'Serialized Gamma', 'product_code' => 'PG-001',
            'barcode' => 'BAR-PG', 'setting_id' => $this->setting->id, 'unit_id' => $unit->id,
            'product_quantity' => 0, 'product_cost' => 5000, 'product_price' => 7000,
            'serial_number_required' => true, 'stock_managed' => true,
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_RETURN_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->privilegedUser->id,
        ]);

        $this->createRoutePolicySnapshot(
            $this->transfer, $this->origin, $this->destination, $this->privilegedUser,
            1, TransferRoutePolicy::CLASSIFICATION_TAX, true, $this->tax
        );

        $returnBatchId = 'ret_batch_comp_01';

        $this->dispatchMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_DISPATCH,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_APPROVED,
            'origin_location_id'      => $this->destination->id,
            'destination_location_id' => $this->origin->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->privilegedUser->id,
            'return_batch_id'         => $returnBatchId,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->productA->id,
            'quantity'             => 5,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->productB->id,
            'quantity'             => 3,
        ]);

        $this->comparator = app(ReturnReceiptComparatorService::class);
        $this->preparationService = app(ReturnReceiptPreparationService::class);
        $this->projectionService = app(ReturnReceiptProjectionService::class);
    }

    public function test_comparator_matches_exact_manifest_order_independently(): void
    {
        $receipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->blindUser->id,
            'return_batch_id'         => $this->dispatchMovement->return_batch_id,
            'source_movement_id'      => $this->dispatchMovement->id,
        ]);

        // Add Product B first, then Product A (reverse order of dispatch)
        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->productB->id,
            'quantity'             => 3,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->productA->id,
            'quantity'             => 5,
        ]);

        $result = $this->comparator->compare($receipt, $this->dispatchMovement);

        $this->assertTrue($result['matches']);
        $this->assertEquals(0, $result['summary']['mismatched_products_count']);
        $this->assertEquals(0, $result['summary']['unexpected_products_count']);
        $this->assertEquals(0, $result['summary']['missing_products_count']);
        $this->assertEquals('MATCH', $result['details'][$this->productA->id]['status']);
        $this->assertEquals('MATCH', $result['details'][$this->productB->id]['status']);
    }

    public function test_comparator_detects_shortages_and_excess(): void
    {
        $receipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->blindUser->id,
            'return_batch_id'         => $this->dispatchMovement->return_batch_id,
            'source_movement_id'      => $this->dispatchMovement->id,
        ]);

        // Product A shortage: 4 instead of 5; Product B excess: 4 instead of 3
        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->productA->id,
            'quantity'             => 4,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->productB->id,
            'quantity'             => 4,
        ]);

        $result = $this->comparator->compare($receipt, $this->dispatchMovement);

        $this->assertFalse($result['matches']);
        $this->assertEquals('SHORTAGE', $result['details'][$this->productA->id]['status']);
        $this->assertEquals('EXCESS', $result['details'][$this->productB->id]['status']);
    }

    public function test_comparator_rejects_substitute_serial_numbers(): void
    {
        $sn1 = ProductSerialNumber::create([
            'product_id'          => $this->serializedProduct->id,
            'serial_number'       => 'SN-ORIGINAL-01',
            'status'              => 'in_transit',
            'location_id'         => $this->destination->id,
            'custody_movement_id' => $this->dispatchMovement->id,
            'custody_transfer_id' => $this->transfer->id,
        ]);

        $sn2 = ProductSerialNumber::create([
            'product_id'          => $this->serializedProduct->id,
            'serial_number'       => 'SN-SUBSTITUTE-02',
            'status'              => ProductSerialNumber::STATUS_ACTIVE,
            'location_id'         => $this->destination->id,
        ]);

        // Dispatch movement has serial SN-ORIGINAL-01
        $dispSerialLine = TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $this->dispatchMovement->id,
            'transfer_movement_line_id' => $dispSerialLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn1->id,
            'serial_number'             => $sn1->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        // Receipt observed substitute serial SN-SUBSTITUTE-02
        $receipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->blindUser->id,
            'return_batch_id'         => $this->dispatchMovement->return_batch_id,
            'source_movement_id'      => $this->dispatchMovement->id,
        ]);

        $recSerialLine = TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receipt->id,
            'transfer_movement_line_id' => $recSerialLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn2->id,
            'serial_number'             => $sn2->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        $result = $this->comparator->compare($receipt, $this->dispatchMovement);

        $this->assertFalse($result['matches']);
        $this->assertEquals('SERIAL_MISMATCH', $result['details'][$this->serializedProduct->id]['status']);
        $this->assertEquals(['SN-ORIGINAL-01'], $result['details'][$this->serializedProduct->id]['missing_serials']);
        $this->assertEquals(['SN-SUBSTITUTE-02'], $result['details'][$this->serializedProduct->id]['unexpected_serials']);
    }

    public function test_approval_projection_hides_manifest_comparison_for_blind_approvers(): void
    {
        $receipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->blindUser->id,
            'return_batch_id'         => $this->dispatchMovement->return_batch_id,
            'source_movement_id'      => $this->dispatchMovement->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->productA->id,
            'quantity'             => 5,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->productB->id,
            'quantity'             => 3,
        ]);

        // Blind approver projection
        $blindProjection = $this->projectionService->getApprovalProjection(
            $this->transfer,
            $receipt,
            false
        );

        $this->assertTrue($blindProjection['matches']);
        $this->assertArrayNotHasKey('details', $blindProjection);

        // Privileged (canViewSystemStock) approver projection
        $privilegedProjection = $this->projectionService->getApprovalProjection(
            $this->transfer,
            $receipt,
            true
        );

        $this->assertTrue($privilegedProjection['matches']);
        $this->assertArrayHasKey('details', $privilegedProjection);
    }
}

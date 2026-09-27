<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\ForwardReceiptApprovalExecutor;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ForwardReceiptReclassificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $originSetting;
    protected Setting $destSetting;
    protected Location $origin;
    protected Location $destination;
    protected Unit $unit;
    protected Tax $tax;
    protected ForwardReceiptApprovalExecutor $approvalExecutor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $this->tax = Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_active' => true, 'is_default' => true]);
        $this->approvalExecutor = app(ForwardReceiptApprovalExecutor::class);
    }

    private function buildRoute(bool $originPkp, bool $destPkp): void
    {
        $this->originSetting = Setting::factory()->create(['is_pkp' => $originPkp]);
        $this->destSetting = Setting::factory()->create(['is_pkp' => $destPkp]);
        $this->origin = Location::create(['setting_id' => $this->originSetting->id, 'name' => 'Origin']);
        $this->destination = Location::create(['setting_id' => $this->destSetting->id, 'name' => 'Destination']);
    }

    private function buildTransferWithDispatch(Product $product, int $quantity, array $dispatchBuckets, string $classification, bool $mandatoryReturn, string $condition = Transfer::CONDITION_GOOD): array
    {
        ProductStock::create([
            'product_id'              => $product->id,
            'location_id'             => $this->destination->id,
            'quantity'                => 0,
            'quantity_non_tax'        => 0,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => $condition,
            'created_by'              => $this->user->id,
        ]);

        $this->createRoutePolicySnapshot(
            $transfer,
            $this->origin,
            $this->destination,
            $this->user,
            1,
            $classification,
            $mandatoryReturn,
            $classification === TransferRoutePolicy::CLASSIFICATION_TAX ? $this->tax : null
        );

        $dispatchMovement = TransferMovement::create([
            'transfer_id'              => $transfer->id,
            'type'                     => TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                 => 1,
            'lock_version'             => 1,
            'transfer_revision'        => 1,
            'status'                   => TransferMovement::STATUS_APPROVED,
            'origin_location_id'       => $this->origin->id,
            'destination_location_id'  => $this->destination->id,
            'stock_condition'          => $condition,
            'created_by'               => $this->user->id,
            'reviewed_by'              => $this->user->id,
            'reviewed_at'              => now(),
        ]);

        $isGood = $condition === Transfer::CONDITION_GOOD;
        $totalDeducted = -$quantity;

        $trx = Transaction::create([
            'product_id'                    => $product->id,
            'setting_id'                    => $this->originSetting->id,
            'type'                          => 'TRF',
            'quantity'                      => $totalDeducted,
            'current_quantity'              => 0,
            'broken_quantity'               => 0,
            'previous_quantity'             => $quantity,
            'previous_quantity_at_location' => $quantity,
            'after_quantity'                => 0,
            'after_quantity_at_location'    => 0,
            'quantity_tax'                  => 0,
            'quantity_non_tax'              => 0,
            'broken_quantity_tax'           => 0,
            'broken_quantity_non_tax'       => 0,
            'location_id'                   => $this->origin->id,
            'user_id'                       => $this->user->id,
            'reason'                        => 'Dispatch test transaction',
        ]);

        $dispatchLine = TransferMovementLine::create([
            'transfer_movement_id'            => $dispatchMovement->id,
            'product_id'                      => $product->id,
            'quantity'                        => $quantity,
            'applied_quantity_tax'            => $isGood ? $dispatchBuckets['tax'] : 0,
            'applied_quantity_non_tax'        => $isGood ? $dispatchBuckets['non_tax'] : 0,
            'applied_quantity_broken_tax'     => $isGood ? 0 : $dispatchBuckets['tax'],
            'applied_quantity_broken_non_tax' => $isGood ? 0 : $dispatchBuckets['non_tax'],
            'inventory_transaction_reference' => (string) $trx->id,
        ]);

        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $dispatchMovement->id,
            'stock_condition'         => $condition,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $product->id,
            'quantity'             => $quantity,
        ]);

        return [$transfer, $receiptMovement];
    }

    private function createProduct(string $code, bool $serialized = false): Product
    {
        return Product::create([
            'product_name'           => 'Product ' . $code,
            'product_code'           => $code,
            'setting_id'             => $this->originSetting->id,
            'unit_id'                => $this->unit->id,
            'product_quantity'       => 10,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => $serialized,
            'stock_managed'          => true,
        ]);
    }

    public function test_pkp_to_non_pkp_good_stock_reclassifies_to_destination_non_tax(): void
    {
        $this->buildRoute(true, false);
        $product = $this->createProduct('RCL-001');

        [$transfer, $receipt] = $this->buildTransferWithDispatch($product, 10, ['tax' => 10, 'non_tax' => 0], TransferRoutePolicy::CLASSIFICATION_NON_TAX, true);

        $approved = $this->approvalExecutor->approve($transfer, $receipt, $this->user->id);

        $line = $approved->lines->first();
        $this->assertEquals('10.0000', (string) $line->applied_quantity_non_tax);
        $this->assertEquals('0.0000', (string) $line->applied_quantity_tax);

        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $this->destination->id)->first();
        $this->assertEquals(10, $stock->quantity_non_tax);
        $this->assertEquals(0, $stock->quantity_tax);

        $transfer->refresh();
        $this->assertEquals(Transfer::STATUS_AWAITING_RETURN, $transfer->status);
    }

    public function test_non_pkp_to_pkp_good_stock_reclassifies_to_destination_tax(): void
    {
        $this->buildRoute(false, true);
        $product = $this->createProduct('RCL-002');

        [$transfer, $receipt] = $this->buildTransferWithDispatch($product, 8, ['tax' => 0, 'non_tax' => 8], TransferRoutePolicy::CLASSIFICATION_TAX, true);

        $approved = $this->approvalExecutor->approve($transfer, $receipt, $this->user->id);

        $line = $approved->lines->first();
        $this->assertEquals('8.0000', (string) $line->applied_quantity_tax);
        $this->assertEquals('0.0000', (string) $line->applied_quantity_non_tax);

        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $this->destination->id)->first();
        $this->assertEquals(8, $stock->quantity_tax);
        $this->assertEquals(0, $stock->quantity_non_tax);
    }

    public function test_broken_stock_reclassifies_into_broken_buckets_without_creating_good_bucket(): void
    {
        $this->buildRoute(false, true);
        $product = $this->createProduct('RCL-003');

        [$transfer, $receipt] = $this->buildTransferWithDispatch($product, 5, ['tax' => 0, 'non_tax' => 5], TransferRoutePolicy::CLASSIFICATION_TAX, true, Transfer::CONDITION_BREAKAGE);

        $approved = $this->approvalExecutor->approve($transfer, $receipt, $this->user->id);

        $line = $approved->lines->first();
        $this->assertEquals('5.0000', (string) $line->applied_quantity_broken_tax);
        $this->assertEquals('0.0000', (string) $line->applied_quantity_tax);
        $this->assertEquals('0.0000', (string) $line->applied_quantity_non_tax);

        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $this->destination->id)->first();
        $this->assertEquals(5, $stock->broken_quantity_tax);
        $this->assertEquals(0, $stock->quantity_tax);
    }

    public function test_same_business_preserves_source_buckets_and_completes(): void
    {
        $this->buildRoute(false, false);
        $this->destSetting = $this->originSetting;
        $this->destination = Location::create(['setting_id' => $this->originSetting->id, 'name' => 'Same Business Dest']);
        $product = $this->createProduct('RCL-004');

        [$transfer, $receipt] = $this->buildTransferWithDispatch($product, 10, ['tax' => 4, 'non_tax' => 6], TransferRoutePolicy::CLASSIFICATION_PRESERVE, false);

        $approved = $this->approvalExecutor->approve($transfer, $receipt, $this->user->id);

        $line = $approved->lines->first();
        $this->assertEquals('4.0000', (string) $line->applied_quantity_tax);
        $this->assertEquals('6.0000', (string) $line->applied_quantity_non_tax);

        $transfer->refresh();
        $this->assertEquals(Transfer::STATUS_COMPLETED, $transfer->status);
        $this->assertFalse(TransferMovementReturnObligation::where('transfer_id', $transfer->id)->exists());
    }

    public function test_mandatory_route_creates_full_quantity_obligation_regardless_of_source_tax_mix(): void
    {
        $this->buildRoute(true, false);
        $product = $this->createProduct('RCL-005');

        // Mixed source provenance: some tax, some non-tax
        [$transfer, $receipt] = $this->buildTransferWithDispatch($product, 10, ['tax' => 6, 'non_tax' => 4], TransferRoutePolicy::CLASSIFICATION_NON_TAX, true);

        $this->approvalExecutor->approve($transfer, $receipt, $this->user->id);

        $obligation = TransferMovementReturnObligation::where('transfer_id', $transfer->id)->first();
        $this->assertNotNull($obligation);
        $this->assertEquals(10, $obligation->required_quantity);
        $this->assertEquals(TransferMovementReturnObligation::CONDITION_GOOD, $obligation->stock_condition);
        $this->assertEquals(TransferMovementReturnObligation::STATUS_OUTSTANDING, $obligation->status);
    }

    public function test_no_return_route_creates_no_obligation_and_completes(): void
    {
        $this->buildRoute(false, false);
        $product = $this->createProduct('RCL-006');

        [$transfer, $receipt] = $this->buildTransferWithDispatch($product, 5, ['tax' => 0, 'non_tax' => 5], TransferRoutePolicy::CLASSIFICATION_NON_TAX, false);

        $this->approvalExecutor->approve($transfer, $receipt, $this->user->id);

        $this->assertFalse(TransferMovementReturnObligation::where('transfer_id', $transfer->id)->exists());
        $transfer->refresh();
        $this->assertEquals(Transfer::STATUS_COMPLETED, $transfer->status);
    }

    public function test_receipt_without_approved_policy_snapshot_is_rejected(): void
    {
        $this->buildRoute(false, true);
        $product = $this->createProduct('RCL-007');

        ProductStock::create([
            'product_id'              => $product->id,
            'location_id'             => $this->destination->id,
            'quantity'                => 0,
            'quantity_non_tax'        => 0,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);
        // Intentionally no route-policy snapshot created

        $dispatchMovement = TransferMovement::create([
            'transfer_id'              => $transfer->id,
            'type'                     => TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                 => 1,
            'lock_version'             => 1,
            'transfer_revision'        => 1,
            'status'                   => TransferMovement::STATUS_APPROVED,
            'origin_location_id'       => $this->origin->id,
            'destination_location_id'  => $this->destination->id,
            'stock_condition'          => TransferMovement::CONDITION_GOOD,
            'created_by'               => $this->user->id,
            'reviewed_by'              => $this->user->id,
            'reviewed_at'              => now(),
        ]);

        $trx = Transaction::create([
            'product_id'                    => $product->id,
            'setting_id'                    => $this->originSetting->id,
            'type'                          => 'TRF',
            'quantity'                      => -5,
            'current_quantity'              => 0,
            'broken_quantity'               => 0,
            'previous_quantity'             => 5,
            'previous_quantity_at_location' => 5,
            'after_quantity'                => 0,
            'after_quantity_at_location'    => 0,
            'quantity_tax'                  => 0,
            'quantity_non_tax'              => 0,
            'broken_quantity_tax'           => 0,
            'broken_quantity_non_tax'       => 0,
            'location_id'                   => $this->origin->id,
            'user_id'                       => $this->user->id,
            'reason'                        => 'Dispatch test transaction',
        ]);

        TransferMovementLine::create([
            'transfer_movement_id'            => $dispatchMovement->id,
            'product_id'                      => $product->id,
            'quantity'                        => 5,
            'applied_quantity_tax'            => 0,
            'applied_quantity_non_tax'        => 5,
            'applied_quantity_broken_tax'     => 0,
            'applied_quantity_broken_non_tax' => 0,
            'inventory_transaction_reference' => (string) $trx->id,
        ]);

        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $product->id,
            'quantity'             => 5,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no approved route-policy snapshot');
        $this->approvalExecutor->approve($transfer, $receiptMovement, $this->user->id);
    }
}

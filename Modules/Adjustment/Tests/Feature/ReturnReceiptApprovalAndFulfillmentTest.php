<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferReturnObligationReservation;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\ReturnReceiptApprovalExecutor;
use Modules\Adjustment\Services\ReturnReceiptComparatorService;
use Modules\Adjustment\Services\ReturnReceiptPreparationService;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use RuntimeException;
use Tests\TestCase;

class ReturnReceiptApprovalAndFulfillmentTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $originSetting;
    protected Setting $destSetting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;
    protected Product $serializedProduct;
    protected Transfer $transfer;
    protected TransferMovement $dispatchMovement;
    protected TransferMovement $forwardReceipt;
    protected TransferMovementReturnObligation $obligation;
    protected TransferRoutePolicy $policy;
    protected Tax $defaultTax;
    protected Tax $fallbackTax;
    protected ReturnReceiptApprovalExecutor $executor;
    protected ReturnReceiptPreparationService $prepService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Origin is PKP (is_pkp = true)
        $this->originSetting = Setting::factory()->create(['is_pkp' => true]);
        // Destination is non-PKP (is_pkp = false)
        $this->destSetting = Setting::factory()->create(['is_pkp' => false]);

        $this->origin = Location::create([
            'setting_id' => $this->originSetting->id,
            'name'       => 'Origin PKP Location',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->destSetting->id,
            'name'       => 'Destination Non-PKP Location',
        ]);

        $this->defaultTax = Tax::create([
            'name'       => 'PPN 11%',
            'value'      => 11.0,
            'is_default' => true,
            'is_active'  => true,
        ]);

        $this->fallbackTax = Tax::create([
            'name'       => 'PPN Fallback 10%',
            'value'      => 10.0,
            'is_default' => false,
            'is_active'  => true,
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->product = Product::create([
            'product_name'           => 'Standard Widget',
            'product_code'           => 'WID-001',
            'barcode'                => 'BAR-WID-1',
            'setting_id'             => $this->originSetting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 0,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->product->id,
            'location_id'             => $this->origin->id,
            'quantity'                => 0,
            'quantity_non_tax'        => 0,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $this->serializedProduct = Product::create([
            'product_name'           => 'Serialized Device',
            'product_code'           => 'DEV-001',
            'barcode'                => 'BAR-DEV-1',
            'setting_id'             => $this->originSetting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 0,
            'product_cost'           => 5000,
            'product_price'          => 7000,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->serializedProduct->id,
            'location_id'             => $this->origin->id,
            'quantity'                => 0,
            'quantity_non_tax'        => 0,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_RETURN_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $this->policy = $this->createRoutePolicySnapshot(
            $this->transfer,
            $this->origin,
            $this->destination,
            $this->user,
            1,
            TransferRoutePolicy::CLASSIFICATION_TAX,
            true,
            $this->defaultTax
        );

        $this->forwardReceipt = TransferMovement::create([
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

        $this->obligation = TransferMovementReturnObligation::create([
            'transfer_id'              => $this->transfer->id,
            'transfer_route_policy_id' => $this->policy->id,
            'receipt_movement_id'      => $this->forwardReceipt->id,
            'product_id'               => $this->product->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => '10.0000',
            'returned_quantity'        => '0.0000',
            'status'                   => TransferMovementReturnObligation::STATUS_OUTSTANDING,
        ]);

        $returnBatchId = 'ret_batch_appr_01';

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
            'created_by'              => $this->user->id,
            'return_batch_id'         => $returnBatchId,
        ]);

        $line = TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->product->id,
            'quantity'             => 10,
        ]);

        TransferReturnObligationReservation::create([
            'transfer_movement_return_obligation_id' => $this->obligation->id,
            'transfer_movement_id'                   => $this->dispatchMovement->id,
            'transfer_movement_line_id'              => $line->id,
            'quantity'                                => '10.0000',
            'status'                                  => TransferReturnObligationReservation::STATUS_ACTIVE,
            'created_by'                              => $this->user->id,
        ]);

        $this->executor = app(ReturnReceiptApprovalExecutor::class);
        $this->prepService = app(ReturnReceiptPreparationService::class);
    }

    public function test_it_approves_receipt_with_pkp_tax_snapshot_and_restores_origin_inventory(): void
    {
        $receipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->destination->id,
            'destination_location_id' => $this->origin->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'return_batch_id'         => $this->dispatchMovement->return_batch_id,
            'source_movement_id'      => $this->dispatchMovement->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->product->id,
            'quantity'             => 10,
        ]);

        $approvedReceipt = $this->executor->approve($this->transfer, $receipt, $this->user->id);

        // 1. Check receipt tax snapshot
        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approvedReceipt->status);
        $this->assertEquals($this->defaultTax->id, $approvedReceipt->tax_id);
        $this->assertEquals($this->defaultTax->name, $approvedReceipt->tax_name);
        $this->assertEquals('11.0000', (string) $approvedReceipt->tax_rate);
        $this->assertEquals(TransferRoutePolicy::PROVENANCE_DEFAULT, $approvedReceipt->tax_resolver_provenance);

        // 2. Check origin stock updated in tax bucket
        $originStock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->origin->id)
            ->first();

        $this->assertEquals(10, $originStock->quantity);
        $this->assertEquals(10, $originStock->quantity_tax);
        $this->assertEquals(0, $originStock->quantity_non_tax);

        // Global product quantity updated
        $this->product->refresh();
        $this->assertEquals(10, $this->product->product_quantity);

        // 3. Positive transaction created
        $trx = Transaction::where('product_id', $this->product->id)
            ->where('location_id', $this->origin->id)
            ->where('type', 'TRF')
            ->first();

        $this->assertNotNull($trx);
        $this->assertEquals(10, $trx->quantity);

        // 4. Source reservation closed
        $res = TransferReturnObligationReservation::where('transfer_movement_id', $this->dispatchMovement->id)->first();
        $this->assertEquals(TransferReturnObligationReservation::STATUS_CLOSED, $res->status);

        // 5. Obligation fulfilled
        $this->obligation->refresh();
        $this->assertEquals('10.0000', $this->obligation->returned_quantity);
        $this->assertEquals(TransferMovementReturnObligation::STATUS_FULFILLED, $this->obligation->status);

        // 6. Transfer completed
        $this->transfer->refresh();
        $this->assertEquals(Transfer::STATUS_COMPLETED, $this->transfer->status);
    }

    public function test_it_relocates_live_serial_and_clears_in_transit_custody_and_claims(): void
    {
        $sn = ProductSerialNumber::create([
            'product_id'          => $this->serializedProduct->id,
            'serial_number'       => 'SN-RECEIPT-LIVE-01',
            'status'              => ProductSerialNumber::STATUS_ACTIVE,
            'location_id'         => $this->destination->id,
            'custody_movement_id' => $this->dispatchMovement->id,
            'custody_transfer_id' => $this->transfer->id,
            'tax_id'              => null,
        ]);

        $serializedLine = TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
        ]);

        $movSerial = TransferMovementSerial::create([
            'transfer_movement_id'      => $this->dispatchMovement->id,
            'transfer_movement_line_id' => $serializedLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn->id,
            'serial_number'             => $sn->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
            'transit_custody_status'    => TransferMovementSerial::CUSTODY_IN_TRANSIT,
        ]);

        $claim = TransferActiveSerialClaim::create([
            'product_serial_number_id'    => $sn->id,
            'transfer_movement_id'        => $this->dispatchMovement->id,
            'transfer_movement_serial_id' => $movSerial->id,
        ]);

        $serializedObligation = TransferMovementReturnObligation::create([
            'transfer_id'              => $this->transfer->id,
            'transfer_route_policy_id' => $this->policy->id,
            'receipt_movement_id'      => $this->forwardReceipt->id,
            'product_id'               => $this->serializedProduct->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => '1.0000',
            'returned_quantity'        => '0.0000',
            'status'                   => TransferMovementReturnObligation::STATUS_OUTSTANDING,
        ]);

        TransferReturnObligationReservation::create([
            'transfer_movement_return_obligation_id' => $serializedObligation->id,
            'transfer_movement_id'                   => $this->dispatchMovement->id,
            'transfer_movement_line_id'              => $serializedLine->id,
            'quantity'                                => '1.0000',
            'status'                                  => TransferReturnObligationReservation::STATUS_ACTIVE,
            'created_by'                              => $this->user->id,
        ]);

        // Create submitted return receipt with exact matching items
        $receipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->destination->id,
            'destination_location_id' => $this->origin->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'return_batch_id'         => $this->dispatchMovement->return_batch_id,
            'source_movement_id'      => $this->dispatchMovement->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->product->id,
            'quantity'             => 10,
        ]);

        $receiptSerialLine = TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receipt->id,
            'transfer_movement_line_id' => $receiptSerialLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn->id,
            'serial_number'             => $sn->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        $this->executor->approve($this->transfer, $receipt, $this->user->id);

        // Verify serial status relocated to origin and tax identity applied
        $sn->refresh();
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $sn->status);
        $this->assertEquals($this->origin->id, $sn->location_id);
        $this->assertEquals($this->defaultTax->id, $sn->tax_id);

        // Active claim must be deleted
        $this->assertDatabaseMissing('transfer_active_serial_claims', [
            'id' => $claim->id,
        ]);
    }

    public function test_non_pkp_origin_resolves_to_non_tax_bucket(): void
    {
        // Change origin setting to non-PKP
        $this->originSetting->update(['is_pkp' => false]);

        $receipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->destination->id,
            'destination_location_id' => $this->origin->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'return_batch_id'         => $this->dispatchMovement->return_batch_id,
            'source_movement_id'      => $this->dispatchMovement->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->product->id,
            'quantity'             => 10,
        ]);

        $approvedReceipt = $this->executor->approve($this->transfer, $receipt, $this->user->id);

        $this->assertNull($approvedReceipt->tax_id);
        $this->assertNull($approvedReceipt->tax_name);
        $this->assertNull($approvedReceipt->tax_rate);
        $this->assertNull($approvedReceipt->tax_resolver_provenance);

        $originStock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->origin->id)
            ->first();

        $this->assertEquals(10, $originStock->quantity);
        $this->assertEquals(10, $originStock->quantity_non_tax);
        $this->assertEquals(0, $originStock->quantity_tax);
    }

    public function test_approval_rejects_and_rolls_back_if_live_serial_condition_drifts(): void
    {
        $sn = ProductSerialNumber::create([
            'product_id'          => $this->serializedProduct->id,
            'serial_number'       => 'SN-DRIFT-COND-01',
            'status'              => ProductSerialNumber::STATUS_ACTIVE,
            'location_id'         => $this->destination->id,
            'is_broken'           => false,
            'tax_id'              => null,
        ]);

        $serializedLine = TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
        ]);

        $movSerial = TransferMovementSerial::create([
            'transfer_movement_id'      => $this->dispatchMovement->id,
            'transfer_movement_line_id' => $serializedLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn->id,
            'serial_number'             => $sn->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
            'transit_custody_status'    => TransferMovementSerial::CUSTODY_IN_TRANSIT,
        ]);

        $claim = TransferActiveSerialClaim::create([
            'product_serial_number_id'    => $sn->id,
            'transfer_movement_id'        => $this->dispatchMovement->id,
            'transfer_movement_serial_id' => $movSerial->id,
        ]);

        $serializedObligation = TransferMovementReturnObligation::create([
            'transfer_id'              => $this->transfer->id,
            'transfer_route_policy_id' => $this->policy->id,
            'receipt_movement_id'      => $this->forwardReceipt->id,
            'product_id'               => $this->serializedProduct->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => '1.0000',
            'returned_quantity'        => '0.0000',
            'status'                   => TransferMovementReturnObligation::STATUS_OUTSTANDING,
        ]);

        TransferReturnObligationReservation::create([
            'transfer_movement_return_obligation_id' => $serializedObligation->id,
            'transfer_movement_id'                   => $this->dispatchMovement->id,
            'transfer_movement_line_id'              => $serializedLine->id,
            'quantity'                                => '1.0000',
            'status'                                  => TransferReturnObligationReservation::STATUS_ACTIVE,
            'created_by'                              => $this->user->id,
        ]);

        $receipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->destination->id,
            'destination_location_id' => $this->origin->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'return_batch_id'         => $this->dispatchMovement->return_batch_id,
            'source_movement_id'      => $this->dispatchMovement->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->product->id,
            'quantity'             => 10,
        ]);

        $receiptSerialLine = TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receipt->id,
            'transfer_movement_line_id' => $receiptSerialLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn->id,
            'serial_number'             => $sn->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        // Drift condition: serial becomes broken while transfer expects GOOD
        $sn->update(['is_broken' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('berstatus barang rusak padahal transfer berstatus GOOD');

        $this->executor->approve($this->transfer, $receipt, $this->user->id);
    }

    public function test_approval_rejects_and_rolls_back_if_live_serial_tax_identity_drifts(): void
    {
        $sn = ProductSerialNumber::create([
            'product_id'          => $this->serializedProduct->id,
            'serial_number'       => 'SN-DRIFT-TAX-01',
            'status'              => ProductSerialNumber::STATUS_ACTIVE,
            'location_id'         => $this->destination->id,
            'is_broken'           => false,
            'tax_id'              => null, // Dispatched as non-tax
        ]);

        $serializedLine = TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
        ]);

        $movSerial = TransferMovementSerial::create([
            'transfer_movement_id'      => $this->dispatchMovement->id,
            'transfer_movement_line_id' => $serializedLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn->id,
            'serial_number'             => $sn->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
            'transit_custody_status'    => TransferMovementSerial::CUSTODY_IN_TRANSIT,
            'tax_id'                    => null, // Dispatched as non-tax snapshot
        ]);

        $claim = TransferActiveSerialClaim::create([
            'product_serial_number_id'    => $sn->id,
            'transfer_movement_id'        => $this->dispatchMovement->id,
            'transfer_movement_serial_id' => $movSerial->id,
        ]);

        $serializedObligation = TransferMovementReturnObligation::create([
            'transfer_id'              => $this->transfer->id,
            'transfer_route_policy_id' => $this->policy->id,
            'receipt_movement_id'      => $this->forwardReceipt->id,
            'product_id'               => $this->serializedProduct->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => '1.0000',
            'returned_quantity'        => '0.0000',
            'status'                   => TransferMovementReturnObligation::STATUS_OUTSTANDING,
        ]);

        TransferReturnObligationReservation::create([
            'transfer_movement_return_obligation_id' => $serializedObligation->id,
            'transfer_movement_id'                   => $this->dispatchMovement->id,
            'transfer_movement_line_id'              => $serializedLine->id,
            'quantity'                                => '1.0000',
            'status'                                  => TransferReturnObligationReservation::STATUS_ACTIVE,
            'created_by'                              => $this->user->id,
        ]);

        $receipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->destination->id,
            'destination_location_id' => $this->origin->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'return_batch_id'         => $this->dispatchMovement->return_batch_id,
            'source_movement_id'      => $this->dispatchMovement->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->product->id,
            'quantity'             => 10,
        ]);

        $receiptSerialLine = TransferMovementLine::create([
            'transfer_movement_id' => $receipt->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receipt->id,
            'transfer_movement_line_id' => $receiptSerialLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn->id,
            'serial_number'             => $sn->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        // Drift tax: serial tax_id mutated in database
        $sn->update(['tax_id' => $this->defaultTax->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Identitas pajak nomor seri SN-DRIFT-TAX-01 telah berubah');

        $this->executor->approve($this->transfer, $receipt, $this->user->id);
    }
}

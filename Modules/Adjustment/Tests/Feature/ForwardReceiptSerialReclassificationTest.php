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

class ForwardReceiptSerialReclassificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $originSetting;
    protected Setting $destSetting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;
    protected ProductSerialNumber $serial1;
    protected ProductSerialNumber $serial2;
    protected Tax $tax;
    protected Transfer $transfer;
    protected TransferMovement $dispatchMovement;
    protected ForwardReceiptApprovalExecutor $approvalExecutor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tax = Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_active' => true, 'is_default' => true]);

        $this->originSetting = Setting::factory()->create(['is_pkp' => false]);
        $this->destSetting = Setting::factory()->create(['is_pkp' => true]);
        $this->origin = Location::create(['setting_id' => $this->originSetting->id, 'name' => 'Origin']);
        $this->destination = Location::create(['setting_id' => $this->destSetting->id, 'name' => 'Destination']);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->product = Product::create([
            'product_name'           => 'Serialized Widget',
            'product_code'           => 'SRL-001',
            'setting_id'             => $this->originSetting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 2,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->product->id,
            'location_id'             => $this->destination->id,
            'quantity'                => 0,
            'quantity_non_tax'        => 0,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $this->serial1 = ProductSerialNumber::create([
            'product_id'    => $this->product->id,
            'serial_number' => 'SRL-SN-001',
            'status'        => 'active',
            'location_id'   => $this->destination->id, // will be moved to origin below via update flow, use origin at dispatch
        ]);
        $this->serial1->update(['location_id' => $this->origin->id]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $this->createRoutePolicySnapshot(
            $this->transfer,
            $this->origin,
            $this->destination,
            $this->user,
            1,
            TransferRoutePolicy::CLASSIFICATION_TAX,
            true,
            $this->tax
        );

        $this->dispatchMovement = TransferMovement::create([
            'transfer_id'              => $this->transfer->id,
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
            'product_id'                    => $this->product->id,
            'setting_id'                    => $this->originSetting->id,
            'type'                          => 'TRF',
            'quantity'                      => -1,
            'current_quantity'              => 0,
            'broken_quantity'               => 0,
            'previous_quantity'             => 1,
            'previous_quantity_at_location' => 1,
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
            'transfer_movement_id'            => $this->dispatchMovement->id,
            'product_id'                      => $this->product->id,
            'quantity'                        => 1,
            'applied_quantity_tax'            => 0,
            'applied_quantity_non_tax'        => 1,
            'applied_quantity_broken_tax'     => 0,
            'applied_quantity_broken_non_tax' => 0,
            'inventory_transaction_reference' => (string) $trx->id,
        ]);

        $dispatchSerial = TransferMovementSerial::create([
            'transfer_movement_id'      => $this->dispatchMovement->id,
            'transfer_movement_line_id' => $dispatchLine->id,
            'product_id'                => $this->product->id,
            'product_serial_number_id'  => $this->serial1->id,
            'serial_number'             => $this->serial1->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
            'tax_id'                    => null,
            'tax_name'                  => null,
            'tax_rate'                  => null,
            'transit_custody_status'    => TransferMovementSerial::CUSTODY_IN_TRANSIT,
            'origin_location_id'        => $this->origin->id,
            'destination_location_id'   => $this->destination->id,
        ]);

        TransferActiveSerialClaim::create([
            'product_serial_number_id'      => $this->serial1->id,
            'transfer_movement_id'          => $this->dispatchMovement->id,
            'transfer_movement_serial_id'   => $dispatchSerial->id,
        ]);

        $this->approvalExecutor = app(ForwardReceiptApprovalExecutor::class);
    }

    public function test_serialized_receipt_assigns_destination_tax_and_preserves_prior_tax_history(): void
    {
        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $receiptLine = TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->product->id,
            'quantity'             => 1,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receiptMovement->id,
            'transfer_movement_line_id' => $receiptLine->id,
            'product_id'                => $this->product->id,
            'product_serial_number_id'  => $this->serial1->id,
            'serial_number'             => $this->serial1->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
            'tax_id'                    => null,
            'tax_name'                  => null,
            'tax_rate'                  => null,
        ]);

        $approved = $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);

        $approvedSerial = $approved->lines->first()->serials->first();
        $this->assertEquals($this->tax->id, $approvedSerial->tax_id);
        $this->assertEquals($this->tax->name, $approvedSerial->tax_name);
        $this->assertNull($approvedSerial->previous_tax_id);

        $liveSerial = ProductSerialNumber::find($this->serial1->id);
        $this->assertEquals($this->tax->id, $liveSerial->tax_id);
        $this->assertEquals((int) $this->destination->id, (int) $liveSerial->location_id);
    }
}

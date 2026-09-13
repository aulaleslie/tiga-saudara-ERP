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
use Modules\Adjustment\Services\ForwardReceiptApprovalExecutor;
use Modules\Adjustment\Services\ForwardReceiptPreparationService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use RuntimeException;
use Tests\TestCase;

class ForwardReceiptCustodyAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $serializedProduct;
    protected ProductSerialNumber $serial1;
    protected ProductSerialNumber $serial2;
    protected Transfer $transfer;
    protected TransferMovement $dispatchMovement;
    protected ForwardReceiptApprovalExecutor $approvalExecutor;
    protected ForwardReceiptPreparationService $preparationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create(['is_pkp' => false]);

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Location',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Location',
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->serializedProduct = Product::create([
            'product_name'           => 'Serialized Unit',
            'product_code'           => 'SN-UNIT',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 0,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);

        // Destination stock placeholder
        ProductStock::create([
            'product_id'              => $this->serializedProduct->id,
            'location_id'             => $this->destination->id,
            'quantity'                => 0,
            'quantity_non_tax'        => 0,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $this->serial1 = ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'serial_number' => 'SN-CUSTODY-001',
            'status'        => 'active',
            'location_id'   => $this->origin->id,
        ]);

        $this->serial2 = ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'serial_number' => 'SN-CUSTODY-002',
            'status'        => 'active',
            'location_id'   => $this->origin->id,
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

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

        $trx = \Modules\Product\Entities\Transaction::create([
            'product_id'                    => $this->serializedProduct->id,
            'setting_id'                    => $this->setting->id,
            'type'                          => 'TRF',
            'quantity'                      => -2,
            'current_quantity'              => 0,
            'broken_quantity'               => 0,
            'previous_quantity'             => 2,
            'previous_quantity_at_location' => 2,
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
            'product_id'                      => $this->serializedProduct->id,
            'quantity'                        => 2,
            'applied_quantity_tax'            => 0,
            'applied_quantity_non_tax'        => 2,
            'applied_quantity_broken_tax'     => 0,
            'applied_quantity_broken_non_tax' => 0,
            'inventory_transaction_reference' => (string) $trx->id,
        ]);

        // Dispatched serials in transit custody
        $dispatchSerial1 = TransferMovementSerial::create([
            'transfer_movement_id'      => $this->dispatchMovement->id,
            'transfer_movement_line_id' => $dispatchLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $this->serial1->id,
            'serial_number'             => $this->serial1->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
            'transit_custody_status'    => TransferMovementSerial::CUSTODY_IN_TRANSIT,
        ]);

        $dispatchSerial2 = TransferMovementSerial::create([
            'transfer_movement_id'      => $this->dispatchMovement->id,
            'transfer_movement_line_id' => $dispatchLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $this->serial2->id,
            'serial_number'             => $this->serial2->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
            'transit_custody_status'    => TransferMovementSerial::CUSTODY_IN_TRANSIT,
        ]);

        // Active claims exist
        TransferActiveSerialClaim::create([
            'transfer_movement_id'        => $this->dispatchMovement->id,
            'transfer_movement_serial_id' => $dispatchSerial1->id,
            'product_serial_number_id'    => $this->serial1->id,
        ]);

        TransferActiveSerialClaim::create([
            'transfer_movement_id'        => $this->dispatchMovement->id,
            'transfer_movement_serial_id' => $dispatchSerial2->id,
            'product_serial_number_id'    => $this->serial2->id,
        ]);

        $this->approvalExecutor = app(ForwardReceiptApprovalExecutor::class);
        $this->preparationService = app(ForwardReceiptPreparationService::class);
    }

    public function test_approving_exact_serials_updates_location_closes_custody_and_removes_claims(): void
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
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 2,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receiptMovement->id,
            'transfer_movement_line_id' => $receiptLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $this->serial1->id,
            'serial_number'             => $this->serial1->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receiptMovement->id,
            'transfer_movement_line_id' => $receiptLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $this->serial2->id,
            'serial_number'             => $this->serial2->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);

        // Check active serial claims removed
        $this->assertCount(0, TransferActiveSerialClaim::where('transfer_movement_id', $this->dispatchMovement->id)->get());

        // Check custody closed on dispatch serial records
        $this->dispatchMovement->refresh();
        foreach ($this->dispatchMovement->serials as $serialRecord) {
            $this->assertEquals(TransferMovementSerial::CUSTODY_CLOSED, $serialRecord->transit_custody_status);
        }

        // Check live serial locations updated to destination
        $this->serial1->refresh();
        $this->serial2->refresh();
        $this->assertEquals($this->destination->id, $this->serial1->location_id);
        $this->assertEquals($this->destination->id, $this->serial2->location_id);
    }

    public function test_approving_substitute_or_mismatched_serial_fails_and_preserves_custody(): void
    {
        $otherSerial = ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'serial_number' => 'SN-SUBSTITUTE-999',
            'status'        => 'active',
            'location_id'   => $this->destination->id,
        ]);

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
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 2,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receiptMovement->id,
            'transfer_movement_line_id' => $receiptLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $this->serial1->id,
            'serial_number'             => $this->serial1->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receiptMovement->id,
            'transfer_movement_line_id' => $receiptLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $otherSerial->id,
            'serial_number'             => $otherSerial->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forward receipt cannot be approved');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    public function test_approving_receipt_with_crafted_text_but_null_or_mismatched_serial_id_fails(): void
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
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 2,
        ]);

        // Serial 1 has correct text and correct ID
        TransferMovementSerial::create([
            'transfer_movement_id'      => $receiptMovement->id,
            'transfer_movement_line_id' => $receiptLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $this->serial1->id,
            'serial_number'             => $this->serial1->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        // Serial 2 has correct text but NULL product_serial_number_id (adversarial observation)
        TransferMovementSerial::create([
            'transfer_movement_id'      => $receiptMovement->id,
            'transfer_movement_line_id' => $receiptLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => null,
            'serial_number'             => $this->serial2->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forward receipt cannot be approved');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    public function test_approving_fails_if_live_serial_text_mutates_after_dispatch(): void
    {
        $receiptMovement = $this->createValidReceiptMovement();

        // Mutate live serial text
        $this->serial1->update(['serial_number' => 'SN-MUTATED-999']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Live serial text');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    public function test_approving_fails_if_live_serial_product_mutates_after_dispatch(): void
    {
        $receiptMovement = $this->createValidReceiptMovement();

        $otherProduct = Product::create([
            'product_name'           => 'Other Serialized Product',
            'product_code'           => 'SN-OTHER-PROD',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $this->serializedProduct->unit_id,
            'product_quantity'       => 0,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);

        // Mutate live serial product
        $this->serial1->update(['product_id' => $otherProduct->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belongs to product ID');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    public function test_approving_fails_if_live_serial_location_mutates_after_dispatch(): void
    {
        $receiptMovement = $this->createValidReceiptMovement();

        // Mutate live serial location to destination prematurely or elsewhere
        $this->serial1->update(['location_id' => $this->destination->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match origin location');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    public function test_approving_fails_if_live_serial_condition_mutates_after_dispatch(): void
    {
        $receiptMovement = $this->createValidReceiptMovement();

        // Mutate live serial condition to broken while transfer is GOOD
        $this->serial1->update(['is_broken' => true, 'status' => 'broken']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is broken but transfer condition is GOOD');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    public function test_approving_fails_if_active_claim_is_missing_or_mismatched(): void
    {
        $receiptMovement = $this->createValidReceiptMovement();

        // Delete active claim for serial1
        TransferActiveSerialClaim::where('product_serial_number_id', $this->serial1->id)->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak memiliki klaim transit aktif');

        $this->approvalExecutor->approve($this->transfer, $receiptMovement, $this->user->id);
    }

    private function createValidReceiptMovement(): TransferMovement
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
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 2,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receiptMovement->id,
            'transfer_movement_line_id' => $receiptLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $this->serial1->id,
            'serial_number'             => $this->serial1->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        TransferMovementSerial::create([
            'transfer_movement_id'      => $receiptMovement->id,
            'transfer_movement_line_id' => $receiptLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $this->serial2->id,
            'serial_number'             => $this->serial2->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        return $receiptMovement;
    }
}

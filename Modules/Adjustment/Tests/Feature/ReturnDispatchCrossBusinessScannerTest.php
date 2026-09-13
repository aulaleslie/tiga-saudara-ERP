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
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

/**
 * A cross-business transfer moves stock between two different tenant settings. The obligated
 * product is catalogued exclusively under the origin business's setting; the return-dispatch
 * batch is prepared at the destination business, whose scan resolver is otherwise scoped to
 * its own setting_id. Without a fallback, the destination operator could never scan the
 * canonical product's barcode or a substitute serial belonging to that same catalogue entry.
 */
class ReturnDispatchCrossBusinessScannerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $originSetting;
    protected Setting $destinationSetting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;
    protected Product $serializedProduct;
    protected Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->originSetting = Setting::factory()->create(['is_pkp' => false]);
        $this->destinationSetting = Setting::factory()->create(['is_pkp' => false]);

        $this->origin = Location::create(['setting_id' => $this->originSetting->id, 'name' => 'Origin Business Location']);
        $this->destination = Location::create(['setting_id' => $this->destinationSetting->id, 'name' => 'Destination Business Location']);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        // Canonical product catalogued ONLY under the origin business setting.
        $this->product = Product::create([
            'product_name'           => 'Cross Business Return Item',
            'product_code'           => 'CBRI-001',
            'barcode'                => 'CBRI-BARCODE-001',
            'setting_id'             => $this->originSetting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 10,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        $this->serializedProduct = Product::create([
            'product_name'           => 'Cross Business Return Gadget',
            'product_code'           => 'CBRG-001',
            'setting_id'             => $this->originSetting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 1,
            'product_cost'           => 2000,
            'product_price'          => 3000,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);

        // Stock and the live serial physically reside at the destination location (having arrived
        // there via the forward leg), even though the catalogue entry belongs to the origin setting.
        ProductStock::create([
            'product_id'              => $this->product->id,
            'location_id'             => $this->destination->id,
            'quantity'                => 10,
            'quantity_non_tax'        => 10,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        ProductStock::create([
            'product_id'              => $this->serializedProduct->id,
            'location_id'             => $this->destination->id,
            'quantity'                => 1,
            'quantity_non_tax'        => 1,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->destination->id,
            'serial_number' => 'CBRG-SN-001',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
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
            1, TransferRoutePolicy::CLASSIFICATION_PRESERVE, true
        );

        $receiptMovement = TransferMovement::create([
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

        foreach ([$this->product, $this->serializedProduct] as $product) {
            TransferMovementReturnObligation::create([
                'transfer_id'              => $this->transfer->id,
                'transfer_route_policy_id' => $policy->id,
                'receipt_movement_id'      => $receiptMovement->id,
                'product_id'               => $product->id,
                'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
                'required_quantity'        => '5.0000',
            ]);
        }
    }

    /** @test */
    public function destination_operator_can_scan_a_barcode_catalogued_only_under_the_origin_business(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);

        // Scanning with the DESTINATION business's setting_id (the tenant actually preparing the
        // batch) must still resolve a barcode that only exists in the origin business's catalogue.
        $result = $prep->applyScan(
            $batch,
            'CBRI-BARCODE-001',
            $this->destinationSetting->id,
            $batch->lock_version,
            $this->user->id
        );

        $this->assertEquals('resolved', $result['status']);
        $line = collect($result['projection']['lines'])->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($line);
        $this->assertSame('1.0000', $line['quantity']);
    }

    /** @test */
    public function destination_operator_can_scan_a_serial_catalogued_only_under_the_origin_business_and_approve_it(): void
    {
        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        $batch = $prep->getOrCreateBatch($this->transfer, $this->user->id);

        $result = $prep->applyScan(
            $batch,
            'CBRG-SN-001',
            $this->destinationSetting->id,
            $batch->lock_version,
            $this->user->id
        );

        $this->assertEquals('resolved', $result['status']);

        $batch = $batch->fresh(['lines.serials']);
        $pending = $prep->submit($batch, $batch->lock_version, $this->user->id);
        $approved = $executor->approve($this->transfer, $pending, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approved->status);

        $obligation = TransferMovementReturnObligation::where('transfer_id', $this->transfer->id)
            ->where('product_id', $this->serializedProduct->id)
            ->firstOrFail();
        $obligation->load('activeReservations');
        $this->assertSame('1.0000', $obligation->activeInTransitQuantity());
    }

    private function givePrivilegedUserPermission(User $user): void
    {
        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name'       => \Modules\Adjustment\Services\TransferStockVisibility::PERMISSION,
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo(\Modules\Adjustment\Services\TransferStockVisibility::PERMISSION);
    }

    /** @test */
    public function blind_user_scanning_a_serial_relocated_away_from_destination_receives_a_neutral_message(): void
    {
        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id, // never arrived at the destination
            'serial_number' => 'CBRG-SN-RELOCATED',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
        ]);

        $privilegedUser = User::factory()->create();
        $this->givePrivilegedUserPermission($privilegedUser);

        $prep = app(ReturnDispatchPreparationService::class);

        $blindBatch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $blindResult = $prep->applyScan($blindBatch, 'CBRG-SN-RELOCATED', $this->destinationSetting->id, $blindBatch->lock_version, $this->user->id, false);

        $this->assertEquals('rejected', $blindResult['status']);
        $this->assertSame('Item yang dipindai tidak dapat digunakan untuk batch retur ini.', $blindResult['message']);
        $this->assertStringNotContainsString('location', mb_strtolower($blindResult['message']));

        $privBatch = $prep->getOrCreateBatch($this->transfer, $privilegedUser->id, $blindBatch->return_batch_id);
        $privResult = $prep->applyScan($privBatch, 'CBRG-SN-RELOCATED', $this->destinationSetting->id, $privBatch->lock_version, $privilegedUser->id, true);

        $this->assertEquals('rejected', $privResult['status']);
        $this->assertStringContainsString('location', mb_strtolower($privResult['message']));
    }

    /** @test */
    public function blind_user_scanning_a_serial_already_under_active_custody_receives_a_neutral_message(): void
    {
        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->destination->id,
            'serial_number' => 'CBRG-SN-CLAIMED',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
        ]);
        $claimedSerial = ProductSerialNumber::where('serial_number', 'CBRG-SN-CLAIMED')->firstOrFail();

        $prep = app(ReturnDispatchPreparationService::class);
        $executor = app(ReturnDispatchApprovalExecutor::class);

        // First batch legitimately claims the serial via approval.
        $claimingBatch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $claimResult = $prep->applyScan($claimingBatch, 'CBRG-SN-CLAIMED', $this->destinationSetting->id, $claimingBatch->lock_version, $this->user->id);
        $this->assertEquals('resolved', $claimResult['status']);
        $claimingBatch = $claimingBatch->fresh(['lines.serials']);
        $pending = $prep->submit($claimingBatch, $claimingBatch->lock_version, $this->user->id);
        $executor->approve($this->transfer, $pending, $this->user->id);

        $privilegedUser = User::factory()->create();
        $this->givePrivilegedUserPermission($privilegedUser);

        // A second, independent batch attempts to scan the now-claimed serial.
        $blindBatch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $blindResult = $prep->applyScan($blindBatch, 'CBRG-SN-CLAIMED', $this->destinationSetting->id, $blindBatch->lock_version, $this->user->id, false);

        $this->assertEquals('rejected', $blindResult['status']);
        $this->assertSame('Item yang dipindai tidak dapat digunakan untuk batch retur ini.', $blindResult['message']);
        $this->assertStringNotContainsString('transit', mb_strtolower($blindResult['message']));

        $privBatch = $prep->getOrCreateBatch($this->transfer, $privilegedUser->id, $blindBatch->return_batch_id);
        $privResult = $prep->applyScan($privBatch, 'CBRG-SN-CLAIMED', $this->destinationSetting->id, $privBatch->lock_version, $privilegedUser->id, true);

        $this->assertEquals('rejected', $privResult['status']);
        $this->assertStringContainsString('transit', mb_strtolower($privResult['message']));
    }

    /** @test */
    public function blind_user_scanning_a_broken_serial_on_a_good_condition_batch_receives_a_neutral_message(): void
    {
        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->destination->id,
            'serial_number' => 'CBRG-SN-BROKEN',
            'status'        => ProductSerialNumber::STATUS_BROKEN,
            'is_broken'     => true,
        ]);

        $privilegedUser = User::factory()->create();
        $this->givePrivilegedUserPermission($privilegedUser);

        $prep = app(ReturnDispatchPreparationService::class);

        // The batch's stock_condition is GOOD (set up transfer-wide), so a broken serial is
        // ineligible; updateDraft's live-serial revalidation should catch this.
        $blindBatch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $blindResult = $prep->applyScan($blindBatch, 'CBRG-SN-BROKEN', $this->destinationSetting->id, $blindBatch->lock_version, $this->user->id, false);

        $this->assertEquals('rejected', $blindResult['status']);
        $this->assertSame('Item yang dipindai tidak dapat digunakan untuk batch retur ini.', $blindResult['message']);
        $this->assertStringNotContainsString('broken', mb_strtolower($blindResult['message']));

        $privBatch = $prep->getOrCreateBatch($this->transfer, $privilegedUser->id, $blindBatch->return_batch_id);
        $privResult = $prep->applyScan($privBatch, 'CBRG-SN-BROKEN', $this->destinationSetting->id, $privBatch->lock_version, $privilegedUser->id, true);

        $this->assertEquals('rejected', $privResult['status']);
        $this->assertStringContainsString('broken', mb_strtolower($privResult['message']));
    }

    /** @test */
    public function blind_user_scanning_a_serial_with_mismatched_tax_classification_receives_a_neutral_message(): void
    {
        $tax = \Modules\Setting\Entities\Tax::create(['name' => 'PPN', 'value' => 11, 'is_active' => true, 'is_default' => false]);

        // A separate TAX-classified transfer, since the fixture's shared transfer already has a
        // PRESERVE-classified route policy (which cannot be swapped in place: it is immutable and
        // referenced by the fixture's obligation rows).
        $taxTransfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_AWAITING_RETURN,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $policy = $this->createRoutePolicySnapshot(
            $taxTransfer, $this->origin, $this->destination, $this->user,
            1, TransferRoutePolicy::CLASSIFICATION_TAX, true, $tax
        );

        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $taxTransfer->id,
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
            'transfer_id'              => $taxTransfer->id,
            'transfer_route_policy_id' => $policy->id,
            'receipt_movement_id'      => $receiptMovement->id,
            'product_id'               => $this->serializedProduct->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => '5.0000',
        ]);

        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->destination->id,
            'serial_number' => 'CBRG-SN-NONTAX',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
            'tax_id'        => null,
        ]);

        $privilegedUser = User::factory()->create();
        $this->givePrivilegedUserPermission($privilegedUser);

        $prep = app(ReturnDispatchPreparationService::class);

        $blindBatch = $prep->getOrCreateBatch($taxTransfer, $this->user->id);
        $blindResult = $prep->applyScan($blindBatch, 'CBRG-SN-NONTAX', $this->destinationSetting->id, $blindBatch->lock_version, $this->user->id, false);

        $this->assertEquals('rejected', $blindResult['status']);
        $this->assertSame('Item yang dipindai tidak dapat digunakan untuk batch retur ini.', $blindResult['message']);
        $this->assertStringNotContainsString('tax classification', mb_strtolower($blindResult['message']));

        $privBatch = $prep->getOrCreateBatch($taxTransfer, $privilegedUser->id, $blindBatch->return_batch_id);
        $privResult = $prep->applyScan($privBatch, 'CBRG-SN-NONTAX', $this->destinationSetting->id, $privBatch->lock_version, $privilegedUser->id, true);

        $this->assertEquals('rejected', $privResult['status']);
        $this->assertStringContainsString('tax classification', mb_strtolower($privResult['message']));
    }

    /** @test */
    public function blind_user_scanning_a_non_obligated_product_barcode_receives_a_neutral_message(): void
    {
        $unit = Unit::create(['name' => 'Box', 'short_name' => 'bx']);
        $nonObligatedProduct = Product::create([
            'product_name'           => 'Non Obligated Product',
            'product_code'           => 'NOP-001',
            'barcode'                => 'NOP-BARCODE-001',
            'setting_id'             => $this->destinationSetting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 5,
            'product_cost'           => 500,
            'product_price'          => 800,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        $privilegedUser = User::factory()->create();
        $this->givePrivilegedUserPermission($privilegedUser);

        $prep = app(ReturnDispatchPreparationService::class);

        $blindBatch = $prep->getOrCreateBatch($this->transfer, $this->user->id);
        $blindResult = $prep->applyScan($blindBatch, 'NOP-BARCODE-001', $this->destinationSetting->id, $blindBatch->lock_version, $this->user->id, false);

        $this->assertEquals('rejected', $blindResult['status']);
        $this->assertSame('Item yang dipindai tidak dapat digunakan untuk batch retur ini.', $blindResult['message']);
        $this->assertStringNotContainsString((string) $nonObligatedProduct->id, $blindResult['message']);

        $privBatch = $prep->getOrCreateBatch($this->transfer, $privilegedUser->id, $blindBatch->return_batch_id);
        $privResult = $prep->applyScan($privBatch, 'NOP-BARCODE-001', $this->destinationSetting->id, $privBatch->lock_version, $privilegedUser->id, true);

        $this->assertEquals('rejected', $privResult['status']);
        $this->assertStringContainsString('kewajiban retur', mb_strtolower($privResult['message']));
    }
}

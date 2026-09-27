<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferLifecycleService;
use Modules\Adjustment\Services\TransferWorkflowEligibilityService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ForwardReceiptRouteActivationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $sameSetting;
    protected Setting $nonPkpSetting1;
    protected Setting $nonPkpSetting2;
    protected Setting $pkpSetting;
    protected TransferWorkflowEligibilityService $eligibilityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->sameSetting = Setting::factory()->create(['is_pkp' => false]);
        $this->nonPkpSetting1 = Setting::factory()->create(['is_pkp' => false]);
        $this->nonPkpSetting2 = Setting::factory()->create(['is_pkp' => false]);
        $this->pkpSetting = Setting::factory()->create(['is_pkp' => true]);

        $this->eligibilityService = app(TransferWorkflowEligibilityService::class);
    }

    public function test_same_business_route_is_eligible_for_v2(): void
    {
        $loc1 = Location::create(['setting_id' => $this->sameSetting->id, 'name' => 'Loc 1']);
        $loc2 = Location::create(['setting_id' => $this->sameSetting->id, 'name' => 'Loc 2']);

        $this->assertTrue($this->eligibilityService->isEligibleForV2($loc1, $loc2));
        $this->assertEquals(2, $this->eligibilityService->resolveWorkflowVersion($loc1, $loc2));
    }

    public function test_cross_business_non_pkp_to_non_pkp_is_eligible_for_v2(): void
    {
        $loc1 = Location::create(['setting_id' => $this->nonPkpSetting1->id, 'name' => 'Non-PKP Loc 1']);
        $loc2 = Location::create(['setting_id' => $this->nonPkpSetting2->id, 'name' => 'Non-PKP Loc 2']);

        $this->assertTrue($this->eligibilityService->isEligibleForV2($loc1, $loc2));
        $this->assertEquals(2, $this->eligibilityService->resolveWorkflowVersion($loc1, $loc2));
    }

    public function test_pkp_involved_routes_are_prospectively_eligible_for_v2(): void
    {
        $locPkp = Location::create(['setting_id' => $this->pkpSetting->id, 'name' => 'PKP Loc']);
        $locNonPkp = Location::create(['setting_id' => $this->nonPkpSetting1->id, 'name' => 'Non-PKP Loc']);

        // PKP origin -> Non-PKP destination
        $this->assertTrue($this->eligibilityService->isEligibleForV2($locPkp, $locNonPkp));
        $this->assertEquals(2, $this->eligibilityService->resolveWorkflowVersion($locPkp, $locNonPkp));

        // Non-PKP origin -> PKP destination
        $this->assertTrue($this->eligibilityService->isEligibleForV2($locNonPkp, $locPkp));
        $this->assertEquals(2, $this->eligibilityService->resolveWorkflowVersion($locNonPkp, $locPkp));
    }

    public function test_transfer_lifecycle_service_assigns_v2_when_eligible(): void
    {
        $loc1 = Location::create(['setting_id' => $this->nonPkpSetting1->id, 'name' => 'Loc A']);
        $loc2 = Location::create(['setting_id' => $this->nonPkpSetting2->id, 'name' => 'Loc B']);

        $lifecycleService = app(TransferLifecycleService::class);
        $transfer = $lifecycleService->createDraft(
            $loc1->id,
            $loc2->id,
            Transfer::CONDITION_GOOD,
            [],
            $this->user->id
        );

        $this->assertEquals(2, $transfer->workflow_version);
    }

    public function test_cross_business_non_pkp_end_to_end_dispatch_and_receipt(): void
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $originLoc = Location::create(['setting_id' => $this->nonPkpSetting1->id, 'name' => 'Origin Non-PKP']);
        $destLoc = Location::create(['setting_id' => $this->nonPkpSetting2->id, 'name' => 'Dest Non-PKP']);

        $originProduct = \Modules\Product\Entities\Product::create([
            'product_name'           => 'Origin Product',
            'product_code'           => 'ORIG-001',
            'barcode'                => 'BAR-ORIG-1',
            'setting_id'             => $this->nonPkpSetting1->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 5,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        $transfer = Transfer::create([
            'origin_location_id'      => $originLoc->id,
            'destination_location_id' => $destLoc->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $dispatchMovement = \Modules\Adjustment\Entities\TransferMovement::create([
            'transfer_id'              => $transfer->id,
            'type'                     => \Modules\Adjustment\Entities\TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                 => 1,
            'lock_version'             => 1,
            'transfer_revision'        => 1,
            'status'                   => \Modules\Adjustment\Entities\TransferMovement::STATUS_APPROVED,
            'origin_location_id'       => $originLoc->id,
            'destination_location_id'  => $destLoc->id,
            'stock_condition'          => \Modules\Adjustment\Entities\TransferMovement::CONDITION_GOOD,
            'created_by'               => $this->user->id,
            'reviewed_by'              => $this->user->id,
            'reviewed_at'              => now(),
        ]);

        $trx = \Modules\Product\Entities\Transaction::create([
            'product_id'                    => $originProduct->id,
            'setting_id'                    => $this->nonPkpSetting1->id,
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
            'location_id'                   => $originLoc->id,
            'user_id'                       => $this->user->id,
            'reason'                        => 'Dispatch test transaction',
        ]);

        \Modules\Adjustment\Entities\TransferMovementLine::create([
            'transfer_movement_id'            => $dispatchMovement->id,
            'product_id'                      => $originProduct->id,
            'quantity'                        => 5,
            'applied_quantity_tax'            => 0,
            'applied_quantity_non_tax'        => 5,
            'applied_quantity_broken_tax'     => 0,
            'applied_quantity_broken_non_tax' => 0,
            'inventory_transaction_reference' => (string) $trx->id,
        ]);

        \Modules\Adjustment\Entities\TransferRoutePolicy::create([
            'transfer_id'                => $transfer->id,
            'transfer_revision'          => 1,
            'origin_location_id'         => $originLoc->id,
            'destination_location_id'    => $destLoc->id,
            'origin_setting_id'          => $this->nonPkpSetting1->id,
            'destination_setting_id'     => $this->nonPkpSetting2->id,
            'origin_is_pkp'              => false,
            'destination_is_pkp'         => false,
            'same_business'              => false,
            'stock_condition'            => Transfer::CONDITION_GOOD,
            'destination_classification' => \Modules\Adjustment\Entities\TransferRoutePolicy::CLASSIFICATION_NON_TAX,
            'mandatory_return'           => false,
            'approved_by'                => $this->user->id,
            'approved_at'                => now(),
        ]);

        $prepService = app(\Modules\Adjustment\Services\ForwardReceiptPreparationService::class);
        $receiptMovement = $prepService->getOrCreateDraft($transfer, $this->user->id);

        // Scan barcode using destination setting context
        $scanRes = $prepService->applyScan($receiptMovement, 'BAR-ORIG-1', $this->nonPkpSetting2->id, $receiptMovement->lock_version, $this->user->id);
        $this->assertEquals('resolved', $scanRes['status']);

        // Set line quantity to 5
        $receiptMovement->refresh();
        $prepService->setLineQuantity($receiptMovement, $originProduct->id, 5, true, $receiptMovement->lock_version, $this->user->id);

        // Submit
        $receiptMovement->refresh();
        $submitted = $prepService->submit($receiptMovement, $receiptMovement->lock_version, $this->user->id);
        $this->assertEquals(\Modules\Adjustment\Entities\TransferMovement::STATUS_PENDING, $submitted->status);

        // Approve
        $approvalExecutor = app(\Modules\Adjustment\Services\ForwardReceiptApprovalExecutor::class);
        $approved = $approvalExecutor->approve($transfer, $submitted, $this->user->id);
        $this->assertEquals(\Modules\Adjustment\Entities\TransferMovement::STATUS_APPROVED, $approved->status);

        // Verify destination stock incremented
        $destStock = \Modules\Product\Entities\ProductStock::where('product_id', $originProduct->id)
            ->where('location_id', $destLoc->id)
            ->first();
        $this->assertNotNull($destStock);
        $this->assertEquals(5, $destStock->quantity);
    }
}

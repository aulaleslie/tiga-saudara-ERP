<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\ForwardDispatchApprovalExecutor;
use Modules\Adjustment\Services\ForwardDispatchPreparationService;
use Modules\Adjustment\Services\ForwardReceiptApprovalExecutor;
use Modules\Adjustment\Services\ForwardReceiptPreparationService;
use Modules\Adjustment\Services\ReturnDispatchApprovalExecutor;
use Modules\Adjustment\Services\ReturnDispatchPreparationService;
use Modules\Adjustment\Services\ReturnReceiptApprovalExecutor;
use Modules\Adjustment\Services\ReturnReceiptPreparationService;
use Modules\Adjustment\Services\TransferLifecycleService;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class CrossBusinessNonPkpReturnLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $originSetting;
    protected Setting $destSetting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;
    protected TransferLifecycleService $lifecycleService;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['stockTransfers.show', 'stockTransfers.view-system-stock'] as $permission) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['stockTransfers.show', 'stockTransfers.view-system-stock']);

        // Both businesses are non-PKP
        $this->originSetting = Setting::factory()->create(['is_pkp' => false, 'company_name' => 'Origin Non-PKP']);
        $this->destSetting = Setting::factory()->create(['is_pkp' => false, 'company_name' => 'Dest Non-PKP']);

        $this->origin = Location::create([
            'setting_id' => $this->originSetting->id,
            'name'       => 'Origin Warehouse',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->destSetting->id,
            'name'       => 'Destination Warehouse',
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->product = Product::create([
            'product_name'           => 'Non-PKP Transfer Item',
            'product_code'           => 'NP-001',
            'barcode'                => 'BAR-NP-001',
            'setting_id'             => $this->originSetting->id,
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
            'quantity_non_tax'        => 10,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $this->lifecycleService = app(TransferLifecycleService::class);
        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);
    }

    public function test_non_pkp_to_non_pkp_transfer_requires_full_return_lifecycle_until_completed(): void
    {
        // 1. Create draft, submit, and approve (snapshots route policy with mandatory_return = true)
        $transfer = $this->lifecycleService->createDraft(
            $this->origin->id,
            $this->destination->id,
            Transfer::CONDITION_GOOD,
            [
                ['product_id' => $this->product->id, 'quantity' => 5],
            ],
            $this->user->id
        );

        $transfer = $this->lifecycleService->submitDraft($transfer, $this->user->id);
        $transfer = $this->lifecycleService->approve($transfer, $this->user->id, $this->originSetting->id);

        $policy = TransferRoutePolicy::where('transfer_id', $transfer->id)->firstOrFail();
        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_NON_TAX, $policy->destination_classification);
        $this->assertTrue($policy->mandatory_return);
        $this->assertFalse($policy->same_business);

        // 2. Forward Dispatch
        $dispatchPrep = app(ForwardDispatchPreparationService::class);
        $dispatchMovement = $dispatchPrep->getOrCreateDraft($transfer, $this->user->id);
        $dispatchMovement = $dispatchPrep->setLineQuantity($dispatchMovement, $this->product->id, 5, true, $dispatchMovement->lock_version, $this->user->id);
        $pendingDispatch = $dispatchPrep->submit($dispatchMovement, $dispatchMovement->lock_version, $this->user->id);
        app(ForwardDispatchApprovalExecutor::class)->approve($transfer, $pendingDispatch, $this->user->id);

        $transfer->refresh();
        $this->assertEquals(Transfer::STATUS_DISPATCHED, $transfer->status);

        // 3. Forward Receipt Approval
        $receiptPrep = app(ForwardReceiptPreparationService::class);
        $receiptMovement = $receiptPrep->getOrCreateDraft($transfer, $this->user->id);
        $receiptMovement = $receiptPrep->setLineQuantity($receiptMovement, $this->product->id, 5, true, $receiptMovement->lock_version, $this->user->id);
        $pendingReceipt = $receiptPrep->submit($receiptMovement, $receiptMovement->lock_version, $this->user->id);
        $approvedReceipt = app(ForwardReceiptApprovalExecutor::class)->approve($transfer, $pendingReceipt, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approvedReceipt->status);

        // Verify status becomes AWAITING_RETURN and full obligation created
        $transfer->refresh();
        $this->assertEquals(Transfer::STATUS_AWAITING_RETURN, $transfer->status);

        $obligation = TransferMovementReturnObligation::where('transfer_id', $transfer->id)->firstOrFail();
        $this->assertEquals($this->product->id, $obligation->product_id);
        $this->assertEquals('5.0000', (string) $obligation->required_quantity);
        $this->assertEquals('0.0000', (string) $obligation->returned_quantity);
        $this->assertEquals(TransferMovementReturnObligation::STATUS_OUTSTANDING, $obligation->status);

        // Destination stock has 5 non-tax
        $destStock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->destination->id)
            ->first();
        $this->assertNotNull($destStock);
        $this->assertEquals(5, $destStock->quantity);
        $this->assertEquals(5, $destStock->quantity_non_tax);

        // 4. Return Dispatch
        $returnDispatchPrep = app(ReturnDispatchPreparationService::class);
        $returnBatch = $returnDispatchPrep->getOrCreateBatch($transfer, $this->user->id);
        $returnBatch = $returnDispatchPrep->setLineQuantity($returnBatch, $this->product->id, 5, true, $returnBatch->lock_version, $this->user->id);
        $pendingReturnDispatch = $returnDispatchPrep->submit($returnBatch, $returnBatch->lock_version, $this->user->id);
        $approvedReturnDispatch = app(ReturnDispatchApprovalExecutor::class)->approve($transfer, $pendingReturnDispatch, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approvedReturnDispatch->status);
        $transfer->refresh();
        $this->assertEquals(Transfer::STATUS_RETURN_DISPATCHED, $transfer->status);

        // 5. Return Receipt
        $returnReceiptPrep = app(ReturnReceiptPreparationService::class);
        $returnReceiptDraft = $returnReceiptPrep->getOrCreateDraft($transfer, $approvedReturnDispatch, $this->user->id);
        $returnReceiptDraft = $returnReceiptPrep->setLineQuantity($returnReceiptDraft, $this->product->id, 5, true, $returnReceiptDraft->lock_version, $this->user->id);
        $pendingReturnReceipt = $returnReceiptPrep->submit($returnReceiptDraft, $returnReceiptDraft->lock_version, $this->user->id);
        $approvedReturnReceipt = app(ReturnReceiptApprovalExecutor::class)->approve($transfer, $pendingReturnReceipt, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approvedReturnReceipt->status);

        // Verify obligation fulfilled and transfer completed
        $obligation->refresh();
        $this->assertEquals('5.0000', (string) $obligation->returned_quantity);
        $this->assertEquals(TransferMovementReturnObligation::STATUS_FULFILLED, $obligation->status);

        $transfer->refresh();
        $this->assertEquals(Transfer::STATUS_COMPLETED, $transfer->status);

        // Origin stock returned to 10 non-tax
        $originStock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->origin->id)
            ->first();
        $this->assertEquals(10, $originStock->quantity);
        $this->assertEquals(10, $originStock->quantity_non_tax);
    }

    public function test_previously_approved_no_return_snapshot_completes_at_forward_receipt_without_obligations(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

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

        $trx = \Modules\Product\Entities\Transaction::create([
            'product_id'                    => $this->product->id,
            'setting_id'                    => $this->originSetting->id,
            'type'                          => 'TRF',
            'quantity'                      => -5,
            'current_quantity'              => 5,
            'broken_quantity'               => 0,
            'previous_quantity'             => 10,
            'previous_quantity_at_location' => 10,
            'after_quantity'                => 5,
            'after_quantity_at_location'    => 5,
            'quantity_tax'                  => 0,
            'quantity_non_tax'              => 0,
            'broken_quantity_tax'           => 0,
            'broken_quantity_non_tax'       => 0,
            'location_id'                   => $this->origin->id,
            'user_id'                       => $this->user->id,
            'reason'                        => 'Dispatch test transaction',
        ]);

        \Modules\Adjustment\Entities\TransferMovementLine::create([
            'transfer_movement_id'            => $dispatchMovement->id,
            'product_id'                      => $this->product->id,
            'quantity'                        => 5,
            'applied_quantity_tax'            => 0,
            'applied_quantity_non_tax'        => 5,
            'applied_quantity_broken_tax'     => 0,
            'applied_quantity_broken_non_tax' => 0,
            'inventory_transaction_reference' => (string) $trx->id,
        ]);

        // Historical snapshot with mandatory_return = false
        $policy = TransferRoutePolicy::create([
            'transfer_id'                => $transfer->id,
            'transfer_revision'          => 1,
            'origin_location_id'         => $this->origin->id,
            'destination_location_id'    => $this->destination->id,
            'origin_setting_id'          => $this->originSetting->id,
            'destination_setting_id'     => $this->destSetting->id,
            'origin_is_pkp'              => false,
            'destination_is_pkp'         => false,
            'same_business'              => false,
            'stock_condition'            => Transfer::CONDITION_GOOD,
            'destination_classification' => TransferRoutePolicy::CLASSIFICATION_NON_TAX,
            'mandatory_return'           => false,
            'approved_by'                => $this->user->id,
            'approved_at'                => now(),
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->product->id,
            'quantity'    => 5,
        ]);

        // While dispatched, requiresReturn() must consult the approved snapshot and return false
        $this->assertFalse($transfer->requiresReturn());

        // View detail check: privileged viewer should see "Wajib Retur:" "Tidak" and NOT see contradictory "Butuh Pengembalian"
        session(['setting_id' => $this->originSetting->id]);
        $response = $this->actingAs($this->user)->get(route('transfers.show', $transfer));
        $response->assertOk();
        $response->assertSee('Kebijakan Rute (V2)');
        $response->assertSee('Wajib Retur:');
        $response->assertSee('Tidak');
        $response->assertDontSee('Butuh Pengembalian');

        $receiptPrep = app(ForwardReceiptPreparationService::class);
        $receiptMovement = $receiptPrep->getOrCreateDraft($transfer, $this->user->id);
        $receiptMovement = $receiptPrep->setLineQuantity($receiptMovement, $this->product->id, 5, true, $receiptMovement->lock_version, $this->user->id);
        $pendingReceipt = $receiptPrep->submit($receiptMovement, $receiptMovement->lock_version, $this->user->id);

        $approvalExecutor = app(ForwardReceiptApprovalExecutor::class);
        $approved = $approvalExecutor->approve($transfer, $pendingReceipt, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_APPROVED, $approved->status);

        // Verify transfer completes immediately and no return obligations exist
        $transfer->refresh();
        $this->assertEquals(Transfer::STATUS_COMPLETED, $transfer->status);
        $this->assertFalse(TransferMovementReturnObligation::where('transfer_id', $transfer->id)->exists());
    }
}

<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementHistory;
use Modules\Adjustment\Services\TransferMovementDocumentService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use RuntimeException;
use Tests\TestCase;

class TransferMovementDocumentDomainTest extends TestCase
{
    use RefreshDatabase;

    protected User $user1;
    protected User $user2;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $serializedProduct;
    protected Product $nonSerializedProduct;
    protected TransferMovementDocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user1 = User::factory()->create();
        $this->user2 = User::factory()->create();
        $this->service = app(TransferMovementDocumentService::class);

        $this->setting = Setting::factory()->create();

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Warehouse',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Warehouse',
        ]);

        $this->serializedProduct = Product::create([
            'product_name'           => 'Serialized Phone',
            'product_code'           => 'SP-001',
            'setting_id'             => $this->setting->id,
            'product_quantity'       => 10,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);

        $this->nonSerializedProduct = Product::create([
            'product_name'           => 'Bulk Cable',
            'product_code'           => 'BC-001',
            'setting_id'             => $this->setting->id,
            'product_quantity'       => 100,
            'product_cost'           => 100,
            'product_price'          => 150,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);
    }

    protected function createApprovedTransfer(string $condition = Transfer::CONDITION_GOOD): Transfer
    {
        return Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => $condition,
            'created_by'              => $this->user1->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 1,
        ]);
    }

    /** @test */
    public function creates_draft_with_canonical_lines_and_records_history(): void
    {
        $transfer = $this->createApprovedTransfer();

        $movement = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [
                ['product_id' => $this->nonSerializedProduct->id, 'quantity' => 2.5],
                ['product_id' => $this->nonSerializedProduct->id, 'quantity' => 1.5], // repeated scan
            ],
            null,
            'IDEM-CREATE-001'
        );

        $this->assertSame(TransferMovement::STATUS_DRAFT, $movement->status);
        $this->assertSame(1, $movement->revision);
        $this->assertCount(1, $movement->lines);
        $this->assertEquals(4.0, (float) $movement->lines->first()->quantity);

        // Repeating create with same idempotency key returns the same movement without extra history
        $repeated = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [],
            null,
            'IDEM-CREATE-001'
        );

        $this->assertSame($movement->id, $repeated->id);
        $this->assertCount(1, $movement->fresh()->histories);
    }

    /** @test */
    public function blocks_competing_open_attempts_for_same_transfer_and_type(): void
    {
        $transfer = $this->createApprovedTransfer();

        $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [['product_id' => $this->nonSerializedProduct->id, 'quantity' => 2]]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An open attempt (DRAFT or PENDING) already exists');

        $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user2->id,
            [['product_id' => $this->nonSerializedProduct->id, 'quantity' => 5]]
        );
    }

    /** @test */
    public function allows_shared_draft_editing_and_rejects_stale_concurrency_revisions(): void
    {
        $transfer = $this->createApprovedTransfer();

        $draft = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [['product_id' => $this->nonSerializedProduct->id, 'quantity' => 2]]
        );

        $this->assertSame(1, (int) $draft->lock_version);

        // User 2 edits open draft with expected lock_version = 1
        $updated = $this->service->updateDraft(
            $draft,
            1, // expected lock_version
            [['product_id' => $this->nonSerializedProduct->id, 'quantity' => 10]],
            $this->user2->id
        );

        $this->assertEquals('10.0000', (string) $updated->lines->first()->quantity);
        $this->assertSame($this->user2->id, $updated->updated_by);
        $this->assertSame(2, (int) $updated->lock_version);

        // Concurrent edit simulation: User 1 tries to update using the stale lock_version = 1
        try {
            $this->service->updateDraft(
                $draft,
                1, // stale lock_version (it is now 2)
                [['product_id' => $this->nonSerializedProduct->id, 'quantity' => 15]],
                $this->user1->id
            );
            $this->fail('Expected optimistic lock error on concurrent draft update');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Optimistic lock error: expected lock_version 1 but movement is at lock_version 2', $e->getMessage());
        }
    }

    /** @test */
    public function rejects_movement_operations_for_ineligible_or_unapproved_transfers(): void
    {
        $draftTransfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user1->id,
            'status'                  => Transfer::STATUS_DRAFT,
            'revision'                => 1,
            'workflow_version'        => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transfer is in status [DRAFT], but only APPROVED transfers are eligible for movement operations.');

        $this->service->createDraft(
            $draftTransfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [['product_id' => $this->nonSerializedProduct->id, 'quantity' => 1]]
        );
    }

    /** @test */
    public function rejects_unsupported_decimal_quantity_precision(): void
    {
        $transfer = $this->createApprovedTransfer();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds maximum supported precision of 4 decimal places');

        $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [['product_id' => $this->nonSerializedProduct->id, 'quantity' => '1.12345']]
        );
    }

    /** @test */
    public function validates_authoritative_live_serial_data_and_rejects_inconsistencies(): void
    {
        $transfer = $this->createApprovedTransfer();

        $liveSerial = ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'LIVE-PHONE-001',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => 1, // Broken in database
        ]);

        // Attempting to add broken serial to GOOD transfer must be rejected
        try {
            $this->service->createDraft(
                $transfer, // GOOD transfer
                TransferMovement::TYPE_FORWARD_DISPATCH,
                $this->user1->id,
                [
                    [
                        'product_id' => $this->serializedProduct->id,
                        'quantity'   => 1,
                        'serials'    => ['LIVE-PHONE-001'],
                    ],
                ]
            );
            $this->fail('Expected rejection for broken serial in good transfer');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is broken but movement condition is GOOD', $e->getMessage());
        }

        // Serial at another location must be rejected (exact origin match required)
        $diffLocSerial = ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->destination->id,
            'serial_number' => 'LIVE-PHONE-DIFFLOC',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => 0,
        ]);

        try {
            $this->service->createDraft(
                $transfer,
                TransferMovement::TYPE_FORWARD_DISPATCH,
                $this->user1->id,
                [
                    [
                        'product_id' => $this->serializedProduct->id,
                        'quantity'   => 1,
                        'serials'    => ['LIVE-PHONE-DIFFLOC'],
                    ],
                ]
            );
            $this->fail('Expected rejection for serial at different location');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not movement origin', $e->getMessage());
        }
    }

    /** @test */
    public function revalidates_live_serial_eligibility_at_submission_time(): void
    {
        $transfer = $this->createApprovedTransfer();

        $liveSerial = ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'LIVE-PHONE-VALID',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => 0,
        ]);

        $draft = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [
                [
                    'product_id' => $this->serializedProduct->id,
                    'quantity'   => 1,
                    'serials'    => ['LIVE-PHONE-VALID'],
                ],
            ]
        );

        // Simulate serial status drift after draft creation (e.g. serial sold / missing in the meantime)
        $liveSerial->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        try {
            $this->service->submitDraft($draft, 1, $this->user1->id);
            $this->fail('Expected rejection for serial whose status became unavailable');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('status [SOLD] is not available', $e->getMessage());
        }

        // Restore status but drift serial text on live record
        $liveSerial->update([
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'serial_number' => 'LIVE-PHONE-RENAMED',
        ]);

        try {
            $this->service->submitDraft($draft, 1, $this->user1->id);
            $this->fail('Expected rejection for serial whose identity text drifted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not match expected serial', $e->getMessage());
        }
    }

    /** @test */
    public function enforces_serialized_quantity_equality_at_submission(): void
    {
        $transfer = $this->createApprovedTransfer();

        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'SN-PHONE-001',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => 0,
        ]);

        $draft = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [
                [
                    'product_id' => $this->serializedProduct->id,
                    'quantity'   => 2,
                    'serials'    => ['SN-PHONE-001'], // only 1 serial for qty 2
                ],
            ]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects 2 serials, found 1');

        $this->service->submitDraft($draft, 1, $this->user1->id);
    }

    /** @test */
    public function supports_submit_reject_and_superseding_correction_workflow(): void
    {
        $transfer = $this->createApprovedTransfer();

        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'SN-PHONE-001',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => 0,
        ]);

        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'SN-PHONE-002',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => 0,
        ]);

        $draft = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [
                [
                    'product_id' => $this->serializedProduct->id,
                    'quantity'   => 1,
                    'serials'    => ['SN-PHONE-001'],
                ],
            ]
        );

        // Submit
        $pending = $this->service->submitDraft($draft, 1, $this->user1->id);
        $this->assertSame(TransferMovement::STATUS_PENDING, $pending->status);

        // Cannot edit submitted pending attempt
        try {
            $this->service->updateDraft($pending, 1, [], $this->user1->id);
            $this->fail('Should not be able to edit pending attempt');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('only DRAFT can be modified', $e->getMessage());
        }

        // Reject
        $rejected = $this->service->reject($pending, 'Damaged packaging observed during physical recount', $this->user2->id);
        $this->assertSame(TransferMovement::STATUS_REJECTED, $rejected->status);
        $this->assertSame('DAMAGED PACKAGING OBSERVED DURING PHYSICAL RECOUNT', $rejected->rejection_reason);

        // Start correction
        $correction = $this->service->startCorrection(
            $rejected,
            $this->user1->id,
            [
                [
                    'product_id' => $this->serializedProduct->id,
                    'quantity'   => 1,
                    'serials'    => ['SN-PHONE-002'],
                ],
            ]
        );

        $this->assertSame(TransferMovement::STATUS_DRAFT, $correction->status);
        $this->assertSame(2, $correction->revision);
        $this->assertSame(1, (int) $correction->lock_version);
        $this->assertSame($rejected->id, $correction->supersedes_movement_id);

        // Submit & Approve correction revision 2 (with newly created draft's lock_version = 1)
        $correctionPending = $this->service->submitDraft($correction, 1, $this->user1->id);
        $approved = $this->service->approve($correctionPending, $this->user2->id);

        $this->assertSame(TransferMovement::STATUS_APPROVED, $approved->status);
        $this->assertSame($this->user2->id, $approved->reviewed_by);
    }

    /** @test */
    public function enforces_receipt_dependency_on_approved_dispatch(): void
    {
        $transfer = $this->createApprovedTransfer();

        // Trying to create forward receipt without approved forward dispatch fails
        try {
            $this->service->createDraft($transfer, TransferMovement::TYPE_FORWARD_RECEIPT, $this->user1->id, [], 999);
            $this->fail('Should require approved dispatch');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot create forward receipt without an approved forward dispatch', $e->getMessage());
        }

        // Create & approve dispatch
        $dispatch = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [['product_id' => $this->nonSerializedProduct->id, 'quantity' => 5]]
        );
        $submittedDispatch = $this->service->submitDraft($dispatch, 1, $this->user1->id);
        $approvedDispatch = $this->service->approve($submittedDispatch, $this->user2->id);

        // Now create forward receipt referencing the approved dispatch
        $receipt = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_RECEIPT,
            $this->user1->id,
            [['product_id' => $this->nonSerializedProduct->id, 'quantity' => 5]],
            $approvedDispatch->id
        );

        $this->assertSame(TransferMovement::TYPE_FORWARD_RECEIPT, $receipt->type);
        $this->assertSame($approvedDispatch->id, $receipt->source_movement_id);

        $submittedReceipt = $this->service->submitDraft($receipt, 1, $this->user1->id);
        $approvedReceipt = $this->service->approve($submittedReceipt, $this->user2->id);

        // Return dispatch without source movement must be rejected
        try {
            $this->service->createDraft($transfer, TransferMovement::TYPE_RETURN_DISPATCH, $this->user1->id, []);
            $this->fail('Should require source movement for return dispatch');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Source movement reference is required for movement type [RETURN_DISPATCH]', $e->getMessage());
        }

        // Return dispatch with approved forward receipt source succeeds
        $returnDispatch = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_RETURN_DISPATCH,
            $this->user1->id,
            [['product_id' => $this->nonSerializedProduct->id, 'quantity' => 2]],
            $approvedReceipt->id
        );

        $this->assertSame(TransferMovement::TYPE_RETURN_DISPATCH, $returnDispatch->type);
        $this->assertSame($approvedReceipt->id, $returnDispatch->source_movement_id);
    }

    /** @test */
    public function cancel_draft_with_reason_prevents_subsequent_submit(): void
    {
        $transfer = $this->createApprovedTransfer();

        $draft = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [['product_id' => $this->nonSerializedProduct->id, 'quantity' => 5]]
        );

        $cancelled = $this->service->cancel($draft, 'No longer needed', $this->user1->id);
        $this->assertSame(TransferMovement::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('NO LONGER NEEDED', $cancelled->cancellation_reason);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only DRAFT movements can be submitted');

        $this->service->submitDraft($cancelled, 1, $this->user1->id);
    }

    /** @test */
    public function dormant_movement_approval_does_not_mutate_stocks_or_transactions_or_serial_locations(): void
    {
        $transfer = $this->createApprovedTransfer();

        // Create initial stock in origin
        $originStock = ProductStock::create([
            'product_id'              => $this->serializedProduct->id,
            'location_id'            => $this->origin->id,
            'quantity'                => 10,
            'quantity_non_tax'        => 10,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $liveSerial = ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'LIVE-SN-001',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => 0,
        ]);

        $initialTxCount = Transaction::count();

        // Create, submit, approve movement
        $draft = $this->service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user1->id,
            [
                [
                    'product_id' => $this->serializedProduct->id,
                    'quantity'   => 1,
                    'serials'    => ['LIVE-SN-001'],
                ],
            ]
        );

        $submitted = $this->service->submitDraft($draft, 1, $this->user1->id);
        $approved = $this->service->approve($submitted, $this->user2->id);

        $this->assertSame(TransferMovement::STATUS_APPROVED, $approved->status);

        // Stock quantity, live serial location, and transactions must remain unchanged
        $this->assertSame(10, (int) $originStock->fresh()->quantity);
        $this->assertSame($this->origin->id, (int) $liveSerial->fresh()->location_id);
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $liveSerial->fresh()->status);
        $this->assertSame($initialTxCount, Transaction::count());
        $this->assertSame(Transfer::STATUS_APPROVED, $transfer->fresh()->status);
    }
}

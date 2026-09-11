<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Adjustment\DTOs\TransferFormLineState;
use Modules\Adjustment\DTOs\TransferFormState;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferDraftService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferDraftDestinationOptionalTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private User $user;
    private Location $origin;
    private Location $destination;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create();
        $this->user = User::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $this->setting->id]);
        $this->destination = Location::factory()->create(['setting_id' => $this->setting->id]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Product',
            'product_code' => 'P-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 4,
            'quantity_non_tax' => 6,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
    }

    private function makeLine(int $quantity, bool $broken = false): TransferFormLineState
    {
        $line = new TransferFormLineState(
            $this->product->id,
            $this->product->product_name,
            $this->product->product_code,
            null,
            false,
            $broken,
            $quantity
        );

        return $line;
    }

    /** @test */
    public function draft_can_be_saved_without_a_destination()
    {
        $state = new TransferFormState($this->origin->id, null, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $transfer = app(TransferDraftService::class)->saveDraft($state, $this->user, $this->setting->id);

        $this->assertEquals(Transfer::STATUS_DRAFT, $transfer->status);
        $this->assertNull($transfer->destination_location_id);
        $this->assertEquals(Transfer::CONDITION_GOOD, $transfer->stock_condition);
    }

    /** @test */
    public function supplied_destination_is_authoritatively_validated_as_active_and_distinct()
    {
        $state = new TransferFormState($this->origin->id, $this->origin->id, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Origin and destination cannot be the same.');

        app(TransferDraftService::class)->saveDraft($state, $this->user, $this->setting->id);
    }

    /** @test */
    public function supplied_inactive_destination_is_rejected()
    {
        $this->destination->update(['is_active' => false]);

        $state = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $this->expectException(InvalidArgumentException::class);

        app(TransferDraftService::class)->saveDraft($state, $this->user, $this->setting->id);
    }

    /** @test */
    public function draft_requires_at_least_one_row()
    {
        $state = new TransferFormState($this->origin->id, null, Transfer::CONDITION_GOOD);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one product row is required to save a draft.');

        app(TransferDraftService::class)->saveDraft($state, $this->user, $this->setting->id);
    }

    /** @test */
    public function draft_requires_a_valid_stock_condition()
    {
        $state = new TransferFormState($this->origin->id, null, null);
        $state->addLine($this->makeLine(3));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A valid stock condition (GOOD or BREAKAGE) must be selected.');

        app(TransferDraftService::class)->saveDraft($state, $this->user, $this->setting->id);
    }

    /** @test */
    public function row_condition_mismatch_with_selected_condition_is_rejected()
    {
        $state = new TransferFormState($this->origin->id, null, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3, broken: true));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Product row stock condition does not match the transfer's selected condition.");

        app(TransferDraftService::class)->saveDraft($state, $this->user, $this->setting->id);
    }

    /** @test */
    public function submit_for_approval_requires_a_destination()
    {
        $state = new TransferFormState($this->origin->id, null, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $draftService = app(TransferDraftService::class);
        $transfer = $draftService->saveDraft($state, $this->user, $this->setting->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A destination location is required to submit for approval.');

        $draftService->submitForApproval($state, $this->user, $this->setting->id, $transfer);
    }

    /** @test */
    public function submit_for_approval_transitions_a_valid_draft_to_pending_atomically()
    {
        $state = new TransferFormState($this->origin->id, null, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $draftService = app(TransferDraftService::class);
        $transfer = $draftService->saveDraft($state, $this->user, $this->setting->id);

        $state->destinationLocationId = $this->destination->id;
        $submitted = $draftService->submitForApproval($state, $this->user, $this->setting->id, $transfer);

        $this->assertEquals(Transfer::STATUS_PENDING, $submitted->status);
        $this->assertEquals($this->destination->id, $submitted->destination_location_id);
    }

    /** @test */
    public function submit_for_approval_rejects_a_non_draft_transfer()
    {
        $state = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $draftService = app(TransferDraftService::class);
        $transfer = $draftService->saveDraft($state, $this->user, $this->setting->id);
        $transfer = $draftService->submitForApproval($state, $this->user, $this->setting->id, $transfer);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only DRAFT transfers can be submitted for approval.');

        $draftService->submitForApproval($state, $this->user, $this->setting->id, $transfer);
    }

    /** @test */
    public function cannot_modify_a_transfer_from_another_tenant()
    {
        $otherSetting = Setting::factory()->create();
        $otherOrigin = Location::factory()->create(['setting_id' => $otherSetting->id]);

        $state = new TransferFormState($otherOrigin->id, null, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $draftService = app(TransferDraftService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid origin location for current tenant.');

        $draftService->saveDraft($state, $this->user, $this->setting->id);
    }

    /** @test */
    public function saving_an_existing_draft_with_a_different_origin_is_rejected_and_the_persisted_origin_is_unchanged()
    {
        $otherOrigin = Location::factory()->create(['setting_id' => $this->setting->id]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $otherOrigin->id,
            'quantity' => 10,
            'quantity_tax' => 4,
            'quantity_non_tax' => 6,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $draftService = app(TransferDraftService::class);

        $originalState = new TransferFormState($this->origin->id, null, Transfer::CONDITION_GOOD);
        $originalState->addLine($this->makeLine(3));
        $transfer = $draftService->saveDraft($originalState, $this->user, $this->setting->id);

        // Attempt to save the same transfer with rows re-validated against a
        // different origin (a crafted request, or an editable origin field).
        $changedOriginState = new TransferFormState($otherOrigin->id, null, Transfer::CONDITION_GOOD);
        $changedOriginState->addLine($this->makeLine(3));

        try {
            $draftService->saveDraft($changedOriginState, $this->user, $this->setting->id, $transfer);
            $this->fail('Expected saving with a different origin to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Origin location cannot be changed', $e->getMessage());
        }

        // Reload from the database: the header must still point at the
        // original origin, and the rows validated against it must not have
        // been silently replaced by rows validated against the other origin.
        $reloaded = $transfer->fresh(['products']);
        $this->assertEquals($this->origin->id, $reloaded->origin_location_id);
        $this->assertCount(1, $reloaded->products);
    }

    /** @test */
    public function create_and_submit_for_approval_leaves_no_orphaned_draft_when_submission_fails()
    {
        // Destination becomes invalid (inactive) only at submission time,
        // which would previously succeed at draft-creation but fail at the
        // separate submission step, leaving an orphaned DRAFT behind.
        $this->destination->update(['is_active' => false]);

        $state = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        try {
            app(TransferDraftService::class)->createAndSubmitForApproval($state, $this->user, $this->setting->id);
            $this->fail('Expected create-and-submit to fail for an inactive destination.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Destination location is inactive.', $e->getMessage());
        }

        // The whole operation is one transaction: no draft should remain.
        $this->assertEquals(0, Transfer::count());
    }

    /** @test */
    public function create_and_submit_for_approval_rolls_back_the_inserted_draft_when_the_submission_stage_fails()
    {
        // Unlike the previous test (where saveDraft's own validation fails
        // before any insert), this forces failure at the *second*,
        // submission-stage call, after a real saveDraft() has already
        // inserted the transfer/products/history rows, to prove the outer
        // transaction in createAndSubmitForApproval() rolls those back too.
        $state = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $draftService = \Mockery::mock(TransferDraftService::class, [
            app(\Modules\Adjustment\Services\TransferAllocationPreviewService::class),
            app(\Modules\Adjustment\Services\TransferLifecycleService::class),
        ])->makePartial();

        $draftService->shouldReceive('submitForApproval')
            ->once()
            ->andThrow(new \RuntimeException('Simulated submission-stage failure'));

        try {
            $draftService->createAndSubmitForApproval($state, $this->user, $this->setting->id);
            $this->fail('Expected create-and-submit to fail at the submission stage.');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Simulated submission-stage failure', $e->getMessage());
        }

        // saveDraft() ran for real and would otherwise have inserted a
        // transfer, its products, and a CREATED action history row. The
        // outer transaction must have rolled all of that back.
        $this->assertEquals(0, Transfer::count());
        $this->assertEquals(0, \Modules\Adjustment\Entities\TransferProduct::count());
        $this->assertEquals(0, \Modules\Adjustment\Entities\TransferActionHistory::count());
    }

    /** @test */
    public function create_and_submit_for_approval_succeeds_atomically_when_valid()
    {
        $state = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $transfer = app(TransferDraftService::class)->createAndSubmitForApproval($state, $this->user, $this->setting->id);

        $this->assertEquals(Transfer::STATUS_PENDING, $transfer->status);
        $this->assertEquals($this->destination->id, $transfer->destination_location_id);
        $this->assertEquals(1, Transfer::count());
    }

    /** @test */
    public function submitting_for_approval_with_a_different_origin_is_rejected_and_the_persisted_origin_is_unchanged()
    {
        $otherOrigin = Location::factory()->create(['setting_id' => $this->setting->id]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $otherOrigin->id,
            'quantity' => 10,
            'quantity_tax' => 4,
            'quantity_non_tax' => 6,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $draftService = app(TransferDraftService::class);

        $originalState = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $originalState->addLine($this->makeLine(3));
        $transfer = $draftService->saveDraft($originalState, $this->user, $this->setting->id);

        $changedOriginState = new TransferFormState($otherOrigin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $changedOriginState->addLine($this->makeLine(3));

        try {
            $draftService->submitForApproval($changedOriginState, $this->user, $this->setting->id, $transfer);
            $this->fail('Expected submission with a different origin to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Origin location cannot be changed', $e->getMessage());
        }

        $reloaded = $transfer->fresh(['products']);
        $this->assertEquals($this->origin->id, $reloaded->origin_location_id);
        $this->assertEquals(Transfer::STATUS_DRAFT, $reloaded->status);
        $this->assertCount(1, $reloaded->products);
    }

    /** @test */
    public function saving_a_pending_transfer_with_a_different_mode_is_rejected_and_the_persisted_header_is_unchanged()
    {
        $draftService = app(TransferDraftService::class);

        $goodState = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $goodState->addLine($this->makeLine(3));
        $transfer = $draftService->saveDraft($goodState, $this->user, $this->setting->id);
        $transfer = $draftService->submitForApproval($goodState, $this->user, $this->setting->id, $transfer);

        $this->assertEquals(Transfer::STATUS_PENDING, $transfer->status);

        // Attempt to save the PENDING transfer with BREAKAGE rows instead.
        $brokenState = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_BREAKAGE);
        $brokenState->addLine($this->makeLine(3, broken: true));

        try {
            $draftService->saveDraft($brokenState, $this->user, $this->setting->id, $transfer);
            $this->fail('Expected saving a PENDING transfer with a different mode to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Stock condition cannot be changed', $e->getMessage());
        }

        // Reload: header condition and rows must remain GOOD, not BREAKAGE.
        $reloaded = $transfer->fresh(['products']);
        $this->assertEquals(Transfer::CONDITION_GOOD, $reloaded->stock_condition);
        $this->assertCount(1, $reloaded->products);
        $tp = $reloaded->products->first();
        $this->assertGreaterThan(0, $tp->quantity_tax + $tp->quantity_non_tax);
        $this->assertEquals(0, $tp->quantity_broken_tax + $tp->quantity_broken_non_tax);
    }

    /** @test */
    public function saving_a_pending_transfer_with_a_different_destination_is_rejected_and_the_persisted_header_is_unchanged()
    {
        $draftService = app(TransferDraftService::class);

        $state = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));
        $transfer = $draftService->saveDraft($state, $this->user, $this->setting->id);
        $transfer = $draftService->submitForApproval($state, $this->user, $this->setting->id, $transfer);

        $otherDestination = Location::factory()->create(['setting_id' => $this->setting->id]);

        $changedDestinationState = new TransferFormState($this->origin->id, $otherDestination->id, Transfer::CONDITION_GOOD);
        $changedDestinationState->addLine($this->makeLine(3));

        try {
            $draftService->saveDraft($changedDestinationState, $this->user, $this->setting->id, $transfer);
            $this->fail('Expected saving a PENDING transfer with a different destination to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Destination cannot be changed', $e->getMessage());
        }

        $reloaded = $transfer->fresh();
        $this->assertEquals($this->destination->id, $reloaded->destination_location_id);
    }

    /** @test */
    public function saving_a_pending_transfer_with_the_same_mode_and_destination_succeeds()
    {
        $draftService = app(TransferDraftService::class);

        $state = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));
        $transfer = $draftService->saveDraft($state, $this->user, $this->setting->id);
        $transfer = $draftService->submitForApproval($state, $this->user, $this->setting->id, $transfer);

        $sameHeaderState = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $sameHeaderState->addLine($this->makeLine(5));

        $updated = $draftService->saveDraft($sameHeaderState, $this->user, $this->setting->id, $transfer);

        $this->assertEquals(Transfer::STATUS_PENDING, $updated->status);
        $this->assertEquals($this->destination->id, $updated->destination_location_id);
        $this->assertEquals(Transfer::CONDITION_GOOD, $updated->stock_condition);
    }

    /** @test */
    public function submission_rejects_an_origin_deactivated_after_the_draft_was_saved()
    {
        $state = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $draftService = app(TransferDraftService::class);
        $transfer = $draftService->saveDraft($state, $this->user, $this->setting->id);

        // Origin becomes inactive after the draft was saved; draft retention
        // would otherwise permit it because it is already on the transfer,
        // but submission must not.
        $this->origin->update(['is_active' => false]);

        try {
            $draftService->submitForApproval($state, $this->user, $this->setting->id, $transfer);
            $this->fail('Expected submission to reject a deactivated origin.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Origin location is inactive', $e->getMessage());
        }

        $this->assertEquals(Transfer::STATUS_DRAFT, $transfer->fresh()->status);
    }

    /** @test */
    public function submission_rejects_a_destination_deactivated_after_the_draft_was_saved()
    {
        $state = new TransferFormState($this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $draftService = app(TransferDraftService::class);
        $transfer = $draftService->saveDraft($state, $this->user, $this->setting->id);

        $this->destination->update(['is_active' => false]);

        try {
            $draftService->submitForApproval($state, $this->user, $this->setting->id, $transfer);
            $this->fail('Expected submission to reject a deactivated destination.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Destination location is inactive', $e->getMessage());
        }

        $this->assertEquals(Transfer::STATUS_DRAFT, $transfer->fresh()->status);
    }

    /** @test */
    public function draft_retention_still_permits_saving_an_unchanged_draft_with_a_deactivated_origin()
    {
        $state = new TransferFormState($this->origin->id, null, Transfer::CONDITION_GOOD);
        $state->addLine($this->makeLine(3));

        $draftService = app(TransferDraftService::class);
        $transfer = $draftService->saveDraft($state, $this->user, $this->setting->id);

        $this->origin->update(['is_active' => false]);

        // Saving (not submitting) the same DRAFT with its existing,
        // now-inactive origin is still permitted: retention is fine for an
        // unchanged draft, only submission requires strict activity.
        $resaved = $draftService->saveDraft($state, $this->user, $this->setting->id, $transfer);

        $this->assertEquals(Transfer::STATUS_DRAFT, $resaved->status);
        $this->assertEquals($this->origin->id, $resaved->origin_location_id);
    }
}

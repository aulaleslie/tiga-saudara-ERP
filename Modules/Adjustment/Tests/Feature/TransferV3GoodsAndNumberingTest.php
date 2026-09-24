<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Entities\TransferApprovalAllocation;
use Modules\Adjustment\Entities\TransferRequestRevision;
use Modules\Adjustment\Services\TransferV3AllocationService;
use Modules\Adjustment\Services\TransferV3GoodsService;
use Modules\Adjustment\Tests\Support\BuildsV3TransferFixtures;
use Modules\Product\Entities\ProductSerialNumber;
use RuntimeException;
use Tests\TestCase;

/**
 * Tasks 1.1-1.3, 3.2, 3.3, 4.2: schema compatibility, location-independent
 * numbering, location-free goods drafts/submission, frozen revisions and
 * approval-context invalidation.
 */
class TransferV3GoodsAndNumberingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsV3TransferFixtures;

    private TransferV3GoodsService $goods;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildV3Fixtures();
        $this->goods = app(TransferV3GoodsService::class);
    }

    /** @test */
    public function schema_is_additive_and_preserves_legacy_rows_indexes_and_keys(): void
    {
        $legacy = Transfer::create([
            'origin_location_id' => $this->a1->id,
            'destination_location_id' => $this->a2->id,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'created_by' => $this->actor->id,
            'status' => Transfer::STATUS_COMPLETED,
            'workflow_version' => 2,
        ]);

        $this->assertMatchesRegularExpression('/^TS-\d{4}-\d{2}-\d{4}$/', $legacy->document_number);
        $this->assertNull($legacy->fresh()->v3_reference_key);
        $this->assertSame(2, (int) $legacy->fresh()->workflow_version);

        foreach (['transfer_request_revisions', 'transfer_approval_allocations', 'transfer_approval_allocation_serials', 'transfer_movement_allocations'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumns('transfers', ['v3_reference_key', 'created_in_setting_id', 'current_request_revision_id', 'approval_configuration_revision', 'cancelled_by', 'cancelled_at', 'cancellation_reason']));
        $this->assertTrue(Schema::hasColumn('transfer_movement_serials', 'transfer_movement_allocation_id'));

        $indexes = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'transfers'"))->pluck('name');
        $this->assertContains('transfers_origin_document_number_unique', $indexes);
        $this->assertContains('transfers_v3_reference_key_unique', $indexes);

        $foreignKeys = collect(DB::select('PRAGMA foreign_key_list(transfers)'))->pluck('table', 'from');
        $this->assertSame('locations', $foreignKeys['origin_location_id']);
        $this->assertSame('users', $foreignKeys['created_by']);

        $movementKeys = collect(DB::select('PRAGMA foreign_key_list(transfer_movements)'))->pluck('table', 'from');
        $this->assertSame('transfers', $movementKeys['transfer_id']);
        $this->assertSame('transfer_movements', $movementKeys['source_movement_id']);
    }

    /** @test */
    public function sqlite_schema_carries_the_foreign_keys_of_added_columns(): void
    {
        $this->assertForeignKey('transfers', 'created_in_setting_id', 'settings', 'SET NULL');
        $this->assertForeignKey('transfers', 'cancelled_by', 'users', 'SET NULL');
        $this->assertForeignKey('transfer_movement_serials', 'transfer_movement_allocation_id', 'transfer_movement_allocations', 'SET NULL');
        $this->assertForeignKey('transfer_approval_allocation_serials', 'transfer_approval_allocation_id', 'transfer_approval_allocations', 'CASCADE');

        // Delete behaviour matches MySQL: the canceller reference is nulled.
        $canceller = \App\Models\User::factory()->create();
        $draft = $this->goods->createDraft(Transfer::CONDITION_GOOD, $this->goodsLines(1, false), $this->actor, $this->businessA->id);
        DB::table('transfers')->where('id', $draft->id)->update(['cancelled_by' => $canceller->id]);
        $canceller->delete();
        $this->assertNull(DB::table('transfers')->where('id', $draft->id)->value('cancelled_by'));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('transfers')->where('id', $draft->id)->update(['created_in_setting_id' => 999999]);
    }

    /** @test */
    public function rollback_without_v3_documents_and_remigration_restore_the_same_schema(): void
    {
        $migration = require base_path('Modules/Adjustment/Database/Migrations/2026_09_23_100000_add_v3_allocation_workflow_to_transfers.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('transfer_movement_allocations'));
        $this->assertFalse(Schema::hasColumn('transfers', 'cancelled_by'));
        $this->assertFalse(Schema::hasColumn('transfer_movement_serials', 'transfer_movement_allocation_id'));

        $migration->up();
        $migration->up(); // re-runnable: completes nothing twice
        $this->assertForeignKey('transfers', 'cancelled_by', 'users', 'SET NULL');
        $this->assertForeignKey('transfer_movement_serials', 'transfer_movement_allocation_id', 'transfer_movement_allocations', 'SET NULL');
        $this->assertCount(1, collect(DB::select('PRAGMA foreign_key_list(transfers)'))->where('from', 'cancelled_by'));
    }

    /** @test */
    public function rollback_refuses_before_any_change_when_v3_documents_exist(): void
    {
        $this->goods->createDraft(Transfer::CONDITION_GOOD, $this->goodsLines(1, false), $this->actor, $this->businessA->id);
        $migration = require base_path('Modules/Adjustment/Database/Migrations/2026_09_23_100000_add_v3_allocation_workflow_to_transfers.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse.');
        } catch (\RuntimeException) {
        }

        $this->assertTrue(Schema::hasColumn('transfers', 'cancelled_by'));
        $this->assertForeignKey('transfer_movement_serials', 'transfer_movement_allocation_id', 'transfer_movement_allocations', 'SET NULL');
    }

    private function assertForeignKey(string $table, string $column, string $references, string $onDelete): void
    {
        $key = collect(DB::select("PRAGMA foreign_key_list({$table})"))->firstWhere('from', $column);

        $this->assertNotNull($key, "{$table}.{$column} has no foreign key");
        $this->assertSame($references, $key->table);
        $this->assertSame($onDelete, strtoupper($key->on_delete));
    }

    /** @test */
    public function v3_numbering_is_location_independent_unique_and_sequenced(): void
    {
        $first = $this->goods->createDraft(Transfer::CONDITION_GOOD, $this->goodsLines(1, false), $this->actor, $this->businessA->id);
        $second = $this->goods->createDraft(Transfer::CONDITION_GOOD, $this->goodsLines(1, false), $this->actor, $this->businessB->id);

        $this->assertNull($first->origin_location_id);
        $this->assertMatchesRegularExpression('/^TSM-\d{4}-\d{2}-0001$/', $first->document_number);
        $this->assertMatchesRegularExpression('/^TSM-\d{4}-\d{2}-0002$/', $second->document_number);
        $this->assertSame($first->document_number, $first->v3_reference_key);

        // Unique key protects rows whose origin is NULL.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('transfers')->where('id', $second->id)->update(['v3_reference_key' => $first->v3_reference_key]);
    }

    /** @test */
    public function numbering_reconciles_a_counter_that_fell_behind(): void
    {
        $first = $this->goods->createDraft(Transfer::CONDITION_GOOD, $this->goodsLines(1, false), $this->actor, $this->businessA->id);
        DB::table('adjustment_reference_sequences')->where('prefix', 'TSM')->update(['last_number' => 0]);

        $next = $this->goods->createDraft(Transfer::CONDITION_GOOD, $this->goodsLines(1, false), $this->actor, $this->businessA->id);

        $this->assertNotSame($first->document_number, $next->document_number);
        $this->assertStringEndsWith('0002', $next->document_number);
    }

    /** @test */
    public function draft_keeps_incomplete_serial_selection_without_stock_effects(): void
    {
        $draft = $this->goods->createDraft(Transfer::CONDITION_GOOD, [
            ['product_id' => $this->serialized->id, 'quantity' => 3, 'serial_ids' => [$this->serials[0]->id]],
        ], $this->actor, $this->businessA->id);

        $this->assertSame(Transfer::STATUS_DRAFT, $draft->status);
        $this->assertSame(Transfer::WORKFLOW_V3, (int) $draft->workflow_version);
        $this->assertNull($draft->origin_location_id);
        $this->assertNull($draft->destination_location_id);
        $this->assertSame($this->businessA->id, (int) $draft->created_in_setting_id);
        $this->assertSame(3, (int) $draft->products()->first()->quantity);
        $this->assertCount(1, $draft->products()->first()->serial_numbers);
        $this->assertSame(1, (int) $this->stockAt($this->serialized, $this->a1)->quantity_non_tax);
    }

    /** @test */
    public function submission_requires_quantity_to_equal_distinct_serials_on_create_and_edit(): void
    {
        $incomplete = [['product_id' => $this->serialized->id, 'quantity' => 3, 'serial_ids' => [$this->serials[0]->id, $this->serials[1]->id]]];

        try {
            $this->goods->createAndSubmit(Transfer::CONDITION_GOOD, $incomplete, $this->actor, $this->businessA->id);
            $this->fail('Incomplete serials must block create-submit.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('harus sama dengan jumlah nomor seri', $e->getMessage());
        }
        $this->assertSame(0, Transfer::count(), 'no partial creation');

        $draft = $this->goods->createDraft(Transfer::CONDITION_GOOD, $incomplete, $this->actor, $this->businessA->id);

        try {
            $this->goods->submit($draft, $incomplete, $this->actor, $this->businessA->id);
            $this->fail('Incomplete serials must block edit submission.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(Transfer::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertSame(0, TransferRequestRevision::count());
    }

    /** @test */
    public function ineligible_or_foreign_serials_are_rejected_at_submission(): void
    {
        $this->serials[0]->update(['is_broken' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->goods->createAndSubmit(Transfer::CONDITION_GOOD, $this->goodsLines(), $this->actor, $this->businessA->id);
    }

    /** @test */
    public function create_submit_commits_exactly_one_pending_transfer_with_both_events(): void
    {
        $transfer = $this->goods->createAndSubmit(Transfer::CONDITION_GOOD, $this->goodsLines(), $this->actor, $this->businessA->id, 'create-key-1');
        $replay = $this->goods->createAndSubmit(Transfer::CONDITION_GOOD, $this->goodsLines(), $this->actor, $this->businessA->id, 'create-key-1');

        $this->assertSame($transfer->id, $replay->id);
        $this->assertSame(1, Transfer::count());
        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);

        $revision = TransferRequestRevision::findOrFail($transfer->fresh()->current_request_revision_id);
        $this->assertSame(1, $revision->revision_number);
        $this->assertCount(2, $revision->lines);
        $this->assertSame(['CREATED', 'SUBMITTED'], TransferActionHistory::where('transfer_id', $transfer->id)->pluck('action')->all());

        // A duplicate submission of the now-pending document is rejected.
        $this->expectException(InvalidArgumentException::class);
        $this->goods->submit($transfer->fresh(), $this->goodsLines(), $this->actor, $this->businessA->id);
    }

    /** @test */
    public function material_pending_edit_returns_to_draft_and_invalidates_saved_allocations(): void
    {
        $transfer = $this->submittedTransfer();
        $this->saveCompletePlan($transfer);
        $transfer->refresh();
        $staleRevisionId = (int) $transfer->current_request_revision_id;
        $staleConfiguration = (int) $transfer->approval_configuration_revision;

        // No-op save leaves the pending document untouched.
        $this->goods->saveDraft($transfer, Transfer::CONDITION_GOOD, $this->goodsLines(), $this->actor, $this->businessA->id);
        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);

        $this->goods->saveDraft($transfer, Transfer::CONDITION_GOOD, $this->goodsLines(9), $this->actor, $this->businessA->id);
        $edited = $transfer->fresh();
        $this->assertSame(Transfer::STATUS_DRAFT, $edited->status);
        $this->assertNull($edited->current_request_revision_id);

        // The stale plan cannot dispatch the changed goods.
        try {
            app(\Modules\Adjustment\Services\TransferV3DispatchExecutor::class)->approveAndDispatch($edited, $this->actor, $this->businessA->id, $staleRevisionId, $staleConfiguration);
            $this->fail('Stale plan must not dispatch.');
        } catch (RuntimeException) {
        }

        $this->goods->submit($edited, $this->goodsLines(9), $this->actor, $this->businessA->id);
        $resubmitted = $transfer->fresh();
        $this->assertNotSame($staleRevisionId, (int) $resubmitted->current_request_revision_id);
        $this->assertTrue(app(TransferV3AllocationService::class)->currentPlan($resubmitted)->isEmpty(), 'old plan does not carry over');
        $this->assertSame(2, TransferApprovalAllocation::where('request_revision_id', $staleRevisionId)->where('product_id', $this->bulk->id)->count(), 'stale plan retained as evidence');
    }

    /** @test */
    public function condition_is_immutable_after_first_persistence(): void
    {
        $draft = $this->goods->createDraft(Transfer::CONDITION_GOOD, $this->goodsLines(1, false), $this->actor, $this->businessA->id);

        $this->expectException(InvalidArgumentException::class);
        $this->goods->saveDraft($draft, Transfer::CONDITION_BREAKAGE, $this->goodsLines(1, false), $this->actor, $this->businessA->id);
    }

    /** @test */
    public function rejection_requires_reason_and_explicit_acknowledgement_back_to_draft(): void
    {
        $transfer = $this->submittedTransfer();

        try {
            $this->goods->reject($transfer, ' ', $this->actor, $this->businessA->id);
            $this->fail('Empty reason must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->goods->reject($transfer, 'Jumlah salah', $this->actor, $this->businessA->id);
        $this->assertSame(Transfer::STATUS_REJECTED, $transfer->fresh()->status);
        $this->assertNotNull($transfer->fresh()->current_request_revision_id, 'submitted revision preserved');

        $this->goods->acknowledgeRejection($transfer, $this->actor, $this->businessA->id);
        $this->assertSame(Transfer::STATUS_DRAFT, $transfer->fresh()->status);
        $this->assertSame(Transfer::WORKFLOW_V3, (int) $transfer->fresh()->workflow_version, 'version never changes');
    }

    /** @test */
    public function progress_save_rejects_stale_revisions_and_reserves_nothing(): void
    {
        $transfer = $this->submittedTransfer();
        $transfer->refresh();
        $staleConfiguration = (int) $transfer->approval_configuration_revision;

        $this->saveCompletePlan($transfer, [
            ['product_id' => $this->bulk->id, 'source_location_id' => $this->a1->id, 'destination_location_id' => null, 'quantity' => 6],
        ], []);

        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
        $this->assertSame(6, (int) $this->stockAt($this->bulk, $this->a1)->quantity_non_tax);
        $this->assertSame(0, \Modules\Adjustment\Entities\TransferActiveSerialClaim::count());

        $workspace = app(TransferV3AllocationService::class)->workspace($transfer->fresh(), false);
        $bulk = collect($workspace['items'])->firstWhere('product_id', $this->bulk->id);
        $this->assertSame([['source_location_id' => $this->a1->id, 'destination_location_id' => null, 'quantity' => 6]], $bulk['rows'], 'incomplete choices resume');
        $this->assertArrayNotHasKey('tax', $bulk['sources'][0], 'no bucket diagnostics without stock visibility');

        $serial = collect($workspace['items'])->firstWhere('product_id', $this->serialized->id);
        $this->assertCount(2, $serial['groups'], 'serials grouped by live source');

        $this->expectException(RuntimeException::class);
        app(TransferV3AllocationService::class)->saveProgress($transfer->fresh(), $this->actor, $this->businessA->id, (int) $transfer->current_request_revision_id, $staleConfiguration, [], []);
    }

    /** @test */
    public function allocation_sources_are_filtered_by_condition_across_businesses(): void
    {
        $service = app(TransferV3AllocationService::class);

        $good = collect($service->sourceOptions($this->bulk->id, false, true))->pluck('available', 'location_id')->all();
        $this->assertSame([$this->a1->id => 10, $this->a2->id => 5], $good);

        $broken = collect($service->sourceOptions($this->bulk->id, true, false))->pluck('available', 'location_id')->all();
        $this->assertSame([$this->a1->id => 2], $broken);

        $serialSources = collect($service->sourceOptions($this->serialized->id, false, false))->pluck('location_id')->all();
        $this->assertContains($this->b1->id, $serialSources, 'other business stock is selectable');
    }
}

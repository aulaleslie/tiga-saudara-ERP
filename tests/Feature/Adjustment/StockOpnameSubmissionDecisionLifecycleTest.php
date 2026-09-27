<?php

namespace Tests\Feature\Adjustment;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\CountDraftService;
use Modules\Adjustment\Services\StockOpnameLifecycleService;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Focused tests for tasks 3.1-3.4: submission, edit/delete/index-action
 * guards, rejection, and the guarded approval entry point for the
 * redesigned (normal versioned) Stock Opname lifecycle.
 */
class StockOpnameSubmissionDecisionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $approver;
    protected Setting $setting;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->location = Location::create([
            'name' => 'Gudang Utama',
            'setting_id' => $this->setting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->approver = User::factory()->create(['is_active' => 1]);

        Permission::findOrCreate('adjustments.access', 'web');
        Permission::findOrCreate('adjustments.create', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        Permission::findOrCreate('adjustments.delete', 'web');
        Permission::findOrCreate('adjustments.show', 'web');
        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.view-system-stock', 'web');

        $this->user->givePermissionTo([
            'adjustments.access', 'adjustments.create', 'adjustments.edit',
            'adjustments.delete', 'adjustments.show', 'adjustments.view-system-stock',
        ]);

        $editorRole = \Spatie\Permission\Models\Role::findOrCreate('Stock Opname Editor', 'web');
        $editorRole->givePermissionTo([
            'adjustments.access', 'adjustments.create', 'adjustments.edit',
            'adjustments.delete', 'adjustments.show', 'adjustments.view-system-stock',
        ]);
        $this->user->assignRole($editorRole);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $editorRole->id]);

        $approverRole = \Spatie\Permission\Models\Role::findOrCreate('Stock Opname Approver', 'web');
        $approverRole->givePermissionTo([
            'adjustments.access', 'adjustments.approval', 'adjustments.show', 'adjustments.view-system-stock',
        ]);
        $this->approver->assignRole($approverRole);
        $this->approver->settings()->attach($this->setting->id, ['role_id' => $approverRole->id]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    private function makeStockManagedProduct(): Product
    {
        $unit = Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
            'is_active' => true,
        ]);

        return Product::create([
            'product_name' => 'Produk Uji',
            'product_code' => 'PU-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);
    }

    private function makeMinimalDraftPayload(Product $product): array
    {
        return [
            'schema_version' => 1,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 3,
                    'bad_count' => 0,
                    'serials' => [],
                ],
            ],
        ];
    }

    private function makeDraftAdjustment(?Product $product = null, string $reference = 'ADJ-SUB-1'): Adjustment
    {
        $product = $product ?? $this->makeStockManagedProduct();
        $service = app(CountDraftService::class);

        return $service->saveDraft([
            'reference' => $reference,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ]);
    }

    // --- Task 3.1: submission ---

    public function test_submit_transitions_owned_nonempty_draft_to_waiting_approval_and_records_metadata()
    {
        $adjustment = $this->makeDraftAdjustment();

        $response = $this->patch(route('adjustments.submit', $adjustment));
        $response->assertRedirect(route('adjustments.index'));

        $fresh = $adjustment->fresh();
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $fresh->status);
        $this->assertEquals($this->user->id, $fresh->submitted_by);
        $this->assertNotNull($fresh->submitted_at);
    }

    public function test_submit_sends_exactly_one_approval_needed_notification()
    {
        $adjustment = $this->makeDraftAdjustment();

        $this->patch(route('adjustments.submit', $adjustment));

        $count = Notification::where('user_id', $this->approver->id)
            ->where('category', 'approval')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_submit_is_idempotent_and_does_not_duplicate_notification_or_metadata()
    {
        $adjustment = $this->makeDraftAdjustment();

        $this->patch(route('adjustments.submit', $adjustment));
        $firstSubmittedAt = $adjustment->fresh()->submitted_at;

        $this->travel(1)->minutes();
        $this->patch(route('adjustments.submit', $adjustment->fresh()));

        $fresh = $adjustment->fresh();
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $fresh->status);
        $this->assertEquals($firstSubmittedAt, $fresh->submitted_at);

        $count = Notification::where('user_id', $this->approver->id)
            ->where('category', 'approval')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_submit_rejects_empty_draft()
    {
        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SUB-EMPTY',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
            'location_id' => $this->location->id,
            'count_draft' => ['schema_version' => 1, 'rows' => []],
        ]);

        $response = $this->patch(route('adjustments.submit', $adjustment));

        $response->assertSessionHasErrors('message');
        $this->assertEquals(AdjustmentStatus::Draft, $adjustment->fresh()->status);
    }

    public function test_submit_rejects_waiting_approval_from_wrong_state_transition_attempt_after_approval()
    {
        $adjustment = $this->makeDraftAdjustment();
        $adjustment->fresh()->update(['status' => AdjustmentStatus::Approved]);

        $service = app(StockOpnameLifecycleService::class);

        $this->expectException(ValidationException::class);
        $service->submit($adjustment->fresh(), $this->user);
    }

    public function test_submit_rejects_rejected_document_directly()
    {
        // A rejected document must first be edited (which returns it to
        // draft) before it can be resubmitted; submit() no longer accepts
        // REJECTED directly.
        $adjustment = $this->makeDraftAdjustment();
        $adjustment->fresh()->update([
            'status' => AdjustmentStatus::Rejected,
            'rejected_by' => $this->approver->id,
            'rejected_at' => now(),
            'rejection_reason' => 'Jumlah tidak sesuai.',
        ]);

        $response = $this->patch(route('adjustments.submit', $adjustment->fresh()));

        $response->assertSessionHasErrors('message');
        $this->assertEquals(AdjustmentStatus::Rejected, $adjustment->fresh()->status);
    }

    public function test_submit_allows_resubmission_after_rejected_document_is_edited_back_to_draft()
    {
        $product = $this->makeStockManagedProduct();
        $adjustment = $this->makeDraftAdjustment($product, 'ADJ-SUB-REVISE');
        $adjustment->fresh()->update([
            'status' => AdjustmentStatus::Rejected,
            'rejected_by' => $this->approver->id,
            'rejected_at' => now(),
            'rejection_reason' => 'Jumlah tidak sesuai.',
        ]);

        // Editing a rejected document returns it to draft (CountDraftService::saveDraft).
        $service = app(CountDraftService::class);
        $service->saveDraft([
            'reference' => 'ADJ-SUB-REVISE',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ], $adjustment->fresh());

        $this->assertEquals(AdjustmentStatus::Draft, $adjustment->fresh()->status);

        $response = $this->patch(route('adjustments.submit', $adjustment->fresh()));
        $response->assertRedirect(route('adjustments.index'));

        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
    }

    public function test_submit_button_is_hidden_for_rejected_document_on_show_page()
    {
        $adjustment = $this->makeDraftAdjustment();
        $adjustment->fresh()->update([
            'status' => AdjustmentStatus::Rejected,
            'rejected_by' => $this->approver->id,
            'rejected_at' => now(),
            'rejection_reason' => 'Jumlah tidak sesuai.',
        ]);

        $response = $this->get(route('adjustments.show', $adjustment->fresh()));

        $response->assertOk();
        $response->assertDontSee(route('adjustments.submit', $adjustment), false);
    }

    public function test_submit_rejects_document_owned_by_another_setting()
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'USD', 'code' => 'USD', 'symbol' => '$',
            'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1,
        ]);
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);
        $otherLocation = Location::create([
            'name' => 'Gudang Lain', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SUB-CROSS',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Draft,
            'location_id' => $otherLocation->id,
            'count_draft' => ['schema_version' => 1, 'rows' => [
                ['product_id' => 1, 'good_count' => 1, 'bad_count' => 0, 'serials' => []],
            ]],
        ]);

        $response = $this->patch(route('adjustments.submit', $adjustment));
        $response->assertForbidden();
    }

    // --- Task 3.2: edit/update/delete/index guards ---

    public function test_edit_is_forbidden_for_waiting_approval_document()
    {
        $adjustment = $this->makeDraftAdjustment();
        $this->patch(route('adjustments.submit', $adjustment));

        $response = $this->get(route('adjustments.edit', $adjustment->fresh()));
        $response->assertForbidden();
    }

    public function test_update_is_forbidden_for_waiting_approval_document()
    {
        $adjustment = $this->makeDraftAdjustment();
        $this->patch(route('adjustments.submit', $adjustment));
        $fresh = $adjustment->fresh();

        $response = $this->put(route('adjustments.update', $fresh), [
            'reference' => $fresh->reference,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($this->makeStockManagedProduct()),
        ]);

        $response->assertForbidden();
    }

    public function test_update_is_forbidden_for_approved_document()
    {
        $adjustment = $this->makeDraftAdjustment();
        $adjustment->fresh()->update(['status' => AdjustmentStatus::Approved]);

        $response = $this->put(route('adjustments.update', $adjustment->fresh()), [
            'reference' => $adjustment->reference,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($this->makeStockManagedProduct()),
        ]);

        $response->assertForbidden();
    }

    public function test_delete_is_allowed_for_draft_document()
    {
        $adjustment = $this->makeDraftAdjustment();

        $response = $this->delete(route('adjustments.destroy', $adjustment));
        $response->assertRedirect(route('adjustments.index'));

        $this->assertDatabaseMissing('adjustments', ['id' => $adjustment->id]);
    }

    public function test_delete_is_forbidden_for_waiting_approval_document()
    {
        $adjustment = $this->makeDraftAdjustment();
        $this->patch(route('adjustments.submit', $adjustment));

        $response = $this->delete(route('adjustments.destroy', $adjustment->fresh()));
        $response->assertForbidden();

        $this->assertDatabaseHas('adjustments', ['id' => $adjustment->id]);
    }

    public function test_delete_is_forbidden_for_approved_document()
    {
        $adjustment = $this->makeDraftAdjustment();
        $adjustment->fresh()->update(['status' => AdjustmentStatus::Approved]);

        $response = $this->delete(route('adjustments.destroy', $adjustment->fresh()));
        $response->assertForbidden();

        $this->assertDatabaseHas('adjustments', ['id' => $adjustment->id]);
    }

    public function test_direct_service_call_cannot_bypass_submission_guard()
    {
        $adjustment = $this->makeDraftAdjustment();
        $adjustment->fresh()->update(['status' => AdjustmentStatus::WaitingApproval]);

        $service = app(CountDraftService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->saveDraft([
            'reference' => $adjustment->reference,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($this->makeStockManagedProduct()),
        ], $adjustment->fresh());
    }

    /**
     * Deterministic concurrency test: simulates a concurrent submit()
     * transitioning the row to WAITING_APPROVAL after the caller loaded a
     * (now stale) in-memory model still showing DRAFT. saveDraft() must lock
     * and reload the row inside its own transaction and re-check status
     * against that authoritative row, not the stale in-memory model passed
     * in, or it would silently overwrite WAITING_APPROVAL back to DRAFT.
     */
    public function test_save_draft_cannot_overwrite_a_concurrently_submitted_document()
    {
        $product = $this->makeStockManagedProduct();
        $adjustment = $this->makeDraftAdjustment($product, 'ADJ-RACE-SAVE');

        // Caller loads a model while it is still DRAFT ...
        $staleModel = Adjustment::find($adjustment->id);
        $this->assertEquals(AdjustmentStatus::Draft, $staleModel->status);

        // ... but another request concurrently submits it for approval before
        // this save reaches the database.
        app(StockOpnameLifecycleService::class)->submit($adjustment->fresh(), $this->user);
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);

        $service = app(CountDraftService::class);

        try {
            $service->saveDraft([
                'reference' => 'ADJ-RACE-SAVE',
                'date' => now()->toDateString(),
                'location_id' => $this->location->id,
                'count_draft' => $this->makeMinimalDraftPayload($product),
            ], $staleModel);

            $this->fail('Expected InvalidArgumentException was not thrown for a concurrently submitted document.');
        } catch (\InvalidArgumentException $e) {
            // Expected: saveDraft() re-checked the row-locked authoritative
            // status inside its own transaction and rejected the stale write.
        }

        // The row must still exist and remain WAITING_APPROVAL; it must not
        // have been reset to DRAFT by the stale-model save attempt.
        $this->assertDatabaseHas('adjustments', ['id' => $adjustment->id]);
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
    }

    /**
     * Deterministic concurrency test: simulates a concurrent submit()
     * transitioning the row to WAITING_APPROVAL after the caller loaded a
     * (now stale) in-memory model still showing DRAFT, then attempts to
     * delete using that stale model. Deletion must lock/reload and re-check
     * the authoritative row before deleting, so a concurrently submitted
     * document cannot be deleted out from under the approval workflow.
     */
    public function test_delete_cannot_remove_a_concurrently_submitted_document()
    {
        $product = $this->makeStockManagedProduct();
        $adjustment = $this->makeDraftAdjustment($product, 'ADJ-RACE-DELETE');

        // Caller loads a model while it is still DRAFT ...
        $staleModel = Adjustment::find($adjustment->id);
        $this->assertEquals(AdjustmentStatus::Draft, $staleModel->status);

        // ... but another request concurrently submits it for approval before
        // this delete reaches the database.
        app(StockOpnameLifecycleService::class)->submit($adjustment->fresh(), $this->user);
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);

        try {
            app(StockOpnameLifecycleService::class)->deleteDraft($staleModel, $this->user);

            $this->fail('Expected ValidationException was not thrown for a concurrently submitted document.');
        } catch (ValidationException $e) {
            // Expected: deleteDraft() re-checked the row-locked authoritative
            // status inside its own transaction and rejected the stale delete.
        }

        $this->assertDatabaseHas('adjustments', ['id' => $adjustment->id]);
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
    }

    /**
     * Route-level guard test (not a concurrency simulation): the
     * route-model-bound $adjustment is resolved fresh from the database on
     * every HTTP request, so this only proves the destroy() route rejects a
     * document that is already WAITING_APPROVAL by the time the request is
     * handled — it does not exercise the stale-in-memory-model race that
     * the two direct-service tests above cover.
     */
    public function test_delete_route_rejects_a_document_that_is_already_waiting_approval()
    {
        $product = $this->makeStockManagedProduct();
        $adjustment = $this->makeDraftAdjustment($product, 'ADJ-RACE-DELETE-HTTP');

        app(StockOpnameLifecycleService::class)->submit($adjustment->fresh(), $this->user);

        $response = $this->delete(route('adjustments.destroy', $adjustment->fresh()));
        $response->assertForbidden();

        $this->assertDatabaseHas('adjustments', ['id' => $adjustment->id]);
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
    }

    public function test_delete_draft_rejects_legacy_pending_document_directly()
    {
        $legacyAdjustment = Adjustment::create([
            'reference' => 'ADJ-DEL-LEGACY',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => 'pending',
            'location_id' => $this->location->id,
        ]);

        try {
            app(StockOpnameLifecycleService::class)->deleteDraft($legacyAdjustment, $this->user);

            $this->fail('Expected ValidationException was not thrown for a legacy (non-versioned) document.');
        } catch (ValidationException $e) {
            // Expected: deleteDraft() is scoped to normal versioned documents only.
        }

        $this->assertDatabaseHas('adjustments', ['id' => $legacyAdjustment->id]);
    }

    public function test_delete_draft_rejects_breakage_document_directly()
    {
        $breakageAdjustment = Adjustment::create([
            'reference' => 'ADJ-DEL-BREAKAGE',
            'date' => now()->toDateString(),
            'type' => 'breakage',
            'status' => 'pending',
            'location_id' => $this->location->id,
        ]);

        try {
            app(StockOpnameLifecycleService::class)->deleteDraft($breakageAdjustment, $this->user);

            $this->fail('Expected ValidationException was not thrown for a breakage document.');
        } catch (ValidationException $e) {
            // Expected: deleteDraft() is scoped to normal versioned documents only.
        }

        $this->assertDatabaseHas('adjustments', ['id' => $breakageAdjustment->id]);
    }

    public function test_delete_draft_rejects_approved_versioned_document_directly()
    {
        $adjustment = $this->makeDraftAdjustment(null, 'ADJ-DEL-APPROVED');
        $adjustment->fresh()->update(['status' => AdjustmentStatus::Approved]);

        try {
            app(StockOpnameLifecycleService::class)->deleteDraft($adjustment->fresh(), $this->user);

            $this->fail('Expected ValidationException was not thrown for an approved document.');
        } catch (ValidationException $e) {
            // Expected: only draft/rejected versioned documents are editable/deletable.
        }

        $this->assertDatabaseHas('adjustments', ['id' => $adjustment->id]);
    }

    public function test_delete_draft_rejects_actor_without_delete_permission_directly()
    {
        $adjustment = $this->makeDraftAdjustment(null, 'ADJ-DEL-NOPERM');

        $unprivilegedUser = User::factory()->create(['is_active' => 1]);
        // Deliberately no adjustments.delete permission granted.

        try {
            app(StockOpnameLifecycleService::class)->deleteDraft($adjustment->fresh(), $unprivilegedUser);

            $this->fail('Expected ValidationException was not thrown for a permission-denied actor.');
        } catch (ValidationException $e) {
            // Expected: deleteDraft() enforces adjustments.delete at the service boundary.
        }

        $this->assertDatabaseHas('adjustments', ['id' => $adjustment->id]);
    }

    // --- Task 3.3: rejection ---

    public function test_reject_requires_permission_and_owned_waiting_approval_document()
    {
        $adjustment = $this->makeDraftAdjustment();
        $this->patch(route('adjustments.submit', $adjustment));

        // Acting user lacks adjustments.approval.
        $response = $this->patch(route('adjustments.reject', $adjustment->fresh()), [
            'rejection_reason' => 'Data tidak sesuai.',
        ]);

        $response->assertForbidden();
    }

    public function test_reject_transitions_waiting_approval_to_rejected_with_reason_and_metadata()
    {
        $adjustment = $this->makeDraftAdjustment();
        $this->patch(route('adjustments.submit', $adjustment));

        $response = $this->actingAs($this->approver)
            ->patch(route('adjustments.reject', $adjustment->fresh()), [
                'rejection_reason' => 'Jumlah fisik tidak sesuai catatan.',
            ]);

        $response->assertRedirect(route('adjustments.index'));

        $fresh = $adjustment->fresh();
        $this->assertEquals(AdjustmentStatus::Rejected, $fresh->status);
        $this->assertEquals($this->approver->id, $fresh->rejected_by);
        $this->assertNotNull($fresh->rejected_at);
        $this->assertEquals('JUMLAH FISIK TIDAK SESUAI CATATAN.', $fresh->rejection_reason);
    }

    public function test_reject_requires_a_reason()
    {
        $adjustment = $this->makeDraftAdjustment();
        $this->patch(route('adjustments.submit', $adjustment));

        $response = $this->actingAs($this->approver)
            ->patch(route('adjustments.reject', $adjustment->fresh()), [
                'rejection_reason' => '   ',
            ]);

        $response->assertSessionHasErrors('rejection_reason');
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
    }

    public function test_reject_is_idempotent()
    {
        $adjustment = $this->makeDraftAdjustment();
        $this->patch(route('adjustments.submit', $adjustment));

        $service = app(StockOpnameLifecycleService::class);
        session(['setting_id' => $this->setting->id]);
        $service->reject($adjustment->fresh(), $this->approver, 'Alasan pertama.');
        $firstRejectedAt = $adjustment->fresh()->rejected_at;

        $this->travel(1)->minutes();
        $again = $service->reject($adjustment->fresh(), $this->approver, 'Alasan berbeda.');

        $this->assertEquals(AdjustmentStatus::Rejected, $again->status);
        $this->assertEquals($firstRejectedAt, $again->rejected_at);
        // Idempotent no-op: the second call's reason is not applied over the first.
        $this->assertEquals('ALASAN PERTAMA.', $again->rejection_reason);
    }

    public function test_reject_does_not_mutate_inventory()
    {
        $product = $this->makeStockManagedProduct();
        $stock = \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $adjustment = $this->makeDraftAdjustment($product, 'ADJ-REJ-NOMUTATE');
        $this->patch(route('adjustments.submit', $adjustment));

        $this->actingAs($this->approver)
            ->patch(route('adjustments.reject', $adjustment->fresh()), [
                'rejection_reason' => 'Perlu ditinjau ulang.',
            ]);

        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_notification_state_through_rejected_draft_resubmit_lifecycle()
    {
        $product = $this->makeStockManagedProduct();
        $adjustment = $this->makeDraftAdjustment($product, 'ADJ-NOTIF-E2E');

        // Submit -> one approval-needed notification for the approver.
        $this->patch(route('adjustments.submit', $adjustment));
        $this->assertSame(
            1,
            Notification::where('user_id', $this->approver->id)->where('category', 'approval')->count()
        );

        // Reject -> approval notification resolved, one revision-needed notification for the editor.
        $this->actingAs($this->approver)->patch(route('adjustments.reject', $adjustment->fresh()), [
            'rejection_reason' => 'Jumlah tidak sesuai fisik gudang.',
        ]);

        $this->assertSame(
            0,
            Notification::where('user_id', $this->approver->id)
                ->where('category', 'approval')
                ->whereNull('resolved_at')
                ->count()
        );
        $this->assertSame(
            1,
            Notification::where('user_id', $this->user->id)
                ->where('category', 'revision')
                ->whereNull('resolved_at')
                ->count()
        );

        // Edit the rejected document back to draft -> revision notification resolved.
        $this->actingAs($this->user);
        app(CountDraftService::class)->saveDraft([
            'reference' => 'ADJ-NOTIF-E2E',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $this->makeMinimalDraftPayload($product),
        ], $adjustment->fresh());

        $this->assertEquals(AdjustmentStatus::Draft, $adjustment->fresh()->status);
        $this->assertSame(
            0,
            Notification::where('user_id', $this->user->id)
                ->where('category', 'revision')
                ->whereNull('resolved_at')
                ->count()
        );

        // Resubmit -> a fresh approval-needed notification for the approver.
        $this->patch(route('adjustments.submit', $adjustment->fresh()));

        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
        $this->assertSame(
            1,
            Notification::where('user_id', $this->approver->id)
                ->where('category', 'approval')
                ->whereNull('resolved_at')
                ->count()
        );
    }

    public function test_reject_rejects_document_in_wrong_state()
    {
        $adjustment = $this->makeDraftAdjustment();
        // Still draft, never submitted.

        $response = $this->actingAs($this->approver)
            ->patch(route('adjustments.reject', $adjustment), [
                'rejection_reason' => 'Alasan.',
            ]);

        $response->assertSessionHasErrors('message');
        $this->assertEquals(AdjustmentStatus::Draft, $adjustment->fresh()->status);
    }

    // --- Task 3.4: guarded approval entry point / legacy isolation ---

    public function test_approve_on_waiting_approval_versioned_document_posts_through_section_4_approval_service()
    {
        // Destination setting is PKP (see setUp()), so approval requires a
        // real Tax record to classify entered quantities into.
        \Modules\Setting\Entities\Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true]);

        $product = $this->makeStockManagedProduct();
        $stock = \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $adjustment = $this->makeDraftAdjustment($product, 'ADJ-APR-GUARD');
        $this->patch(route('adjustments.submit', $adjustment));

        $response = $this->actingAs($this->approver)
            ->patch(route('adjustments.approve', $adjustment->fresh()));

        $response->assertRedirect(route('adjustments.index'));
        $response->assertSessionDoesntHaveErrors();

        // The draft payload's entered good_count (3) is now the atomic
        // section-4 approval poster's applied absolute count, not the
        // "under development" no-op section 3 previously stubbed out.
        $fresh = $adjustment->fresh();
        $this->assertEquals(AdjustmentStatus::Approved, $fresh->status);
        $this->assertEquals($this->approver->id, $fresh->approved_by);
        $this->assertEquals(3, $stock->fresh()->quantity_tax);
        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'type' => 'ADJ',
        ]);
    }

    public function test_approve_requires_adjustments_approval_permission_for_versioned_document()
    {
        $adjustment = $this->makeDraftAdjustment();
        $this->patch(route('adjustments.submit', $adjustment));

        // Acting user (owner) lacks adjustments.approval.
        $response = $this->patch(route('adjustments.approve', $adjustment->fresh()));
        $response->assertForbidden();
    }

    public function test_approve_rejects_draft_versioned_document()
    {
        $adjustment = $this->makeDraftAdjustment();

        $response = $this->actingAs($this->approver)
            ->patch(route('adjustments.approve', $adjustment));

        $response->assertSessionHasErrors('message');
        $this->assertEquals(AdjustmentStatus::Draft, $adjustment->fresh()->status);
    }

    public function test_approve_rejects_document_owned_by_another_setting()
    {
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'USD', 'code' => 'USD', 'symbol' => '$',
            'thousand_separator' => ',', 'decimal_separator' => '.', 'exchange_rate' => 1,
        ]);
        $otherSetting = Setting::create([
            'company_name' => 'Other Co', 'company_email' => 'other@company.com',
            'company_phone' => '000', 'notification_email' => 'n@company.com',
            'footer_text' => 'F', 'company_address' => 'Bandung',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);
        $otherLocation = Location::create([
            'name' => 'Gudang Lain', 'setting_id' => $otherSetting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-APR-CROSS',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::WaitingApproval,
            'location_id' => $otherLocation->id,
            'count_draft' => ['schema_version' => 1, 'rows' => [
                ['product_id' => 1, 'good_count' => 1, 'bad_count' => 0, 'serials' => []],
            ]],
        ]);

        $response = $this->actingAs($this->approver)
            ->patch(route('adjustments.approve', $adjustment));

        $response->assertSessionHasErrors('message');
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $adjustment->fresh()->status);
    }

    public function test_legacy_approve_normal_still_posts_non_versioned_documents()
    {
        Permission::findOrCreate('adjustments.breakage.approval', 'web');
        $this->approver->givePermissionTo('adjustments.breakage.approval');

        $product = $this->makeStockManagedProduct();
        $stock = \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $legacyAdjustment = Adjustment::create([
            'reference' => 'ADJ-LEGACY-1',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => 'pending',
            'location_id' => $this->location->id,
        ]);

        \Modules\Adjustment\Entities\AdjustedProduct::create([
            'adjustment_id' => $legacyAdjustment->id,
            'product_id' => $product->id,
            'quantity' => 8,
            'quantity_tax' => 8,
            'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]),
            'is_taxable' => 1,
            'type' => 'sub',
        ]);

        $response = $this->actingAs($this->approver)
            ->patch(route('adjustments.approve', $legacyAdjustment));

        $response->assertRedirect(route('adjustments.index'));
        $this->assertEquals(AdjustmentStatus::Approved, $legacyAdjustment->fresh()->status);
        $this->assertEquals(8, $stock->fresh()->quantity_tax);
    }
}

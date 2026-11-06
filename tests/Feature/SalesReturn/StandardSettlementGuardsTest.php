<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\SalesReturn\SaleReturnSettlementForm;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Tests\TestCase;

/** Settlement, approval, dispatch and receiving are authoritative, replay-safe and atomic. */
class StandardSettlementGuardsTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    protected $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        Gate::before(fn () => true);   // permissions are not what is under test here
        $this->actingAs(User::factory()->create());
        $this->notifications = \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing();
        $this->app->instance(DocumentNotificationService::class, $this->notifications);
    }

    private function form(SaleReturn $return)
    {
        return Livewire::test(SaleReturnSettlementForm::class, ['saleReturnId' => $return->id]);
    }

    private function http(): self
    {
        return $this->withSession(['setting_id' => 1]);
    }

    // ---------------------------------------------------------------- 3.3 state & lineage

    /** @test */
    public function settlement_is_refused_for_unapproved_or_unreceived_returns_without_writes(): void
    {
        $flow = $this->makeDispatchedSale(5);

        foreach ([
            ['status' => 'Pending Approval', 'approval_status' => 'pending'],
            ['status' => 'Awaiting Receiving', 'approval_status' => 'approved'],
            ['status' => 'Awaiting Settlement', 'approval_status' => 'approved'],   // never actually received
        ] as $state) {
            $return = $this->makeReturnFor($flow, 1, $state);
            $this->form($return)
                ->set('settlementLines.0.method', 'CASH_REFUND')
                ->call('submitLine', 0)
                ->assertStatus(409);
        }

        $this->assertSame(0, Settlement::query()->count());
    }

    /** @test */
    public function a_return_of_another_business_cannot_be_settled(): void
    {
        $foreign = $this->makeDispatchedSale(5, false, 2);
        $return = $this->awaitingSettlement($foreign);   // setting 2; session business is 1

        // (the guard runs first, so even editing a field of a foreign document is refused)
        $this->form($return)
            ->call('submitLine', 0)
            ->assertStatus(403);

        $this->assertSame(0, Settlement::query()->count());
    }

    /** @test */
    public function forged_detail_or_serial_lineage_is_rejected(): void
    {
        $flow = $this->makeDispatchedSale(3, true);
        $other = $this->makeDispatchedSale(3, true);
        $return = $this->awaitingSettlement($flow, 1, [$flow['serials'][0]->id]);
        $foreignReturn = $this->awaitingSettlement($other, 1, [$other['serials'][0]->id]);
        $foreignDetailId = $foreignReturn->saleReturnDetails()->value('id');

        // detail of another return
        $this->form($return)
            ->set('settlementLines.0.method', 'REPAIR')
            ->set('settlementLines.0.detail_id', $foreignDetailId)
            ->call('submitLine', 0)
            ->assertStatus(409);

        // serial not claimed on this detail
        $this->form($return)
            ->set('settlementLines.0.method', 'REPAIR')
            ->set('settlementLines.0.serial_number_id', $flow['serials'][2]->id)
            ->call('submitLine', 0)
            ->assertStatus(409);

        $this->assertSame(0, Settlement::query()->count());
    }

    /** @test */
    public function nominal_cannot_exceed_the_authoritative_document_value(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow, 2);   // value 2000

        $this->form($return)
            ->set('settlementLines.0.method', 'CASH_REFUND')
            ->set('settlementLines.0.max_nominal', 999999)   // forged ceiling
            ->set('settlementLines.0.nominal', 5000)
            ->call('submitLine', 0);

        $this->assertSame(0, Settlement::query()->count());
    }

    // ---------------------------------------------------------------- 3.1 methods

    /** @test */
    public function only_the_three_current_methods_are_accepted_for_new_submissions(): void
    {
        $flow = $this->makeDispatchedSale(5);

        foreach (['CUSTOMER_CREDIT', 'MODIFY_SALE', 'NOT_A_METHOD'] as $legacy) {
            $return = $this->awaitingSettlement($flow);
            $this->form($return)
                ->set('settlementLines.0.method', $legacy)
                ->call('submitLine', 0)
                ->assertHasErrors(['settlementLines.0.method']);
        }
        $this->assertSame(0, Settlement::query()->count());

        foreach (['REPAIR', 'CASH_REFUND'] as $method) {
            $return = $this->awaitingSettlement($flow);
            $this->form($return)
                ->set('settlementLines.0.method', $method)
                ->call('submitLine', 0)
                ->assertHasNoErrors();
            $this->assertSame('SUBMITTED', Settlement::query()->where('sale_return_id', $return->id)->value('status'));
        }
    }

    /** @test */
    public function cannot_process_requires_a_real_reason(): void
    {
        $flow = $this->makeDispatchedSale(5);

        foreach ([null, '', '   '] as $notes) {
            $return = $this->awaitingSettlement($flow);
            $this->form($return)
                ->set('settlementLines.0.method', 'UNPROCESSED')
                ->set('settlementLines.0.notes', $notes)
                ->call('submitLine', 0)
                ->assertHasErrors(['settlementLines.0.notes']);
        }
        $this->assertSame(0, Settlement::query()->count());
    }

    /** @test */
    public function historical_method_labels_remain_readable(): void
    {
        $labels = SaleReturnDetail::settlementMethods();
        $this->assertArrayHasKey('CUSTOMER_CREDIT', $labels);
        $this->assertArrayHasKey('MODIFY_SALE', $labels);
        $this->assertArrayNotHasKey('CUSTOMER_CREDIT', SaleReturnDetail::selectableSettlementMethods());
        $this->assertCount(3, SaleReturnDetail::selectableSettlementMethods());
    }

    // ---------------------------------------------------------------- 3.4 resubmission

    /** @test */
    public function approved_dispatching_or_dispatched_rows_cannot_be_resubmitted_even_with_a_blank_hydrated_id(): void
    {
        $flow = $this->makeDispatchedSale(5);

        foreach ([
            Settlement::STATUS_SUBMITTED,
            Settlement::STATUS_APPROVED,
            Settlement::STATUS_APPROVED_AWAITING_DISPATCH,
            Settlement::STATUS_DISPATCH_REQUESTED,
            Settlement::STATUS_DISPATCHED,
        ] as $status) {
            $return = $this->awaitingSettlement($flow);
            $row = $this->settlementFor($return, $status, 'REPAIR');
            $before = $row->fresh()->toArray();

            $this->form($return)
                ->set('settlementLines.0.id', null)               // forged: pretend it is a new row
                ->set('settlementLines.0.method', 'CASH_REFUND')
                ->call('submitLine', 0)
                ->assertStatus(409);

            $this->assertSame($before, $row->fresh()->toArray(), "status {$status} must be untouched");
        }
    }

    /** @test */
    public function a_draft_or_rejected_row_can_be_submitted_and_reset_through_the_authoritative_row(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow);
        $rejected = $this->settlementFor($return, Settlement::STATUS_REJECTED, 'REPAIR');
        $rejected->update(['rejection_reason' => 'no']);

        $this->form($return)->call('resetLine', 0);
        $this->assertSame(Settlement::STATUS_DRAFT, $rejected->fresh()->status);

        $this->notifications->shouldReceive('notifyApprovalNeeded')->once();
        $this->form($return)
            ->set('settlementLines.0.method', 'REPAIR')
            ->call('submitLine', 0)
            ->assertHasNoErrors();
        $this->assertSame(Settlement::STATUS_SUBMITTED, $rejected->fresh()->status);
        $this->assertSame(1, Settlement::query()->count());
    }

    // ---------------------------------------------------------------- 3.4 / 3.5 dispatch

    private function awaitingDispatchRow(SaleReturn $return, string $method = 'REPAIR'): Settlement
    {
        return $this->settlementFor($return, Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $method);
    }

    /** @test */
    public function one_invalid_bulk_dispatch_item_rolls_back_the_whole_request_and_emits_nothing(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow, 2);
        $good = $this->awaitingDispatchRow($return);
        $notReady = $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'REPAIR');   // not approved yet
        $this->notifications->shouldNotReceive('notifyApprovalNeeded');

        $this->http()->post(route('sale-returns.dispatch.request', $return), [
            'items' => [['id' => $good->id], ['id' => $notReady->id]],
        ])->assertSessionHas('error');

        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $good->fresh()->status, 'earlier valid item rolled back');
        $this->assertSame(Settlement::STATUS_SUBMITTED, $notReady->fresh()->status);
    }

    /** @test */
    public function a_bulk_item_from_another_return_is_never_silently_skipped(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow);
        $other = $this->awaitingSettlement($flow);
        $good = $this->awaitingDispatchRow($return);
        $foreign = $this->awaitingDispatchRow($other);

        $this->http()->post(route('sale-returns.dispatch.request', $return), [
            'items' => [['id' => $good->id], ['id' => $foreign->id]],
        ])->assertSessionHas('error');

        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $good->fresh()->status);
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $foreign->fresh()->status);
    }

    /** @test */
    public function bulk_and_item_dispatch_apply_the_same_prerequisites(): void
    {
        $flow = $this->makeDispatchedSale(3, true);
        $return = $this->awaitingSettlement($flow, 1, [$flow['serials'][0]->id]);

        foreach (['bulk', 'item'] as $path) {
            $row = $this->settlementFor($return->fresh(), Settlement::STATUS_APPROVED_AWAITING_DISPATCH, 'REPAIR', $flow['serials'][0]->id);
            $row->update(['sale_return_id' => $return->id]);

            // serial-tracked product with no outgoing serial
            $response = $path === 'bulk'
                ? $this->http()->post(route('sale-returns.dispatch.request', $return), ['items' => [['id' => $row->id]]])
                : $this->http()->post(route('sale-return-settlements.item.dispatch', $row), []);
            $response->assertSessionHas('error');
            $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status, $path);

            // a non-repair method is not dispatchable
            $row->update(['method' => 'CASH_REFUND']);
            $response = $path === 'bulk'
                ? $this->http()->post(route('sale-returns.dispatch.request', $return), ['items' => [['id' => $row->id, 'dispatched_serial_number' => $flow['serials'][0]->serial_number]]])
                : $this->http()->post(route('sale-return-settlements.item.dispatch', $row), ['dispatched_serial_number' => $flow['serials'][0]->serial_number]);
            $response->assertSessionHas('error');
            $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status, $path);

            $row->delete();
        }
    }

    /** @test */
    public function a_valid_dispatch_request_moves_to_requested_and_notifies_only_after_commit(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow);
        $row = $this->awaitingDispatchRow($return);
        $this->notifications->shouldReceive('notifyApprovalNeeded')->once();

        $this->http()->post(route('sale-return-settlements.item.dispatch', $row), [])->assertSessionHas('success');

        $this->assertSame(Settlement::STATUS_DISPATCH_REQUESTED, $row->fresh()->status);
    }

    /** @test */
    public function dispatch_approval_replay_finds_the_row_no_longer_requested_and_changes_nothing(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow);
        $row = $this->settlementFor($return, Settlement::STATUS_DISPATCHED, 'REPAIR');
        $before = $row->fresh()->toArray();

        $this->http()->post(route('sale-return-settlements.item.dispatch.approve', $row))->assertSessionHas('error');
        $this->http()->post(route('sale-return-settlements.item.dispatch.reject', $row), ['rejection_reason' => 'x'])->assertSessionHas('error');

        $this->assertSame($before, $row->fresh()->toArray());
    }

    // ---------------------------------------------------------------- 3.5 replay & rollback

    /** @test */
    public function approving_a_cash_refund_twice_pays_out_once(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow);
        $row = $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'CASH_REFUND');

        $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertSessionHas('success');
        $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertSessionHas('error');

        $this->assertSame(1, SaleReturnPayment::query()->where('sale_return_id', $return->id)->count());
        $this->assertSame($row->id, (int) SaleReturnPayment::query()->value('sale_return_item_settlement_id'));
    }

    /** @test */
    public function the_database_rejects_a_second_payout_for_the_same_settlement_row(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow);
        $row = $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'CASH_REFUND');
        $payload = [
            'sale_return_id' => $return->id, 'amount' => 1000, 'date' => now()->toDateString(),
            'reference' => 'SRPAY/X', 'payment_method' => 'Cash Refund', 'sale_return_item_settlement_id' => $row->id,
        ];

        SaleReturnPayment::create($payload);

        $this->expectException(QueryException::class);
        SaleReturnPayment::create($payload);
    }

    /** @test */
    public function an_injected_effect_failure_rolls_back_the_approval_and_emits_no_notification(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow);
        $row = $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'CASH_REFUND');
        // A pre-existing anchored payout makes the effect insert fail inside the transaction.
        SaleReturnPayment::create([
            'sale_return_id' => $return->id, 'amount' => 1000, 'date' => now()->toDateString(),
            'reference' => 'SRPAY/PRE', 'payment_method' => 'Cash Refund', 'sale_return_item_settlement_id' => $row->id,
        ]);
        $this->notifications->shouldNotReceive('resolveApproval');
        $this->notifications->shouldNotReceive('resolveRevision');

        $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertSessionHas('error');

        $this->assertSame(Settlement::STATUS_SUBMITTED, $row->fresh()->status, 'approval rolled back');
        $this->assertSame(1, SaleReturnPayment::query()->count());
    }

    /** @test */
    public function settlement_approval_is_refused_for_another_business_and_for_unreceived_returns(): void
    {
        $foreign = $this->makeDispatchedSale(5, false, 2);
        $foreignReturn = $this->awaitingSettlement($foreign);
        $foreignRow = $this->settlementFor($foreignReturn, Settlement::STATUS_SUBMITTED, 'CASH_REFUND');
        $this->http()->post(route('sale-return-settlements.item.approve', $foreignRow))->assertStatus(403);
        $this->assertSame(Settlement::STATUS_SUBMITTED, $foreignRow->fresh()->status);

        $flow = $this->makeDispatchedSale(5);
        $unreceived = $this->makeReturnFor($flow, 1, ['status' => 'Awaiting Receiving', 'approval_status' => 'approved']);
        $row = $this->settlementFor($unreceived, Settlement::STATUS_SUBMITTED, 'CASH_REFUND');
        $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertSessionHas('error');
        $this->assertSame(0, SaleReturnPayment::query()->count());
    }

    /** @test */
    public function receiving_twice_adds_stock_once_and_a_foreign_business_cannot_receive(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->makeReturnFor($flow, 2, ['status' => 'Awaiting Receiving', 'approval_status' => 'approved']);

        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();
        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();   // replay

        $this->assertSame(1, Transaction::query()->where('product_id', $flow['product']->id)->where('type', 'like', 'SALE_RETURN_GOOD%')->count());
        $this->assertSame(2, (int) $flow['product']->fresh()->product_quantity);
        $this->assertSame(2, (int) ProductStock::query()->where('product_id', $flow['product']->id)->value('quantity'));
        $this->assertSame('AWAITING SETTLEMENT', strtoupper((string) $return->fresh()->status));

        $foreign = $this->makeDispatchedSale(5, false, 2);
        $foreignReturn = $this->makeReturnFor($foreign, 1, ['status' => 'Awaiting Receiving', 'approval_status' => 'approved']);
        $this->http()->post(route('sale-returns.receive', $foreignReturn))->assertStatus(403);
        $this->assertNull($foreignReturn->fresh()->received_at);
    }

    /** @test */
    public function approve_and_reject_re_read_authoritative_state_and_respect_business_scope(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->makeReturnFor($flow, 1);

        $this->http()->post(route('sale-returns.approve', $return))->assertRedirect();
        $this->assertSame('approved', strtolower((string) $return->fresh()->approval_status));

        // replay and reject-after-approve change nothing
        $approvedAt = $return->fresh()->approved_at;
        $this->http()->post(route('sale-returns.approve', $return))->assertRedirect();
        $this->http()->post(route('sale-returns.reject', $return), ['reason' => 'late'])->assertRedirect();
        $this->assertSame('approved', strtolower((string) $return->fresh()->approval_status));
        $this->assertEquals($approvedAt, $return->fresh()->approved_at);

        $foreign = $this->makeDispatchedSale(5, false, 2);
        $foreignReturn = $this->makeReturnFor($foreign, 1);
        $this->http()->post(route('sale-returns.approve', $foreignReturn))->assertStatus(403);
        $this->assertSame('pending', strtolower((string) $foreignReturn->fresh()->approval_status));
    }

    // ---------------------------------------------------------------- lock-time re-read vs stale models

    /** @test */
    public function a_stale_hydrated_return_cannot_receive_again_because_the_locked_state_decides(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->makeReturnFor($flow, 2, ['status' => 'Awaiting Receiving', 'approval_status' => 'approved']);
        $stale = SaleReturn::find($return->id);   // what a racing request already holds in memory

        // Another request receives it first.
        $return->update(['status' => 'Awaiting Settlement', 'received_at' => now()]);

        app(\Modules\SalesReturn\Http\Controllers\SalesReturnController::class)->receive($stale);

        $this->assertSame(0, Transaction::query()->where('type', 'like', 'SALE_RETURN_GOOD%')->count());
        $this->assertSame(0, (int) $flow['product']->fresh()->product_quantity);
    }

    /** @test */
    public function a_stale_pending_return_cannot_be_approved_or_rejected_over_a_newer_state(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->makeReturnFor($flow, 1);
        $stale = SaleReturn::find($return->id);
        $return->update(['approval_status' => 'approved', 'status' => 'Awaiting Receiving', 'approved_at' => now()]);
        $approvedAt = $return->fresh()->approved_at;

        $controller = app(\Modules\SalesReturn\Http\Controllers\SalesReturnController::class);
        $controller->approve($stale);
        $controller->reject(request()->merge(['reason' => 'late']), $stale);

        $fresh = $return->fresh();
        $this->assertSame('approved', strtolower((string) $fresh->approval_status));
        $this->assertEquals($approvedAt, $fresh->approved_at);
        $this->assertNull($fresh->rejected_at);
    }

    /** @test */
    public function a_stale_submitted_settlement_row_cannot_be_approved_twice(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow);
        $row = $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'CASH_REFUND');
        $stale = Settlement::find($row->id);   // still SUBMITTED in memory

        $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertSessionHas('success');
        $this->assertSame(1, SaleReturnPayment::query()->count());

        $controller = app(\Modules\SalesReturn\Http\Controllers\SalesReturnSettlementController::class);
        $controller->approveItemSettlement(request(), $stale);

        $this->assertSame(1, SaleReturnPayment::query()->count(), 'the locked re-read found the row already approved');
    }

    /** @test */
    public function a_stale_requested_dispatch_cannot_be_approved_after_it_was_rejected(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow);
        $row = $this->settlementFor($return, Settlement::STATUS_DISPATCH_REQUESTED, 'REPAIR');
        $stale = Settlement::find($row->id);
        $row->update(['status' => Settlement::STATUS_APPROVED_AWAITING_DISPATCH]);   // rejected meanwhile

        app(\Modules\SalesReturn\Http\Controllers\SaleReturnDispatchController::class)
            ->approveDispatch(request(), $stale);

        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status);
        $this->assertNull($row->fresh()->dispatch_approved_at);
    }
}

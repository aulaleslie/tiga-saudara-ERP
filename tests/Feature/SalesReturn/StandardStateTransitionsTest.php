<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\SalesReturn\SaleReturnEditForm;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Tests\TestCase;

/**
 * One focused test per documented transition (see docs/sales-return-repair-guide.md, "Standard
 * state transitions"). Each runs on relationally real records with foreign keys enabled.
 */
class StandardStateTransitionsTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->app->instance(DocumentNotificationService::class, \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing());
    }

    private function http(): self
    {
        return $this->withSession(['setting_id' => 1]);
    }

    private function pending(int $qty = 1): SaleReturn
    {
        return $this->makeReturnFor($this->makeDispatchedSale(5), $qty);
    }

    // ------------------------------------------------ header: Pending Approval

    /** @test T2: Pending Approval -> approve -> Awaiting Receiving */
    public function pending_approval_to_awaiting_receiving_on_approve(): void
    {
        $return = $this->pending();

        $this->http()->post(route('sale-returns.approve', $return))->assertRedirect();

        $fresh = $return->fresh();
        $this->assertSame('approved', strtolower((string) $fresh->approval_status));
        $this->assertSame('AWAITING RECEIVING', strtoupper((string) $fresh->status));
        $this->assertNotNull($fresh->approved_at);
    }

    /** @test T3: Pending Approval -> reject -> Rejected, and a rejected return may be edited again */
    public function pending_approval_to_rejected_on_reject_and_rejected_returns_stay_editable(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->makeReturnFor($flow, 1);

        $this->http()->post(route('sale-returns.reject', $return), ['reason' => 'Salah barang'])->assertRedirect();

        $fresh = $return->fresh();
        $this->assertSame('rejected', strtolower((string) $fresh->approval_status));
        $this->assertSame('REJECTED', strtoupper((string) $fresh->status));
        $this->assertSame('SALAH BARANG', strtoupper((string) $fresh->rejection_reason));

        Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $fresh])
            ->set('rows', [[
                'dispatch_detail_id' => $flow['dispatchDetail']->id, 'product_id' => $flow['product']->id,
                'quantity' => 2, 'unit_price' => 1000, 'total' => 2000, 'serial_numbers' => [],
            ]])
            ->call('submit');
        $this->assertSame(2, (int) $fresh->saleReturnDetails()->sum('quantity'));
    }

    /** @test T1: edit is a Pending/Rejected-only action */
    public function approved_returns_cannot_be_edited(): void
    {
        $return = $this->makeReturnFor($this->makeDispatchedSale(5), 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);

        $this->http()->get(route('sale-returns.edit', $return))->assertSuccessful();   // opens read-only (approval lock)
        $component = Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $return]);
        $component->call('submit');

        $this->assertSame(1, (int) $return->fresh()->saleReturnDetails()->sum('quantity'));
    }

    /** @test T4: archive and delete are only possible before goods are received */
    public function archive_and_delete_are_allowed_only_before_receiving(): void
    {
        $open = $this->pending();
        $this->http()->put(route('sale-returns.archive', $open))->assertRedirect();
        $this->assertNotNull(SaleReturn::withArchived()->find($open->id)->archived_at);

        $deletable = $this->pending();
        $this->http()->delete(route('sale-returns.destroy', $deletable))->assertRedirect();
        $this->assertNull(SaleReturn::withArchived()->find($deletable->id));

        $received = $this->makeReturnFor($this->makeDispatchedSale(5), 1, [
            'approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now(),
        ]);
        $this->http()->put(route('sale-returns.archive', $received))->assertForbidden();
        $this->http()->delete(route('sale-returns.destroy', $received))->assertForbidden();
        $this->assertNull(SaleReturn::withArchived()->find($received->id)->archived_at);
        $this->assertNotNull(SaleReturn::withArchived()->find($received->id));
    }

    // ------------------------------------------------ header: Awaiting Receiving -> Awaiting Settlement

    /** @test T5: Awaiting Receiving -> receive -> Awaiting Settlement (once) */
    public function awaiting_receiving_to_awaiting_settlement_on_receive(): void
    {
        $return = $this->makeReturnFor($this->makeDispatchedSale(5), 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);

        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();

        $fresh = $return->fresh();
        $this->assertSame('AWAITING SETTLEMENT', strtoupper((string) $fresh->status));
        $this->assertNotNull($fresh->received_at);
    }

    /** @test receive is refused unless the return is approved and awaiting receiving */
    public function receive_is_refused_for_pending_returns(): void
    {
        $return = $this->pending();

        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();

        $this->assertNull($return->fresh()->received_at);
    }

    // ------------------------------------------------ settlement rows

    /** @test S1: DRAFT -> submit -> SUBMITTED is covered in StandardSettlementGuardsTest; S2: SUBMITTED -> approve (repair) -> APPROVED_AWAITING_DISPATCH */
    public function submitted_repair_row_becomes_approved_awaiting_dispatch(): void
    {
        $return = $this->awaitingSettlement($this->makeDispatchedSale(5));
        $row = $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'REPAIR');

        $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertSessionHas('success');

        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status);
        $this->assertSame('AWAITING SETTLEMENT', strtoupper((string) $return->fresh()->status), 'a repair row keeps the return open until it is dispatched');
    }

    /** @test S3: SUBMITTED -> approve (cash refund) -> APPROVED, return rolls up to Completed */
    public function submitted_cash_refund_row_becomes_approved_and_completes_the_return(): void
    {
        $return = $this->awaitingSettlement($this->makeDispatchedSale(1));
        $row = $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'CASH_REFUND');

        $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertSessionHas('success');

        $this->assertSame(Settlement::STATUS_APPROVED, $row->fresh()->status);
        $this->assertSame('COMPLETED', strtoupper((string) $return->fresh()->status));
    }

    /** @test S4: SUBMITTED -> reject -> REJECTED; S5: REJECTED -> reset -> DRAFT */
    public function submitted_row_can_be_rejected_and_then_reset_to_draft(): void
    {
        $return = $this->awaitingSettlement($this->makeDispatchedSale(5));
        $row = $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'REPAIR');

        $this->http()->post(route('sale-return-settlements.item.reject', $row), ['rejection_reason' => 'Bukti kurang'])->assertSessionHas('success');
        $this->assertSame(Settlement::STATUS_REJECTED, $row->fresh()->status);
        $this->assertSame('AWAITING SETTLEMENT', strtoupper((string) $return->fresh()->status));

        Livewire::test(\App\Livewire\SalesReturn\SaleReturnSettlementForm::class, ['saleReturnId' => $return->id])
            ->call('resetLine', 0);
        $this->assertSame(Settlement::STATUS_DRAFT, $row->fresh()->status);
    }

    /** @test S6: APPROVED_AWAITING_DISPATCH -> request -> DISPATCH_REQUESTED -> reject -> APPROVED_AWAITING_DISPATCH */
    public function dispatch_request_can_be_rejected_back_to_awaiting_dispatch(): void
    {
        $return = $this->awaitingSettlement($this->makeDispatchedSale(5));
        $row = $this->settlementFor($return, Settlement::STATUS_APPROVED_AWAITING_DISPATCH, 'UNPROCESSED');

        $this->http()->post(route('sale-return-settlements.item.dispatch', $row), [])->assertSessionHas('success');
        $this->assertSame(Settlement::STATUS_DISPATCH_REQUESTED, $row->fresh()->status);

        $this->http()->post(route('sale-return-settlements.item.dispatch.reject', $row), ['rejection_reason' => 'Belum siap'])->assertSessionHas('success');
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status);
    }

    /** @test S7: DISPATCH_REQUESTED -> approve (same serial repair) -> DISPATCHED, serial leaves the warehouse */
    public function approved_dispatch_of_the_same_serial_marks_the_row_dispatched(): void
    {
        $flow = $this->makeDispatchedSale(2, true);
        $serial = $flow['serials'][0];
        $return = $this->awaitingSettlement($flow, 1, [$serial->id]);
        $row = $this->settlementFor($return, Settlement::STATUS_DISPATCH_REQUESTED, 'REPAIR', $serial->id);
        $row->update(['dispatched_serial_number' => $serial->serial_number]);

        $this->http()->post(route('sale-return-settlements.item.dispatch.approve', $row))->assertSessionHas('success');

        $this->assertSame(Settlement::STATUS_DISPATCHED, $row->fresh()->status);
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $serial->fresh()->status);
    }

    /** @test S8: DISPATCHED / APPROVED rows are terminal for settlement actions */
    public function terminal_rows_accept_no_further_settlement_actions(): void
    {
        $return = $this->awaitingSettlement($this->makeDispatchedSale(5));

        foreach ([Settlement::STATUS_APPROVED, Settlement::STATUS_DISPATCHED] as $status) {
            $row = $this->settlementFor($return->fresh(), $status, 'REPAIR');
            $before = $row->fresh()->toArray();

            $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertSessionHas('error');
            $this->http()->post(route('sale-return-settlements.item.reject', $row), ['rejection_reason' => 'x'])->assertSessionHas('error');
            $this->http()->post(route('sale-return-settlements.item.dispatch', $row), [])->assertSessionHas('error');

            $this->assertSame($before, $row->fresh()->toArray(), $status);
            $row->delete();
        }
    }
}

<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\SalesReturn\SaleReturnSettlementForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Tests\TestCase;

class StandardMutationBoundaryTest extends TestCase
{
    use RefreshDatabase, BuildsWorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableForeignKeys();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        // Simulates a user holding BOTH the standard and POS permission sets.
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->withSession(['setting_id' => 1]);
        session(['setting_id' => 1]);
    }

    private function awaitingSettlementReturn(): SaleReturn
    {
        return $this->makeReturn($this->makeSale(), [
            'status' => 'AWAITING SETTLEMENT', 'approval_status' => 'APPROVED', 'received_at' => now(),
        ]);
    }

    private function posLinkedReturn(string $mode): SaleReturn
    {
        $sale = $this->makeSale();
        if ($mode === 'link') {
            return $this->makeReturn($sale, ['pos_return_id' => 7]);
        }
        $this->linkSplitCheckoutSale($sale);

        return $this->makeReturn($sale);   // mixed history: POS-origin sale, standard record
    }

    /** @test */
    public function every_standard_mutation_route_rejects_pos_linked_and_mixed_returns_without_writes(): void
    {
        foreach (['link', 'mixed'] as $mode) {
            $return = $this->posLinkedReturn($mode);
            $settlement = SaleReturnItemSettlement::query()->create([
                'sale_return_id' => $return->id, 'sale_return_detail_id' => 1,
                'method' => 'cash_refund', 'nominal' => 100, 'status' => SaleReturnItemSettlement::STATUS_SUBMITTED,
            ]);
            $before = [$return->fresh()->toArray(), $settlement->fresh()->toArray()];

            $calls = [
                ['post', route('sale-returns.approve', $return)],
                ['post', route('sale-returns.reject', $return), ['reason' => 'x']],
                ['post', route('sale-returns.receive', $return)],
                ['put', route('sale-returns.archive', $return)],
                ['get', route('sale-returns.edit', $return)],
                ['put', route('sale-returns.update', $return)],
                ['delete', route('sale-returns.destroy', $return)],
                ['get', route('sale-returns.dispatch', $return)],
                ['post', route('sale-returns.dispatch.request', $return)],
                ['post', route('sale-return-settlements.item.approve', $settlement)],
                ['post', route('sale-return-settlements.item.reject', $settlement), ['reason' => 'x']],
                ['post', route('sale-return-settlements.item.dispatch', $settlement)],
                ['post', route('sale-return-settlements.item.dispatch.approve', $settlement)],
                ['post', route('sale-return-settlements.item.dispatch.reject', $settlement), ['reason' => 'x']],
            ];

            foreach ($calls as $call) {
                $this->{$call[0]}($call[1], $call[2] ?? [])->assertStatus(409);
            }

            $this->assertSame($before, [$return->fresh()->toArray(), $settlement->fresh()->toArray()], "mode={$mode}");
        }
    }

    /** @test */
    public function legacy_payment_adapters_reject_pos_linked_returns(): void
    {
        $return = $this->posLinkedReturn('link');
        $controller = app(\Modules\SalesReturn\Http\Controllers\SaleReturnPaymentsController::class);

        foreach ([
            fn () => $controller->create($return->id),
            fn () => $controller->store(request()->merge(['sale_return_id' => $return->id])),
        ] as $call) {
            try {
                $call();
                $this->fail('Legacy payment adapter must reject POS-linked returns.');
            } catch (\App\Support\SalesReturn\SalesReturnWorkflowException $e) {
                $this->assertStringContainsString('/pos/returns', $e->getMessage());
            }
        }
    }

    /** @test */
    public function settlement_form_is_read_only_but_rejects_every_action_for_pos_linked_returns(): void
    {
        $return = $this->posLinkedReturn('link');

        // Mount only (no render): the settlement view itself is repaired in task 3.1.
        $component = new SaleReturnSettlementForm();
        $component->mount($return->id);
        $this->assertTrue($component->isReadOnly);

        foreach ([
            fn () => $component->submit(),
            fn () => $component->submitLine(0),
            fn () => $component->resetLine(0),
            fn () => $component->updatedSettlementLines('cash_refund', '0.method'),
        ] as $action) {
            try {
                $action();
                $this->fail('Settlement action must be rejected for a POS-linked return.');
            } catch (\App\Support\SalesReturn\SalesReturnWorkflowException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertSame(0, SaleReturnItemSettlement::query()->count());
    }

    /** @test */
    public function settlement_form_identity_is_locked_against_client_tampering(): void
    {
        foreach (['saleReturnId', 'saleReturn', 'isReadOnly'] as $property) {
            $attributes = (new \ReflectionProperty(SaleReturnSettlementForm::class, $property))
                ->getAttributes(\Livewire\Attributes\Locked::class);
            $this->assertNotEmpty($attributes, "{$property} must be #[Locked]");
        }
    }

    /** @test */
    public function repointed_component_identity_is_rejected_by_the_authoritative_guard(): void
    {
        $standard = $this->makeReturn($this->makeSale());
        $pos = $this->posLinkedReturn('link');

        $component = new SaleReturnSettlementForm();
        $component->mount($standard->id);
        $component->saleReturnId = $pos->id;   // what a tampered payload would attempt

        $this->expectException(\App\Support\SalesReturn\SalesReturnWorkflowException::class);
        $component->resetLine(0);
    }

    /** @test */
    public function reset_line_cannot_touch_a_settlement_of_another_return(): void
    {
        $standard = $this->awaitingSettlementReturn();
        $pos = $this->posLinkedReturn('link');
        $foreign = SaleReturnItemSettlement::query()->create([
            'sale_return_id' => $pos->id, 'sale_return_detail_id' => 1,
            'method' => 'cash_refund', 'nominal' => 100, 'status' => SaleReturnItemSettlement::STATUS_REJECTED,
            'rejection_reason' => 'no',
        ]);
        $before = $foreign->fresh()->toArray();

        $component = new SaleReturnSettlementForm();
        $component->mount($standard->id);
        $component->settlementLines = [['id' => $foreign->id, 'status' => SaleReturnItemSettlement::STATUS_REJECTED]];

        try {
            $component->resetLine(0);
            $this->fail('A settlement of another return must be rejected.');
        } catch (\App\Support\SalesReturn\SalesReturnWorkflowException $e) {
            $this->assertStringContainsString('tidak termasuk', $e->getMessage());
        }

        $this->assertSame($before, $foreign->fresh()->toArray());
    }

    /** @test */
    public function reset_line_uses_authoritative_status_not_the_hydrated_line(): void
    {
        $standard = $this->awaitingSettlementReturn();
        $own = SaleReturnItemSettlement::query()->create([
            'sale_return_id' => $standard->id, 'sale_return_detail_id' => 1,
            'method' => 'cash_refund', 'nominal' => 100, 'status' => SaleReturnItemSettlement::STATUS_APPROVED,
        ]);

        $component = new SaleReturnSettlementForm();
        $component->mount($standard->id);
        $component->settlementLines = [['id' => $own->id, 'status' => SaleReturnItemSettlement::STATUS_REJECTED]];
        $component->resetLine(0);

        $this->assertSame(SaleReturnItemSettlement::STATUS_APPROVED, $own->fresh()->status);
    }

    /** @test */
    public function authorized_reads_of_pos_linked_returns_still_work(): void
    {
        $return = $this->posLinkedReturn('link');

        // Reads are never an ownership rejection. (The show view itself has an unrelated
        // pre-existing defect: undefined `saleReturnGoods` relation; out of phase-2 scope.)
        $this->assertNotContains($this->get(route('sale-returns.show', $return))->getStatusCode(), [403, 409]);
        $this->assertNotSame(409, $this->get(route('sale-returns.settlement', $return))->getStatusCode());
    }

    /** @test */
    public function a_standard_return_still_reaches_the_controller(): void
    {
        $return = $this->makeReturn($this->makeSale());

        $response = $this->get(route('sale-returns.dispatch', $return));
        $this->assertNotSame(409, $response->getStatusCode());
    }
}

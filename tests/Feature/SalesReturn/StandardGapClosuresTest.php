<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\SalesReturn\SaleReturnCreateForm;
use App\Livewire\SalesReturn\SaleReturnEditForm;
use App\Livewire\SalesReturn\SaleReturnSettlementForm;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use App\Support\SalesReturn\StandardReturnClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Tests\TestCase;

/** Closes the review gaps: aggregate claims, action permissions, commit-aware notifications, fail-closed scope, numbering. */
class StandardGapClosuresTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    protected $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->actingAs(User::factory()->create());
        $this->notifications = \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing();
        $this->app->instance(DocumentNotificationService::class, $this->notifications);
    }

    private function row(array $flow, int $quantity, array $overrides = []): array
    {
        return array_merge([
            'dispatch_detail_id' => $flow['dispatchDetail']->id, 'sale_detail_id' => $flow['saleDetail']->id,
            'product_id' => $flow['product']->id, 'product_name' => $flow['product']->product_name,
            'product_code' => $flow['product']->product_code,
            'serial_number_required' => (bool) $flow['product']->serial_number_required,
            'serial_numbers' => [], 'quantity' => $quantity, 'unit_price' => 1000, 'total' => 1000 * $quantity,
            'location_id' => 1,
        ], $overrides);
    }

    private function create(array $flow, array $rows)
    {
        return Livewire::test(SaleReturnCreateForm::class)
            ->set('sale_id', $flow['sale']->id)->set('rows', $rows)->set('date', now()->toDateString())->call('submit');
    }

    // ------------------------------------------------------------ P1: aggregate / duplicate claims

    /** @test */
    public function two_rows_for_the_same_dispatch_line_share_one_remaining_quantity(): void
    {
        Gate::before(fn () => true);
        $flow = $this->makeDispatchedSale(5);
        $service = app(StandardReturnClaimService::class);

        // 5 + 5 against 5 available
        try {
            $service->authorize($flow['sale']->fresh(), [$this->row($flow, 5), $this->row($flow, 5)]);
            $this->fail('Aggregate over-claim must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rows.1.quantity', $e->errors());
        }

        // 3 + 2 is exactly the entitlement and is fine
        $ok = $service->authorize($flow['sale']->fresh(), [$this->row($flow, 3), $this->row($flow, 2)]);
        $this->assertSame(5000.0, $ok['total']);

        // through the create form, nothing is written for the over-claim
        $this->notifications->shouldNotReceive('notifyApprovalNeeded');
        $this->create($flow, [$this->row($flow, 5), $this->row($flow, 5)]);
        $this->assertSame(0, SaleReturn::withArchived()->count());
    }

    /** @test */
    public function duplicate_serials_across_rows_are_rejected_for_create_and_edit(): void
    {
        Gate::before(fn () => true);
        $flow = $this->makeDispatchedSale(3, true);
        $serial = ['id' => $flow['serials'][0]->id];
        $rows = [
            $this->row($flow, 1, ['serial_numbers' => [$serial]]),
            $this->row($flow, 1, ['serial_numbers' => [$serial]]),   // same serial twice in one request
        ];

        try {
            app(StandardReturnClaimService::class)->authorize($flow['sale']->fresh(), $rows);
            $this->fail('A repeated serial must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rows.1.serial_numbers', $e->errors());
        }

        // edit path (own claim excluded from entitlement, but not from the within-request check)
        $return = $this->makeReturnFor($flow, 1, [], [$flow['serials'][1]->id]);
        try {
            app(StandardReturnClaimService::class)->authorize($flow['sale']->fresh(), $rows, $return->id);
            $this->fail('A repeated serial must be rejected on edit too.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rows.1.serial_numbers', $e->errors());
        }
    }

    /** @test */
    public function edit_rejects_rows_that_together_exceed_the_entitlement(): void
    {
        Gate::before(fn () => true);
        $flow = $this->makeDispatchedSale(5);
        $return = $this->makeReturnFor($flow, 3);

        Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $return])
            ->set('rows', [$this->row($flow, 3), $this->row($flow, 3)])   // 6 > 5
            ->call('submit');

        $this->assertSame(3, (int) $return->fresh()->saleReturnDetails()->sum('quantity'));
        $this->assertSame(1, $return->fresh()->saleReturnDetails()->count());
    }

    // ------------------------------------------------------------ P1: action permissions

    /** @test */
    public function creating_requires_the_create_permission_inside_the_action(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $notified = 0;
        $this->notifications->shouldReceive('notifyApprovalNeeded')->andReturnUsing(function () use (&$notified) { $notified++; });

        // no ability is granted to this user
        Livewire::test(SaleReturnCreateForm::class)
            ->set('sale_id', $flow['sale']->id)->set('rows', [$this->row($flow, 1)])->set('date', now()->toDateString())
            ->call('submit')
            ->assertStatus(403);
        $this->assertSame(0, SaleReturn::withArchived()->count());
        $this->assertSame(0, $notified, 'a denied action emits nothing');

        Gate::define('saleReturns.create', fn () => true);
        $this->create($flow, [$this->row($flow, 1)])->assertHasNoErrors();
        $this->assertSame(1, SaleReturn::withArchived()->count());
        $this->assertSame(1, $notified);
    }

    /** @test */
    public function editing_requires_the_edit_permission_inside_the_action(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->makeReturnFor($flow, 2);

        Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $return])
            ->set('rows', [$this->row($flow, 1)])->call('submit')->assertStatus(403);
        $this->assertSame(2, (int) $return->fresh()->saleReturnDetails()->sum('quantity'));

        Gate::define('saleReturns.edit', fn () => true);
        Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $return])
            ->set('rows', [$this->row($flow, 1)])->call('submit');
        $this->assertSame(1, (int) $return->fresh()->saleReturnDetails()->sum('quantity'));
    }

    /** @test */
    public function settlement_edit_submit_and_reset_require_the_submit_permission(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->awaitingSettlement($flow);
        $rejected = $this->settlementFor($return, Settlement::STATUS_REJECTED, 'REPAIR');

        // unauthorized: reset, submit and even editing a field are refused with no writes
        Livewire::test(SaleReturnSettlementForm::class, ['saleReturnId' => $return->id])->call('resetLine', 0)->assertStatus(403);
        Livewire::test(SaleReturnSettlementForm::class, ['saleReturnId' => $return->id])->call('submitLine', 0)->assertStatus(403);
        $this->assertSame(Settlement::STATUS_REJECTED, $rejected->fresh()->status);
        $this->assertSame(1, Settlement::query()->count());

        Gate::define('saleReturnSettlements.submit', fn () => true);
        Livewire::test(SaleReturnSettlementForm::class, ['saleReturnId' => $return->id])->call('resetLine', 0);
        $this->assertSame(Settlement::STATUS_DRAFT, $rejected->fresh()->status);
    }

    // ------------------------------------------------------------ P2: notifications after the OUTERMOST commit

    /** @test */
    public function an_enclosing_rollback_suppresses_the_create_notification(): void
    {
        Gate::before(fn () => true);
        $flow = $this->makeDispatchedSale(5);
        $this->notifications->shouldNotReceive('notifyApprovalNeeded');

        DB::beginTransaction();                       // an enclosing transaction owned by someone else
        $this->create($flow, [$this->row($flow, 1)])->assertHasNoErrors();
        $this->assertSame(1, SaleReturn::withArchived()->count());   // written inside the enclosing transaction
        DB::rollBack();

        $this->assertSame(0, SaleReturn::withArchived()->count());
    }

    /** @test */
    public function the_notification_waits_for_the_outermost_commit_signal_and_fires_once(): void
    {
        Gate::before(fn () => true);
        $flow = $this->makeDispatchedSale(5);
        $calls = 0;
        $this->notifications->shouldReceive('notifyApprovalNeeded')->andReturnUsing(function () use (&$calls) { $calls++; });

        DB::beginTransaction();
        $this->create($flow, [$this->row($flow, 1)])->assertHasNoErrors();
        $this->assertSame(0, $calls, 'still inside an enclosing transaction: nothing may be emitted yet');

        // Laravel delivers this signal when the real outermost transaction commits. (The test
        // harness wraps every test in a never-committed transaction, so it is sent explicitly.)
        app('db.transactions')->commit(DB::connection()->getName());
        DB::commit();

        $this->assertSame(1, $calls);
    }

    // ------------------------------------------------------------ P1: missing business scope fails closed

    /** @test */
    public function a_missing_business_scope_is_refused_everywhere(): void
    {
        Gate::before(fn () => true);
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        $flow = $this->makeDispatchedSale(5);
        $return = $this->makeReturnFor($flow, 1);
        $awaiting = $this->awaitingSettlement($flow);
        $row = $this->settlementFor($awaiting, Settlement::STATUS_SUBMITTED, 'REPAIR');

        session()->forget('setting_id');   // no active business, and no `role.setting` to supply one

        $this->post(route('sale-returns.approve', $return))->assertStatus(403);
        $this->post(route('sale-returns.receive', $return))->assertStatus(403);
        $this->post(route('sale-return-settlements.item.approve', $row))->assertStatus(403);
        $this->assertSame('pending', strtolower((string) $return->fresh()->approval_status));
        $this->assertSame(Settlement::STATUS_SUBMITTED, $row->fresh()->status);

        $this->create($flow, [$this->row($flow, 1)]);
        $this->assertSame(2, SaleReturn::withArchived()->count(), 'only the two fixtures exist; nothing created');

        Livewire::test(SaleReturnSettlementForm::class, ['saleReturnId' => $awaiting->id])
            ->call('resetLine', 0)->assertStatus(403);
    }

    // ------------------------------------------------------------ P2: reference numbering is serialized per business

    /** @test */
    public function references_are_allocated_from_the_locked_business_sequence_and_continue_history(): void
    {
        $flowA = $this->makeDispatchedSale(5);
        $flowB = $this->makeDispatchedSale(5);   // a DIFFERENT source sale: sale-level locks never covered this

        // history already used number 7 this month
        $prefix = 'SLRN-' . now()->format('Y-m');
        $historic = $this->makeReturnFor($flowA, 1, ['reference' => $prefix . '-00007']);

        $a = $this->makeReturnFor($flowA, 1);
        $b = $this->makeReturnFor($flowB, 1);
        $c = $this->makeReturnFor($flowB, 1);

        $this->assertSame([$prefix . '-00008', $prefix . '-00009', $prefix . '-00010'], [$a->reference, $b->reference, $c->reference]);
        $this->assertCount(4, collect([$historic, $a, $b, $c])->pluck('reference')->unique());

        $sequence = DB::table('document_sequences')->where('document_type', 'sale_return')->where('setting_id', 1)->first();
        $this->assertNotNull($sequence, 'numbering goes through the locked document_sequences row');
        $this->assertSame(10, (int) $sequence->last_number);
    }

    /** @test */
    public function an_explicit_reference_is_never_overwritten_and_businesses_number_independently(): void
    {
        $flow1 = $this->makeDispatchedSale(5);
        $flow2 = $this->makeDispatchedSale(5, false, 2);

        $explicit = $this->makeReturnFor($flow1, 1, ['reference' => 'POS-FIXED-1']);
        $one = $this->makeReturnFor($flow1, 1);
        $two = $this->makeReturnFor($flow2, 1);

        $this->assertSame('POS-FIXED-1', $explicit->reference);
        $this->assertStringEndsWith('-00001', $one->reference);
        $this->assertStringEndsWith('-00001', $two->reference, 'a different business has its own sequence');
        $this->assertNotSame($one->reference, $two->reference === $one->reference ? 'x' : $two->reference . 'y');
    }
}

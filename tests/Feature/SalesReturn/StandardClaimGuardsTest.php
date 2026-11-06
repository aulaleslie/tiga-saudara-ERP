<?php

namespace Tests\Feature\SalesReturn;

use App\Livewire\SalesReturn\SaleReturnCreateForm;
use App\Livewire\SalesReturn\SaleReturnEditForm;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Tests\TestCase;

/** Create/edit claims are authoritative: entitlement, lineage, serials and prices are recomputed under lock. */
class StandardClaimGuardsTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        \Illuminate\Support\Facades\Gate::before(fn () => true);   // action permissions are covered in StandardActionPermissionsTest
        $this->actingAs(User::factory()->create());
        $this->notifications = \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing();
        $this->app->instance(DocumentNotificationService::class, $this->notifications);
    }

    protected $notifications;

    private function row(array $flow, int $quantity, array $overrides = []): array
    {
        return array_merge([
            'dispatch_detail_id' => $flow['dispatchDetail']->id,
            'sale_detail_id' => $flow['saleDetail']->id,
            'product_id' => $flow['product']->id,
            'product_name' => $flow['product']->product_name,
            'product_code' => $flow['product']->product_code,
            'serial_number_required' => (bool) $flow['product']->serial_number_required,
            'serial_numbers' => [],
            'quantity' => $quantity,
            'unit_price' => 1000,
            'total' => 1000 * $quantity,
            'location_id' => 1,
        ], $overrides);
    }

    private function create(array $flow, array $rows)
    {
        return Livewire::test(SaleReturnCreateForm::class)
            ->set('sale_id', $flow['sale']->id)
            ->set('rows', $rows)
            ->set('date', now()->toDateString())
            ->call('submit');
    }

    /** @test */
    public function a_valid_claim_is_created_with_server_computed_value_and_notified_after_commit(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $this->notifications->shouldReceive('notifyApprovalNeeded')->once();

        $this->create($flow, [$this->row($flow, 2)])->assertHasNoErrors();

        $return = SaleReturn::query()->firstOrFail();
        $this->assertSame(2000.0, (float) $return->total_amount);
        $this->assertFalse($return->reference === null || $return->reference === '', 'reference is generated');
    }

    /** @test */
    public function forged_price_and_total_are_ignored_and_recomputed_from_the_source(): void
    {
        $flow = $this->makeDispatchedSale(5);

        $this->create($flow, [$this->row($flow, 1, ['unit_price' => 1, 'total' => 1, 'location_id' => 1])])->assertHasNoErrors();

        $return = SaleReturn::query()->firstOrFail();
        $this->assertSame(1000.0, (float) $return->total_amount);
        $this->assertSame(1000.0, (float) $return->saleReturnDetails()->first()->unit_price);
    }

    /** @test */
    public function excess_quantity_is_rejected_without_writes_or_notifications(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $this->notifications->shouldNotReceive('notifyApprovalNeeded');

        $this->create($flow, [$this->row($flow, 6)])->assertHasErrors(['rows.0.quantity']);

        $this->assertSame(0, SaleReturn::withArchived()->count());
    }

    /** @test */
    public function a_stale_claim_after_another_return_consumed_the_entitlement_fails_atomically(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $this->makeReturnFor($flow, 4);   // 4 of 5 already claimed elsewhere
        $this->notifications->shouldNotReceive('notifyApprovalNeeded');

        $this->create($flow, [$this->row($flow, 2)])->assertHasErrors(['rows.0.quantity']);

        $this->assertSame(1, SaleReturn::withArchived()->count());
        $this->assertSame(1, SaleReturnDetail::query()->count());
    }

    /** @test */
    public function a_claim_stale_only_at_lock_time_is_caught_by_the_authoritative_recheck(): void
    {
        // The pre-lock form validation passes, but the entitlement disappears before the locked
        // re-check runs: the claim service must still refuse it.
        $flow = $this->makeDispatchedSale(5);
        $sale = $flow['sale']->fresh();
        $this->makeReturnFor($flow, 5);

        $this->expectException(ValidationException::class);
        app(\App\Support\SalesReturn\StandardReturnClaimService::class)->authorize($sale, [$this->row($flow, 1)]);
    }

    /** @test */
    public function forged_lineage_is_rejected(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $other = $this->makeDispatchedSale(5);

        // dispatch line of ANOTHER sale
        $this->create($flow, [$this->row($flow, 1, ['dispatch_detail_id' => $other['dispatchDetail']->id])])->assertHasErrors();
        // product that does not match the dispatch line
        $this->create($flow, [$this->row($flow, 1, ['product_id' => $other['product']->id])])->assertHasErrors();

        $this->assertSame(0, SaleReturn::withArchived()->count());
    }

    /** @test */
    public function forged_lineage_is_also_refused_by_the_authoritative_claim_service(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $other = $this->makeDispatchedSale(5);
        $service = app(\App\Support\SalesReturn\StandardReturnClaimService::class);

        foreach ([
            $this->row($flow, 1, ['dispatch_detail_id' => $other['dispatchDetail']->id]),
            $this->row($flow, 1, ['product_id' => $other['product']->id]),
        ] as $forged) {
            try {
                $service->authorize($flow['sale']->fresh(), [$forged]);
                $this->fail('Forged lineage must be rejected under lock.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    /** @test */
    public function serials_must_belong_to_the_dispatch_line_and_not_be_reserved_elsewhere(): void
    {
        $flow = $this->makeDispatchedSale(3, true);
        $other = $this->makeDispatchedSale(3, true);

        // a serial of ANOTHER dispatch line
        $this->create($flow, [$this->row($flow, 1, ['serial_numbers' => [['id' => $other['serials'][0]->id]]])])
            ->assertHasErrors(['rows.0.serial_numbers']);

        // a serial already reserved by another (non-rejected) return
        $this->makeReturnFor($flow, 1, [], [$flow['serials'][0]->id]);
        $this->create($flow, [$this->row($flow, 1, ['serial_numbers' => [['id' => $flow['serials'][0]->id]]])])
            ->assertHasErrors(['rows.0.serial_numbers']);

        // serial-tracked product with no serial selected
        $this->create($flow, [$this->row($flow, 1, ['serial_numbers' => []])])
            ->assertHasErrors(['rows.0.serial_numbers']);

        $this->assertSame(1, SaleReturn::withArchived()->count());   // only the pre-existing reservation
    }

    /** @test */
    public function serial_rules_are_also_enforced_by_the_authoritative_claim_service(): void
    {
        $flow = $this->makeDispatchedSale(3, true);
        $other = $this->makeDispatchedSale(3, true);
        $this->makeReturnFor($flow, 1, [], [$flow['serials'][0]->id]);
        $service = app(\App\Support\SalesReturn\StandardReturnClaimService::class);

        foreach ([
            [['id' => $other['serials'][0]->id]],   // foreign serial
            [['id' => $flow['serials'][0]->id]],    // reserved
            [],                                      // missing for a tracked product
        ] as $serials) {
            try {
                $service->authorize($flow['sale']->fresh(), [$this->row($flow, 1, ['serial_numbers' => $serials])]);
                $this->fail('Invalid serial claim must be rejected under lock.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('rows.0.serial_numbers', $e->errors());
            }
        }
    }

    /** @test */
    public function a_sale_of_another_business_cannot_be_claimed(): void
    {
        $foreign = $this->makeDispatchedSale(5, false, 2);
        $this->notifications->shouldNotReceive('notifyApprovalNeeded');

        $this->create($foreign, [$this->row($foreign, 1)]);   // session business is 1

        $this->assertSame(0, SaleReturn::withArchived()->count());
    }

    /** @test */
    public function edit_recomputes_entitlement_excluding_its_own_claim_and_rejects_excess(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->makeReturnFor($flow, 3, ['approval_status' => 'pending']);

        // 3 are held by this very return, so raising to 5 is allowed ...
        Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $return])
            ->set('rows', [$this->row($flow, 5)])
            ->call('submit');
        $this->assertSame(5, (int) $return->fresh()->saleReturnDetails()->sum('quantity'));

        // ... but not to 6, whatever the client claims its "original" quantity was.
        Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $return->fresh()])
            ->set('rows', [$this->row($flow, 6, ['original_quantity' => 100])])
            ->call('submit')
            ->assertHasErrors(['rows.0.quantity']);
        $this->assertSame(5, (int) $return->fresh()->saleReturnDetails()->sum('quantity'));
    }

    /** @test */
    public function edit_after_approval_or_receiving_is_rejected_from_authoritative_state(): void
    {
        $flow = $this->makeDispatchedSale(5);
        $return = $this->makeReturnFor($flow, 2, ['approval_status' => 'pending']);

        $component = Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $return])
            ->set('rows', [$this->row($flow, 1)]);

        // The page was loaded while pending; meanwhile the return gets approved elsewhere.
        $return->update(['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);

        $component->call('submit');

        $this->assertSame(2, (int) $return->fresh()->saleReturnDetails()->sum('quantity'), 'no write after approval');
    }
}

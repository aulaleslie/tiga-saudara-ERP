<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Tests\TestCase;

/** Group 8.2: completed/legacy records stay immutable; custody-dependent actions block with audit guidance. */
class LegacySafeguardsTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow, BuildsFinancialFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        Gate::before(fn ($user, $ability) => true);
        $this->actingAs(User::factory()->create());
        $this->app->instance(DocumentNotificationService::class, \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing());
    }

    private function http(): self
    {
        return $this->withSession(['setting_id' => 1]);
    }

    private function table(string $table): string
    {
        return DB::table($table)->orderBy('id')->get()->toJson();
    }

    /** @test */
    public function a_legacy_received_return_blocks_custody_dependent_actions_with_audit_guidance_and_changes_nothing(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $line = $s['lines'][0];
        // received before the custody policy: no policy marker, no custody rows
        $legacy = $this->makeReturnFor($line, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()], [$line['serials'][0]->id], 1000);
        $before = [$this->table('sale_returns'), $this->table('product_serial_numbers'), $this->table('product_stocks'), $this->table('sale_return_custodies')];

        $this->http()->get(route('sale-returns.inspect', $legacy))->assertSuccessful();   // readable
        $this->assertSame(0, SaleReturnCustody::query()->count(), 'no backfilled custody');

        try {
            app(\App\Support\SalesReturn\StandardReturnCustodyService::class)->release($legacy, [['custody_id' => 1]], 'tidak boleh');
            $this->fail('a legacy record has no custody to release');
        } catch (\App\Support\SalesReturn\SalesReturnStateException $e) {
            $this->assertStringContainsString('audit', strtolower($e->getMessage()));
        }

        $this->assertSame($before, [$this->table('sale_returns'), $this->table('product_serial_numbers'), $this->table('product_stocks'), $this->table('sale_return_custodies')]);
    }

    /** @test */
    public function first_receiving_of_an_older_unreceived_return_adopts_custody_without_touching_other_records(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 2000]], paid: 2000, serial: true);
        $line = $s['lines'][0];
        $ids = collect($line['serials'])->pluck('id')->all();
        $legacy = $this->makeReturnFor($line, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()], [$ids[0]], 1000);
        $older = $this->makeReturnFor($line, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], [$ids[1]], 1000);   // unreceived at deployment
        $legacyBefore = $legacy->fresh()->toArray();

        $this->http()->post(route('sale-returns.receive', $older))->assertRedirect();

        $this->assertSame(1, (int) $older->fresh()->custody_policy_version, 'adopts the policy on first receiving');
        $this->assertSame(1, SaleReturnCustody::query()->count());
        $this->assertSame($legacyBefore, $legacy->fresh()->toArray(), 'the already-received legacy record is untouched');
        $this->assertNull($legacy->fresh()->custody_policy_version);
    }

    /** @test */
    public function completed_returns_are_immutable_for_settlement_and_dispatch_actions(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $line = $s['lines'][0];
        $done = $this->makeReturnFor($line, 1, ['approval_status' => 'approved', 'status' => 'Completed', 'received_at' => now()], [$line['serials'][0]->id], 1000);
        $detailId = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->where('sale_return_id', $done->id)->value('id');
        $row = Settlement::create(['sale_return_id' => $done->id, 'sale_return_detail_id' => $detailId, 'product_serial_number_id' => $line['serials'][0]->id, 'method' => 'CASH_REFUND', 'status' => Settlement::STATUS_SUBMITTED, 'nominal' => 1000]);
        $before = [$this->table('sale_return_item_settlements'), $this->table('sale_return_payments'), $this->table('sale_return_adjustments'), $this->table('sale_returns')];

        $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertRedirect();
        $this->http()->post(route('sale-return-settlements.item.reject', $row), ['rejection_reason' => 'x'])->assertRedirect();

        $this->assertSame($before, [$this->table('sale_return_item_settlements'), $this->table('sale_return_payments'), $this->table('sale_return_adjustments'), $this->table('sale_returns')]);
        $this->assertSame(Settlement::STATUS_SUBMITTED, $row->fresh()->status);
    }
}

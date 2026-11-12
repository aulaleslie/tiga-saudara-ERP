<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Query budget for the buyout preparation screen on representative data: one shared product/location pool with
 * 8 supplier receipts and 12 fully attributed sold sources (the shape that multiplied evidence reads quadratically).
 * Read-only evidence is reused within the request; mutation paths are not covered by this memo.
 */
class ConsignmentBuyoutPreparationPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    public const RECEIPTS = 8;
    public const SOURCES = 12;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);
    }

    private function seedRepresentative(): void
    {
        [$nr, $ns] = $this->datasetSize();
        $receipts = [];
        for ($i = 0; $i < $nr; $i++) {
            $receipts[] = $this->receipt($this->supplier, '20');
        }
        for ($i = 0; $i < $ns; $i++) {
            $src = $this->soldSource('2');
            $this->capacity()->setAttribution($src->id, [$receipts[$i % $nr]->id => '2'], null, 'attribute');
        }
        $this->stock((string) ($nr * 20 - $ns * 2));
    }

    /** BUYOUT_PREP_SIZE="receipts,sources" scales the dataset for reporting; the budget applies to the default size. */
    private function datasetSize(): array
    {
        $env = env('BUYOUT_PREP_SIZE');

        return $env ? array_map('intval', explode(',', $env)) : [self::RECEIPTS, self::SOURCES];
    }

    private function viewer(): User
    {
        $u = User::factory()->create();
        $u->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        foreach (['consignments.buyouts.access', 'consignments.buyouts.create'] as $p) {
            Permission::findOrCreate($p, 'web');
            $u->givePermissionTo($p);
        }

        return $u;
    }

    /** @test */
    public function the_preparation_screen_stays_within_its_query_budget(): void
    {
        config(['consignment.buyouts.ui_enabled' => true]);
        $this->seedRepresentative();
        $user = $this->viewer();

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->actingAs($user)->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.buyouts.create', ['supplier_id' => $this->supplier->id, 'destination_location_id' => $this->dest1->id]))
            ->assertOk()->assertSee('Pilih semua yang tersedia');

        [$nr, $ns] = $this->datasetSize();
        fwrite(STDERR, "\n[buyout-preparation] receipts={$nr} sources={$ns} queries={$count}\n");
        if (! env('BUYOUT_PREP_SIZE')) {
            $this->assertLessThan(450, $count, 'measured 2811 before evidence reuse, 338 after');
        }
    }

    /** @test */
    public function evidence_is_reused_only_inside_the_read_only_scope_and_mutation_reads_stay_fresh(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $svc = $this->capacity();

        $svc->readOnlyEvidence(function ($cap) use ($r) {
            $this->assertTrue($cap->memoizing());
            $this->assertEquals(10.0, $cap->receiptCapacity($r->id)['received']);
            DB::table('consignment_receiving_details')->where('id', $r->id)->update(['quantity_received' => 7]);
            $this->assertEquals(10.0, $cap->receiptCapacity($r->id)['received'], 'reused within the preparation request');
            $this->assertEquals(7.0, $cap->receiptCapacity($r->id, 999)['received'], 'exclusion reads (mutation shape) are never memoized');
        });

        $this->assertFalse($svc->memoizing(), 'scope closed');
        $this->assertEquals(7.0, $svc->receiptCapacity($r->id)['received'], 'outside the scope every read is fresh');
    }
}

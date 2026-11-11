<?php

namespace Modules\Consignment\Tests\Feature;

use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Setting\Entities\SettingSaleLocation;
use Tests\TestCase;

/**
 * Buying stock into an owned destination is an inventory/ownership event only: it never creates, enables or
 * re-orders POS sale-location assignments (main's "disabled locations stay disabled" rule).
 */
class ConsignmentBuyoutSaleLocationIsolationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
    }

    /** @test */
    public function finalizing_and_reversing_a_buyout_never_enables_the_destination_for_pos(): void
    {
        // Locations get an enabled assignment on creation; the operator then explicitly disables dest1 and dest2.
        SettingSaleLocation::whereIn('location_id', [$this->dest1->id, $this->dest2->id])->update(['is_enabled' => false]);
        $before = SettingSaleLocation::orderBy('id')->get(['setting_id', 'location_id', 'is_enabled', 'position'])->toArray();
        SalesLocationResolver::forget($this->setting->id);
        $enabledBefore = SalesLocationResolver::resolveLocationIds($this->setting->id)->all();

        // Unsold goods land in BOTH disabled destinations.
        [$b] = $this->mixed([
            ['destination_location_id' => $this->dest1->id, 'base_quantity' => '2', 'buckets' => ['NON_TAXED' => '2']],
            ['destination_location_id' => $this->dest2->id, 'base_quantity' => '1', 'buckets' => ['NON_TAXED' => '1']],
        ]);
        $this->fin->finalize($b->fresh(), $this->user);
        $this->assertSame(2.0, (float) \DB::table('product_stocks')->where('location_id', $this->dest1->id)->sum('quantity'));

        SalesLocationResolver::forget($this->setting->id);
        $this->assertSame($before, SettingSaleLocation::orderBy('id')->get(['setting_id', 'location_id', 'is_enabled', 'position'])->toArray());
        $this->assertSame($enabledBefore, SalesLocationResolver::resolveLocationIds($this->setting->id)->all());
        $this->assertNotContains($this->dest1->id, SalesLocationResolver::resolveLocationIds($this->setting->id)->all());
        $this->assertNotContains($this->dest2->id, SalesLocationResolver::resolveLocationIds($this->setting->id)->all());
        $this->assertSame(1, SettingSaleLocation::where('location_id', $this->dest2->id)->count());

        (new \Modules\Consignment\Services\ConsignmentBuyoutReversalService())->reverse($b->fresh(), $this->user, 'undo');
        SalesLocationResolver::forget($this->setting->id);
        $this->assertSame($before, SettingSaleLocation::orderBy('id')->get(['setting_id', 'location_id', 'is_enabled', 'position'])->toArray());
    }
}

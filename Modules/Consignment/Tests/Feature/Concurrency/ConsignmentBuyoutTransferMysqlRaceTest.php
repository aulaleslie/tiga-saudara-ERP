<?php

namespace Modules\Consignment\Tests\Feature\Concurrency;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Tests\Support\BuildsV3TransferFixtures;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Tests\Support\RunsBuyoutRaceWorkers;
use Modules\People\Entities\Supplier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real transfer approval+dispatch (TransferV3DispatchExecutor) versus buyout approval over the same bucket, as two real
 * OS processes against the isolated MySQL database. Invariants hold for either winner order.
 *
 * @group mysql
 */
class ConsignmentBuyoutTransferMysqlRaceTest extends TestCase
{
    use BuildsV3TransferFixtures;
    use RunsBuyoutRaceWorkers;

    protected User $user;
    protected $setting;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }
        $this->build();
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql_test') {
            $this->truncateAll();
        }
        parent::tearDown();
    }

    private function build(): void
    {
        $this->truncateAll();
        $this->serials = [];
        $this->buildV3Fixtures();
        $this->grant($this->actor, self::PERMISSIONS);
        $this->user = $this->actor;
        $this->setting = $this->businessA;
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            $this->actor->givePermissionTo($p);
        }
        $this->actor->settings()->attach($this->businessA->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        $this->supplier = Supplier::create(['setting_id' => $this->businessA->id, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
    }

    private function receipt(string $qty): ConsignmentReceivingDetail
    {
        $ref = 'CR-T-' . uniqid();
        $receival = ConsignmentReceival::create(['setting_id' => $this->businessA->id, 'supplier_id' => $this->supplier->id, 'reference' => $ref,
            'receival_number' => $ref, 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $this->businessA->id, 'location_id' => $this->a1->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => $ref, 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $this->bulk->id,
            'product_name' => 'P', 'product_code' => 'P', 'quantity' => $qty, 'unit_cost' => 100, 'unit_dpp' => 100,
            'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);

        return ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $this->bulk->id, 'quantity_received' => $qty, 'received_base_quantity' => $qty, 'unit_cost' => 100, 'unit_dpp' => 100]);
    }

    /** @test 3.6: real transfer approval/dispatch versus buyout approval reserving the same non-tax bucket */
    public function transfer_dispatch_versus_buyout_approval_cannot_both_take_the_goods(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            if ($round > 0) {
                $this->build();
            }
            $outcomes[] = $this->round($round, true);
        }
        fwrite(STDERR, "\n[transfer-vs-approval outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    /** @test negative control: with no buyout the same worker really dispatches the transfer */
    public function transfer_dispatch_alone_dispatches_in_a_worker(): void
    {
        $this->assertSame('control', $this->round(0, false));
    }

    private function round(int $round, bool $withBuyout): string
    {
        $r = $this->receipt('10');          // A1 holds 6 non-tax + 4 tax good units of the bulk product
        $transfer = $this->submittedTransfer(10, false);
        $this->saveCompletePlan($transfer, null, []);                  // dispatch takes 6 from A1 (and 4 from A2 into A1)

        if (! $withBuyout) {
            $results = $this->race([['transfer', $transfer->id]]);
            $this->assertSame(1, $this->ok($results), implode(' | ', $results));
            $this->assertSame(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);

            return 'control';
        }

        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft($this->businessA->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->a2->id,
            'base_quantity' => '5', 'buckets' => ['NON_TAXED' => '5']]], $this->actor);
        $b = $svc->submit($b->fresh(), $this->actor);
        $before = DB::table('product_stocks')->where('product_id', $this->bulk->id)->orderBy('id')->get(['location_id', 'quantity', 'quantity_tax', 'quantity_non_tax'])->toArray();

        $results = $this->race([['approve', $b->id], ['transfer', $transfer->id]], $round % 2 ? [200, 0] : [0, 200]);

        $approved = ConsignmentBuyout::find($b->id)->status === 'APPROVED';
        $dispatched = $transfer->fresh()->status === Transfer::STATUS_DISPATCHED;
        $this->assertFalse($approved && $dispatched, 'reserved goods were dispatched: ' . implode(' | ', $results));
        $this->assertTrue($approved || $dispatched, 'one of the two must have won: ' . implode(' | ', $results));
        $after = DB::table('product_stocks')->where('product_id', $this->bulk->id)->orderBy('id')->get(['location_id', 'quantity', 'quantity_tax', 'quantity_non_tax'])->toArray();
        if ($approved) {
            $this->assertEquals($before, $after, 'the refused dispatch changed no stock');
        } else {
            $this->assertEquals(0, DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), 'the refused approval reserved nothing');
        }

        return $approved ? 'approval-won' : 'dispatch-won';
    }
}

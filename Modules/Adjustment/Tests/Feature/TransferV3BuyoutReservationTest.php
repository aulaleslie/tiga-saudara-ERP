<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Tests\Support\BuildsV3TransferFixtures;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real transfer approval/dispatch versus approved buyout reservations (deterministic SQLite integration,
 * not a concurrency proof).
 */
class TransferV3BuyoutReservationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsV3TransferFixtures;

    private User $buyoutUser;
    private Supplier $supplier;
    private ConsignmentBuyoutLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildV3Fixtures();
        $this->grant($this->actor, self::PERMISSIONS);
        $this->lifecycle = new ConsignmentBuyoutLifecycleService();
        $this->supplier = Supplier::create(['setting_id' => $this->businessA->id, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);

        $this->buyoutUser = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            $this->buyoutUser->givePermissionTo($p);
        }
        $this->buyoutUser->settings()->attach($this->businessA->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
    }

    private function receipt(Product $product, Location $location, string $qty): ConsignmentReceivingDetail
    {
        $ref = 'CR-T-' . uniqid();
        $receival = ConsignmentReceival::create(['setting_id' => $this->businessA->id, 'supplier_id' => $this->supplier->id, 'reference' => $ref,
            'receival_number' => $ref, 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $this->businessA->id, 'location_id' => $location->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => $ref, 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $product->id,
            'product_name' => 'P', 'product_code' => 'P', 'quantity' => $qty, 'unit_cost' => 100, 'unit_dpp' => 100,
            'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);

        return ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $product->id, 'quantity_received' => $qty, 'received_base_quantity' => $qty, 'unit_cost' => 100, 'unit_dpp' => 100]);
    }

    private function approvedBuyout(array $line)
    {
        $b = $this->lifecycle->createDraft($this->businessA->id, $this->supplier->id, '2026-11-10', [$line], $this->buyoutUser);

        return $this->lifecycle->approve($this->lifecycle->submit($b, $this->buyoutUser), $this->buyoutUser);
    }

    public function test_dispatch_cannot_consume_reserved_quantity_and_succeeds_after_cancellation_with_no_partial_writes(): void
    {
        $r = $this->receipt($this->bulk, $this->a1, '10');
        // A1 holds 6 non-tax + 4 tax good units; reserve 5 non-tax.
        $buyout = $this->approvedBuyout(['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id,
            'destination_location_id' => $this->a2->id, 'base_quantity' => '5', 'buckets' => ['NON_TAXED' => '5']]);

        $transfer = $this->submittedTransfer(10, false);
        $this->saveCompletePlan($transfer, null, []);
        $before = [$this->stockAt($this->bulk, $this->a1)->only(['quantity', 'quantity_tax', 'quantity_non_tax']),
            $this->stockAt($this->bulk, $this->a2)->only(['quantity']), $this->stockAt($this->bulk, $this->b1)];

        try {
            $this->dispatchTransfer($transfer);
            $this->fail('reserved goods were dispatched');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak mencukupi', $e->getMessage());
        }
        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
        $this->assertEquals($before[0], $this->stockAt($this->bulk, $this->a1)->only(['quantity', 'quantity_tax', 'quantity_non_tax']));
        $this->assertEquals($before[1], $this->stockAt($this->bulk, $this->a2)->only(['quantity']));
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());

        $this->lifecycle->cancel($buyout, 'release', $this->buyoutUser);
        $done = $this->dispatchTransfer($transfer);
        $this->assertSame(Transfer::STATUS_DISPATCHED, $done->status);
        // 10 units left A1/A2 as planned (6 from A1, 4 from A2).
        $this->assertSame(0, (int) $this->stockAt($this->bulk, $this->a1)->quantity_non_tax + (int) $this->stockAt($this->bulk, $this->a1)->quantity_tax - 4);
    }

    public function test_dispatch_cannot_move_serials_claimed_by_a_buyout(): void
    {
        $r = $this->receipt($this->serialized, $this->a1, '2');
        $claimed = $this->serials[0];
        $claimed->update(['consignment_receiving_detail_id' => $r->id]);
        $this->serials[1]->update(['consignment_receiving_detail_id' => $r->id]);
        $buyout = $this->approvedBuyout(['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id,
            'destination_location_id' => $this->a2->id, 'base_quantity' => '1', 'serial_ids' => [$claimed->id]]);
        $this->assertSame(1, ConsignmentActiveSerialClaim::count());

        $transfer = app(\Modules\Adjustment\Services\TransferV3GoodsService::class)->createAndSubmit(
            Transfer::CONDITION_GOOD,
            [['product_id' => $this->serialized->id, 'quantity' => 3, 'serial_ids' => array_map(fn ($s) => $s->id, $this->serials)]],
            $this->actor,
            $this->businessA->id
        );
        $this->saveCompletePlan($transfer, [], [
            "{$this->serialized->id}:{$this->a1->id}" => $this->a2->id,
            "{$this->serialized->id}:{$this->b1->id}" => $this->a1->id,
        ]);

        try {
            $this->dispatchTransfer($transfer);
            $this->fail('claimed serial was transferred');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('konsinyasi', $e->getMessage());
        }
        $this->assertSame(Transfer::STATUS_PENDING, $transfer->fresh()->status);
        $this->assertSame($this->a1->id, (int) $claimed->fresh()->location_id);
        $this->assertSame(1, ConsignmentActiveSerialClaim::where('consignment_buyout_id', $buyout->id)->count());
    }
}

<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\AdjustedProduct;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Services\BreakageApprovalService;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real breakage approval (serial-state change good -> broken) versus approved buyout reservations.
 * Deterministic SQLite integration, not a concurrency proof.
 */
class ConsignmentBuyoutBreakageReservationTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Location $location;
    private Location $dest;
    private User $approver;
    private User $buyoutUser;
    private Supplier $supplier;
    private ConsignmentBuyoutLifecycleService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $currency = Currency::create(['currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'RP',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1]);
        $this->setting = Setting::create(['company_name' => 'CV Buyout Breakage', 'company_email' => 'o@t.test', 'company_phone' => '08',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix', 'notification_email' => 'o@t.test',
            'footer_text' => 'F', 'company_address' => 'B', 'is_pkp' => true]);
        $this->location = Location::create(['setting_id' => $this->setting->id, 'name' => 'Konsinyasi', 'is_consignment' => true]);
        $this->dest = Location::create(['setting_id' => $this->setting->id, 'name' => 'Standar', 'is_consignment' => false]);
        $this->svc = new ConsignmentBuyoutLifecycleService();

        $this->approver = User::factory()->create(['is_active' => 1]);
        $perm = Permission::firstOrCreate(['name' => 'adjustments.breakage.approval', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'approver', 'guard_name' => 'web']);
        $role->givePermissionTo($perm);
        $this->approver->assignRole($role);
        $this->approver->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $this->buyoutUser = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            $this->buyoutUser->givePermissionTo($p);
        }
        $this->buyoutUser->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        $this->supplier = Supplier::create(['setting_id' => $this->setting->id, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
    }

    private function product(bool $serial): Product
    {
        return Product::create(['setting_id' => $this->setting->id, 'product_name' => 'P' . uniqid(), 'product_code' => 'SKU-' . uniqid(),
            'product_quantity' => 10, 'serial_number_required' => $serial, 'product_cost' => 1000, 'product_price' => 1500,
            'product_stock_alert' => 1, 'stock_managed' => true]);
    }

    private function receipt(Product $product, string $qty): ConsignmentReceivingDetail
    {
        $ref = 'CR-' . uniqid();
        $receival = ConsignmentReceival::create(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'reference' => $ref,
            'receival_number' => $ref, 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $this->setting->id, 'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => $ref, 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $product->id,
            'product_name' => 'P', 'product_code' => 'P', 'quantity' => $qty, 'unit_cost' => 1, 'unit_dpp' => 1,
            'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);

        return ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $product->id, 'quantity_received' => $qty, 'received_base_quantity' => $qty, 'unit_cost' => 1, 'unit_dpp' => 1]);
    }

    private function buyout(array $line)
    {
        $b = $this->svc->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [$line + [
            'line_type' => 'UNSOLD_BUYOUT', 'destination_location_id' => $this->dest->id]], $this->buyoutUser);

        return $this->svc->approve($this->svc->submit($b, $this->buyoutUser), $this->buyoutUser);
    }

    private function breakage(Product $product, int $qty, array $serialIds = []): Adjustment
    {
        $adj = Adjustment::create(['date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $this->location->id]);
        AdjustedProduct::create(['adjustment_id' => $adj->id, 'product_id' => $product->id, 'quantity' => $qty, 'quantity_tax' => $qty,
            'quantity_non_tax' => 0, 'serial_numbers' => json_encode($serialIds), 'type' => 'sub']);

        return $adj->fresh();
    }

    /** @test */
    public function breakage_cannot_reclassify_reserved_quantity_and_rolls_back_completely(): void
    {
        $product = $this->product(false);
        $stock = ProductStock::create(['product_id' => $product->id, 'location_id' => $this->location->id, 'quantity' => 10,
            'quantity_tax' => 10, 'quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);
        $r = $this->receipt($product, '10');
        $buyout = $this->buyout(['consignment_receiving_detail_id' => $r->id, 'base_quantity' => '8', 'buckets' => ['TAXED' => '8']]);

        // Breakage is already refused at consignment locations (existing rule): reservations stay untouched.
        $refused = $this->breakage($product, 1);
        try {
            app(BreakageApprovalService::class)->approve($refused, $this->approver, $this->setting->id);
            $this->fail('breakage ran at a consignment location');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('konsinyasi', json_encode($e->errors()));
        }
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());

        // Defense in depth: even if the location were later reclassified as standard, the reservation still protects the goods.
        \DB::table('locations')->where('id', $this->location->id)->update(['is_consignment' => false]);

        $adj = $this->breakage($product, 4); // 10 - 8 reserved = 2 breakable
        try {
            app(BreakageApprovalService::class)->approve($adj, $this->approver, $this->setting->id);
            $this->fail('reserved quantity was reclassified as broken');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('dicadangkan', json_encode($e->errors()));
        }
        $stock->refresh();
        $this->assertSame([10, 0], [(int) $stock->quantity_tax, (int) $stock->broken_quantity_tax]);
        $this->assertNotSame(\Modules\Adjustment\Entities\AdjustmentStatus::Approved, $adj->fresh()->status);
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());

        // The unreserved remainder can be broken; cancelling the buyout frees the rest.
        app(BreakageApprovalService::class)->approve($this->breakage($product, 2), $this->approver, $this->setting->id);
        $this->assertSame([8, 2], [(int) $stock->fresh()->quantity_tax, (int) $stock->fresh()->broken_quantity_tax]);
        $this->svc->cancel($buyout, 'release', $this->buyoutUser);
        app(BreakageApprovalService::class)->approve($this->breakage($product, 3), $this->approver, $this->setting->id);
        $this->assertSame([5, 5], [(int) $stock->fresh()->quantity_tax, (int) $stock->fresh()->broken_quantity_tax]);
    }

    /** @test */
    public function breakage_cannot_change_the_state_of_a_serial_claimed_by_a_buyout(): void
    {
        $product = $this->product(true);
        $stock = ProductStock::create(['product_id' => $product->id, 'location_id' => $this->location->id, 'quantity' => 2,
            'quantity_tax' => 0, 'quantity_non_tax' => 2, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);
        $r = $this->receipt($product, '2');
        $claimed = ProductSerialNumber::create(['product_id' => $product->id, 'location_id' => $this->location->id, 'serial_number' => 'SN-BK-1',
            'status' => 'ACTIVE', 'tax_id' => null, 'consignment_receiving_detail_id' => $r->id]);
        $free = ProductSerialNumber::create(['product_id' => $product->id, 'location_id' => $this->location->id, 'serial_number' => 'SN-BK-2',
            'status' => 'ACTIVE', 'tax_id' => null, 'consignment_receiving_detail_id' => $r->id]);
        $buyout = $this->buyout(['consignment_receiving_detail_id' => $r->id, 'base_quantity' => '1', 'serial_ids' => [$claimed->id]]);

        // Existing rule first: no breakage at a consignment location. Then reclassify to prove the claim guard (defense in depth).
        $this->setting->update(['is_pkp' => false]);
        \DB::table('locations')->where('id', $this->location->id)->update(['is_consignment' => false]);

        $adj = Adjustment::create(['date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $this->location->id]);
        AdjustedProduct::create(['adjustment_id' => $adj->id, 'product_id' => $product->id, 'quantity' => 1, 'quantity_tax' => 0,
            'quantity_non_tax' => 1, 'serial_numbers' => json_encode([$claimed->id]), 'type' => 'sub']);

        try {
            app(BreakageApprovalService::class)->approve($adj->fresh(), $this->approver, $this->setting->id);
            $this->fail('claimed serial was marked broken');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }
        $this->assertFalse((bool) $claimed->fresh()->is_broken);
        $this->assertSame([2, 0], [(int) $stock->fresh()->quantity_non_tax, (int) $stock->fresh()->broken_quantity_non_tax]);
        $this->assertSame(1, ConsignmentActiveSerialClaim::where('consignment_buyout_id', $buyout->id)->count());

        // Negative control: the unclaimed serial can be marked broken by the same operation.
        $ok = Adjustment::create(['date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $this->location->id]);
        AdjustedProduct::create(['adjustment_id' => $ok->id, 'product_id' => $product->id, 'quantity' => 1, 'quantity_tax' => 0,
            'quantity_non_tax' => 1, 'serial_numbers' => json_encode([$free->id]), 'type' => 'sub']);
        app(BreakageApprovalService::class)->approve($ok->fresh(), $this->approver, $this->setting->id);
        $this->assertTrue((bool) $free->fresh()->is_broken);
        $this->assertFalse((bool) $claimed->fresh()->is_broken);
    }

    /** @test */
    public function legacy_normal_adjustment_cannot_set_a_bucket_below_the_reserved_quantity_or_delete_claimed_serials(): void
    {
        $approval = Permission::firstOrCreate(['name' => 'adjustments.approval', 'guard_name' => 'web']);
        $this->approver->givePermissionTo($approval);

        // Non-serialized: absolute count of 5 would eat into the 8 reserved units.
        $product = $this->product(false);
        $stock = ProductStock::create(['product_id' => $product->id, 'location_id' => $this->location->id, 'quantity' => 10,
            'quantity_tax' => 10, 'quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);
        $r = $this->receipt($product, '10');
        $buyout = $this->buyout(['consignment_receiving_detail_id' => $r->id, 'base_quantity' => '8', 'buckets' => ['TAXED' => '8']]);

        $adj = Adjustment::create(['date' => now(), 'type' => 'normal', 'status' => 'pending', 'location_id' => $this->location->id]);
        AdjustedProduct::create(['adjustment_id' => $adj->id, 'product_id' => $product->id, 'quantity' => 5, 'quantity_tax' => 5,
            'quantity_non_tax' => 0, 'serial_numbers' => json_encode([]), 'type' => 'add']);

        $this->actingAs($this->approver)->withSession(['setting_id' => $this->setting->id])->patch(route('adjustments.approve', $adj));
        $this->assertSame(10, (int) $stock->fresh()->quantity_tax, 'reserved quantity must survive a lower absolute count');
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());

        // A count that keeps the reserved units is fine.
        $ok = Adjustment::create(['date' => now(), 'type' => 'normal', 'status' => 'pending', 'location_id' => $this->location->id]);
        AdjustedProduct::create(['adjustment_id' => $ok->id, 'product_id' => $product->id, 'quantity' => 9, 'quantity_tax' => 9,
            'quantity_non_tax' => 0, 'serial_numbers' => json_encode([]), 'type' => 'add']);
        $this->actingAs($this->approver)->withSession(['setting_id' => $this->setting->id])->patch(route('adjustments.approve', $ok));
        $this->assertSame(9, (int) $stock->fresh()->quantity_tax);
    }

    /** @test */
    public function legacy_normal_adjustment_does_not_delete_a_serial_claimed_by_a_buyout(): void
    {
        $this->approver->givePermissionTo(Permission::firstOrCreate(['name' => 'adjustments.approval', 'guard_name' => 'web']));
        $product = $this->product(true);
        ProductStock::create(['product_id' => $product->id, 'location_id' => $this->location->id, 'quantity' => 2,
            'quantity_tax' => 0, 'quantity_non_tax' => 2, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);
        $r = $this->receipt($product, '2');
        $claimed = ProductSerialNumber::create(['product_id' => $product->id, 'location_id' => $this->location->id, 'serial_number' => 'SN-LG-1',
            'status' => 'ACTIVE', 'consignment_receiving_detail_id' => $r->id]);
        $kept = ProductSerialNumber::create(['product_id' => $product->id, 'location_id' => $this->location->id, 'serial_number' => 'SN-LG-2',
            'status' => 'ACTIVE', 'consignment_receiving_detail_id' => $r->id]);
        $buyout = $this->buyout(['consignment_receiving_detail_id' => $r->id, 'base_quantity' => '1', 'serial_ids' => [$claimed->id]]);

        // The document only lists the kept serial, so the claimed one would be deleted as "unmatched".
        $adj = Adjustment::create(['date' => now(), 'type' => 'normal', 'status' => 'pending', 'location_id' => $this->location->id]);
        AdjustedProduct::create(['adjustment_id' => $adj->id, 'product_id' => $product->id, 'quantity' => 1, 'quantity_tax' => 0,
            'quantity_non_tax' => 1, 'serial_numbers' => json_encode([['id' => $kept->id, 'taxable' => 0]]), 'type' => 'add']);

        $this->actingAs($this->approver)->withSession(['setting_id' => $this->setting->id])->patch(route('adjustments.approve', $adj));

        $this->assertNotNull(ProductSerialNumber::find($claimed->id), 'claimed serial must not be deleted');
        $this->assertSame(1, ConsignmentActiveSerialClaim::where('consignment_buyout_id', $buyout->id)->count());
        $this->assertSame(2, (int) ProductStock::where('product_id', $product->id)->value('quantity'), 'whole approval rolled back');

        // Negative control: when the document lists the claimed serial too, only a free unmatched serial is removed.
        $spare = ProductSerialNumber::create(['product_id' => $product->id, 'location_id' => $this->location->id, 'serial_number' => 'SN-LG-3',
            'status' => 'ACTIVE']);
        $ok = Adjustment::create(['date' => now(), 'type' => 'normal', 'status' => 'pending', 'location_id' => $this->location->id]);
        AdjustedProduct::create(['adjustment_id' => $ok->id, 'product_id' => $product->id, 'quantity' => 2, 'quantity_tax' => 0,
            'quantity_non_tax' => 2, 'serial_numbers' => json_encode([['id' => $kept->id, 'taxable' => 0], ['id' => $claimed->id, 'taxable' => 0]]), 'type' => 'add']);
        $this->actingAs($this->approver)->withSession(['setting_id' => $this->setting->id])->patch(route('adjustments.approve', $ok));
        $this->assertNull(ProductSerialNumber::find($spare->id), 'free unmatched serial is removed by the same operation');
        $this->assertNotNull(ProductSerialNumber::find($claimed->id));
        $this->assertSame(1, ConsignmentActiveSerialClaim::where('consignment_buyout_id', $buyout->id)->count());
    }
}

<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\People\Entities\Supplier;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Real Sales dispatch approval versus buyout reservations: the dispatch consumer must honour the shared
 * availability authority (deterministic SQLite integration; NOT a concurrency proof - see testing.md).
 */
class ConsignmentBuyoutDispatchReservationTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;
    private ConsignmentReceivingDetail $receipt;
    private User $user;
    private Sale $sale;
    private Product $product;
    private Location $standardLocation;
    private Location $consignmentLocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->setting = Setting::create([
            'company_name' => 'Consignment Co',
            'company_email' => 'c@example.com',
            'company_phone' => '123',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'n@example.com',
            'footer_text' => 'F',
            'company_address' => 'Addr',
        ]);

        $term = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $this->setting->id, 'payment_term_id' => $term->id]);

        foreach (['sales.dispatch', 'salesDispatches.approval'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['sales.dispatch', 'salesDispatches.approval']);

        $category = Category::create([
            'category_name' => 'Cat', 'category_code' => 'CAT',
            'setting_id' => $this->setting->id, 'created_by' => $this->user->id,
        ]);
        $unit = Unit::create([
            'name' => 'Unit', 'short_name' => 'U', 'operator' => '*',
            'operation_value' => 1, 'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Consigned Item', 'product_code' => 'CON-001',
            'setting_id' => $this->setting->id, 'product_quantity' => 20,
            'product_cost' => 1000, 'stock_managed' => true, 'product_price' => 1500,
            'category_id' => $category->id, 'product_unit' => $unit->id,
        ]);

        $this->standardLocation = Location::create([
            'name' => 'Gudang Biasa', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);
        $this->consignmentLocation = Location::create([
            'name' => 'Gudang Konsinyasi', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => true,
        ]);

        foreach ([$this->standardLocation, $this->consignmentLocation] as $location) {
            ProductStock::create([
                'product_id' => $this->product->id, 'location_id' => $location->id,
                'quantity' => 10, 'quantity_tax' => 0, 'quantity_non_tax' => 10,
                'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
            ]);
        }

        $this->sale = Sale::create([
            'date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0,
            'discount_amount' => 0, 'shipping_amount' => 0, 'total_amount' => 15000,
            'paid_amount' => 0, 'due_amount' => 15000, 'status' => 'Approved',
            'payment_status' => 'Unpaid', 'payment_method' => 'cash',
            'payment_term_id' => $term->id, 'setting_id' => $this->setting->id,
            'is_tax_included' => false, 'reference' => 'SO-CONS-001',
        ]);

        SaleDetails::create([
            'sale_id' => $this->sale->id, 'product_id' => $this->product->id,
            'product_name' => $this->product->product_name, 'product_code' => $this->product->product_code,
            'quantity' => 10, 'price' => 1500, 'unit_price' => 1500, 'sub_total' => 15000,
            'product_discount_amount' => 0, 'product_discount_type' => 'fixed',
            'product_tax_amount' => 0, 'tax_id' => null,
        ]);
    
        $this->supplier = Supplier::create(['setting_id' => $this->setting->id, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $receival = ConsignmentReceival::create(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'reference' => 'CR-D',
            'receival_number' => 'CR-D', 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $this->setting->id, 'location_id' => $this->consignmentLocation->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => 'CR-D', 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $this->product->id,
            'product_name' => 'P', 'product_code' => 'P', 'quantity' => 10, 'unit_cost' => 100, 'unit_dpp' => 100,
            'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $this->receipt = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $this->product->id, 'quantity_received' => 10, 'received_base_quantity' => 10, 'unit_cost' => 100, 'unit_dpp' => 100]);

        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
            $this->user->givePermissionTo($p);
        }
        $this->user->settings()->attach($this->setting->id, ['role_id' => \Spatie\Permission\Models\Role::findOrCreate('R', 'web')->id]);
    }

    private function approvedBuyout(string $qty): ConsignmentBuyout
    {
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id,
            'destination_location_id' => $this->standardLocation->id, 'base_quantity' => $qty, 'buckets' => ['NON_TAXED' => $qty],
        ]], $this->user);

        return $svc->approve($svc->submit($b, $this->user), $this->user);
    }

    private function tryDispatch(Location $location, int $quantity): ?Dispatch
    {
        $key = $this->product->id . '--0';
        $this->actingAs($this->user)->withSession(['setting_id' => $this->setting->id])
            ->post(route('sales.storeDispatch', $this->sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [$key => $quantity],
                'selectedLocations' => [$key => $location->id],
            ])->assertRedirect();
        $dispatch = Dispatch::where('sale_id', $this->sale->id)->latest('id')->firstOrFail();
        $this->actingAs($this->user)->withSession(['setting_id' => $this->setting->id])
            ->post(route('dispatches.approve', $dispatch))->assertRedirect();

        return $dispatch->fresh();
    }

    private function stockAt(Location $l): float
    {
        return (float) ProductStock::where('product_id', $this->product->id)->where('location_id', $l->id)->value('quantity');
    }

    public function test_dispatch_cannot_consume_stock_reserved_by_an_approved_buyout_but_can_consume_the_rest(): void
    {
        $buyout = $this->approvedBuyout('6');
        $reservationsBefore = $buyout->reservations()->where('status', 'ACTIVE')->count();

        // 10 on hand, 6 reserved: 5 is refused atomically.
        $d = $this->tryDispatch($this->consignmentLocation, 5);
        $this->assertSame(Dispatch::STATUS_PENDING, $d->status);
        $this->assertSame(10.0, $this->stockAt($this->consignmentLocation));
        $this->assertSame(0, DispatchDetail::where('dispatch_id', $d->id)->whereHas('dispatch', fn ($q) => $q->where('status', 'APPROVED'))->count());

        // 4 is unreserved stock and succeeds without touching the claim.
        $this->sale->update(['status' => 'Approved']);
        $ok = $this->tryDispatch($this->consignmentLocation, 4);
        $this->assertSame(Dispatch::STATUS_APPROVED, $ok->status);
        $this->assertSame(6.0, $this->stockAt($this->consignmentLocation));
        $this->assertSame($reservationsBefore, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());
        $this->assertSame('APPROVED', $buyout->fresh()->status);
    }

    public function test_standard_location_dispatch_is_unaffected_by_buyout_reservations(): void
    {
        $this->approvedBuyout('10');
        $d = $this->tryDispatch($this->standardLocation, 3);
        $this->assertSame(Dispatch::STATUS_APPROVED, $d->status);
    }

    public function test_cancelling_the_buyout_frees_the_stock_for_dispatch(): void
    {
        $buyout = $this->approvedBuyout('10');
        $this->assertSame(Dispatch::STATUS_PENDING, $this->tryDispatch($this->consignmentLocation, 1)->status);

        (new ConsignmentBuyoutLifecycleService())->cancel($buyout, 'no longer needed', $this->user);
        $dispatch = Dispatch::where('sale_id', $this->sale->id)->latest('id')->firstOrFail();
        $this->actingAs($this->user)->withSession(['setting_id' => $this->setting->id])
            ->post(route('dispatches.approve', $dispatch))->assertRedirect();
        $this->assertSame(Dispatch::STATUS_APPROVED, $dispatch->fresh()->status);
    }

    public function test_dispatch_cannot_consume_claimed_serials_but_can_consume_unclaimed_ones_without_altering_claims(): void
    {
        $this->product->update(['serial_number_required' => true]);
        $mk = fn (string $sn) => \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $this->product->id, 'location_id' => $this->consignmentLocation->id, 'serial_number' => $sn,
            'consignment_receiving_detail_id' => $this->receipt->id, 'status' => 'ACTIVE']);
        $s1 = $mk('SN-A');
        $s2 = $mk('SN-B');
        $s3 = $mk('SN-C');

        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id,
            'destination_location_id' => $this->standardLocation->id, 'base_quantity' => '2', 'serial_ids' => [$s1->id, $s2->id],
        ]], $this->user);
        $b = $svc->approve($svc->submit($b, $this->user), $this->user);
        $this->assertSame(2, \Modules\Consignment\Entities\ConsignmentActiveSerialClaim::count());

        $dispatchSerials = function (array $serials): Dispatch {
            $key = $this->product->id . '--0';
            $this->actingAs($this->user)->withSession(['setting_id' => $this->setting->id])
                ->post(route('sales.storeDispatch', $this->sale), [
                    'dispatch_date' => now()->toDateString(),
                    'dispatchedQuantities' => [$key => count($serials)],
                    'selectedSerialNumbers' => [$key => $serials],
                    'serialNumberLocations' => [$key => array_fill_keys($serials, $this->consignmentLocation->id)],
                ]);
            $dispatch = Dispatch::where('sale_id', $this->sale->id)->latest('id')->firstOrFail();
            $this->actingAs($this->user)->withSession(['setting_id' => $this->setting->id])
                ->post(route('dispatches.approve', $dispatch));

            return $dispatch->fresh();
        };

        $blocked = $dispatchSerials(['SN-A']);
        $this->assertSame(Dispatch::STATUS_PENDING, $blocked->status, 'claimed serial must not be dispatched');
        $this->assertSame('ACTIVE', $s1->fresh()->status);
        $this->assertSame(10.0, $this->stockAt($this->consignmentLocation));

        // Reject the stuck pending dispatch (release path), then dispatch the unclaimed serial.
        $this->actingAs($this->user)->withSession(['setting_id' => $this->setting->id])
            ->post(route('dispatches.reject', $blocked), ['rejection_reason' => 'claimed']);
        $this->assertSame(Dispatch::STATUS_REJECTED, $blocked->fresh()->status);

        $ok = $dispatchSerials(['SN-C']);
        $this->assertSame(Dispatch::STATUS_APPROVED, $ok->status);
        $this->assertSame('SOLD', $s3->fresh()->status);
        $this->assertSame(2, \Modules\Consignment\Entities\ConsignmentActiveSerialClaim::where('consignment_buyout_id', $b->id)->count());
        $this->assertSame(9.0, $this->stockAt($this->consignmentLocation));
    }

    public function test_acquired_goods_sold_later_from_the_owned_destination_never_create_a_second_supplier_bill(): void
    {
        config(['consignment.buyouts.finalization_enabled' => true]);
        foreach (['consignments.buyouts.finalize', 'consignments.billing.convert'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
            $this->user->givePermissionTo($p);
        }
        \Modules\Product\Entities\ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id,
            'sale_price' => 1, 'last_purchase_price' => 1, 'average_purchase_price' => 100]);

        // Buy out 4 unsold units; they move from the consignment location to the standard (owned) location.
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id,
            'destination_location_id' => $this->standardLocation->id, 'base_quantity' => '4', 'buckets' => ['NON_TAXED' => '4']]],
            $this->user, ['invoice_metadata' => ['invoice_date' => '2026-11-10', 'supplier_invoice_number' => 'INV-D1']]);
        $b = $svc->approve($svc->submit($b, $this->user), $this->user);
        (new \Modules\Consignment\Services\ConsignmentBuyoutFinalizationService())->finalize($b, $this->user);
        $this->assertSame(6.0, $this->stockAt($this->consignmentLocation));
        $this->assertSame(14.0, $this->stockAt($this->standardLocation));

        // The company now sells owned stock through the real Sales dispatch workflow.
        $owned = $this->tryDispatch($this->standardLocation, 3);
        $this->assertSame(Dispatch::STATUS_APPROVED, $owned->status);
        $result = app(\Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->setting->id);
        $this->assertSame(0, $result['created'], 'owned stock is not a consignment sold source');
        $this->assertSame(0, \Modules\Consignment\Entities\ConsignmentSoldSource::count());

        // Control: selling what is still supplier-owned from the consignment location does create a sold source.
        $this->sale->update(['status' => 'Approved']);
        $this->tryDispatch($this->consignmentLocation, 2);
        $result = app(\Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->setting->id);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, \Modules\Consignment\Entities\ConsignmentSoldSource::count());

        // And the acquired quantity is already consumed supplier capacity: it cannot be billed a second time.
        $cap = (new \Modules\Consignment\Services\ConsignmentBuyoutCapacityService())->receiptCapacity($this->receipt->id);
        $this->assertSame(4.0, $cap['buyout_finalized']);
        $this->assertSame(6.0, $cap['financial_available'] + 0.0 - 0.0);
    }
}

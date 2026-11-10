<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\ProductSerialNumber;
use Spatie\Permission\Models\Permission;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\SettingSaleLocation;

/**
 * Real POS checkout versus approved buyout reservations (deterministic SQLite integration, not a concurrency proof).
 */
class ConsignmentBuyoutPosReservationTest extends PosTransactionFeatureTestCase
{
    private $setting;
    private $cashier;
    private $terminal;
    private $standard;
    private $consignment;
    private $cash;
    private $customer;
    private $product;
    private $supplier;
    private ConsignmentReceivingDetail $receipt;
    private User $buyoutUser;

    private function arrange(bool $serial = false): void
    {
        $this->setting = $this->createSetting('Buyout POS Biz');
        $this->cashier = $this->createUserForSetting($this->setting, 'buyout pos cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment',
        ]);
        [$this->terminal, $this->standard] = $this->createTerminalWithLocation($this->setting);
        $this->consignment = Location::create(['name' => 'Konsinyasi', 'setting_id' => $this->setting->id, 'is_active' => true, 'is_consignment' => true]);
        SalesLocationResolver::forget($this->setting->id);

        $this->product = $this->createStockedProduct($this->setting, $this->standard, [
            'product_code' => 'BO-POS-1', 'stock_qty' => 2, 'sale_price' => 10000,
        ]);
        if ($serial) {
            $this->product->update(['serial_number_required' => true]);
        }
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->consignment->id, 'quantity' => 5,
            'quantity_non_tax' => 5, 'quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0, 'tax_id' => null]);

        $coaId = DB::table('chart_of_accounts')->insertGetId(['name' => 'COA BO', 'account_number' => 'BO-CASH', 'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->cash = PaymentMethod::create(['name' => 'CASH BO', 'coa_id' => $coaId, 'is_cash' => true, 'requires_reference' => false]);
        DB::table('setting_pos_payment_methods')->insert(['setting_id' => $this->setting->id, 'payment_method_id' => $this->cash->id,
            'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        $this->setting->update(['pos_walk_in_customer_id' => $this->customer->id]);
        $this->openSession($this->setting, $this->terminal, $this->cashier);

        $this->supplier = Supplier::create(['setting_id' => $this->setting->id, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $receival = ConsignmentReceival::create(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'reference' => 'CR-P',
            'receival_number' => 'CR-P', 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $this->setting->id, 'location_id' => $this->consignment->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => 'CR-P', 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $this->product->id,
            'product_name' => 'P', 'product_code' => 'P', 'quantity' => 5, 'unit_cost' => 100, 'unit_dpp' => 100,
            'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $this->receipt = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $this->product->id, 'quantity_received' => 5, 'received_base_quantity' => 5, 'unit_cost' => 100, 'unit_dpp' => 100]);

        $this->buyoutUser = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
            $this->buyoutUser->givePermissionTo($p);
        }
        $this->buyoutUser->settings()->attach($this->setting->id, ['role_id' => \Spatie\Permission\Models\Role::findOrCreate('BR', 'web')->id]);
    }

    private function buyout(string $qty, array $serialIds = []): \Modules\Consignment\Entities\ConsignmentBuyout
    {
        $svc = new ConsignmentBuyoutLifecycleService();
        $line = ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id,
            'destination_location_id' => $this->standard->id, 'base_quantity' => $qty];
        $line += $serialIds ? ['serial_ids' => $serialIds] : ['buckets' => ['NON_TAXED' => $qty]];
        $b = $svc->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [$line], $this->buyoutUser);

        return $svc->approve($svc->submit($b, $this->buyoutUser), $this->buyoutUser);
    }

    private function checkout(int $qty, array $extra = [])
    {
        $http = fn () => $this->actingAs($this->cashier)->withSession(['setting_id' => $this->setting->id]);
        $http()->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $this->product->id, 'qty' => $qty] + $extra)->assertOk();
        $http()->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $this->customer->id])->assertOk();

        return $http()->postJson(route('pos.sell.checkout.finalize'), [
            'idempotency_key' => 'K-BO-' . uniqid(),
            'payment' => ['payment_method_id' => $this->cash->id, 'amount_paid' => 10000 * $qty],
        ]);
    }

    private function stock(Location $l): float
    {
        return (float) ProductStock::where('product_id', $this->product->id)->where('location_id', $l->id)->value('quantity');
    }

    public function test_pos_checkout_respects_buyout_reserved_stock_and_rolls_back_completely_on_contention(): void
    {
        $this->arrange();
        $buyout = $this->buyout('4'); // consignment: 5 on hand, 4 reserved => 1 sellable

        // 4 units = 2 standard + 2 consignment: the consignment part collides with the reservation.
        $resp = $this->checkout(4);
        $this->assertNotSame(201, $resp->status(), $resp->getContent());
        $this->assertSame(2.0, $this->stock($this->standard), 'standard stock untouched after rollback');
        $this->assertSame(5.0, $this->stock($this->consignment));
        $this->assertSame(0, Dispatch::count());
        $this->assertSame(4.0, (float) $buyout->fresh()->reservations()->where('resource_type', 'PHYSICAL_BUCKET')->value('base_quantity'));
    }

    public function test_pos_checkout_can_use_unreserved_consignment_stock_and_cancellation_releases_the_rest(): void
    {
        $this->arrange();
        $buyout = $this->buyout('4');

        $ok = $this->checkout(3); // 2 standard + 1 consignment (the single unreserved unit)
        $this->assertSame(201, $ok->status(), $ok->getContent());
        $this->assertSame(0.0, $this->stock($this->standard));
        $this->assertSame(4.0, $this->stock($this->consignment));
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());

        // Cancel the buyout: the remaining stock becomes sellable again.
        (new ConsignmentBuyoutLifecycleService())->cancel($buyout, 'release', $this->buyoutUser);
        $again = $this->checkout(4);
        $this->assertSame(201, $again->status(), $again->getContent());
        $this->assertSame(0.0, $this->stock($this->consignment));
    }

    private function checkoutSerial(string $serial)
    {
        $http = fn () => $this->actingAs($this->cashier)->withSession(['setting_id' => $this->setting->id]);
        $http()->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $this->product->id, 'qty' => 1])->assertOk();
        $snapshot = $http()->getJson(route('pos.sell.cart.show'))->assertOk()->json('cart_snapshot');
        $lineId = (int) (collect($snapshot['lines'])->firstWhere('product_id', $this->product->id)['line_id'] ?? 0);
        $this->assertGreaterThan(0, $lineId);
        $http()->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), ['serial_numbers' => [$serial]])->assertOk();
        $http()->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $this->customer->id])->assertOk();

        return $http()->postJson(route('pos.sell.checkout.finalize'), [
            'idempotency_key' => 'K-BOS-' . uniqid(),
            'payment' => ['payment_method_id' => $this->cash->id, 'amount_paid' => 10000],
        ]);
    }

    public function test_pos_checkout_cannot_sell_serials_claimed_by_a_buyout_but_sells_unclaimed_ones(): void
    {
        $this->arrange(true);
        // Only consignment-held serials exist so the planner must draw from the consignment location.
        \DB::table('product_stocks')->where('product_id', $this->product->id)->where('location_id', $this->standard->id)->update(['quantity' => 0, 'quantity_non_tax' => 0]);
        $mk = fn (string $sn) => ProductSerialNumber::create(['product_id' => $this->product->id, 'location_id' => $this->consignment->id,
            'serial_number' => $sn, 'consignment_receiving_detail_id' => $this->receipt->id, 'status' => 'ACTIVE']);
        $claimed = $mk('BO-SN-1');
        $free = $mk('BO-SN-2');
        $this->buyout('1', [$claimed->id]);
        $this->assertSame(1, ConsignmentActiveSerialClaim::count());

        $resp = $this->checkoutSerial('BO-SN-1');
        $this->assertNotSame(201, $resp->status(), $resp->getContent());
        $this->assertStringContainsString('dicadangkan', $resp->getContent());
        $this->assertSame('ACTIVE', $claimed->fresh()->status);
        $this->assertSame(0, Dispatch::count());
    }

    public function test_pos_checkout_sells_an_unclaimed_serial_while_another_is_claimed(): void
    {
        $this->arrange(true);
        \DB::table('product_stocks')->where('product_id', $this->product->id)->where('location_id', $this->standard->id)->update(['quantity' => 0, 'quantity_non_tax' => 0]);
        $mk = fn (string $sn) => ProductSerialNumber::create(['product_id' => $this->product->id, 'location_id' => $this->consignment->id,
            'serial_number' => $sn, 'consignment_receiving_detail_id' => $this->receipt->id, 'status' => 'ACTIVE']);
        $claimed = $mk('BO-SN-1');
        $free = $mk('BO-SN-2');
        $this->buyout('1', [$claimed->id]);

        $ok = $this->checkoutSerial('BO-SN-2');
        $this->assertSame(201, $ok->status(), $ok->getContent());
        $this->assertSame('SOLD', $free->fresh()->status);
        $this->assertSame('ACTIVE', $claimed->fresh()->status);
        $this->assertSame(1, ConsignmentActiveSerialClaim::where('product_serial_number_id', $claimed->id)->count(), 'claim ownership untouched');
    }

    public function test_acquired_goods_sold_later_through_pos_never_create_a_second_supplier_bill(): void
    {
        $this->arrange();
        config(['consignment.buyouts.finalization_enabled' => true]);
        foreach (['consignments.buyouts.finalize', 'consignments.billing.convert'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
            $this->buyoutUser->givePermissionTo($p);
        }
        \Modules\Product\Entities\ProductPrice::updateOrCreate(['product_id' => $this->product->id, 'setting_id' => $this->setting->id],
            ['sale_price' => 10000, 'last_purchase_price' => 1, 'average_purchase_price' => 100]);

        // Buy out 3 of the 5 consignment units into the POS standard location, then sell them through real POS checkout.
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'destination_location_id' => $this->standard->id,
            'base_quantity' => '3', 'buckets' => ['NON_TAXED' => '3']]], $this->buyoutUser,
            ['invoice_metadata' => ['invoice_date' => '2026-11-10', 'supplier_invoice_number' => 'INV-POS']]);
        $b = $svc->approve($svc->submit($b, $this->buyoutUser), $this->buyoutUser);
        (new \Modules\Consignment\Services\ConsignmentBuyoutFinalizationService())->finalize($b, $this->buyoutUser);
        $this->assertSame(5.0, $this->stock($this->standard));
        $this->assertSame(2.0, $this->stock($this->consignment));

        $ok = $this->checkout(5);        // priority: standard (5 owned units) first, consignment untouched
        $this->assertSame(201, $ok->status(), $ok->getContent());
        $this->assertSame(0.0, $this->stock($this->standard));
        $this->assertSame(2.0, $this->stock($this->consignment));

        $result = app(\Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->setting->id);
        $this->assertSame(0, $result['created'], 'acquired stock sold from the standard location is not a consignment sold source');
        $this->assertSame(0, \Modules\Consignment\Entities\ConsignmentSoldSource::count());
        $this->assertSame(3.0, (new \Modules\Consignment\Services\ConsignmentBuyoutCapacityService())->receiptCapacity($this->receipt->id)['buyout_finalized']);
    }
}

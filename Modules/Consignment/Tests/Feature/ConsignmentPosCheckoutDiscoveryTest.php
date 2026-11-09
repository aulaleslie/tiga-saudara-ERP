<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
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
 * Spec: consignment-sales-allocation, "POS sale uses mixed source locations".
 *
 * Drives a REAL POS checkout (cart -> finalize) whose quantity spans a standard and a consignment
 * sale location, then runs sold-source discovery. The other consignment tests hand-build
 * dispatch/POS rows; this guards the producing code (POS location priority, dispatch creation,
 * pos_checkout linkage) against drifting from what discovery relies on.
 */
class ConsignmentPosCheckoutDiscoveryTest extends PosTransactionFeatureTestCase
{
    public function test_real_pos_checkout_over_standard_and_consignment_locations_only_captures_the_consignment_quantity(): void
    {
        $setting = $this->createSetting('Consignment POS Biz');
        $cashier = $this->createUserForSetting($setting, 'consignment pos cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment',
        ]);

        [$terminal, $standardLocation] = $this->createTerminalWithLocation($setting);
        $consignmentLocation = Location::create([
            'name' => 'Gudang Konsinyasi POS',
            'setting_id' => $setting->id,
            'is_active' => true,
            'is_consignment' => true,
        ]);

        // Location::booted() registers each new location as an enabled POS sale location in creation
        // order, so priority is standard (1) then consignment (2). This is what makes consignment
        // stock reachable from POS at all, so assert it rather than silently re-creating the rows.
        $this->assertSame(
            [$standardLocation->id, $consignmentLocation->id],
            SettingSaleLocation::where('setting_id', $setting->id)->where('is_enabled', true)
                ->orderBy('position')->pluck('location_id')->map(fn ($id) => (int) $id)->all()
        );
        SalesLocationResolver::forget($setting->id);

        $product = $this->createStockedProduct($setting, $standardLocation, [
            'product_code' => 'CONS-POS-1',
            'stock_qty' => 2,
            'sale_price' => 10000,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $consignmentLocation->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA CONS POS', 'account_number' => 'CONS-POS-CASH', 'category' => 'Kas & Bank',
            'setting_id' => $setting->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cash = PaymentMethod::create(['name' => 'CASH CONS POS', 'coa_id' => $coaId, 'is_cash' => true, 'requires_reference' => false]);
        DB::table('setting_pos_payment_methods')->insert([
            'setting_id' => $setting->id, 'payment_method_id' => $cash->id, 'is_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $customer = Customer::factory()->create(['setting_id' => $setting->id]);
        $setting->update(['pos_walk_in_customer_id' => $customer->id]);

        $this->openSession($setting, $terminal, $cashier);

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 4])
            ->assertOk();
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer->id])
            ->assertOk();

        $response = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-CONS-POS-' . uniqid(),
                'payment' => ['payment_method_id' => $cash->id, 'amount_paid' => 40000],
            ]);
        $this->assertSame(201, $response->status(), $response->getContent());

        $saleId = (int) $response->json('sale_id');
        $this->assertGreaterThan(0, $saleId);

        // The real POS posting persisted approved dispatch evidence per source location.
        $details = DispatchDetail::where('sale_id', $saleId)->get();
        $this->assertEqualsCanonicalizing(
            [$standardLocation->id, $consignmentLocation->id],
            $details->pluck('location_id')->map(fn ($id) => (int) $id)->all(),
            'POS must record one dispatch detail per source location it drew stock from.'
        );
        $this->assertEquals(2, (float) $details->firstWhere('location_id', $standardLocation->id)->dispatched_quantity);
        $this->assertEquals(2, (float) $details->firstWhere('location_id', $consignmentLocation->id)->dispatched_quantity);
        $this->assertSame(
            [Dispatch::STATUS_APPROVED],
            Dispatch::whereIn('id', $details->pluck('dispatch_id'))->pluck('status')->unique()->values()->all()
        );

        $result = app(ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($setting->id);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['blocked']);

        $consignmentDetail = $details->firstWhere('location_id', $consignmentLocation->id);
        $standardDetail = $details->firstWhere('location_id', $standardLocation->id);

        $this->assertDatabaseMissing('consignment_sold_sources', ['dispatch_detail_id' => $standardDetail->id]);

        $source = ConsignmentSoldSource::where('dispatch_detail_id', $consignmentDetail->id)->firstOrFail();
        $this->assertSame($consignmentLocation->id, (int) $source->location_id);
        $this->assertEquals(2, (float) $source->original_base_quantity);
        $this->assertSame($saleId, (int) $source->sale_id);

        // KNOWN GAP (decision pending, see docs/testing/known-failing-baseline.md): a single-setting
        // POS checkout links its sale only via pos_checkouts.sale_id and writes no pos_checkout_sales
        // row, while discovery resolves POS provenance only through pos_checkout_sales. So sold
        // sources from non-split checkouts currently carry no pos_checkout_id. pos_checkout_id is part
        // of the versioned source snapshot, so changing this must be a deliberate decision. When it
        // is made, flip this assertion to expect the checkout id.
        $this->assertNull($source->pos_checkout_id);
        $this->assertSame([], DB::table('pos_checkout_sales')->where('sale_id', $saleId)->pluck('id')->all());
        $this->assertSame($saleId, (int) PosCheckout::query()->value('sale_id'));
    }
}

<?php

namespace Modules\Consignment\Tests\Feature;

use App\Support\SalesLocationResolver;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentObligationBinding;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent as Event;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;

/**
 * 8.1 POS boundary: a REAL POS checkout at the remote business over transferred, provenance-tracked consignment stock.
 * Checkout, location order, tax, payment and receipt behave exactly as before; nothing consignment-specific is asked
 * or written at the register. Only the later manual discovery relates the sale to the origin's receipt.
 */
class ConsignmentRemotePosBoundaryTest extends PosTransactionFeatureTestCase
{
    public function test_remote_pos_checkout_is_unchanged_and_only_manual_discovery_links_it(): void
    {
        $origin = $this->createSetting('TOP IT Test');
        $remote = $this->createSetting('PERDANA Test');
        $cashier = $this->createUserForSetting($remote, 'remote cashier', ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        [$terminal, $standard] = $this->createTerminalWithLocation($remote);
        $remoteRack = Location::create(['name' => 'Konsinyasi PERDANA', 'setting_id' => $remote->id, 'is_active' => true, 'is_consignment' => true]);
        $originRack = Location::create(['name' => 'Konsinyasi TOP IT', 'setting_id' => $origin->id, 'is_active' => true, 'is_consignment' => true]);
        SalesLocationResolver::forget($remote->id);

        $product = $this->createStockedProduct($remote, $standard, ['product_code' => 'AX-POS', 'stock_qty' => 0, 'sale_price' => 10000]);
        ProductStock::create(['product_id' => $product->id, 'location_id' => $remoteRack->id, 'quantity' => 3, 'quantity_non_tax' => 3, 'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0, 'tax_id' => null]);

        // TOP IT receipt of 5 at its own rack; 3 arrived at PERDANA through a recorded consignment transfer.
        $supplier = Supplier::create(['setting_id' => $origin->id, 'supplier_name' => 'CANDRA', 'supplier_email' => 'c@x.t', 'supplier_phone' => '1', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $rcv = ConsignmentReceival::create(['setting_id' => $origin->id, 'supplier_id' => $supplier->id, 'reference' => 'R1', 'receival_number' => 'R1', 'status' => 'APPROVED', 'date' => now()]);
        $rcg = ConsignmentReceiving::create(['setting_id' => $origin->id, 'location_id' => $originRack->id, 'consignment_receival_id' => $rcv->id, 'receiving_number' => 'TPI-CRN-1', 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $rcv->id, 'product_id' => $product->id, 'product_name' => 'P', 'product_code' => 'P', 'quantity' => 5,
            'unit_cost' => 100, 'unit_dpp' => 100, 'subtotal_cost' => 500, 'subtotal_dpp' => 500, 'total_cost' => 500, 'total_dpp' => 500]);
        $receipt = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $rcg->id, 'consignment_receival_line_id' => $line->id, 'product_id' => $product->id,
            'quantity_received' => 5, 'received_base_quantity' => 5, 'unit_cost' => 100, 'unit_dpp' => 100]);
        foreach ([['SELLABLE', $originRack, 'TRANSIT', $remoteRack, 'd'], ['TRANSIT', $remoteRack, 'SELLABLE', $remoteRack, 'r']] as [$fs, $fl, $ts, $tl, $k]) {
            Event::create(['consignment_receiving_detail_id' => $receipt->id, 'original_setting_id' => $origin->id, 'supplier_id' => $supplier->id, 'product_id' => $product->id,
                'kind' => 'TEST', 'source_type' => 'TEST', 'source_id' => 1, 'source_event_key' => "pos-boundary:{$k}", 'from_state' => $fs, 'from_location_id' => $fl->id,
                'from_setting_id' => $fl->setting_id, 'to_state' => $ts, 'to_location_id' => $tl->id, 'to_setting_id' => $tl->setting_id, 'base_quantity' => 3, 'effective_at' => now()->subHour()]);
        }

        $coaId = DB::table('chart_of_accounts')->insertGetId(['name' => 'COA', 'account_number' => 'COA-RP', 'category' => 'Kas & Bank', 'setting_id' => $remote->id, 'created_at' => now(), 'updated_at' => now()]);
        $cash = PaymentMethod::create(['name' => 'CASH RP', 'coa_id' => $coaId, 'is_cash' => true, 'requires_reference' => false]);
        DB::table('setting_pos_payment_methods')->insert(['setting_id' => $remote->id, 'payment_method_id' => $cash->id, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $customer = Customer::factory()->create(['setting_id' => $remote->id]);
        $remote->update(['pos_walk_in_customer_id' => $customer->id]);
        $this->openSession($remote, $terminal, $cashier);

        $events = Event::count();
        $as = fn () => $this->actingAs($cashier)->withSession(['setting_id' => $remote->id]);
        $as()->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 2])->assertOk();
        $as()->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer->id])->assertOk();
        $response = $as()->postJson(route('pos.sell.checkout.finalize'), [
            'idempotency_key' => 'K-RP-' . uniqid(), 'payment' => ['payment_method_id' => $cash->id, 'amount_paid' => 20000]]);
        $this->assertSame(201, $response->status(), $response->getContent());
        $saleId = (int) $response->json('sale_id');

        // The register behaved as always: own business, own consignment rack, own payment; no supplier step, no provenance write.
        $detail = DispatchDetail::where('sale_id', $saleId)->sole();
        $this->assertSame($remoteRack->id, (int) $detail->location_id);
        $this->assertSame((int) $remote->id, (int) DB::table('sales')->where('id', $saleId)->value('setting_id'));
        $this->assertSame($events, Event::count(), 'checkout writes no provenance');
        $this->assertSame(0, ConsignmentSoldSource::count(), 'no automatic discovery');
        $this->assertStringNotContainsString('CANDRA', $response->getContent());

        // Manual discovery by the origin links the sale; non-serialized attribution stays explicit back-office work.
        app(ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($origin->id);
        $src = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->sole();
        $this->assertSame((int) $remote->id, (int) $src->setting_id);
        $cap = app(ConsignmentBuyoutCapacityService::class);
        $this->assertFalse($cap->soldAttribution($src)['complete']);
        $cap->setAttribution($src->id, [$receipt->id => 2], null, 'back-office attribution');
        $this->assertSame(1, ConsignmentObligationBinding::count());
        $this->assertTrue($cap->soldAttribution($src->fresh())['complete']);
    }
}

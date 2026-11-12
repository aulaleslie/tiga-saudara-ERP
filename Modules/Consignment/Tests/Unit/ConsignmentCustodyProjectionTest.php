<?php

namespace Modules\Consignment\Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutAuditEvent;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentInventoryCoordination;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Consignment\Services\ConsignmentCustodyProjection;
use Tests\TestCase;

class ConsignmentCustodyProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Supplier $supplier;
    protected Product $product;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Custody Projection Test',
            'company_email' => 'test@example.com',
            'company_phone' => '08123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
            'document_prefix' => 'CSG',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Beta',
            'supplier_email' => 'beta@example.com',
            'supplier_phone' => '081111112',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Vendor St',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Rack B',
            'is_consignment' => true,
        ]);

        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pieces',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Widget Schema',
            'product_code' => 'W-BO',
            'product_unit' => $unit->id,
            'product_price' => 100000,
            'product_cost' => 80000,
            'product_quantity' => 10,
        ]);
    }

    private function makeReceipt(Supplier $supplier, string $ref, string $qty = '10.500'): ConsignmentReceivingDetail
    {
        $receival = ConsignmentReceival::create([
            'setting_id' => $this->setting->id, 'supplier_id' => $supplier->id, 'reference' => $ref,
            'receival_number' => $ref, 'status' => 'APPROVED', 'date' => now(),
        ]);
        $receiving = ConsignmentReceiving::create([
            'setting_id' => $this->setting->id, 'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => $ref,
            'status' => ConsignmentReceiving::STATUS_APPROVED, 'date' => now(),
        ]);
        $line = ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id, 'product_id' => $this->product->id,
            'product_name' => 'W', 'product_code' => 'W', 'quantity' => $qty, 'unit_cost' => 100,
            'unit_dpp' => 100, 'subtotal_cost' => 1050, 'subtotal_dpp' => 1050, 'total_cost' => 1050, 'total_dpp' => 1050,
        ]);

        return ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $this->product->id, 'quantity_received' => $qty, 'received_base_quantity' => $qty,
            'unit_cost' => 100, 'unit_dpp' => 100,
        ]);
    }


    private function eventAttrs(ConsignmentReceivingDetail $d, string $key, string $qty = '1.000'): array
    {
        return [
            'consignment_receiving_detail_id' => $d->id, 'original_setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id, 'product_id' => $this->product->id,
            'kind' => 'RECEIPT', 'source_type' => 'consignment_receiving_detail', 'source_id' => $d->id,
            'source_event_key' => $key, 'from_state' => ConsignmentProvenanceEvent::STATE_EXTERNAL,
            'to_state' => ConsignmentProvenanceEvent::STATE_SELLABLE, 'to_location_id' => $this->location->id,
            'to_setting_id' => $this->setting->id, 'base_quantity' => $qty, 'effective_at' => now(),
        ];
    }


    private function move(ConsignmentReceivingDetail $d, string $key, string $from, ?Location $fromLoc, string $to, ?Location $toLoc, string $qty): void
    {
        ConsignmentProvenanceEvent::create([
            'kind' => 'TEST', 'from_state' => $from, 'from_location_id' => $fromLoc?->id, 'from_setting_id' => $fromLoc?->setting_id,
            'to_state' => $to, 'to_location_id' => $toLoc?->id, 'to_setting_id' => $toLoc?->setting_id,
        ] + $this->eventAttrs($d, $key, $qty));
    }

    private function remote(): Location
    {
        $s = Setting::create([
            'company_name' => 'Remote', 'company_email' => 'r@example.com', 'company_phone' => '0812',
            'default_currency_id' => $this->setting->default_currency_id, 'default_currency_position' => 'prefix',
            'notification_email' => 'r@example.com', 'footer_text' => 'F', 'company_address' => 'A', 'is_pkp' => false, 'document_prefix' => 'RMT',
        ]);

        return Location::create(['setting_id' => $s->id, 'name' => 'Remote Rack', 'is_consignment' => true]);
    }

    /** @test */
    public function implicit_receipt_root_is_direct_custody()
    {
        $p = (new ConsignmentCustodyProjection)->forReceipt($this->makeReceipt($this->supplier, 'CRN-1', '10.000'));
        $this->assertTrue($p['conserved']);
        $this->assertSame([], $p['blockers']);
        $this->assertEqualsWithDelta(10.0, (new ConsignmentCustodyProjection)->quantityAt($p, 'SELLABLE', $this->location->id), 0.0001);
    }

    /** @test */
    public function transfers_sales_and_acquisitions_are_disjoint_and_conserved()
    {
        $S = ConsignmentProvenanceEvent::class;
        $d = $this->makeReceipt($this->supplier, 'CRN-H1', '50.000');
        $r = $this->remote();
        $this->move($d, 'k1', $S::STATE_SELLABLE, $this->location, $S::STATE_TRANSIT, $this->location, '5.000');
        $this->move($d, 'k2', $S::STATE_TRANSIT, $this->location, $S::STATE_SELLABLE, $r, '5.000');
        $this->move($d, 'k3', $S::STATE_SELLABLE, $this->location, $S::STATE_SOLD, null, '41.000');
        $this->move($d, 'k4', $S::STATE_SELLABLE, $r, $S::STATE_SOLD, null, '3.000');
        $this->move($d, 'k5', $S::STATE_SELLABLE, $r, $S::STATE_ACQUIRED, null, '1.000');
        $this->move($d, 'k6', $S::STATE_SELLABLE, $r, $S::STATE_HELD, $r, '1.000');

        $proj = new ConsignmentCustodyProjection;
        $p = $proj->forReceipt($d->fresh());
        $this->assertTrue($p['conserved']);
        $this->assertSame([], $p['blockers']);
        $this->assertEqualsWithDelta(44.0, $p['sold'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $p['acquired'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $proj->quantityAt($p, 'SELLABLE', $this->location->id), 0.0001);
        $this->assertEqualsWithDelta(0.0, $proj->quantityAt($p, 'SELLABLE', $r->id), 0.0001);
        $this->assertEqualsWithDelta(1.0, $proj->quantityAt($p, 'HELD', $r->id), 0.0001);
        $this->assertEqualsWithDelta(0.0, $proj->quantityAt($p, 'TRANSIT', $this->location->id), 0.0001);
    }

    /** @test */
    public function unexplained_negative_balances_are_blockers()
    {
        $S = ConsignmentProvenanceEvent::class;
        $d = $this->makeReceipt($this->supplier, 'CRN-NEG', '5.000');
        $this->move($d, 'n1', $S::STATE_SELLABLE, $this->remote(), $S::STATE_SOLD, null, '2.000');

        $p = (new ConsignmentCustodyProjection)->forReceipt($d->fresh());
        $this->assertNotEmpty($p['blockers']);
        $this->assertStringContainsString('negative', implode(' ', $p['blockers']));
    }
}

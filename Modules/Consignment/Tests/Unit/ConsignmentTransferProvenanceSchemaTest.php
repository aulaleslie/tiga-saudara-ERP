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
use Tests\TestCase;

class ConsignmentTransferProvenanceSchemaTest extends TestCase
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
            'company_name' => 'Provenance Schema Test',
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

    /** @test */
    public function it_creates_provenance_tables_with_identity_columns()
    {
        foreach ([
            'consignment_transfer_lot_selections' => ['original_setting_id', 'supplier_id', 'source_setting_id', 'destination_setting_id', 'base_quantity'],
            'consignment_provenance_events' => ['original_setting_id', 'from_setting_id', 'to_setting_id', 'acquired_owner_setting_id', 'source_event_key'],
            'consignment_obligation_bindings' => ['original_setting_id', 'seller_setting_id', 'seller_location_id', 'binding_hash'],
            'consignment_historical_mappings' => ['original_setting_id', 'evidence_hash', 'mapping', 'reason'],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumns($table, $columns), $table);
        }
    }

    /** @test */
    public function it_rejects_duplicate_source_events_and_keeps_exact_quantities()
    {
        $d = $this->makeReceipt($this->supplier, 'CRN-P1');
        $e = ConsignmentProvenanceEvent::create($this->eventAttrs($d, 'receipt:'.$d->id, '10.500'));
        $this->assertSame('10.500', (string) $e->fresh()->base_quantity);

        $this->expectException(QueryException::class);
        ConsignmentProvenanceEvent::create($this->eventAttrs($d, 'receipt:'.$d->id));
    }

    /** @test */
    public function it_keeps_multiple_receipts_lossless_in_one_custody_location()
    {
        $other = Supplier::create([
            'setting_id' => $this->setting->id, 'supplier_name' => 'Supplier Gamma', 'supplier_email' => 'g@example.com',
            'supplier_phone' => '0812', 'city' => 'Jakarta', 'country' => 'Indonesia', 'address' => 'X',
        ]);
        $a = $this->makeReceipt($this->supplier, 'CRN-A', '4.000');
        $b = $this->makeReceipt($other, 'CRN-B', '6.250');
        ConsignmentProvenanceEvent::create($this->eventAttrs($a, 'receipt:'.$a->id, '4.000'));
        ConsignmentProvenanceEvent::create(['supplier_id' => $other->id] + $this->eventAttrs($b, 'receipt:'.$b->id, '6.250'));

        $sums = ConsignmentProvenanceEvent::query()->where('to_location_id', $this->location->id)
            ->groupBy('consignment_receiving_detail_id')->selectRaw('consignment_receiving_detail_id, SUM(base_quantity) q')
            ->pluck('q', 'consignment_receiving_detail_id');
        $this->assertEqualsWithDelta(4.0, (float) $sums[$a->id], 0.0001);
        $this->assertEqualsWithDelta(6.25, (float) $sums[$b->id], 0.0001);
    }

    /** @test */
    public function it_enforces_foreign_keys_on_provenance_rows()
    {
        $d = $this->makeReceipt($this->supplier, 'CRN-FK');
        $this->expectException(QueryException::class);
        ConsignmentProvenanceEvent::create(['to_location_id' => 999999] + $this->eventAttrs($d, 'fk:1'));
    }
}

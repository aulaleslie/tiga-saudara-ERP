<?php

namespace Modules\Consignment\Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutAuditEvent;
use Modules\Consignment\Entities\ConsignmentBuyoutCostEvent;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentInventoryCoordination;
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

class ConsignmentBuyoutInventoryOwnerSchemaTest extends TestCase
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
            'company_name' => 'Owner Schema Test',
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

    private function makeBuyout(string $ref = 'BO-001'): ConsignmentBuyout
    {
        return ConsignmentBuyout::create([
            'setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id,
            'reference' => $ref, 'status' => ConsignmentBuyout::STATUS_DRAFT, 'date' => now(),
        ]);
    }

    private function lineAttrs(ConsignmentBuyout $b, ConsignmentReceivingDetail $d, string $qty): array
    {
        return [
            'consignment_buyout_id' => $b->id, 'line_type' => ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT,
            'consignment_receiving_detail_id' => $d->id, 'product_id' => $this->product->id,
            'source_location_id' => $this->location->id, 'base_quantity' => $qty, 'original_snapshot' => ['k' => 1],
        ];
    }


    private function otherSetting(): Setting
    {
        return Setting::create([
            'company_name' => 'Remote Owner', 'company_email' => 'r@example.com', 'company_phone' => '0812',
            'default_currency_id' => $this->setting->default_currency_id, 'default_currency_position' => 'prefix',
            'notification_email' => 'r@example.com', 'footer_text' => 'F', 'company_address' => 'A',
            'is_pkp' => false, 'document_prefix' => 'RMT',
        ]);
    }

    private function costEvent(ConsignmentBuyout $b, int $settingId): ConsignmentBuyoutCostEvent
    {
        return ConsignmentBuyoutCostEvent::create([
            'consignment_buyout_id' => $b->id, 'setting_id' => $settingId, 'product_id' => $this->product->id,
            'kind' => 'ACQUISITION', 'total_quantity' => 5, 'acquired_quantity' => 2, 'acquired_cost_total' => 200,
            'average_before' => 80, 'average_after' => 90, 'effective_at' => now(),
        ]);
    }

    /** @test */
    public function owner_columns_exist_and_are_nullable_for_legacy_rows()
    {
        foreach (['consignment_buyout_lines', 'consignment_buyout_purchase_lineages', 'consignment_buyout_movements', 'consignment_buyout_ownership_events'] as $t) {
            $this->assertTrue(Schema::hasColumn($t, 'inventory_owner_setting_id'), $t);
        }
        $b = $this->makeBuyout();
        $line = ConsignmentBuyoutLine::create($this->lineAttrs($b, $this->makeReceipt($this->supplier, 'CRN-L'), '1.000'));
        $this->assertNull($line->fresh()->inventory_owner_setting_id);
        $this->assertSame((int) $this->setting->id, $line->fresh()->inventoryOwnerSettingId());
    }

    /** @test */
    public function one_cost_event_per_owner_for_the_same_product()
    {
        $b = $this->makeBuyout();
        $this->costEvent($b, $this->setting->id);
        $this->costEvent($b, $this->otherSetting()->id);
        $this->assertSame(2, ConsignmentBuyoutCostEvent::where('consignment_buyout_id', $b->id)->count());

        $this->expectException(QueryException::class);
        $this->costEvent($b, $this->setting->id);
    }

    /** @test */
    public function rollback_is_refused_when_multi_owner_cost_events_exist()
    {
        $b = $this->makeBuyout();
        $this->costEvent($b, $this->setting->id);
        $this->costEvent($b, $this->otherSetting()->id);
        $migration = require base_path('Modules/Consignment/Database/Migrations/2026_11_11_000002_add_inventory_owner_to_consignment_buyout_evidence.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}

<?php

namespace Modules\Consignment\Tests\Support;

use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentBuyoutReservation;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

/** Shared fixtures for buyout domain tests. */
trait BuildsBuyoutFixtures
{
    protected Setting $setting;
    protected Supplier $supplier;
    protected Product $product;
    protected Location $location;
    protected ConsignmentBuyoutCapacityService $svc;
    private int $n = 0;

    protected function setUpBuyoutFixtures(): void
    {
        $this->svc = new ConsignmentBuyoutCapacityService();

        $currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Buyout Schema Test',
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

    private function raw(ConsignmentSoldSource $src, array $attrs): void
    {
        \DB::table('consignment_sold_sources')->where('id', $src->id)->update($attrs); // sold sources are immutable via Eloquent
    }

    private function supplier(string $name): Supplier
    {
        return Supplier::create(['setting_id' => $this->setting->id, 'supplier_name' => $name, 'supplier_email' => $name . '@e.com',
            'supplier_phone' => '08' . (++$this->n), 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
    }

    private function receipt(Supplier $supplier, string $qty): ConsignmentReceivingDetail
    {
        $ref = 'CR-CAP-' . (++$this->n);
        $receival = ConsignmentReceival::create(['setting_id' => $this->setting->id, 'supplier_id' => $supplier->id, 'reference' => $ref,
            'receival_number' => $ref, 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $this->setting->id, 'location_id' => $this->location->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => $ref, 'status' => ConsignmentReceiving::STATUS_APPROVED, 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $this->product->id,
            'product_name' => 'W', 'product_code' => 'W', 'quantity' => $qty, 'unit_cost' => 100, 'unit_dpp' => 100,
            'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);

        return ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $this->product->id, 'quantity_received' => $qty, 'received_base_quantity' => $qty, 'unit_cost' => 100, 'unit_dpp' => 100]);
    }

    private function soldSource(string $qty): ConsignmentSoldSource
    {
        $i = ++$this->n;
        $customer = \Modules\People\Entities\Customer::create(['setting_id' => $this->setting->id, 'customer_name' => "C{$i}",
            'customer_email' => "c{$i}@e.com", 'customer_phone' => "081{$i}", 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $sale = \Modules\Sale\Entities\Sale::create(['reference' => "SL-RACE-{$i}", 'date' => now(), 'customer_id' => $customer->id, 'customer_name' => "C{$i}",
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => 1, 'paid_amount' => 1, 'due_amount' => 0, 'status' => 'Completed', 'payment_status' => 'Paid',
            'payment_method' => 'Cash', 'setting_id' => $this->setting->id]);
        $dispatch = \Modules\Sale\Entities\Dispatch::create(['sale_id' => $sale->id, 'status' => 'APPROVED']);
        $dd = \Modules\Sale\Entities\DispatchDetail::create(['dispatch_id' => $dispatch->id, 'sale_id' => $sale->id,
            'product_id' => $this->product->id, 'location_id' => $this->location->id, 'dispatched_quantity' => $qty]);

        $src = ConsignmentSoldSource::create(['setting_id' => $this->setting->id, 'dispatch_detail_id' => $dd->id, 'sale_id' => $sale->id,
            'product_id' => $this->product->id, 'location_id' => $this->location->id, 'original_base_quantity' => $qty,
            'dispatched_at' => now(), 'source_hash' => "h{$i}", 'source_snapshot' => []]);
        // The evidence hash is the genuine live dispatch hash, so buyout validation can recompute and compare it.
        $this->raw($src, ['source_hash' => app(\Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService::class)->computeCanonicalLiveHash($src->fresh())]);

        return $src->fresh();
    }

    private function buyout(Supplier $s, string $status = 'DRAFT'): ConsignmentBuyout
    {
        return ConsignmentBuyout::create(['setting_id' => $this->setting->id, 'supplier_id' => $s->id,
            'reference' => 'BO-CAP-' . (++$this->n), 'status' => $status, 'date' => now()]);
    }

    private function line(ConsignmentBuyout $b, ConsignmentReceivingDetail $d, string $type, string $qty, ?ConsignmentSoldSource $src = null): ConsignmentBuyoutLine
    {
        return ConsignmentBuyoutLine::create(['consignment_buyout_id' => $b->id, 'line_type' => $type,
            'consignment_receiving_detail_id' => $d->id, 'product_id' => $this->product->id,
            'consignment_sold_source_id' => $src?->id, 'source_location_id' => $this->location->id,
            'base_quantity' => $qty, 'original_snapshot' => []]);
    }

    private function reserve(ConsignmentBuyout $b, ConsignmentBuyoutLine $l, string $type, string $qty, array $extra = []): void
    {
        ConsignmentBuyoutReservation::create(['consignment_buyout_id' => $b->id, 'consignment_buyout_line_id' => $l->id, 'revision' => 1,
            'resource_type' => $type, 'base_quantity' => $qty, 'status' => 'ACTIVE'] + $extra);
    }

    private function stock(string $good, string $broken = '0'): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id,
            'quantity' => bcadd($good, $broken, 3), 'quantity_tax' => 0, 'quantity_non_tax' => $good,
            'broken_quantity_non_tax' => $broken, 'broken_quantity_tax' => 0, 'broken_quantity' => $broken]);
    }

}

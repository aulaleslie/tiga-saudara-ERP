<?php

namespace Modules\Consignment\Tests\Support;

use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferV3GoodsService;
use Modules\Adjustment\Services\TransferV3ReceiptExecutor;
use Modules\Adjustment\Tests\Support\BuildsV3TransferFixtures;
use Modules\Consignment\Entities\ConsignmentObligationBinding;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Consignment\Services\ConsignmentRemoteSaleResolver;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

/** Origin business A (TOP IT-like) and remote seller B (PERDANA-like) with receipt-backed consignment stock. */
trait BuildsRemoteProvenanceFixtures
{
    use BuildsV3TransferFixtures;

    protected Location $ca;
    protected Location $cb;
    protected Supplier $supplier;
    protected ConsignmentReceivingDetail $receipt;
    /** @var ProductSerialNumber[] */
    protected array $sn = [];
    protected int $n = 0;

    protected function setUpRemoteFixtures(): void
    {
        $this->buildV3Fixtures();
        $this->ca = Location::create(['setting_id' => $this->businessA->id, 'name' => 'Konsinyasi A', 'is_active' => true, 'is_consignment' => true]);
        $this->cb = Location::create(['setting_id' => $this->businessB->id, 'name' => 'Konsinyasi B', 'is_active' => true, 'is_consignment' => true]);
        $this->supplier = Supplier::create(['setting_id' => $this->businessA->id, 'supplier_name' => 'CANDRA', 'supplier_email' => 'c@x.test',
            'supplier_phone' => '0812', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $this->receipt = $this->makeReceipt(3);
        $this->stock($this->serialized, $this->ca, nonTax: 3);
        \DB::table('consignment_receival_lines')->where('id', $this->receipt->consignment_receival_line_id)->update(['is_serialized' => true]);
        foreach (['AX-1', 'AX-2', 'AX-3'] as $no) {
            $this->sn[] = $psn = ProductSerialNumber::create(['product_id' => $this->serialized->id, 'location_id' => $this->ca->id, 'serial_number' => $no,
                'tax_id' => null, 'consignment_receiving_detail_id' => $this->receipt->id]);
            \DB::table('consignment_receiving_detail_serial_numbers')->insert(['consignment_receiving_detail_id' => $this->receipt->id, 'product_serial_number_id' => $psn->id,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function makeReceipt(int $qty): ConsignmentReceivingDetail
    {
        $ref = 'TPI-CRN-' . (++$this->n);
        $receival = ConsignmentReceival::create(['setting_id' => $this->businessA->id, 'supplier_id' => $this->supplier->id, 'reference' => $ref,
            'receival_number' => $ref, 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $this->businessA->id, 'location_id' => $this->ca->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => $ref, 'status' => ConsignmentReceiving::STATUS_APPROVED, 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $this->serialized->id,
            'product_name' => 'HYPE', 'product_code' => 'H', 'quantity' => $qty, 'unit_cost' => 100, 'unit_dpp' => 100,
            'subtotal_cost' => 100 * $qty, 'subtotal_dpp' => 100 * $qty, 'total_cost' => 100 * $qty, 'total_dpp' => 100 * $qty]);

        return ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $this->serialized->id, 'quantity_received' => $qty, 'received_base_quantity' => $qty, 'unit_cost' => 100, 'unit_dpp' => 100]);
    }

    protected function moveSerial(ProductSerialNumber $serial, Location $from, Location $to, bool $receive = true): Transfer
    {
        $setting = (int) $from->setting_id;
        session(['setting_id' => $setting]);
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->serialized->id, 'quantity' => 1, 'serial_ids' => [$serial->id]]], $this->actor, $setting);
        $t->refresh();
        app(\Modules\Adjustment\Services\TransferV3AllocationService::class)->saveProgress($t, $this->actor, $setting,
            (int) $t->current_request_revision_id, (int) $t->approval_configuration_revision, ["{$this->serialized->id}:{$from->id}" => $to->id], []);
        $t->refresh();
        app(\Modules\Adjustment\Services\TransferV3DispatchExecutor::class)->approveAndDispatch($t, $this->actor, $setting,
            (int) $t->current_request_revision_id, (int) $t->approval_configuration_revision);
        if ($receive) {
            app(TransferV3ReceiptExecutor::class)->receive($t->fresh(), $this->actor, (int) $to->setting_id);
        }

        return $t;
    }

    /** An approved sale dispatch at a location (read-only input to discovery). */
    protected function sellAt(Location $location, array $serials, ?int $productId = null): DispatchDetail
    {
        $i = ++$this->n;
        $setting = (int) $location->setting_id;
        $customer = Customer::create(['setting_id' => $setting, 'customer_name' => "Cust{$i}", 'customer_email' => "c{$i}@e.com",
            'customer_phone' => "081{$i}", 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $sale = Sale::create(['reference' => "PD-JL-{$i}", 'date' => now(), 'customer_id' => $customer->id, 'customer_name' => "Cust{$i}",
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => 1, 'paid_amount' => 1, 'due_amount' => 0, 'status' => 'Completed', 'payment_status' => 'Paid',
            'payment_method' => 'Cash', 'setting_id' => $setting]);
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED, 'approved_at' => now()->addSecond()]);

        return DispatchDetail::create(['dispatch_id' => $dispatch->id, 'sale_id' => $sale->id, 'product_id' => $productId ?? $this->serialized->id,
            'location_id' => $location->id, 'dispatched_quantity' => max(1, count($serials)),
            'serial_numbers' => $serials ? json_encode(array_map(fn ($s) => $s->serial_number, $serials)) : null]);
    }

    protected function discover(Setting $s): array
    {
        return app(ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($s->id);
    }

    protected function perReceipt(DispatchDetail $dd): array
    {
        return app(ConsignmentBuyoutCapacityService::class)->soldAttribution(ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail());
    }

    protected function bulkReceipt(int $qty): ConsignmentReceivingDetail
    {
        $d = $this->makeReceipt($qty);
        \DB::table('consignment_receiving_details')->where('id', $d->id)->update(['product_id' => $this->bulk->id]);
        \DB::table('consignment_receival_lines')->where('id', $d->consignment_receival_line_id)->update(['product_id' => $this->bulk->id]);

        return $d->fresh();
    }

    protected function moveBulk(ConsignmentReceivingDetail $lot, int $qty): void
    {
        session(['setting_id' => $this->businessA->id]);
        $t = app(TransferV3GoodsService::class)->createAndSubmit(Transfer::CONDITION_GOOD,
            [['product_id' => $this->bulk->id, 'quantity' => $qty, 'serial_ids' => []]], $this->actor, $this->businessA->id);
        $this->saveCompletePlan($t, [['product_id' => $this->bulk->id, 'source_location_id' => $this->ca->id, 'destination_location_id' => $this->cb->id, 'quantity' => $qty]], []);
        $row = app(\Modules\Adjustment\Services\TransferV3AllocationService::class)->currentPlan($t->fresh())->first()->id;
        app(\Modules\Consignment\Services\ConsignmentTransferProvenanceService::class)->confirmSelection($t, [$row => [$lot->id => $qty]], $this->actor);
        $this->dispatchTransfer($t);
        app(TransferV3ReceiptExecutor::class)->receive($t->fresh(), $this->actor, $this->businessB->id);
    }

}

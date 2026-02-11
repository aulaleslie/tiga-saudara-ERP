<?php

namespace Modules\Sale\Tests\Feature;

use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

class PosSerialValidationTest extends PosDraftFeatureTestCase
{
    public function test_submit_is_blocked_when_serial_count_is_less_than_quantity(): void
    {
        $product = $this->createProduct([
            'serial_number_required' => true,
            'product_quantity' => 10,
        ]);
        $this->createStock($product, nonTaxQty: 10, taxQty: 0);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SER-LESS-COUNT',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'is_in_return_process' => false,
        ]);

        $payload = $this->makeDraftPayload($product, qty: 2, price: 100000, optionsOverrides: [
            'serial_numbers' => [[
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'location_id' => $this->location->id,
                'tax_id' => null,
            ]],
        ]);

        $response = $this->submitDraftAndPay($payload, 'idem-serial-count-less');

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'POS_DRAFT_STATE_INVALID');
    }

    public function test_submit_rejects_unavailable_serial_status(): void
    {
        $product = $this->createProduct([
            'serial_number_required' => true,
            'product_quantity' => 10,
        ]);
        $this->createStock($product, nonTaxQty: 10, taxQty: 0);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SER-NOT-ACTIVE',
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'is_broken' => false,
            'is_in_return_process' => false,
        ]);

        $payload = $this->makeDraftPayload($product, qty: 1, price: 100000, optionsOverrides: [
            'serial_numbers' => [[
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'location_id' => $this->location->id,
                'tax_id' => null,
            ]],
            'pos_location_allocations' => [[
                'location_id' => $this->location->id,
                'allocated_non_tax' => 1,
                'allocated_tax' => 0,
            ]],
        ]);

        $response = $this->submitDraftAndPay($payload, 'idem-serial-unavailable');

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'POS_DRAFT_STATE_INVALID');
    }

    public function test_submit_rejects_serial_from_location_not_in_pos_source(): void
    {
        $product = $this->createProduct([
            'serial_number_required' => true,
            'product_quantity' => 10,
        ]);

        $outsideSetting = Setting::factory()->create();
        $outsideLocation = Location::factory()->create([
            'setting_id' => $outsideSetting->id,
        ]);

        $this->createStock($product, nonTaxQty: 10, taxQty: 0);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $outsideLocation->id,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'quantity' => 10,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $outsideLocation->id,
            'serial_number' => 'SER-OUTSIDE-LOC',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'is_in_return_process' => false,
        ]);

        $payload = $this->makeDraftPayload($product, qty: 1, price: 100000, optionsOverrides: [
            'serial_numbers' => [[
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'location_id' => $outsideLocation->id,
                'tax_id' => null,
            ]],
            'pos_location_allocations' => [[
                'location_id' => $outsideLocation->id,
                'allocated_non_tax' => 1,
                'allocated_tax' => 0,
            ]],
        ]);

        $response = $this->submitDraftAndPay($payload, 'idem-serial-location-blocked');

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'POS_DRAFT_STATE_INVALID');
    }

    public function test_serial_tax_is_bound_to_sale_detail_and_dispatch_detail(): void
    {
        $product = $this->createProduct([
            'serial_number_required' => true,
            'product_quantity' => 10,
        ]);

        $tax = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
        ]);

        $this->createStock($product, nonTaxQty: 0, taxQty: 10, taxId: $tax->id);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SER-TAX-BOUND',
            'tax_id' => $tax->id,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'is_in_return_process' => false,
        ]);

        $payload = $this->makeDraftPayload($product, qty: 1, price: 100000, optionsOverrides: [
            'serial_numbers' => [[
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'location_id' => $this->location->id,
                'tax_id' => $tax->id,
            ]],
            'serial_tax_ids' => [$tax->id],
            'resolved_tax_id' => $tax->id,
            'pos_location_allocations' => [[
                'location_id' => $this->location->id,
                'allocated_non_tax' => 0,
                'allocated_tax' => 1,
            ]],
            'product_tax' => $tax->id,
        ]);

        $response = $this->submitDraftAndPay($payload, 'idem-serial-tax-binding');
        $response->assertOk();

        $saleDetail = SaleDetails::query()
            ->where('product_id', $product->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($tax->id, (int) $saleDetail->tax_id);
        $this->assertDatabaseHas('dispatch_details', [
            'sale_id' => $saleDetail->sale_id,
            'product_id' => $saleDetail->product_id,
            'tax_id' => $tax->id,
        ]);
    }

    private function submitDraftAndPay(array $payload, string $idempotencyKey)
    {
        $createResponse = $this->postJson(route('app.pos.drafts.store'), $payload);
        $createResponse->assertCreated();
        $code = (string) $createResponse->json('code');

        $this->postJson(route('app.pos.drafts.lock', $code))->assertOk();

        return $this->postJson(route('app.pos.drafts.submit-payment', $code), [
            'idempotency_key' => $idempotencyKey,
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => (float) ($payload['total_amount'] ?? 0)],
            ],
        ]);
    }
}

<?php

namespace Modules\Sale\Tests\Feature;

use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;

class PosMultiOwnerPartitionTest extends PosDraftFeatureTestCase
{
    public function test_finalize_partitions_sales_by_owner_setting_under_one_receipt(): void
    {
        $otherSetting = Setting::factory()->create([
            'pos_draft_flow_enabled' => true,
        ]);
        $otherLocation = Location::factory()->create([
            'setting_id' => $otherSetting->id,
            'name' => 'Other Tenant Location',
        ]);

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $otherLocation->id],
            ['setting_id' => $this->setting->id, 'position' => 2]
        );

        $ownProduct = $this->createProduct([
            'setting_id' => $this->setting->id,
            'product_code' => 'OWN-PROD-001',
            'product_name' => 'Own Product',
        ]);
        $otherProduct = $this->createProduct([
            'setting_id' => $otherSetting->id,
            'product_code' => 'OTH-PROD-001',
            'product_name' => 'Other Product',
        ]);

        $this->createStock($ownProduct, nonTaxQty: 10);
        ProductStock::create([
            'product_id' => $otherProduct->id,
            'location_id' => $otherLocation->id,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'quantity' => 10,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        $payload = [
            'customer_id' => $this->customer->id,
            'total_amount' => 300000,
            'payload' => [
                'customer_id' => $this->customer->id,
                'total_amount' => 300000,
                'cart' => [
                    [
                        'id' => (string) $ownProduct->id,
                        'name' => $ownProduct->product_name,
                        'qty' => 1,
                        'price' => 100000,
                        'weight' => 1,
                        'options' => [
                            'product_id' => $ownProduct->id,
                            'code' => $ownProduct->product_code,
                            'sub_total' => 100000,
                        ],
                    ],
                    [
                        'id' => (string) $otherProduct->id,
                        'name' => $otherProduct->product_name,
                        'qty' => 1,
                        'price' => 200000,
                        'weight' => 1,
                        'options' => [
                            'product_id' => $otherProduct->id,
                            'code' => $otherProduct->product_code,
                            'sub_total' => 200000,
                            'setting_id' => $otherSetting->id,
                        ],
                    ],
                ],
            ],
        ];

        $createResponse = $this->postJson(route('app.pos.drafts.store'), $payload);
        $createResponse->assertCreated();
        $code = (string) $createResponse->json('code');

        $this->postJson(route('app.pos.drafts.lock', $code))->assertOk();

        $submitResponse = $this->postJson(route('app.pos.drafts.submit-payment', $code), [
            'idempotency_key' => 'idem-multi-owner-partition',
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => 150000],
                ['method_id' => $this->cardMethod->id, 'amount' => 150000],
            ],
        ]);
        $submitResponse->assertOk();

        $receiptId = (int) $submitResponse->json('receipt_id');
        $this->assertDatabaseHas('pos_receipts', ['id' => $receiptId]);
        $this->assertDatabaseCount('sales', 2);

        $this->assertDatabaseHas('sales', [
            'pos_receipt_id' => $receiptId,
            'setting_id' => $this->setting->id,
        ]);
        $this->assertDatabaseHas('sales', [
            'pos_receipt_id' => $receiptId,
            'setting_id' => $otherSetting->id,
        ]);

        $paymentCount = SalePayment::query()
            ->where('pos_receipt_id', $receiptId)
            ->count();
        $this->assertGreaterThanOrEqual(2, $paymentCount);

        $totalPaid = (float) SalePayment::query()
            ->where('pos_receipt_id', $receiptId)
            ->sum('amount');
        $this->assertSame(300000.0, $totalPaid);
    }
}

<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Services\GlobalProjectedHppService;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Services\PurchaseCostHelper;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class GlobalProjectedHppServiceTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private Product $product;
    private GlobalProjectedHppService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingA = Setting::factory()->create();
        $this->settingB = Setting::factory()->create();
        $this->product = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Projected HPP Product',
            'product_code' => 'PROJECTED-HPP',
            'product_quantity' => 100,
            'product_cost' => 100,
            'product_price' => 200,
            'product_unit' => 'pc',
            'stock_managed' => true,
            'is_active' => true,
        ]);
        $this->service = new GlobalProjectedHppService();
    }

    private function createPurchase(Setting $setting, string $status = Purchase::STATUS_APPROVED): Purchase
    {
        return Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PO-' . uniqid(),
            'status' => $status,
            'setting_id' => $setting->id,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'payment_method' => 'Cash',
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'is_tax_included' => false,
        ]);
    }

    private function createLine(
        Purchase $purchase,
        float $quantity,
        float $subTotal,
        float $taxAmount = 0,
        float $discountAmount = 0
    ): PurchaseDetail {
        return PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => $subTotal,
            'product_discount_amount' => $discountAmount,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => $taxAmount,
        ]);
    }

    public function test_it_weights_global_stock_with_outstanding_cost_across_businesses_without_mutating_data(): void
    {
        $lineA = $this->createLine(
            $this->createPurchase($this->settingA),
            10,
            2200,
            200,
            500
        );
        $this->createLine(
            $this->createPurchase($this->settingB),
            30,
            9900,
            900,
            1200
        );
        $price = ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 200,
            'last_purchase_price' => 100,
            'average_purchase_price' => 100,
        ]);

        $unitCost = PurchaseCostHelper::calculateUnitCost(
            $lineA->sub_total,
            $lineA->product_tax_amount,
            $lineA->product_discount_amount,
            $lineA->quantity
        );
        $this->assertSame(200.0, $unitCost);
        $this->assertEqualsWithDelta(150, $this->service->project($this->product, 100), 0.000001);

        $this->assertEquals(100, (float) $this->product->fresh()->product_quantity);
        $this->assertEquals(100, (float) $this->product->fresh()->product_cost);
        $this->assertEquals(100, (float) $price->fresh()->average_purchase_price);
    }

    public function test_it_returns_the_current_hpp_when_no_eligible_outstanding_quantity_exists(): void
    {
        $this->createLine(
            $this->createPurchase($this->settingA, Purchase::STATUS_RECEIVED),
            10,
            1000
        );

        $this->assertSame(125.5, $this->service->project($this->product, 125.5));
    }

    public function test_it_preserves_fractional_incoming_quantity_and_uses_incoming_cost_when_stock_is_nonpositive(): void
    {
        $purchase = $this->createPurchase($this->settingB, Purchase::STATUS_RECEIVED_PARTIALLY);
        $this->createLine($purchase, 1.5, 300.75, 0.75, 30);
        $this->product->update(['product_quantity' => 0.25]);

        $this->assertEqualsWithDelta(
            (0.25 * 90 + 300) / 1.75,
            $this->service->project($this->product->fresh(), 90),
            0.000001
        );

        $this->product->update(['product_quantity' => -1.5]);
        $this->assertEqualsWithDelta(200, $this->service->project($this->product->fresh(), 90), 0.000001);

        $this->product->update(['product_quantity' => -2]);
        $this->assertEqualsWithDelta(200, $this->service->project($this->product->fresh(), 90), 0.000001);
    }

    public function test_it_projects_incoming_cost_when_current_stock_is_zero(): void
    {
        $this->product->update(['product_quantity' => 0]);
        $this->createLine(
            $this->createPurchase($this->settingA),
            2,
            500,
            100
        );

        $this->assertEqualsWithDelta(200, $this->service->project($this->product->fresh(), 700), 0.000001);
    }
}

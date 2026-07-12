<?php

namespace Modules\Adjustment\Tests\Unit;

use Tests\TestCase;
use Modules\Adjustment\Services\TransferAllocationPreviewService;
use Modules\Product\Entities\ProductStock;

class TransferAllocationPreviewServiceTest extends TestCase
{
    private TransferAllocationPreviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TransferAllocationPreviewService();
    }

    public function test_normal_mode_allocates_non_tax_first()
    {
        $stock = new ProductStock([
            'quantity' => 10,
            'quantity_non_tax' => 6,
            'quantity_tax' => 4,
            'broken_quantity' => 5,
            'broken_quantity_non_tax' => 2,
            'broken_quantity_tax' => 3,
        ]);

        // Request 5 units (less than non-tax available)
        $preview = $this->service->previewAllocation($stock, 5.0, false);
        $this->assertEquals(5.0, $preview->allocatedNonTax);
        $this->assertEquals(0.0, $preview->allocatedTax);
        $this->assertFalse($preview->isInsufficient);
        $this->assertEquals(0.0, $preview->mandatoryReturnQuantity);

        // Request 8 units (spills into tax)
        $preview2 = $this->service->previewAllocation($stock, 8.0, false);
        $this->assertEquals(6.0, $preview2->allocatedNonTax);
        $this->assertEquals(2.0, $preview2->allocatedTax);
        $this->assertFalse($preview2->isInsufficient);
        $this->assertEquals(2.0, $preview2->mandatoryReturnQuantity);

        // Request 12 units (insufficient)
        $preview3 = $this->service->previewAllocation($stock, 12.0, false);
        $this->assertEquals(6.0, $preview3->allocatedNonTax);
        $this->assertEquals(4.0, $preview3->allocatedTax);
        $this->assertTrue($preview3->isInsufficient);
        $this->assertEquals(4.0, $preview3->mandatoryReturnQuantity);
    }

    public function test_broken_mode_allocates_broken_non_tax_first()
    {
        $stock = new ProductStock([
            'quantity' => 10,
            'quantity_non_tax' => 6,
            'quantity_tax' => 4,
            'broken_quantity' => 5,
            'broken_quantity_non_tax' => 2,
            'broken_quantity_tax' => 3,
        ]);

        // Request 1 unit (less than broken non-tax available)
        $preview = $this->service->previewAllocation($stock, 1.0, true);
        $this->assertEquals(1.0, $preview->allocatedNonTax);
        $this->assertEquals(0.0, $preview->allocatedTax);
        $this->assertFalse($preview->isInsufficient);
        $this->assertEquals(0.0, $preview->mandatoryReturnQuantity);

        // Request 4 units (spills into broken tax)
        $preview2 = $this->service->previewAllocation($stock, 4.0, true);
        $this->assertEquals(2.0, $preview2->allocatedNonTax);
        $this->assertEquals(2.0, $preview2->allocatedTax);
        $this->assertFalse($preview2->isInsufficient);
        $this->assertEquals(2.0, $preview2->mandatoryReturnQuantity);

        // Request 6 units (insufficient)
        $preview3 = $this->service->previewAllocation($stock, 6.0, true);
        $this->assertEquals(2.0, $preview3->allocatedNonTax);
        $this->assertEquals(3.0, $preview3->allocatedTax);
        $this->assertTrue($preview3->isInsufficient);
        $this->assertEquals(3.0, $preview3->mandatoryReturnQuantity);
    }
}

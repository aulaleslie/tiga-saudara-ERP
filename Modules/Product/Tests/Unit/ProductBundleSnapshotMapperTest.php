<?php

namespace Modules\Product\Tests\Unit;

use Modules\Product\Services\BundleLifecycle\ProductBundleSnapshotMapper;
use Tests\TestCase;

class ProductBundleSnapshotMapperTest extends TestCase
{
    private ProductBundleSnapshotMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ProductBundleSnapshotMapper();
    }

    public function test_to_canonical_bundle_snapshot_returns_null_when_no_bundle_id(): void
    {
        $this->assertNull($this->mapper->toCanonicalBundleSnapshot([]));
        $this->assertNull($this->mapper->toCanonicalBundleSnapshot(['bundle_id' => 0]));
        $this->assertNull($this->mapper->toCanonicalBundleSnapshot(['bundle_id' => null]));
    }

    public function test_to_canonical_bundle_snapshot_extracts_and_normalizes_parent_and_components(): void
    {
        $raw = [
            'bundle_id' => 12,
            'bundle_name' => '  Paket Komputer  ',
            'bundle_price' => '15000.50',
            'unit_price' => '250000.00',
            'qty' => 2,
            'bundle_items' => [
                [
                    'product_id' => 102,
                    'name' => 'Mouse Wireless',
                    'quantity' => 2,
                    'informational_item_price' => '75000.00',
                    'stock_managed' => true,
                    'serial_number_required' => false,
                ],
                [
                    'bundle_item_id' => 5,
                    'product_id' => 101,
                    'product_name' => 'Keyboard Mechanical',
                    'quantity_per_bundle' => 1,
                    'informational_item_price' => '150000.00',
                    'stock_managed' => false,
                    'serial_number_required' => true,
                ],
            ],
        ];

        $snapshot = $this->mapper->toCanonicalBundleSnapshot($raw);

        $this->assertNotNull($snapshot);
        $this->assertSame(12, $snapshot['bundle_id']);
        $this->assertSame('Paket Komputer', $snapshot['bundle_name']);
        $this->assertSame(15000.50, $snapshot['bundle_price']);
        $this->assertSame(250000.00, $snapshot['bundle_sale_price']);

        // Component deterministic ordering: 101 should be first, then 102
        $this->assertCount(2, $snapshot['bundle_items']);

        $comp0 = $snapshot['bundle_items'][0];
        $this->assertSame(5, $comp0['bundle_item_id']);
        $this->assertSame(101, $comp0['product_id']);
        $this->assertSame('Keyboard Mechanical', $comp0['product_name']);
        $this->assertSame(1.0, $comp0['quantity_per_bundle']);
        $this->assertSame(2.0, $comp0['quantity']); // 1 * parent qty 2
        $this->assertSame(150000.00, $comp0['informational_item_price']);
        $this->assertFalse($comp0['stock_managed']);
        $this->assertTrue($comp0['serial_number_required']);

        $comp1 = $snapshot['bundle_items'][1];
        $this->assertNull($comp1['bundle_item_id']);
        $this->assertSame(102, $comp1['product_id']);
        $this->assertSame('Mouse Wireless', $comp1['product_name']);
        $this->assertSame(2.0, $comp1['quantity_per_bundle']);
        $this->assertSame(4.0, $comp1['quantity']); // 2 * parent qty 2
        $this->assertSame(75000.00, $comp1['informational_item_price']);
        $this->assertTrue($comp1['stock_managed']);
        $this->assertFalse($comp1['serial_number_required']);
    }

    public function test_deterministic_ordering_is_stable_regardless_of_input_order(): void
    {
        $componentsOrderA = [
            ['product_id' => 300, 'name' => 'C', 'quantity' => 1],
            ['product_id' => 100, 'name' => 'A', 'quantity' => 1],
            ['product_id' => 200, 'name' => 'B', 'quantity' => 1],
        ];

        $componentsOrderB = [
            ['product_id' => 100, 'name' => 'A', 'quantity' => 1],
            ['product_id' => 200, 'name' => 'B', 'quantity' => 1],
            ['product_id' => 300, 'name' => 'C', 'quantity' => 1],
        ];

        $resA = $this->mapper->canonicalizeComponents($componentsOrderA);
        $resB = $this->mapper->canonicalizeComponents($componentsOrderB);

        $this->assertSame($resA, $resB);
        $this->assertSame(100, $resA[0]['product_id']);
        $this->assertSame(200, $resA[1]['product_id']);
        $this->assertSame(300, $resA[2]['product_id']);
    }
}

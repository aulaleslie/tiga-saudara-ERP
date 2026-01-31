<?php

namespace Tests\Unit\Modules\Sale;

use PHPUnit\Framework\TestCase;
use Modules\Sale\Services\SaleCartAggregator;
use stdClass;

class SaleCartAggregatorTest extends TestCase
{
    /**
     * Test that items with different bundles are NOT merged.
     */
    public function test_different_bundles_are_not_merged()
    {
        $cartItems = [
            (object) [
                'id' => 'row-1',
                'name' => 'Product A',
                'qty' => 1,
                'price' => 100,
                'options' => [
                    'product_id' => 1,
                    'product_tax' => 10,
                    'bundle_items' => [
                        ['bundle_id' => 101, 'product_id' => 10, 'quantity' => 1, 'price' => 0]
                    ]
                ]
            ],
            (object) [
                'id' => 'row-2',
                'name' => 'Product A',
                'qty' => 1,
                'price' => 100,
                'options' => [
                    'product_id' => 1,
                    'product_tax' => 10,
                    'bundle_items' => [
                        ['bundle_id' => 102, 'product_id' => 10, 'quantity' => 1, 'price' => 0]
                    ]
                ]
            ]
        ];

        $result = SaleCartAggregator::aggregate($cartItems);

        $this->assertCount(2, $result, 'Items with different bundle IDs should not be merged.');
    }

    /**
     * Test that items with the same bundle ARE merged.
     */
    public function test_same_bundles_are_merged()
    {
        $cartItems = [
            (object) [
                'id' => 'row-1',
                'name' => 'Product A',
                'qty' => 1,
                'price' => 100,
                'options' => [
                    'product_id' => 1,
                    'product_tax' => 10,
                    'bundle_items' => [
                        ['bundle_id' => 101, 'product_id' => 10, 'quantity' => 1, 'price' => 0]
                    ]
                ]
            ],
            (object) [
                'id' => 'row-2',
                'name' => 'Product A',
                'qty' => 2,
                'price' => 100,
                'options' => [
                    'product_id' => 1,
                    'product_tax' => 10,
                    'bundle_items' => [
                        ['bundle_id' => 101, 'product_id' => 10, 'quantity' => 1, 'price' => 0]
                    ]
                ]
            ]
        ];

        $result = SaleCartAggregator::aggregate($cartItems);

        $this->assertCount(1, $result, 'Items with same bundle IDs should be merged.');
        $this->assertEquals(3, $result[0]['quantity']);
    }

    /**
     * Test that one bundled and one non-bundled item are NOT merged.
     */
    public function test_mixed_bundled_and_non_bundled_are_not_merged()
    {
        $cartItems = [
            (object) [
                'id' => 'row-1',
                'name' => 'Product A',
                'qty' => 1,
                'price' => 100,
                'options' => [
                    'product_id' => 1,
                    'product_tax' => 10,
                    'bundle_items' => [
                        ['bundle_id' => 101, 'product_id' => 10, 'quantity' => 1, 'price' => 0]
                    ]
                ]
            ],
            (object) [
                'id' => 'row-2',
                'name' => 'Product A',
                'qty' => 1,
                'price' => 100,
                'options' => [
                    'product_id' => 1,
                    'product_tax' => 10,
                    'bundle_items' => []
                ]
            ]
        ];

        $result = SaleCartAggregator::aggregate($cartItems);

        $this->assertCount(2, $result, 'Bundled and non-bundled items should not be merged.');
    }

    /**
     * Test that bundle quantities are expanded by parent quantity.
     */
    public function test_bundle_quantities_are_expanded_by_parent_qty()
    {
        $cartItems = [
            (object) [
                'id' => 'row-1',
                'name' => 'Parent Product',
                'qty' => 2,
                'price' => 100,
                'options' => [
                    'product_id' => 1,
                    'bundle_items' => [
                        [
                            'bundle_id' => 101,
                            'product_id' => 2,
                            'name' => 'Bundle Item',
                            'quantity' => 1,
                            'price' => 10
                        ]
                    ]
                ]
            ]
        ];

        $result = SaleCartAggregator::aggregate($cartItems);

        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]['bundle_items']);
        
        $bundleItem = reset($result[0]['bundle_items']);
        $this->assertEquals(2, $bundleItem['quantity'], 'Bundle quantity should be expanded (base 1 * parent 2).');
    }

    /**
     * Test that bundle sub_total reflects expanded quantity.
     */
    public function test_bundle_sub_total_reflects_expanded_quantity()
    {
        $cartItems = [
            (object) [
                'id' => 'row-1',
                'name' => 'Parent Product',
                'qty' => 3,
                'price' => 100,
                'options' => [
                    'product_id' => 1,
                    'bundle_items' => [
                        [
                            'bundle_id' => 101,
                            'product_id' => 2,
                            'name' => 'Bundle Item',
                            'quantity' => 1,
                            'price' => 50
                        ]
                    ]
                ]
            ]
        ];

        $result = SaleCartAggregator::aggregate($cartItems);

        $bundleItem = reset($result[0]['bundle_items']);
        $this->assertEquals(3, $bundleItem['quantity']);
        $this->assertEquals(150, (float) $bundleItem['sub_total'], 'Bundle sub_total should reflect expanded qty (50 * 3).');
    }

    /**
     * Test that aggregator prefers quantity_per_bundle to avoid double expansion.
     */
    public function test_aggregator_prefers_quantity_per_bundle_to_avoid_double_expansion()
    {
        $cartItems = [
            (object) [
                'id' => 'row-1',
                'name' => 'Parent Product',
                'qty' => 2,
                'price' => 100,
                'options' => [
                    'product_id' => 1,
                    'bundle_items' => [
                        [
                            'bundle_id' => 101,
                            'product_id' => 2,
                            'name' => 'Bundle Item',
                            'quantity_per_bundle' => 1, // base
                            'quantity' => 2,           // already expanded in cart
                            'price' => 10
                        ]
                    ]
                ]
            ]
        ];

        $result = SaleCartAggregator::aggregate($cartItems);

        $bundleItem = reset($result[0]['bundle_items']);
        // result should be base (1) * parent qty (2) = 2.
        // If it used 'quantity' (2) * parent qty (2), it would be 4.
        $this->assertEquals(2, $bundleItem['quantity'], 'Should use quantity_per_bundle (1) * parent qty (2) = 2.');
    }
}

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
}

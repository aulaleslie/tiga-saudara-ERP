<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosTransactionSnapshotMapper;
use Tests\TestCase;

class PosTransactionSnapshotMapperTest extends TestCase
{
    private PosTransactionSnapshotMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = app(PosTransactionSnapshotMapper::class);
    }

    /**
     * Test persist_lines writes correct line count and serial rows.
     */
    public function test_persist_lines_writes_correct_line_count_and_serial_rows(): void
    {
        // Arrange
        $transaction = PosTransaction::factory()->create();
        $product = $this->createProduct(['require_serial' => true]);

        $cartLines = [
            1 => [
                'line_id' => 1,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'barcode' => $product->barcode,
                'serial_number_required' => true,
                'assigned_serials' => ['SN001', 'SN002'],
                'qty' => 2,
                'unit_price' => 100000.00,
                'tax_id' => null,
                'tax_name' => null,
                'tax_rate' => 0.0,
                'line_discount_type' => 'fixed',
                'line_discount_value' => 10000.00,
            ],
            2 => [
                'line_id' => 2,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'barcode' => $product->barcode,
                'serial_number_required' => true,
                'assigned_serials' => ['SN003'],
                'qty' => 1,
                'unit_price' => 50000.00,
                'tax_id' => null,
                'tax_name' => null,
                'tax_rate' => 0.0,
                'line_discount_type' => 'fixed',
                'line_discount_value' => 0.0,
            ],
        ];

        // Act
        $this->mapper->persistLines($transaction, $cartLines);

        // Assert
        $transaction->refresh();
        $this->assertCount(2, $transaction->lines);

        // Check first line serials
        $line1 = $transaction->lines[0];
        $this->assertCount(2, $line1->serials);
        $this->assertEquals('SN001', $line1->serials[0]->serial_number);
        $this->assertEquals('SN002', $line1->serials[1]->serial_number);

        // Check second line serials
        $line2 = $transaction->lines[1];
        $this->assertCount(1, $line2->serials);
        $this->assertEquals('SN003', $line2->serials[0]->serial_number);
    }

    /**
     * Test hydrate_cart restores line fields with safe defaults.
     */
    public function test_hydrate_cart_restores_line_fields_with_safe_defaults(): void
    {
        // Arrange
        $product = $this->createProduct(['require_serial' => true]);
        $transaction = PosTransaction::factory()
            ->has(\Modules\Pos\Entities\PosTransactionLine::factory()
                ->state([
                    'product_id' => $product->id,
                    'product_name_snapshot' => 'Test Product',
                    'qty' => 5,
                    'unit_price' => 100000.00,
                ])
                ->count(1)
            )
            ->create();

        // Act
        $cart = $this->mapper->hydrateCart($transaction);

        // Assert
        $this->assertCount(1, $cart['lines']);
        $line = $cart['lines'][1];

        // Check specific fields
        $this->assertEquals($product->id, $line['product_id']);
        $this->assertEquals('Test Product', $line['product_name']);
        $this->assertEquals(5, $line['qty']);
        $this->assertEquals(100000.00, $line['unit_price']);

        // Check safe defaults
        $this->assertEquals(0, $line['available_qty']);
        $this->assertTrue($line['price_valid']);
        $this->assertNull($line['price_error']);
        $this->assertEquals('DRAFT_RESTORED', $line['price_source']);
        $this->assertEquals($transaction->id, $cart['active_transaction_id']);
    }

    /**
     * Test persist and hydrate round trip preserves qty, price, discount.
     */
    public function test_persist_and_hydrate_round_trip_preserves_values(): void
    {
        // Arrange
        $product = $this->createProduct();
        $transaction = PosTransaction::factory()->create();

        $originalCart = [
            1 => [
                'line_id' => 1,
                'product_id' => $product->id,
                'product_name' => 'Test Product',
                'product_code' => 'TP001',
                'barcode' => 'BAR123',
                'qty' => 10,
                'unit_price' => 50000.00,
                'line_discount_type' => 'percentage',
                'line_discount_value' => 10.0,
                'tax_id' => null,
                'tax_name' => null,
                'tax_rate' => 0.0,
                'serial_number_required' => false,
                'assigned_serials' => [],
            ],
        ];

        // Act
        $this->mapper->persistLines($transaction, $originalCart);
        $hydratedCart = $this->mapper->hydrateCart($transaction);

        // Assert
        $hydratedLine = $hydratedCart['lines'][1];
        $this->assertEquals($originalCart[1]['qty'], $hydratedLine['qty']);
        $this->assertEquals($originalCart[1]['unit_price'], $hydratedLine['unit_price']);
        $this->assertEquals($originalCart[1]['line_discount_type'], $hydratedLine['line_discount_type']);
        $this->assertEquals($originalCart[1]['line_discount_value'], $hydratedLine['line_discount_value']);
    }

    /**
     * Test build_snapshot_totals creates correct structure.
     */
    public function test_build_snapshot_totals_creates_correct_structure(): void
    {
        // Arrange
        $totals = [
            'line_count' => 3,
            'subtotal' => 300000.00,
            'discount_total' => 30000.00,
            'tax_total' => 30000.00,
            'grand_total' => 300000.00,
        ];

        // Act
        $snapshotTotals = $this->mapper->buildSnapshotTotals($totals);

        // Assert
        $this->assertEquals(3, $snapshotTotals['line_count']);
        $this->assertEquals(300000.00, $snapshotTotals['subtotal']);
        $this->assertEquals(30000.00, $snapshotTotals['discount_total']);
        $this->assertEquals(30000.00, $snapshotTotals['tax_total']);
        $this->assertEquals(300000.00, $snapshotTotals['grand_total']);
    }

    /**
     * Test hydrate_cart assigns merge_key consistent with cart service logic.
     */
    public function test_hydrate_cart_merge_key_consistency(): void
    {
        // Arrange
        $product = $this->createProduct();
        $taxId = 5;
        $conversionId = null;
        $unitPrice = 100000.00;

        $transaction = PosTransaction::factory()
            ->has(\Modules\Pos\Entities\PosTransactionLine::factory()
                ->state([
                    'product_id' => $product->id,
                    'unit_price' => $unitPrice,
                    'tax_id' => $taxId,
                    'conversion_id' => $conversionId,
                ])
                ->count(1)
            )
            ->create();

        // Act
        $cart = $this->mapper->hydrateCart($transaction);

        // Assert - Merge key should follow pattern: "{product_id}:{unit_price}:{tax_id}:{conversion_id}"
        $line = $cart['lines'][1];
        $expectedMergeKey = "{$product->id}:{$unitPrice}:{$taxId}:{$conversionId}";
        $this->assertEquals($expectedMergeKey, $line['merge_key']);
    }
}

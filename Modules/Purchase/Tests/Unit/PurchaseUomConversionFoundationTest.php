<?php

namespace Modules\Purchase\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Services\PurchaseUomConversionService;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseUomConversionFoundationTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseUomConversionService $service;
    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurchaseUomConversionService();
        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);
    }

    public function test_purchase_detail_and_received_note_detail_schema_and_model_fallbacks(): void
    {
        $unit = Unit::create(['name' => 'KOTAK', 'short_name' => 'KTK']);
        $baseUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS']);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'unit_id' => $baseUnit->id,
            'base_unit_id' => $baseUnit->id,
            'product_quantity' => 100,
            'product_price' => 10000,
            'product_cost' => 8000,
        ]);

        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier A',
            'supplier_phone' => '08123456789',
            'supplier_email' => 'supplier@example.com',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
        ]);

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'reference' => 'PO-TEST-001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 120000,
            'paid_amount' => 0,
            'due_amount' => 120000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        // 1. Snapshot persistence & accessors
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'purchase_unit_id' => $unit->id,
            'quantity' => 24.000,
            'entered_quantity' => 2.000,
            'unit_price' => 5000.00,
            'entered_unit_price' => 60000.00,
            'conversion_factor' => 12.000000,
            'unit_name' => 'KOTAK',
            'base_unit_name' => 'PCS',
            'price' => 60000,
            'sub_total' => 120000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $this->assertEquals(2.000, $detail->effective_entered_quantity);
        $this->assertEquals(60000.00, $detail->effective_entered_unit_price);
        $this->assertEquals(12.000000, $detail->effective_conversion_factor);
        $this->assertEquals('KOTAK', $detail->effective_unit_name);
        $this->assertEquals('PCS', $detail->effective_base_unit_name);

        // 2. Legacy line fallback accessors
        $legacyDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10.000,
            'unit_price' => 5000.00,
            'price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $this->assertEquals(10.000, $legacyDetail->effective_entered_quantity);
        $this->assertEquals(5000.00, $legacyDetail->effective_entered_unit_price);
        $this->assertEquals('1.000000', $legacyDetail->effective_conversion_factor);
        $this->assertEquals('PCS', $legacyDetail->effective_base_unit_name);

        // 3. ReceivedNoteDetail quantity_received decimal cast
        $rn = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'external_delivery_number' => 'DN-TEST-001',
            'date' => now()->toDateString(),
        ]);

        $rnd = ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 12.500,
        ]);

        $this->assertEquals(12.500, $rnd->quantity_received);
    }

    public function test_conversion_service_base_unit_and_conversion_unit_arithmetic(): void
    {
        $baseUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS']);
        $boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'BOX']);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Widget',
            'product_code' => 'W001',
            'unit_id' => $baseUnit->id,
            'base_unit_id' => $baseUnit->id,
            'product_quantity' => 50,
            'product_price' => 1000,
            'product_cost' => 800,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 12.000000,
        ]);

        // Base unit conversion
        $resBase = $this->service->convert($product, 5.0, 1000.0);
        $this->assertEquals($baseUnit->id, $resBase->purchaseUnitId);
        $this->assertNull($resBase->productUnitConversionId);
        $this->assertEquals(5.0, $resBase->enteredQuantity);
        $this->assertEquals(5.0, $resBase->canonicalQuantity);
        $this->assertEquals(1.0, $resBase->conversionFactor);
        $this->assertEquals(1000.0, $resBase->enteredUnitPrice);
        $this->assertEquals(1000.0, $resBase->normalizedUnitPrice);

        // Conversion unit conversion (2.5 BOX = 30 PCS)
        $resBox = $this->service->convert($product, 2.5, 12000.0, conversionId: $conversion->id);
        $this->assertEquals($boxUnit->id, $resBox->purchaseUnitId);
        $this->assertEquals($conversion->id, $resBox->productUnitConversionId);
        $this->assertEquals(2.5, $resBox->enteredQuantity);
        $this->assertEquals(30.0, $resBox->canonicalQuantity);
        $this->assertEquals(12.0, $resBox->conversionFactor);
        $this->assertEquals(12000.0, $resBox->enteredUnitPrice);
        $this->assertEquals(1000.0, $resBox->normalizedUnitPrice);
    }

    public function test_conversion_service_repeating_base_price_and_precision(): void
    {
        $baseUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS']);
        $packUnit = Unit::create(['name' => 'PACK', 'short_name' => 'PAK']);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Candy',
            'product_code' => 'C001',
            'unit_id' => $baseUnit->id,
            'base_unit_id' => $baseUnit->id,
            'product_quantity' => 10,
            'product_price' => 100,
            'product_cost' => 80,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $packUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 3.000000,
        ]);

        // 100,000 / 3 = 33333.333333... per PCS
        $res = $this->service->convert($product, 1.0, 100000.0, conversionId: $conversion->id);
        $this->assertEquals(100000.0, $res->enteredUnitPrice);
        $this->assertEquals(33333.333333, round($res->normalizedUnitPrice, 6));
        // Persistence array contains high-precision formatted string
        $array = $res->toArray();
        $this->assertEquals('100000.00', $array['entered_unit_price']);
        $this->assertEquals('33333.333333', $array['unit_price']);
    }

    public function test_conversion_service_rejects_inactive_conversion_unit(): void
    {
        $baseUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS']);
        $inactiveUnit = Unit::create(['name' => 'OLD_BOX', 'short_name' => 'OBX', 'is_active' => false]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Item Inactive',
            'product_code' => 'II1',
            'unit_id' => $baseUnit->id,
            'base_unit_id' => $baseUnit->id,
            'product_quantity' => 10,
            'product_price' => 100,
            'product_cost' => 80,
        ]);

        $inactiveConv = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $inactiveUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 10.0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inactive');

        $this->service->convert($product, 1.0, 100.0, conversionId: $inactiveConv->id);
    }

    public function test_conversion_service_rejects_unit_id_and_conversion_id_mismatch(): void
    {
        $baseUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS']);
        $boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'BOX']);
        $otherUnit = Unit::create(['name' => 'BAG', 'short_name' => 'BAG']);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Item Mismatch',
            'product_code' => 'IM1',
            'unit_id' => $baseUnit->id,
            'base_unit_id' => $baseUnit->id,
            'product_quantity' => 10,
            'product_price' => 100,
            'product_cost' => 80,
        ]);

        $boxConv = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 12.0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match conversion');

        // Submitting boxConv ID but supplying different unit ID
        $this->service->convert($product, 1.0, 100.0, conversionId: $boxConv->id, unitId: $otherUnit->id);
    }

    public function test_conversion_service_rejects_tampered_or_unrelated_conversion(): void
    {
        $baseUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS']);
        $boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'BOX']);

        $product1 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Item 1',
            'product_code' => 'I1',
            'unit_id' => $baseUnit->id,
            'base_unit_id' => $baseUnit->id,
            'product_quantity' => 10,
            'product_price' => 100,
            'product_cost' => 80,
        ]);

        $product2 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Item 2',
            'product_code' => 'I2',
            'unit_id' => $baseUnit->id,
            'base_unit_id' => $baseUnit->id,
            'product_quantity' => 10,
            'product_price' => 100,
            'product_cost' => 80,
        ]);

        $conversion2 = ProductUnitConversion::create([
            'product_id' => $product2->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 10.0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to product');

        // Product 1 given Product 2's conversion ID
        $this->service->convert($product1, 1.0, 100.0, conversionId: $conversion2->id);
    }

    public function test_conversion_service_rejects_unsupported_canonical_precision_and_fractional_serials(): void
    {
        $baseUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS']);
        $boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'BOX']);

        $serialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Phone',
            'product_code' => 'P001',
            'unit_id' => $baseUnit->id,
            'base_unit_id' => $baseUnit->id,
            'product_quantity' => 10,
            'product_price' => 1000,
            'product_cost' => 800,
            'serial_number_required' => true,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $serialProduct->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 12.0,
        ]);

        // 0.25 BOX * 12 = 3 PCS (valid whole serial count)
        $res = $this->service->convert($serialProduct, 0.25, 1200.0, conversionId: $conversion->id);
        $this->assertEquals(3.0, $res->canonicalQuantity);

        // 0.10 BOX * 12 = 1.2 PCS (invalid fractional base units for serialized product)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serialized product quantities must resolve to whole base units');

        $this->service->convert($serialProduct, 0.10, 1200.0, conversionId: $conversion->id);
    }

    public function test_migration_rollback_guard_blocks_six_decimal_prices(): void
    {
        $baseUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS']);
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Item Rollback Guard',
            'product_code' => 'IRG1',
            'unit_id' => $baseUnit->id,
            'base_unit_id' => $baseUnit->id,
            'product_quantity' => 10,
            'product_price' => 100,
            'product_cost' => 80,
        ]);
        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier RB',
            'supplier_phone' => '08123456789',
            'supplier_email' => 'supplier_rb@example.com',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
        ]);
        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'reference' => 'PO-RB-001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        // Insert row with 6-decimal unit price
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 3.0,
            'unit_price' => 33333.333333,
            'price' => 33333.333333,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $migration = include database_path('migrations/../../Modules/Purchase/Database/Migrations/2026_09_03_000001_add_conversion_unit_snapshots_to_purchase_details_table.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('high-precision unit_price or price data exists');

        $migration->down();
    }
}

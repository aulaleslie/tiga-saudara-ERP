<?php

namespace Modules\Product\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Services\ProductCreator;
use Modules\Product\Support\ProductCreateValidation;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ProductConversionInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Unit $baseUnit;
    private Unit $boxUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);

        $this->baseUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS', 'is_active' => true]);
        $this->boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'BOX', 'is_active' => true]);
    }

    public function test_validation_rejects_conversion_factor_less_than_or_equal_to_one(): void
    {
        $input = [
            'product_name' => 'Widget',
            'stock_managed' => true,
            'base_unit_id' => $this->baseUnit->id,
            'conversions' => [
                [
                    'unit_id' => $this->boxUnit->id,
                    'conversion_factor' => 1.0,
                    'price' => 1000,
                ],
            ],
        ];

        $validator = Validator::make(
            $input,
            ProductCreateValidation::rules($input),
            ProductCreateValidation::messages()
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('conversions.0.conversion_factor', $validator->errors()->toArray());
        $this->assertStringContainsString('lebih besar dari 1', $validator->errors()->first('conversions.0.conversion_factor'));
    }

    public function test_validation_rejects_fractional_factor_for_serialized_product(): void
    {
        $input = [
            'product_name' => 'Smartphone',
            'stock_managed' => true,
            'serial_number_required' => true,
            'base_unit_id' => $this->baseUnit->id,
            'conversions' => [
                [
                    'unit_id' => $this->boxUnit->id,
                    'conversion_factor' => 12.5,
                    'price' => 120000,
                ],
            ],
        ];

        $validator = Validator::make(
            $input,
            ProductCreateValidation::rules($input),
            ProductCreateValidation::messages()
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('conversions.0.conversion_factor', $validator->errors()->toArray());
        $this->assertStringContainsString('bilangan bulat', $validator->errors()->first('conversions.0.conversion_factor'));
    }

    public function test_validation_allows_valid_decimal_factor_for_non_serialized_product(): void
    {
        $input = [
            'product_name' => 'Cable',
            'stock_managed' => true,
            'serial_number_required' => false,
            'base_unit_id' => $this->baseUnit->id,
            'conversions' => [
                [
                    'unit_id' => $this->boxUnit->id,
                    'conversion_factor' => 2.5,
                    'price' => 25000,
                ],
            ],
        ];

        $validator = Validator::make(
            $input,
            ProductCreateValidation::rules($input),
            ProductCreateValidation::messages()
        );

        $this->assertFalse($validator->fails());
    }

    public function test_product_creator_enforces_conversion_factor_invariants(): void
    {
        $creator = new ProductCreator();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Faktor konversi harus lebih besar dari 1');

        $creator->create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Item',
            'product_code' => 'TI-001',
            'stock_managed' => true,
            'base_unit_id' => $this->baseUnit->id,
            'conversions' => [
                [
                    'unit_id' => $this->boxUnit->id,
                    'conversion_factor' => 0.5,
                    'price' => 500,
                ],
            ],
        ]);
    }

    public function test_eligible_purchase_conversions_excludes_inactive_mismatched_and_legacy_small_factors(): void
    {
        $inactiveUnit = Unit::create(['name' => 'PACK', 'short_name' => 'PAK', 'is_active' => false]);
        $otherBaseUnit = Unit::create(['name' => 'KG', 'short_name' => 'KG', 'is_active' => true]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Multi Unit Product',
            'product_code' => 'MUP-001',
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'product_quantity' => 10,
            'product_price' => 100,
            'product_cost' => 80,
        ]);

        // 1. Valid conversion (Factor 12)
        $validConv = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 12.0,
        ]);

        // 2. Legacy conversion with factor <= 1 (Factor 0.8) - left untouched in DB
        $legacySmallConv = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $inactiveUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 0.8,
        ]);

        // 3. Conversion with mismatched base unit
        $mismatchedUnit = Unit::create(['name' => 'DOZEN', 'short_name' => 'DZN', 'is_active' => true]);
        $mismatchedConv = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $mismatchedUnit->id,
            'base_unit_id' => $otherBaseUnit->id,
            'conversion_factor' => 24.0,
        ]);

        $product->load(['conversions.unit']);

        $eligible = $product->eligiblePurchaseConversions();

        $this->assertCount(1, $eligible);
        $this->assertEquals($validConv->id, $eligible->first()->id);

        // Verify legacy row remains untouched in database
        $this->assertDatabaseHas('product_unit_conversions', [
            'id' => $legacySmallConv->id,
            'conversion_factor' => 0.8,
        ]);
    }
}

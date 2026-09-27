<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Purchase\Services\PurchaseNormalizer;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class PurchasePersistenceLineTotalTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected $normalizer;
    protected $currency;
    protected $unit;
    protected $category;
    protected $tax11;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new PurchaseNormalizer();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->category = Category::create([
            'created_by' => \App\Models\User::factory()->create()->id,
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
            'setting_id' => 1,
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
            'is_default' => true,
        ]);
    }

    /** @test */
    public function it_persists_line_total_through_normalizer_non_pkp()
    {
        $detailInput = [
            'id' => 1,
            'product_id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'quantity' => 2,
            'price' => 1500,
            'unit_price' => 1500,
            'options' => [
                'unit_price' => 1500,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 3000,
                'sub_total_before_tax' => 3000,
                'product_tax_amount' => 0,
            ]
        ];

        $result = $this->normalizer->normalize(
            ['discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0],
            [$detailInput],
            false
        );

        $detail = $result['details'][0];

        $this->assertEquals(3000, $detail['sub_total']);
        $this->assertEquals(3000, $detail['sub_total_before_tax']);
        $this->assertEquals(0, $detail['product_tax_amount']);
        $this->assertEquals(1500, $detail['unit_price']);
    }

    /** @test */
    public function it_persists_line_total_through_normalizer_pkp_tax_excluded()
    {
        $detailInput = [
            'id' => 1,
            'product_id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'quantity' => 2,
            'price' => 1500,
            'unit_price' => 1500,
            'tax_id' => $this->tax11->id,
            'options' => [
                'unit_price' => 1500,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => $this->tax11->id,
                'sub_total' => 3330,
                'sub_total_before_tax' => 3000,
                'product_tax_amount' => 330,
            ]
        ];

        $result = $this->normalizer->normalize(
            ['discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0, 'tax_id' => $this->tax11->id],
            [$detailInput],
            true
        );

        $detail = $result['details'][0];

        $this->assertEquals(3330, $detail['sub_total']);
        $this->assertEquals(3000, $detail['sub_total_before_tax']);
        $this->assertEquals(330, $detail['product_tax_amount']);
        $this->assertEquals(1500, $detail['unit_price']);
    }

    /** @test */
    public function it_persists_line_total_with_fixed_discount()
    {
        $detailInput = [
            'id' => 1,
            'product_id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'quantity' => 2,
            'price' => 1200,
            'unit_price' => 1200,
            'options' => [
                'unit_price' => 1200,
                'product_discount' => 200,
                'product_discount_type' => 'fixed',
                'sub_total' => 2000,
                'sub_total_before_tax' => 2000,
                'product_tax_amount' => 0,
            ]
        ];

        $result = $this->normalizer->normalize(
            ['discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0],
            [$detailInput],
            false
        );

        $detail = $result['details'][0];

        $this->assertEquals(2000, $detail['sub_total']);
        $this->assertEquals(200, $detail['product_discount_amount']);
        $this->assertEquals(1200, $detail['unit_price']);
    }

    /** @test */
    public function it_persists_line_total_with_percentage_discount()
    {
        $detailInput = [
            'id' => 1,
            'product_id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'quantity' => 2,
            'price' => 1250,
            'unit_price' => 1250,
            'options' => [
                'unit_price' => 1250,
                'product_discount' => 250,
                'product_discount_type' => 'percentage',
                'product_discount_input' => 20,
                'sub_total' => 2000,
                'sub_total_before_tax' => 2000,
                'product_tax_amount' => 0,
            ]
        ];

        $result = $this->normalizer->normalize(
            ['discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0],
            [$detailInput],
            false
        );

        $detail = $result['details'][0];

        $this->assertEquals(2000, $detail['sub_total']);
        $this->assertEquals(250, $detail['product_discount_amount']);
        $this->assertEquals(1250, $detail['unit_price']);
    }

    /** @test */
    public function global_discount_and_shipping_remain_header_only()
    {
        $detailInput = [
            'id' => 1,
            'product_id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'options' => [
                'unit_price' => 1000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 1000,
                'sub_total_before_tax' => 1000,
                'product_tax_amount' => 0,
            ]
        ];

        $result = $this->normalizer->normalize(
            [
                'discount_percentage' => 10,
                'discount_amount' => 0,
                'shipping_amount' => 500
            ],
            [$detailInput],
            false
        );

        $detail = $result['details'][0];
        $header = $result['header'];

        // Line total should not include global discount or shipping
        $this->assertEquals(1000, $detail['sub_total']);

        // Header should have discount percentage stored (not computed yet)
        $this->assertEquals(10, $header['discount_percentage']);
        $this->assertEquals(500, $header['shipping_amount']);

        // Computed total with discount and shipping
        // 1000 - 100 (10% discount) + 500 (shipping) = 1400
        $this->assertEquals(1400, $header['total_amount']);
        $this->assertEquals(100, $result['computed_discount_amount']);
    }
}

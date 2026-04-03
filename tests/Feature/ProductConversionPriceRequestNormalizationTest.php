<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Modules\Product\Http\Requests\StoreProductInfoRequest;
use Modules\Product\Http\Requests\UpdateProductRequest;
use Tests\TestCase;

class ProductConversionPriceRequestNormalizationTest extends TestCase
{
    public function test_store_request_normalizes_formatted_conversion_prices(): void
    {
        $request = StoreProductInfoRequest::create('/products', 'POST', [
            'product_name' => 'Produk A',
            'conversions' => [
                [
                    'unit_id' => 10,
                    'price' => 'RP 65.000,00',
                ],
            ],
        ]);

        $this->invokePrepareForValidation($request);

        $this->assertSame('65000', $request->input('conversions.0.price'));
    }

    public function test_update_request_normalizes_formatted_and_raw_conversion_prices(): void
    {
        $request = UpdateProductRequest::create('/products/1', 'PUT', [
            'product_name' => 'Produk B',
            'product_code' => 'PROD-001',
            'conversions' => [
                [
                    'unit_id' => 11,
                    'price' => 'RP 17.500,00',
                ],
                [
                    'unit_id' => 12,
                    'price' => '65000',
                ],
            ],
        ]);

        $request->setRouteResolver(fn () => new Route('PUT', '/products/{product}', []));

        $this->invokePrepareForValidation($request);

        $this->assertSame('17500', $request->input('conversions.0.price'));
        $this->assertSame('65000', $request->input('conversions.1.price'));
    }

    public function test_empty_and_invalid_conversion_prices_keep_existing_rule_behavior(): void
    {
        $request = StoreProductInfoRequest::create('/products', 'POST', [
            'product_name' => 'Produk C',
            'conversions' => [
                [
                    'unit_id' => 10,
                    'price' => '',
                ],
                [
                    'unit_id' => 11,
                    'price' => 'abc',
                ],
                [
                    'unit_id' => 12,
                    'price' => 'RP 0,00',
                ],
            ],
        ]);

        $this->invokePrepareForValidation($request);

        $rules = (new StoreProductInfoRequest())->rules();
        $validator = Validator::make(
            $request->all(),
            [
                'conversions' => $rules['conversions'],
                'conversions.*.unit_id' => $rules['conversions.*.unit_id'],
                'conversions.*.price' => $rules['conversions.*.price'],
            ],
            (new StoreProductInfoRequest())->messages(),
        );

        $this->assertSame('', $request->input('conversions.0.price'));
        $this->assertSame('abc', $request->input('conversions.1.price'));
        $this->assertSame('0', $request->input('conversions.2.price'));
        $this->assertSame(
            'Harga konversi wajib diisi jika Anda memilih unit konversi.',
            $validator->errors()->first('conversions.0.price'),
        );
        $this->assertSame('Harga konversi harus berupa angka.', $validator->errors()->first('conversions.1.price'));
        $this->assertSame('Harga konversi harus lebih dari 0.', $validator->errors()->first('conversions.2.price'));
    }

    private function invokePrepareForValidation(object $request): void
    {
        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);
    }
}

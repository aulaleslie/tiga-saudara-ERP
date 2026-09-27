<?php

namespace Tests\Unit;

use Modules\Purchase\Services\PurchaseImportService;
use Modules\Sale\Services\SalesImportService;
use Tests\TestCase;

/**
 * Quantities may be fractional (e.g. 23.7 KG); the importers must parse them as floats rather than
 * truncating to integers, which would drift line totals away from the source invoice total.
 */
class ImportQuantityParsingTest extends TestCase
{
    /**
     * @return iterable<string, array{0:string,1:float}>
     */
    public static function quantityProvider(): iterable
    {
        yield 'plain integer' => ['60', 60.0];
        yield 'dot decimal' => ['23.7', 23.7];
        yield 'comma decimal' => ['23,7', 23.7];
        yield 'thousands with dot decimal' => ['1,234.5', 1234.5];
        yield 'blank defaults to one' => ['', 1.0];
        yield 'non numeric defaults to one' => ['abc', 1.0];
    }

    /**
     * @test
     * @dataProvider quantityProvider
     */
    public function purchase_service_parses_quantity_without_truncation(string $input, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, (new PurchaseImportService())->parseQuantity($input), 0.0001);
    }

    /**
     * @test
     * @dataProvider quantityProvider
     */
    public function sales_service_parses_quantity_without_truncation(string $input, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, (new SalesImportService())->parseQuantity($input), 0.0001);
    }

    /** @test */
    public function null_quantity_defaults_to_one(): void
    {
        $this->assertEqualsWithDelta(1.0, (new PurchaseImportService())->parseQuantity(null), 0.0001);
        $this->assertEqualsWithDelta(1.0, (new SalesImportService())->parseQuantity(null), 0.0001);
    }
}

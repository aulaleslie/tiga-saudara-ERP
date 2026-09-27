<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\CrossBusinessQuantityPresenter;
use PHPUnit\Framework\TestCase;

class CrossBusinessQuantityPresenterTest extends TestCase
{
    /** @test */
    public function it_formats_whole_numbers_in_decimal_mode_without_comma_zero_zero()
    {
        $this->assertEquals('10', CrossBusinessQuantityPresenter::format(10, 'decimal'));
        $this->assertEquals('0', CrossBusinessQuantityPresenter::format(0, 'decimal'));
        $this->assertEquals('100', CrossBusinessQuantityPresenter::format(100.0, 'decimal'));
        $this->assertEquals('10', CrossBusinessQuantityPresenter::format('10.000', 'decimal'));
    }

    /** @test */
    public function it_formats_fractional_numbers_in_decimal_mode_with_comma_and_two_decimals()
    {
        $this->assertEquals('10,50', CrossBusinessQuantityPresenter::format(10.5, 'decimal'));
        $this->assertEquals('10,25', CrossBusinessQuantityPresenter::format(10.25, 'decimal'));
        $this->assertEquals('0,75', CrossBusinessQuantityPresenter::format(0.75, 'decimal'));
    }

    /** @test */
    public function it_rounds_to_two_decimal_places_in_decimal_mode()
    {
        $this->assertEquals('10,13', CrossBusinessQuantityPresenter::format(10.126, 'decimal'));
        $this->assertEquals('10,12', CrossBusinessQuantityPresenter::format(10.124, 'decimal'));
        // 9.999 rounds to 10.00 which is whole -> '10'
        $this->assertEquals('10', CrossBusinessQuantityPresenter::format(9.999, 'decimal'));
    }

    /** @test */
    public function it_formats_largest_conversion_with_whole_and_fractional_remainders()
    {
        // 150 with Karton (144) and Pcs -> 1 Karton 6 Pcs
        $result = CrossBusinessQuantityPresenter::format(
            quantity: 150,
            mode: 'conversion',
            baseUnitName: 'Pcs',
            conversionUnitName: 'Karton',
            conversionFactor: 144
        );
        $this->assertEquals('1 Karton 6 Pcs', $result);

        // 150.5 with Karton (144) and Pcs -> 1 Karton 6,50 Pcs
        $resultFract = CrossBusinessQuantityPresenter::format(
            quantity: 150.5,
            mode: 'conversion',
            baseUnitName: 'Pcs',
            conversionUnitName: 'Karton',
            conversionFactor: 144
        );
        $this->assertEquals('1 Karton 6,50 Pcs', $resultFract);

        // 72 with Karton (144) -> 0 Karton 72 Pcs
        $resultZeroConverted = CrossBusinessQuantityPresenter::format(
            quantity: 72,
            mode: 'conversion',
            baseUnitName: 'Pcs',
            conversionUnitName: 'Karton',
            conversionFactor: 144
        );
        $this->assertEquals('0 Karton 72 Pcs', $resultZeroConverted);
    }

    /** @test */
    public function it_falls_back_to_base_unit_when_no_conversion_is_present()
    {
        $result = CrossBusinessQuantityPresenter::format(
            quantity: 17.5,
            mode: 'conversion',
            baseUnitName: 'Pcs',
            conversionUnitName: null,
            conversionFactor: null
        );
        $this->assertEquals('17,50 Pcs', $result);

        $resultWhole = CrossBusinessQuantityPresenter::format(
            quantity: 20,
            mode: 'conversion',
            baseUnitName: 'Kg',
            conversionUnitName: null,
            conversionFactor: null
        );
        $this->assertEquals('20 Kg', $resultWhole);
    }

    /** @test */
    public function it_falls_back_to_bare_formatted_number_when_no_base_unit_is_present()
    {
        $result = CrossBusinessQuantityPresenter::format(
            quantity: 17.5,
            mode: 'conversion',
            baseUnitName: null,
            conversionUnitName: null,
            conversionFactor: null
        );
        $this->assertEquals('17,50', $result);

        $resultZeroFactor = CrossBusinessQuantityPresenter::format(
            quantity: 17.5,
            mode: 'conversion',
            baseUnitName: null,
            conversionUnitName: 'Box',
            conversionFactor: 0
        );
        $this->assertEquals('17,50', $resultZeroFactor);
    }

    /** @test */
    public function it_formats_negative_quantities_in_conversion_mode_sensibly()
    {
        // -1 Pcs with factor 12: no whole converted unit, so show only the signed remainder
        // ("-1 Pcs"), never the mathematically wrong "-1 Box 11 Pcs" or meaningless "-0 Box 1 Pcs".
        $result = CrossBusinessQuantityPresenter::format(
            quantity: -1,
            mode: 'conversion',
            baseUnitName: 'Pcs',
            conversionUnitName: 'Box',
            conversionFactor: 12
        );
        $this->assertEquals('-1 Pcs', $result);

        // -12 Pcs with factor 12: exactly one whole converted unit, no remainder
        $resultExactFactor = CrossBusinessQuantityPresenter::format(
            quantity: -12,
            mode: 'conversion',
            baseUnitName: 'Pcs',
            conversionUnitName: 'Box',
            conversionFactor: 12
        );
        $this->assertEquals('-1 Box 0 Pcs', $resultExactFactor);

        // -13 Pcs with factor 12: both the converted unit and remainder are negative
        $resultBeyondFactor = CrossBusinessQuantityPresenter::format(
            quantity: -13,
            mode: 'conversion',
            baseUnitName: 'Pcs',
            conversionUnitName: 'Box',
            conversionFactor: 12
        );
        $this->assertEquals('-1 Box -1 Pcs', $resultBeyondFactor);

        // Zero stays unsigned
        $resultZero = CrossBusinessQuantityPresenter::format(
            quantity: 0,
            mode: 'conversion',
            baseUnitName: 'Pcs',
            conversionUnitName: 'Box',
            conversionFactor: 12
        );
        $this->assertEquals('0 Box 0 Pcs', $resultZero);
    }
}

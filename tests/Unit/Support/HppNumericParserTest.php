<?php

namespace Tests\Unit\Support;

use App\Support\HppNumericParser;
use PHPUnit\Framework\TestCase;

class HppNumericParserTest extends TestCase
{
    protected HppNumericParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new HppNumericParser();
    }

    public function test_it_parses_negative_quantities()
    {
        // Mutasi is often negative
        $this->assertEquals(-5.0, $this->parser->parseQuantity('-5'));
        $this->assertEquals(-5.5, $this->parser->parseQuantity('-5.5'));
        $this->assertEquals(-5.5, $this->parser->parseQuantity('-5,5'));
        $this->assertEquals(-1000.0, $this->parser->parseQuantity('-1,000'));
        $this->assertEquals(-1000.0, $this->parser->parseQuantity('-1.000'));
    }

    public function test_it_parses_positive_quantities()
    {
        $this->assertEquals(10.0, $this->parser->parseQuantity('10'));
        $this->assertEquals(12.5, $this->parser->parseQuantity('12.5'));
        $this->assertEquals(12.5, $this->parser->parseQuantity('12,5'));
        $this->assertEquals(1200.5, $this->parser->parseQuantity('1,200.5'));
        $this->assertEquals(1200.5, $this->parser->parseQuantity('1.200,5'));
    }

    public function test_it_parses_hpp_with_indonesian_format()
    {
        $this->assertEquals(1500000.0, $this->parser->parseHpp('1.500.000,00'));
        $this->assertEquals(1500000.0, $this->parser->parseHpp('1.500.000'));
        $this->assertEquals(1500000.5, $this->parser->parseHpp('1.500.000,50'));
    }

    public function test_it_parses_hpp_with_english_format()
    {
        $this->assertEquals(1500000.0, $this->parser->parseHpp('1,500,000.00'));
        $this->assertEquals(1500000.0, $this->parser->parseHpp('1,500,000'));
        $this->assertEquals(1500000.5, $this->parser->parseHpp('1,500,000.50'));
    }

    public function test_it_strips_currency_symbols()
    {
        $this->assertEquals(1500000.0, $this->parser->parseHpp('Rp 1.500.000,00'));
        $this->assertEquals(1500000.0, $this->parser->parseHpp('Rp1.500.000,00'));
        $this->assertEquals(1500000.0, $this->parser->parseHpp('IDR 1.500.000,00'));
        $this->assertEquals(1500000.0, $this->parser->parseHpp('$ 1,500,000.00'));
    }
}

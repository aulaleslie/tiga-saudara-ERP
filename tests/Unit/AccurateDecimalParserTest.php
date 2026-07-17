<?php

namespace Tests\Unit;

use App\Support\AccurateDecimalParser;
use PHPUnit\Framework\TestCase;

class AccurateDecimalParserTest extends TestCase
{
    public function test_it_parses_comma_grouped_prices()
    {
        $this->assertEquals(400000.00, AccurateDecimalParser::parse('400,000.00'));
        $this->assertEquals(1234567.89, AccurateDecimalParser::parse('1,234,567.89'));
        $this->assertEquals(1000.0, AccurateDecimalParser::parse('1,000'));
    }

    public function test_it_parses_ordinary_numeric_cells()
    {
        $this->assertEquals(150.50, AccurateDecimalParser::parse('150.50'));
        $this->assertEquals(150.50, AccurateDecimalParser::parse(150.5));
        $this->assertEquals(100, AccurateDecimalParser::parse(100));
        $this->assertEquals(0.99, AccurateDecimalParser::parse('0.99'));
    }

    public function test_it_returns_null_for_blank_or_non_numeric()
    {
        $this->assertNull(AccurateDecimalParser::parse(''));
        $this->assertNull(AccurateDecimalParser::parse(null));
        $this->assertNull(AccurateDecimalParser::parse('   '));
        $this->assertNull(AccurateDecimalParser::parse('abc'));
        $this->assertNull(AccurateDecimalParser::parse('Rp 50,000')); // With letters
    }

    public function test_it_parses_zero_and_negative_values()
    {
        $this->assertEquals(0.0, AccurateDecimalParser::parse('0'));
        $this->assertEquals(0.0, AccurateDecimalParser::parse('0.00'));
        $this->assertEquals(-150.50, AccurateDecimalParser::parse('-150.50'));
        $this->assertEquals(-400000.0, AccurateDecimalParser::parse('-400,000.00'));
    }
}

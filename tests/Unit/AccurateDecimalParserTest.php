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

    // parseStock() tests
    public function test_parseStock_accepts_positive_integers()
    {
        $this->assertEquals(100, AccurateDecimalParser::parseStock('100'));
        $this->assertEquals(0, AccurateDecimalParser::parseStock('0'));
        $this->assertEquals(1000000, AccurateDecimalParser::parseStock('1000000'));
    }

    public function test_parseStock_accepts_thousands_formatted_integers()
    {
        $this->assertEquals(100000, AccurateDecimalParser::parseStock('100,000'));
        $this->assertEquals(1000000, AccurateDecimalParser::parseStock('1,000,000'));
        $this->assertEquals(1234567, AccurateDecimalParser::parseStock('1,234,567'));
    }

    public function test_parseStock_accepts_negative_integers()
    {
        $this->assertEquals(-50, AccurateDecimalParser::parseStock('-50'));
        $this->assertEquals(-100000, AccurateDecimalParser::parseStock('-100,000'));
        $this->assertEquals(-1234567, AccurateDecimalParser::parseStock('-1,234,567'));
    }

    public function test_parseStock_rejects_blank_values()
    {
        $this->assertNull(AccurateDecimalParser::parseStock(''));
        $this->assertNull(AccurateDecimalParser::parseStock(null));
        $this->assertNull(AccurateDecimalParser::parseStock('   '));
    }

    public function test_parseStock_rejects_fractional_values()
    {
        // Reject decimal points
        $this->assertNull(AccurateDecimalParser::parseStock('100.5'));
        $this->assertNull(AccurateDecimalParser::parseStock('1.5'));
        $this->assertNull(AccurateDecimalParser::parseStock('0.99'));
        $this->assertNull(AccurateDecimalParser::parseStock('-50.25'));
    }

    public function test_parseStock_rejects_malformed_strings()
    {
        // Reject strings with letters
        $this->assertNull(AccurateDecimalParser::parseStock('12abc'));
        $this->assertNull(AccurateDecimalParser::parseStock('abc123'));
        $this->assertNull(AccurateDecimalParser::parseStock('100pcs'));

        // Reject special characters
        $this->assertNull(AccurateDecimalParser::parseStock('100 units'));
        $this->assertNull(AccurateDecimalParser::parseStock('$100'));
        $this->assertNull(AccurateDecimalParser::parseStock('Rp 100,000'));

        // Reject only minus sign
        $this->assertNull(AccurateDecimalParser::parseStock('-'));
    }
}

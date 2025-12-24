<?php

namespace Tests\Unit;

use Modules\Purchase\Services\PurchaseImportService;
use PHPUnit\Framework\TestCase;

class PurchaseImportServiceTest extends TestCase
{
    protected PurchaseImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurchaseImportService();
    }

    /**
     * Test parsing product names with asterisk prefix.
     */
    public function test_parse_product_name_with_asterisk_prefix(): void
    {
        $result = $this->service->parseProductName('* Scanner Plustek PS186');

        $this->assertEquals('Scanner Plustek PS186', $result['clean_name']);
        $this->assertEquals('asterisk', $result['marker']);
    }

    /**
     * Test parsing product names with asterisk and extra spaces.
     */
    public function test_parse_product_name_with_asterisk_and_spaces(): void
    {
        $result = $this->service->parseProductName('*  PC SERVER DELL Power Edge');

        $this->assertEquals('PC SERVER DELL Power Edge', $result['clean_name']);
        $this->assertEquals('asterisk', $result['marker']);
    }

    /**
     * Test parsing product names with TP suffix.
     */
    public function test_parse_product_name_with_tp_suffix(): void
    {
        $result = $this->service->parseProductName('Monitor LG 24 Inch TP');

        $this->assertEquals('Monitor LG 24 Inch', $result['clean_name']);
        $this->assertEquals('tp', $result['marker']);
    }

    /**
     * Test parsing product names with no marker.
     */
    public function test_parse_product_name_with_no_marker(): void
    {
        $result = $this->service->parseProductName('Printer Canon G2010');

        $this->assertEquals('Printer Canon G2010', $result['clean_name']);
        $this->assertEquals('default', $result['marker']);
    }

    /**
     * Test parsing product names with whitespace.
     */
    public function test_parse_product_name_trims_whitespace(): void
    {
        $result = $this->service->parseProductName('  Keyboard Logitech  ');

        $this->assertEquals('Keyboard Logitech', $result['clean_name']);
        $this->assertEquals('default', $result['marker']);
    }

    /**
     * Test tax calculation with 10% rate.
     */
    public function test_calculate_tax_percentage_10_percent(): void
    {
        $subtotal = 1000000;
        $taxAmount = 100000;

        $result = $this->service->calculateTaxPercentage($subtotal, $taxAmount);

        $this->assertEquals(10, $result);
    }

    /**
     * Test tax calculation with 11% rate.
     */
    public function test_calculate_tax_percentage_11_percent(): void
    {
        $subtotal = 1000000;
        $taxAmount = 110000;

        $result = $this->service->calculateTaxPercentage($subtotal, $taxAmount);

        $this->assertEquals(11, $result);
    }

    /**
     * Test tax calculation with zero subtotal.
     */
    public function test_calculate_tax_percentage_zero_subtotal(): void
    {
        $result = $this->service->calculateTaxPercentage(0, 100);

        $this->assertEquals(0, $result);
    }

    /**
     * Test tax calculation with zero tax.
     */
    public function test_calculate_tax_percentage_zero_tax(): void
    {
        $result = $this->service->calculateTaxPercentage(1000000, 0);

        $this->assertEquals(0, $result);
    }

    /**
     * Test tax calculation rounds to nearest integer.
     */
    public function test_calculate_tax_percentage_rounds_correctly(): void
    {
        // 10.5% should round to 11
        $result = $this->service->calculateTaxPercentage(1000000, 105000);
        $this->assertEquals(11, $result);

        // 10.4% should round to 10
        $result = $this->service->calculateTaxPercentage(1000000, 104000);
        $this->assertEquals(10, $result);
    }

    /**
     * Test asterisk prefix detection edge cases.
     */
    public function test_parse_product_name_asterisk_only(): void
    {
        $result = $this->service->parseProductName('*');

        $this->assertEquals('', $result['clean_name']);
        $this->assertEquals('asterisk', $result['marker']);
    }

    /**
     * Test TP suffix is case sensitive.
     */
    public function test_parse_product_name_tp_case_sensitive(): void
    {
        // Only " TP" at end should be detected, not "tp" or "Tp"
        $result = $this->service->parseProductName('Product tp');

        $this->assertEquals('Product tp', $result['clean_name']);
        $this->assertEquals('default', $result['marker']);
    }

    /**
     * Test product with TP in middle is not treated as marker.
     */
    public function test_parse_product_name_tp_in_middle(): void
    {
        $result = $this->service->parseProductName('TP-Link Router');

        $this->assertEquals('TP-Link Router', $result['clean_name']);
        $this->assertEquals('default', $result['marker']);
    }
}

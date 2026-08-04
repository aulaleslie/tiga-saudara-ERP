<?php

namespace Modules\Product\Tests\Unit;

use Modules\Product\Services\Ean13Service;
use Tests\TestCase;

class Ean13ServiceTest extends TestCase
{
    private Ean13Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new Ean13Service();
    }

    public function test_valid_ean13_is_recognized()
    {
        $validEan = '5901234123457'; // Real valid EAN-13
        $this->assertTrue($this->service->isValidEan13($validEan));
    }

    public function test_invalid_checksum_is_rejected()
    {
        $invalidChecksum = '5901234123456'; // Correct format but wrong check digit
        $this->assertFalse($this->service->isValidEan13($invalidChecksum));
    }

    public function test_too_short_barcode_is_rejected()
    {
        $this->assertFalse($this->service->isValidEan13('590123412345'));
    }

    public function test_too_long_barcode_is_rejected()
    {
        $this->assertFalse($this->service->isValidEan13('59012341234578'));
    }

    public function test_non_numeric_barcode_is_rejected()
    {
        $this->assertFalse($this->service->isValidEan13('590123412345A'));
    }

    public function test_null_barcode_is_rejected()
    {
        $this->assertFalse($this->service->isValidEan13(null));
    }

    public function test_empty_string_is_rejected()
    {
        $this->assertFalse($this->service->isValidEan13(''));
    }

    public function test_generated_candidate_is_13_digits()
    {
        $candidate = $this->service->generateCandidate();
        $this->assertEquals(13, strlen($candidate));
        $this->assertTrue(ctype_digit($candidate));
    }

    public function test_generated_candidate_has_correct_prefix()
    {
        for ($i = 0; $i < 10; $i++) {
            $candidate = $this->service->generateCandidate();
            $prefix = (int) substr($candidate, 0, 3);
            $this->assertGreaterThanOrEqual(200, $prefix);
            $this->assertLessThanOrEqual(299, $prefix);
        }
    }

    public function test_generated_candidate_has_valid_checksum()
    {
        for ($i = 0; $i < 10; $i++) {
            $candidate = $this->service->generateCandidate();
            $this->assertTrue($this->service->isValidEan13($candidate));
        }
    }

    public function test_generated_candidates_are_not_identical()
    {
        $candidates = array_unique(array_map(fn() => $this->service->generateCandidate(), range(1, 20)));
        $this->assertGreaterThan(1, count($candidates));
    }

    public function test_barcode_with_spaces_is_rejected()
    {
        $this->assertFalse($this->service->isValidEan13('5901234 123457'));
    }

    public function test_barcode_with_leading_zeros_validates_correctly()
    {
        $ean = '0000000000000'; // All zeros (valid checksum)
        $this->assertTrue($this->service->isValidEan13($ean));
    }
}

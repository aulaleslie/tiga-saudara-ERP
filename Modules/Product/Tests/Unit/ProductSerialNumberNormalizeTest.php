<?php

namespace Modules\Product\Tests\Unit;

use Tests\TestCase;
use Modules\Product\Entities\ProductSerialNumber;

class ProductSerialNumberNormalizeTest extends TestCase
{
    public function test_normalize_handles_mixed_case_and_whitespace(): void
    {
        $this->assertSame('SN12345ABC', ProductSerialNumber::normalize('  sn12345abc  '));
        $this->assertSame('SN12345ABC', ProductSerialNumber::normalize("sn12345abc\n"));
        $this->assertSame('SN12345ABC', ProductSerialNumber::normalize('SN12345ABC'));
        $this->assertSame('ABC-XYZ-999', ProductSerialNumber::normalize(' abc-xyz-999 '));
    }

    public function test_normalize_handles_multibyte_characters(): void
    {
        $this->assertSame('ÉLÈVE-123', ProductSerialNumber::normalize('élève-123'));
    }

    public function test_mutator_normalizes_serial_number_on_model(): void
    {
        $psn = new ProductSerialNumber();
        $psn->serial_number = '  sn98765xyz  ';
        $this->assertSame('SN98765XYZ', $psn->serial_number);
    }
}

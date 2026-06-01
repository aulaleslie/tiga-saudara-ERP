<?php

namespace Tests\Unit;

use App\Support\ImportPaymentSummaryResolver;
use PHPUnit\Framework\TestCase;

class ImportPaymentSummaryResolverTest extends TestCase
{
    /** @test */
    public function it_treats_single_separator_exported_float_values_as_decimals(): void
    {
        $resolver = new ImportPaymentSummaryResolver();

        $summary = $resolver->resolve([
            [
                'source_total' => '144750000.000005',
                'pembayaran' => '130405405.40541',
                'sisa_tagihan_hari_ini' => '14344594.594595',
            ],
        ], 144750000.0);

        $this->assertSame(144750000.0, $summary['source_total']);
        $this->assertSame(130405405.41, $summary['paid_amount']);
        $this->assertSame(14344594.59, $summary['outstanding_balance']);
        $this->assertTrue($summary['needs_payment']);
    }

    /** @test */
    public function it_still_supports_mixed_thousands_and_decimal_separators(): void
    {
        $resolver = new ImportPaymentSummaryResolver();

        $summary = $resolver->resolve([
            [
                'source_total' => '1.234.567,89',
                'pembayaran' => '1,234,567.89',
                'sisa_tagihan' => '0',
            ],
        ], 1234567.89);

        $this->assertSame(1234567.89, $summary['source_total']);
        $this->assertSame(1234567.89, $summary['paid_amount']);
        $this->assertSame(0.0, $summary['outstanding_balance']);
        $this->assertTrue($summary['needs_payment']);
    }
}
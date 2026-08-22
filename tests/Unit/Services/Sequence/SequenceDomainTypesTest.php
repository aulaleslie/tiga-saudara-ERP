<?php

namespace Tests\Unit\Services\Sequence;

use App\Services\Sequence\DocumentReferenceFormatter;
use App\Services\Sequence\DocumentType;
use App\Services\Sequence\SequenceAllocation;
use App\Services\Sequence\SequenceNamespace;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SequenceDomainTypesTest extends TestCase
{
    public function test_sequence_namespace_validation(): void
    {
        $namespace = new SequenceNamespace(DocumentType::PURCHASE, 6, 'PD-BL-PR', 2026, 8);
        $this->assertEquals(DocumentType::PURCHASE, $namespace->documentType);
        $this->assertEquals(6, $namespace->settingId);
        $this->assertEquals('PD-BL-PR', $namespace->prefix);
        $this->assertEquals(2026, $namespace->year);
        $this->assertEquals(8, $namespace->month);
        $this->assertEquals('purchase:6:PD-BL-PR:2026:08', $namespace->canonicalKey());

        $this->expectException(InvalidArgumentException::class);
        new SequenceNamespace(DocumentType::SALE, 0, 'TST', 2026, 8);
    }

    public function test_sequence_namespace_canonical_ordering(): void
    {
        $ns1 = new SequenceNamespace(DocumentType::PURCHASE, 1, 'PR', 2026, 8);
        $ns2 = new SequenceNamespace(DocumentType::PURCHASE, 2, 'PR', 2026, 8);
        $ns3 = new SequenceNamespace(DocumentType::SALE, 1, 'SL', 2026, 8);
        $ns4 = new SequenceNamespace(DocumentType::SALE, 1, 'SL', 2026, 9);
        $ns5 = new SequenceNamespace(DocumentType::SALE, 2, 'A-SL', 2026, 8);
        $ns6 = new SequenceNamespace(DocumentType::SALE, 2, 'B-SL', 2026, 8);

        $list = [$ns6, $ns4, $ns1, $ns5, $ns3, $ns2];
        usort($list, fn(SequenceNamespace $a, SequenceNamespace $b) => $a->compareTo($b));

        $this->assertSame($ns1, $list[0]);
        $this->assertSame($ns2, $list[1]);
        $this->assertSame($ns3, $list[2]);
        $this->assertSame($ns4, $list[3]);
        $this->assertSame($ns5, $list[4]);
        $this->assertSame($ns6, $list[5]);
    }

    public function test_formatter_formatting(): void
    {
        $formatter = new DocumentReferenceFormatter();

        $ref1 = $formatter->format('PD-BL-PR', 2026, 8, 1);
        $this->assertEquals('PD-BL-PR-2026-08-00001', $ref1);

        $ref181 = $formatter->format('PD-BL-PR', 2026, 8, 181);
        $this->assertEquals('PD-BL-PR-2026-08-00181', $ref181);

        // Numeric suffix beyond 5 digits
        $refLarge = $formatter->format('PD-BL-PR', 2026, 8, 100005);
        $this->assertEquals('PD-BL-PR-2026-08-100005', $refLarge);
    }

    public function test_formatter_parsing_valid_references(): void
    {
        $formatter = new DocumentReferenceFormatter();

        $parsed = $formatter->parse('PD-BL-PR-2026-08-00181');
        $this->assertNotNull($parsed);
        $this->assertEquals('PD-BL-PR', $parsed['prefix']);
        $this->assertEquals(2026, $parsed['year']);
        $this->assertEquals(8, $parsed['month']);
        $this->assertEquals(181, $parsed['number']);

        $parsedLarge = $formatter->parse('TST-SL-2026-12-123456');
        $this->assertNotNull($parsedLarge);
        $this->assertEquals('TST-SL', $parsedLarge['prefix']);
        $this->assertEquals(2026, $parsedLarge['year']);
        $this->assertEquals(12, $parsedLarge['month']);
        $this->assertEquals(123456, $parsedLarge['number']);
    }

    public function test_formatter_parsing_rejects_malformed_references(): void
    {
        $formatter = new DocumentReferenceFormatter();

        $this->assertNull($formatter->parse(''));
        $this->assertNull($formatter->parse('INVALID-FORMAT'));
        $this->assertNull($formatter->parse('PR-2026-8-00001')); // month not 2 digits
        $this->assertNull($formatter->parse('PR-2026-13-00001')); // invalid month 13
        $this->assertNull($formatter->parse('PR-1999-08-00001')); // year out of range
        $this->assertNull($formatter->parse('PR-2026-08-00000')); // number 0
        $this->assertNull($formatter->parse('PR-2026-08-abc')); // non-numeric suffix
    }

    public function test_sequence_allocation_object(): void
    {
        $ns = new SequenceNamespace(DocumentType::PURCHASE, 6, 'PD-BL-PR', 2026, 8);
        $allocation = new SequenceAllocation($ns, 182, 'PD-BL-PR-2026-08-00182');

        $this->assertSame($ns, $allocation->namespace);
        $this->assertEquals(182, $allocation->number);
        $this->assertEquals('PD-BL-PR-2026-08-00182', $allocation->reference);
    }
}

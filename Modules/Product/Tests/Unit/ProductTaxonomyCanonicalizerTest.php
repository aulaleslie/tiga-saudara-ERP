<?php

namespace Modules\Product\Tests\Unit;

use InvalidArgumentException;
use Modules\Product\Services\ProductTaxonomyCanonicalizer;
use Tests\TestCase;

class ProductTaxonomyCanonicalizerTest extends TestCase
{
    private ProductTaxonomyCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canonicalizer = new ProductTaxonomyCanonicalizer();
    }

    public function test_normalizes_case_and_ascii_whitespace(): void
    {
        $result = $this->canonicalizer->canonicalize("  Alfa   \t\n  Ink  ");

        $this->assertSame('ALFA INK', $result['display_name']);
        $this->assertSame('alfa ink', $result['normalized_name']);
        $this->assertSame('alfa ink', $this->canonicalizer->brandKey('ALFA INK'));
    }

    public function test_normalizes_unicode_whitespace(): void
    {
        // NBSP (\u{00A0}), ideographic space (\u{3000}), thin space (\u{2009}), zero-width space (\u{200B})
        $raw = "\u{00A0}Tinta\u{3000}\u{2009}Alfa\u{200B}  Ink\u{00A0}";
        $result = $this->canonicalizer->canonicalize($raw);

        $this->assertSame('TINTA ALFA INK', $result['display_name']);
        $this->assertSame('tinta alfa ink', $result['normalized_name']);
    }

    public function test_rejects_empty_and_whitespace_only_values(): void
    {
        $this->assertNull($this->canonicalizer->tryNormalizeName(null));
        $this->assertNull($this->canonicalizer->tryNormalizeName(''));
        $this->assertNull($this->canonicalizer->tryNormalizeName("  \t \u{00A0} \u{200B} "));

        $this->expectException(InvalidArgumentException::class);
        $this->canonicalizer->canonicalize("  \u{00A0}  ");
    }

    public function test_preserves_punctuation_spelling_and_product_owner_markers(): void
    {
        $asterisk = $this->canonicalizer->canonicalize('* Alfa-Ink TP');
        $plain = $this->canonicalizer->canonicalize('Alfa Ink');
        $slash = $this->canonicalizer->canonicalize('Alfa/Ink');

        $this->assertSame('* ALFA-INK TP', $asterisk['display_name']);
        $this->assertSame('* alfa-ink tp', $asterisk['normalized_name']);
        $this->assertNotSame($asterisk['normalized_name'], $plain['normalized_name']);
        $this->assertNotSame($plain['normalized_name'], $slash['normalized_name']);
    }

    public function test_resolves_parent_namespace_and_distinct_category_paths(): void
    {
        $this->assertSame(
            ProductTaxonomyCanonicalizer::ROOT_PARENT_NAMESPACE,
            $this->canonicalizer->parentNamespace(null)
        );
        $this->assertSame(12, $this->canonicalizer->parentNamespace(12));

        $rootKey = $this->canonicalizer->categoryKey('Tinta', null);
        $childKeyA = $this->canonicalizer->categoryKey('  tinta ', 10);
        $childKeyB = $this->canonicalizer->categoryKey('TINTA', 20);

        $this->assertSame('0:tinta', $rootKey);
        $this->assertSame('10:tinta', $childKeyA);
        $this->assertSame('20:tinta', $childKeyB);
        $this->assertNotSame($rootKey, $childKeyA);
        $this->assertNotSame($childKeyA, $childKeyB);
    }

    public function test_case_folding_is_idempotent_across_unicode_expansions(): void
    {
        $first = $this->canonicalizer->canonicalize('Straße');
        $second = $this->canonicalizer->canonicalize($first['display_name']);

        $this->assertSame('STRASSE', $first['display_name']);
        $this->assertSame('strasse', $first['normalized_name']);
        $this->assertSame($first, $second);
        $this->assertSame($first['normalized_name'], $this->canonicalizer->tryNormalizeName('Straße'));
    }
}

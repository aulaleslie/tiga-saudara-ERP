<?php

namespace Modules\Product\Tests\Unit;

use Tests\TestCase;
use Modules\Product\Services\ProductCanonicalizer;

class ProductCanonicalizerTest extends TestCase
{
    protected ProductCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canonicalizer = new ProductCanonicalizer();
    }

    public function test_it_handles_case_and_basic_whitespace()
    {
        $result = $this->canonicalizer->canonicalize("  SomE ProDuct  ");
        
        $this->assertEquals("SomE ProDuct", $result['display_name']);
        $this->assertEquals("some product", $result['canonical_key']);
    }

    public function test_it_collapses_repeated_and_unicode_whitespace()
    {
        // "Product" followed by a non-breaking space (\u{00A0}), zero-width space (\u{200B}), and multiple spaces
        $raw = "Product\xC2\xA0 \xE2\x80\x8B   Name";
        $result = $this->canonicalizer->canonicalize($raw);
        
        $this->assertEquals("Product Name", $result['display_name']);
        $this->assertEquals("product name", $result['canonical_key']);
    }

    public function test_it_removes_leading_asterisk_marker()
    {
        $result = $this->canonicalizer->canonicalize("*   Special Item ");
        
        $this->assertEquals("Special Item", $result['display_name']);
        $this->assertEquals("special item", $result['canonical_key']);
    }

    public function test_it_removes_trailing_tp_marker()
    {
        $result = $this->canonicalizer->canonicalize(" Regular Item TP");
        $this->assertEquals("Regular Item", $result['display_name']);
        
        $result = $this->canonicalizer->canonicalize(" Item tp");
        $this->assertEquals("Item", $result['display_name']);
        $this->assertEquals("item", $result['canonical_key']);
    }

    public function test_it_removes_both_markers()
    {
        $result = $this->canonicalizer->canonicalize("*  Marker Item TP ");
        
        $this->assertEquals("Marker Item", $result['display_name']);
        $this->assertEquals("marker item", $result['canonical_key']);
    }

    public function test_it_preserves_non_marker_punctuation()
    {
        // asterisks in the middle or end, TP not standalone or not at the end
        $raw = "Item * Name TPP / (Size TP)";
        $result = $this->canonicalizer->canonicalize($raw);
        
        $this->assertEquals("Item * Name TPP / (Size TP)", $result['display_name']);
        $this->assertEquals("item * name tpp / (size tp)", $result['canonical_key']);
    }

    public function test_it_rejects_blank_names()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->canonicalizer->canonicalize("   *   TP  ");
    }
}

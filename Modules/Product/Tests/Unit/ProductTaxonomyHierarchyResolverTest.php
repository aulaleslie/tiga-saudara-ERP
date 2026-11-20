<?php

namespace Modules\Product\Tests\Unit;

use Modules\Product\Services\ProductTaxonomyCanonicalizer;
use Modules\Product\Services\ProductTaxonomyHierarchyResolver;
use Tests\TestCase;

class ProductTaxonomyHierarchyResolverTest extends TestCase
{
    private ProductTaxonomyHierarchyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProductTaxonomyHierarchyResolver(new ProductTaxonomyCanonicalizer());
    }

    public function test_duplicate_parents_resolve_before_children_and_merge_equivalent_children(): void
    {
        $result = $this->resolver->resolve([
            ['id' => 1, 'category_name' => 'ALFA INK', 'category_code' => 'CAT-01', 'parent_id' => null],
            ['id' => 5, 'category_name' => "  alfa\u{00A0}ink  ", 'category_code' => 'CAT-05', 'parent_id' => null],
            ['id' => 10, 'category_name' => 'TINTA ALFA INK', 'category_code' => 'CAT-10', 'parent_id' => 1],
            ['id' => 20, 'category_name' => 'tinta   alfa ink', 'category_code' => 'CAT-20', 'parent_id' => 5],
            ['id' => 30, 'category_name' => 'TINTA ALFA INK', 'category_code' => 'CAT-30', 'parent_id' => null],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);

        // Parents merge to lowest ID (1)
        $this->assertSame(1, $result['id_to_survivor'][1]);
        $this->assertSame(1, $result['id_to_survivor'][5]);

        // Children under duplicate parents merge to lowest child ID (10) under canonical parent 1
        $this->assertSame(10, $result['id_to_survivor'][10]);
        $this->assertSame(10, $result['id_to_survivor'][20]);
        $this->assertSame(1, $result['nodes'][20]['canonical_parent_id']);
        $this->assertSame(1, $result['nodes'][20]['parent_namespace']);

        // Root category with the same name stays distinct (parent_namespace = 0)
        $this->assertSame(30, $result['id_to_survivor'][30]);
        $this->assertSame(0, $result['nodes'][30]['parent_namespace']);
    }

    public function test_detects_orphan_parent_reference(): void
    {
        $result = $this->resolver->resolve([
            ['id' => 1, 'category_name' => 'ORPHAN', 'category_code' => 'CAT-01', 'parent_id' => 999],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('missing parent_id #999', $result['errors'][0]);
    }

    public function test_detects_self_parent_and_multi_node_cycles(): void
    {
        $result = $this->resolver->resolve([
            ['id' => 1, 'category_name' => 'SELF', 'category_code' => 'C1', 'parent_id' => 1],
            ['id' => 2, 'category_name' => 'CYCLE A', 'category_code' => 'C2', 'parent_id' => 3],
            ['id' => 3, 'category_name' => 'CYCLE B', 'category_code' => 'C3', 'parent_id' => 2],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $joined = implode("\n", $result['errors']);
        $this->assertStringContainsString('references itself', $joined);
        $this->assertStringContainsString('cycle detected', $joined);
    }

    public function test_would_create_cycle_helper(): void
    {
        $parents = [
            1 => null,
            2 => 1,
            3 => 2,
            4 => null,
        ];
        $lookup = fn (int $id): ?int => $parents[$id] ?? null;

        $this->assertTrue($this->resolver->wouldCreateCycle(1, 1, $lookup));
        $this->assertTrue($this->resolver->wouldCreateCycle(1, 3, $lookup));
        $this->assertFalse($this->resolver->wouldCreateCycle(1, 4, $lookup));
        $this->assertFalse($this->resolver->wouldCreateCycle(3, null, $lookup));
    }
}

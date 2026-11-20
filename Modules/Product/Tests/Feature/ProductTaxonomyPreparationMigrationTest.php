<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductTaxonomyPreparationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preparation_migrations_add_canonical_fields_and_audit_table(): void
    {
        $this->assertTrue(Schema::hasColumn('brands', 'normalized_name'));
        $this->assertTrue(Schema::hasColumn('categories', 'normalized_name'));
        $this->assertTrue(Schema::hasColumn('categories', 'parent_namespace'));
        $this->assertTrue(Schema::hasTable('product_taxonomy_reconciliation_audits'));
    }
}

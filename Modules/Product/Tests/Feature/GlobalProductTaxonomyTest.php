<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Livewire\BrandSearchDropdown;
use Modules\Product\Livewire\CategorySearchDropdown;
use Modules\Product\Livewire\Modals\BrandQuickAddModal;
use Modules\Product\Livewire\Modals\CategoryQuickAddModal;
use Modules\Product\Services\ProductTaxonomyResolver;
use Modules\Product\Services\ProductTaxonomySchemaFinalizer;
use RuntimeException;
use Tests\TestCase;

class GlobalProductTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $settingA;
    private int $settingB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currencyId = (int) DB::table('currencies')->insertGetId([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->settingA = (int) DB::table('settings')->insertGetId([
            'company_name' => 'Business A',
            'company_email' => 'a@example.com',
            'company_phone' => '0811111111',
            'default_currency_id' => $currencyId,
            'default_currency_position' => 'prefix',
            'notification_email' => 'a@example.com',
            'footer_text' => 'Footer A',
            'company_address' => 'Address A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->settingB = (int) DB::table('settings')->insertGetId([
            'company_name' => 'Business B',
            'company_email' => 'b@example.com',
            'company_phone' => '0822222222',
            'default_currency_id' => $currencyId,
            'default_currency_position' => 'prefix',
            'notification_email' => 'b@example.com',
            'footer_text' => 'Footer B',
            'company_address' => 'Address B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_finalized_schema_removes_setting_ownership_and_enforces_uniqueness_and_business_independence(): void
    {
        $this->assertFalse(Schema::hasColumn('brands', 'setting_id'));
        $this->assertFalse(Schema::hasColumn('categories', 'setting_id'));

        $brand = Brand::create(['name' => 'Epson', 'created_by' => $this->user->id]);
        $rootCat = Category::create([
            'category_code' => 'CAT-ROOT',
            'category_name' => 'Alfa Ink',
            'parent_id' => null,
            'created_by' => $this->user->id,
        ]);

        // Deleting a business setting does NOT delete shared brands or categories
        DB::table('settings')->where('id', $this->settingA)->delete();
        $this->assertNotNull(Brand::find($brand->id));
        $this->assertNotNull(Category::find($rootCat->id));

        // Root uniqueness is enforced at the database level via parent_namespace = 0
        try {
            DB::table('categories')->insert([
                'category_code' => 'CAT-ROOT-2',
                'category_name' => 'ALFA INK',
                'normalized_name' => 'alfa ink',
                'parent_id' => null,
                'parent_namespace' => 0,
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException for duplicate root category.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // Soft-deleted brand name remains reserved at the database level
        $brand->delete();
        try {
            DB::table('brands')->insert([
                'name' => 'EPSON',
                'normalized_name' => 'epson',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException for deleted-brand normalized_name collision.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // Idempotent retry of finalizer succeeds cleanly
        app(ProductTaxonomySchemaFinalizer::class)->finalize();
        $this->assertSame([], app(ProductTaxonomySchemaFinalizer::class)->preflight());
    }

    public function test_dropdowns_and_quick_add_share_taxonomy_across_businesses(): void
    {
        session(['setting_id' => $this->settingA]);

        Livewire::test(BrandQuickAddModal::class)
            ->set('name', '  Brother  ')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(CategoryQuickAddModal::class)
            ->set('category_code', 'CAT-PRN')
            ->set('category_name', 'Printer')
            ->call('save')
            ->assertHasNoErrors();

        $brand = Brand::firstOrFail();
        $category = Category::firstOrFail();

        // Switch to Business B: same shared brand and category are visible and selectable
        session(['setting_id' => $this->settingB]);

        Livewire::test(BrandSearchDropdown::class, ['selected' => $brand->id])
            ->assertSet('selectedLabel', 'BROTHER');

        Livewire::test(CategorySearchDropdown::class, ['selected' => $category->id])
            ->assertSet('selectedLabel', 'PRINTER');

        // Duplicate creation from Business B fails validation
        Livewire::test(BrandQuickAddModal::class)
            ->set('name', 'brother')
            ->call('save')
            ->assertHasErrors(['name']);

        Livewire::test(CategoryQuickAddModal::class)
            ->set('category_code', 'CAT-PRN-2')
            ->set('category_name', "  printer\u{00A0}")
            ->call('save')
            ->assertHasErrors(['category_name']);
    }

    public function test_category_moves_reject_cycles_and_sibling_duplicates_and_allow_valid_edits(): void
    {
        $resolver = app(ProductTaxonomyResolver::class);

        $parentA = $resolver->createCategory('C1', 'Parent A', null, $this->user->id);
        $childA = $resolver->createCategory('C2', 'Child X', $parentA->id, $this->user->id);
        $parentB = $resolver->createCategory('C3', 'Parent B', null, $this->user->id);
        $childB = $resolver->createCategory('C4', 'Child X', $parentB->id, $this->user->id);

        // Moving parentA under its own descendant childA is rejected
        try {
            $resolver->updateCategory($parentA, 'C1', 'Parent A', $childA->id);
            $this->fail('Expected cycle ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }

        // Moving childB under parentA conflicts with existing sibling "Child X"
        try {
            $resolver->updateCategory($childB, 'C4', 'child   x', $parentA->id);
            $this->fail('Expected sibling duplicate ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category_name', $e->errors());
        }

        // Valid rename + move succeeds
        $updated = $resolver->updateCategory($childB, 'C4', 'Child Y', $parentA->id);
        $this->assertSame($parentA->id, $updated->parent_id);
        $this->assertSame($parentA->id, $updated->parent_namespace);
        $this->assertSame('CHILD Y', $updated->category_name);
    }

    public function test_import_resolution_reuses_unique_child_reports_ambiguity_and_blocks_deleted_brand(): void
    {
        $resolver = app(ProductTaxonomyResolver::class);

        $parentA = $resolver->createCategory('P1', 'Alfa Ink', null, $this->user->id);
        $uniqueChild = $resolver->createCategory('P2', 'Tinta Alfa Ink', $parentA->id, $this->user->id);

        // 1. Unique child-name reuse (does NOT create a duplicate root)
        $reused = $resolver->resolveOrCreateCategoryForImport('  tinta   alfa ink ', $this->user->id);
        $this->assertSame($uniqueChild->id, $reused->id);

        // 2. Absent name creates a root category
        $createdRoot = $resolver->resolveOrCreateCategoryForImport('Kertas Foto', $this->user->id);
        $this->assertNull($createdRoot->parent_id);
        $this->assertSame(0, (int) $createdRoot->parent_namespace);

        // 3. Ambiguous category name across multiple parent paths reports actionable error
        $parentB = $resolver->createCategory('P3', 'Epson', null, $this->user->id);
        $resolver->createCategory('P4', 'Tinta', $parentA->id, $this->user->id);
        $resolver->createCategory('P5', 'Tinta', $parentB->id, $this->user->id);

        try {
            $resolver->resolveOrCreateCategoryForImport('tinta', $this->user->id);
            $this->fail('Expected ambiguity RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Ambiguous category name', $e->getMessage());
        }

        // 4. Deleted brand conflict blocks import without recreating or restoring
        $deletedBrand = $resolver->createBrand('Canon', null, $this->user->id);
        $deletedBrand->delete();

        try {
            $resolver->resolveOrCreateBrandForImport('  canon ', $this->user->id);
            $this->fail('Expected deleted-brand RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('reserved by a deleted brand', $e->getMessage());
        }
        $this->assertSoftDeleted('brands', ['id' => $deletedBrand->id]);
    }

    public function test_permission_denial_remains_enforced_for_taxonomy_mutations(): void
    {
        $unauthorized = User::factory()->create();
        $this->actingAs($unauthorized);

        $this->post(route('brands.store'), ['name' => 'Denied Brand'])
            ->assertStatus(403);

        $this->post(route('product-categories.store'), [
            'category_code' => 'DENIED-1',
            'category_name' => 'Denied Category',
        ])->assertStatus(403);
    }

    public function test_repeat_import_reuses_unicode_case_expanded_brand_and_does_not_misreport_code_collisions(): void
    {
        $resolver = app(ProductTaxonomyResolver::class);

        // Repeat import with Unicode expansion ("Straße" -> "STRASSE" / "strasse") reuses the same record
        $first = $resolver->resolveOrCreateBrandForImport('Straße', $this->user->id);
        $second = $resolver->resolveOrCreateBrandForImport('Straße', $this->user->id);
        $this->assertSame($first->id, $second->id);

        // Duplicate category_code (different name) must NOT be misreported as duplicate category_name
        $resolver->createCategory('DUP-CODE', 'Unique Name One', null, $this->user->id);
        try {
            $resolver->createCategory('DUP-CODE', 'Unique Name Two', null, $this->user->id);
            $this->fail('Expected QueryException for duplicate category_code.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('category_code', $e->getMessage());
        }
    }
}

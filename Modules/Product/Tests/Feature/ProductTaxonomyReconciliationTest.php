<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Services\ProductTaxonomyReconciliationService;
use RuntimeException;
use Tests\TestCase;

class ProductTaxonomyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;
    private int $settingA;
    private int $settingB;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->userId = (int) $user->id;

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

    private function seedDuplicateTaxonomyFixture(): array
    {
        $catHasSetting = Schema::hasColumn('categories', 'setting_id');
        $brandHasSetting = Schema::hasColumn('brands', 'setting_id');

        $catRow = fn (array $data, int $settingId) => $catHasSetting ? array_merge($data, ['setting_id' => $settingId]) : $data;
        $brandRow = fn (array $data, int $settingId) => $brandHasSetting ? array_merge($data, ['setting_id' => $settingId]) : $data;

        $catParentSurvivor = (int) DB::table('categories')->insertGetId($catRow([
            'category_code' => 'CAT-01',
            'category_name' => 'ALFA INK',
            'parent_id' => null,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->settingA));

        $catParentDuplicate = (int) DB::table('categories')->insertGetId($catRow([
            'category_code' => 'CAT-02',
            'category_name' => "  alfa\u{00A0}ink  ",
            'parent_id' => null,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->settingB));

        $catChildSurvivor = (int) DB::table('categories')->insertGetId($catRow([
            'category_code' => 'CAT-03',
            'category_name' => 'TINTA ALFA INK',
            'parent_id' => $catParentSurvivor,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->settingA));

        $catChildDuplicate = (int) DB::table('categories')->insertGetId($catRow([
            'category_code' => 'CAT-04',
            'category_name' => 'tinta   alfa ink',
            'parent_id' => $catParentDuplicate,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->settingB));

        // Distinct child under duplicate parent (not duplicated itself, only needs parent_id remapped)
        $catUniqueChild = (int) DB::table('categories')->insertGetId($catRow([
            'category_code' => 'CAT-05',
            'category_name' => 'SERBUK TONER',
            'parent_id' => $catParentDuplicate,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->settingB));

        $brandSurvivor = (int) DB::table('brands')->insertGetId($brandRow([
            'name' => 'EPSON',
            'description' => 'Original survivor description',
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->settingA));

        $brandDuplicate = (int) DB::table('brands')->insertGetId($brandRow([
            'name' => '  epson  ',
            'description' => 'Conflicting duplicate description',
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->settingB));

        $productRemapped = (int) DB::table('products')->insertGetId([
            'setting_id' => $this->settingA,
            'category_id' => $catChildDuplicate,
            'brand_id' => $brandDuplicate,
            'product_name' => 'Tinta Epson 003',
            'product_code' => 'PRD-001',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 50000,
            'product_price' => 75000,
            'product_unit' => 'BOTOL',
            'product_stock_alert' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productNullRelations = (int) DB::table('products')->insertGetId([
            'setting_id' => $this->settingA,
            'category_id' => null,
            'brand_id' => null,
            'product_name' => 'Produk Tanpa Taksonomi',
            'product_code' => 'PRD-002',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 5,
            'product_cost' => 10000,
            'product_price' => 15000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact(
            'catParentSurvivor',
            'catParentDuplicate',
            'catChildSurvivor',
            'catChildDuplicate',
            'catUniqueChild',
            'brandSurvivor',
            'brandDuplicate',
            'productRemapped',
            'productNullRelations'
        );
    }

    public function test_preview_is_read_only_and_reports_expected_mappings_and_conflicts(): void
    {
        $ids = $this->seedDuplicateTaxonomyFixture();

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('product:reconcile-taxonomy', ['--json' => true]);
        $this->assertSame(0, $exitCode);

        $plan = json_decode(Artisan::output(), true);
        $this->assertTrue($plan['valid']);
        $this->assertTrue($plan['has_work']);
        $this->assertNotEmpty($plan['plan_fingerprint']);

        $this->assertSame(5, $plan['summary']['categories_before']);
        $this->assertSame(3, $plan['summary']['categories_after']);
        $this->assertSame(2, $plan['summary']['categories_removed']);
        $this->assertSame(2, $plan['summary']['brands_before_active']);
        $this->assertSame(1, $plan['summary']['brands_after_active']);
        $this->assertSame(1, $plan['summary']['brands_removed']);
        $this->assertSame(1, $plan['summary']['product_category_references_updated']);
        $this->assertSame(1, $plan['summary']['category_parent_references_updated']);
        $this->assertSame(1, $plan['summary']['product_brand_references_updated']);

        // Preview must not mutate any rows
        $this->assertSame(5, DB::table('categories')->count());
        $this->assertSame(2, DB::table('brands')->count());
        $this->assertSame($ids['catChildDuplicate'], (int) DB::table('products')->where('id', $ids['productRemapped'])->value('category_id'));
        $this->assertSame(0, DB::table('product_taxonomy_reconciliation_audits')->count());
    }

    public function test_execute_remaps_references_preserves_survivor_metadata_records_audit_and_is_idempotent(): void
    {
        $ids = $this->seedDuplicateTaxonomyFixture();
        $service = app(ProductTaxonomyReconciliationService::class);
        $plan = $service->buildPlan();

        $result = $service->execute($plan['plan_fingerprint'], 'ops@example.com');
        $this->assertSame('completed', $result['status']);
        $this->assertNotNull($result['audit_id']);

        // Redundant categories & brands removed; survivors preserved with original metadata & codes
        $this->assertSame(3, DB::table('categories')->count());
        $this->assertSame('CAT-01', DB::table('categories')->where('id', $ids['catParentSurvivor'])->value('category_code'));
        $this->assertSame('CAT-03', DB::table('categories')->where('id', $ids['catChildSurvivor'])->value('category_code'));
        $this->assertSame($ids['catParentSurvivor'], (int) DB::table('categories')->where('id', $ids['catUniqueChild'])->value('parent_id'));
        $this->assertSame('alfa ink', DB::table('categories')->where('id', $ids['catParentSurvivor'])->value('normalized_name'));
        $this->assertSame(0, (int) DB::table('categories')->where('id', $ids['catParentSurvivor'])->value('parent_namespace'));
        $this->assertSame($ids['catParentSurvivor'], (int) DB::table('categories')->where('id', $ids['catChildSurvivor'])->value('parent_namespace'));

        $this->assertSame(1, DB::table('brands')->count());
        $this->assertSame('Original survivor description', DB::table('brands')->where('id', $ids['brandSurvivor'])->value('description'));
        $this->assertSame('epson', DB::table('brands')->where('id', $ids['brandSurvivor'])->value('normalized_name'));

        // Product remapped cleanly; product ID and unrelated fields preserved; NULL relations preserved
        $remappedProduct = DB::table('products')->where('id', $ids['productRemapped'])->first();
        $this->assertSame($ids['catChildSurvivor'], (int) $remappedProduct->category_id);
        $this->assertSame($ids['brandSurvivor'], (int) $remappedProduct->brand_id);
        $this->assertSame('PRD-001', $remappedProduct->product_code);
        $this->assertSame(75000, (int) $remappedProduct->product_price);

        $nullProduct = DB::table('products')->where('id', $ids['productNullRelations'])->first();
        $this->assertNull($nullProduct->category_id);
        $this->assertNull($nullProduct->brand_id);

        // Durable audit recorded
        $audit = DB::table('product_taxonomy_reconciliation_audits')->where('id', $result['audit_id'])->first();
        $this->assertNotNull($audit);
        $this->assertSame('ops@example.com', $audit->operator);
        $this->assertSame($plan['plan_fingerprint'], $audit->plan_fingerprint);
        $this->assertSame(2, (int) $audit->categories_removed);
        $this->assertSame(1, (int) $audit->brands_removed);
        $this->assertSame(1, (int) $audit->product_category_references_updated);
        $this->assertSame(1, (int) $audit->category_parent_references_updated);
        $this->assertSame(1, (int) $audit->product_brand_references_updated);

        // Idempotent rerun produces no_op and does not add another audit row
        $freshPlan = $service->buildPlan();
        $this->assertFalse($freshPlan['has_work']);
        $rerun = $service->execute($freshPlan['plan_fingerprint'], 'ops@example.com');
        $this->assertSame('no_op', $rerun['status']);
        $this->assertSame(1, DB::table('product_taxonomy_reconciliation_audits')->count());
    }

    public function test_execute_rolls_back_on_mid_merge_failure_and_rejects_stale_plan(): void
    {
        $ids = $this->seedDuplicateTaxonomyFixture();
        $service = app(ProductTaxonomyReconciliationService::class);
        $plan = $service->buildPlan();

        // 1. Injected mid-merge failure rolls back all mutations
        try {
            $service->execute($plan['plan_fingerprint'], 'ops@example.com', null, function () {
                throw new RuntimeException('Simulated mid-merge failure');
            });
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated mid-merge failure', $e->getMessage());
        }

        $this->assertSame(5, DB::table('categories')->count());
        $this->assertSame(2, DB::table('brands')->count());
        $this->assertSame($ids['catChildDuplicate'], (int) DB::table('products')->where('id', $ids['productRemapped'])->value('category_id'));
        $this->assertSame(0, DB::table('product_taxonomy_reconciliation_audits')->count());

        // 2. Stale plan rejected after underlying data changes
        DB::table('brands')->where('id', $ids['brandDuplicate'])->update(['description' => 'Mutated after preview']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stale plan fingerprint');
        $service->execute($plan['plan_fingerprint'], 'ops@example.com');
    }

    public function test_deleted_brand_collision_blocks_reconciliation(): void
    {
        $this->seedDuplicateTaxonomyFixture();

        $deletedBrandRow = [
            'name' => 'Epson',
            'description' => 'Soft-deleted copy',
            'created_by' => $this->userId,
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('brands', 'setting_id')) {
            $deletedBrandRow['setting_id'] = $this->settingA;
        }
        DB::table('brands')->insert($deletedBrandRow);

        $service = app(ProductTaxonomyReconciliationService::class);
        $plan = $service->buildPlan();

        $this->assertFalse($plan['valid']);
        $this->assertNotEmpty($plan['brands']['deleted_brand_conflicts']);
        $this->assertCount(1, $plan['brands']['deleted_brands']);
    }

    public function test_unsupported_incoming_foreign_key_blocks_reconciliation(): void
    {
        $this->seedDuplicateTaxonomyFixture();

        Schema::create('tmp_unsupported_category_refs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->foreign('category_id')->references('id')->on('categories');
        });

        try {
            $service = app(ProductTaxonomyReconciliationService::class);
            $plan = $service->buildPlan();

            $this->assertFalse($plan['valid']);
            $this->assertNotEmpty($plan['unsupported_foreign_keys']);
            $this->assertSame('tmp_unsupported_category_refs', $plan['unsupported_foreign_keys'][0]['table']);
        } finally {
            Schema::dropIfExists('tmp_unsupported_category_refs');
        }
    }
}

<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Services\ProductTaxonomyReconciliationService;
use Modules\Product\Services\ProductTaxonomyResolver;
use Modules\Product\Services\ProductTaxonomySchemaFinalizer;
use Tests\TestCase;

/**
 * @group mysql
 */
class GlobalProductTaxonomyMysqlTest extends TestCase
{
    use DatabaseTruncation;

    public function test_mysql_collation_root_uniqueness_and_concurrent_brand_and_category_recovery(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires isolated MySQL test connection (phpunit.mysql.xml).');
        }

        $user = User::factory()->create();
        $resolver = app(ProductTaxonomyResolver::class);

        // 1. Accent-sensitive utf8mb4_bin collation keeps distinct accented names separate
        $b1 = $resolver->createBrand('Cafe', null, $user->id);
        $b2 = $resolver->createBrand('Café', null, $user->id);
        $this->assertNotSame($b1->id, $b2->id);

        // 2. Root unique key prevents duplicate root categories on MySQL
        $resolver->createCategory('M-CAT-1', 'Root Name', null, $user->id);
        try {
            DB::table('categories')->insert([
                'category_code' => 'M-CAT-2',
                'category_name' => 'ROOT NAME',
                'normalized_name' => 'root name',
                'parent_id' => null,
                'parent_namespace' => 0,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected MySQL duplicate key error for root category.');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) ($e->errorInfo[1] ?? 0));
        }

        // 3. Concurrent same-identity creation under MySQL REPEATABLE READ across two connections
        //    for BOTH brands and categories (including competing category_code collision).
        $baseConfig = Config::get('database.connections.' . DB::getDefaultConnection());
        Config::set('database.connections.mysql_test_peer', $baseConfig);
        $peer = DB::connection('mysql_test_peer');

        DB::beginTransaction();
        try {
            // Establish REPEATABLE READ snapshot before peer inserts
            $this->assertNull(Brand::where('normalized_name', 'concurrent brand')->first());
            $this->assertNull(Category::where('normalized_name', 'concurrent cat')->first());

            // Peer connection commits winning brand and winning root category (with slug code 'concurrent_cat')
            $winnerBrandId = (int) $peer->table('brands')->insertGetId([
                'name' => 'CONCURRENT BRAND',
                'normalized_name' => 'concurrent brand',
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $winnerCatId = (int) $peer->table('categories')->insertGetId([
                'category_code' => 'concurrent_cat',
                'category_name' => 'CONCURRENT CAT',
                'normalized_name' => 'concurrent cat',
                'parent_id' => null,
                'parent_namespace' => 0,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Current transaction hits ER_DUP_ENTRY (1062) on brand and on category_code/identity,
            // and must recover both winning records via lockForUpdate() current reads.
            $recoveredBrand = $resolver->resolveOrCreateBrandForImport('  Concurrent   Brand ', $user->id);
            $this->assertNotNull($recoveredBrand);
            $this->assertSame($winnerBrandId, (int) $recoveredBrand->id);

            $recoveredCat = $resolver->resolveOrCreateCategoryForImport('  Concurrent   Cat ', $user->id);
            $this->assertNotNull($recoveredCat);
            $this->assertSame($winnerCatId, (int) $recoveredCat->id);
        } finally {
            DB::rollBack();
            DB::purge('mysql_test_peer');
        }
    }

    public function test_mysql_populated_upgrade_reconciliation_and_partial_ddl_retry(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires isolated MySQL test connection (phpunit.mysql.xml).');
        }

        $user = User::factory()->create();
        $currencyId = (int) DB::table('currencies')->insertGetId([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $settingId = (int) DB::table('settings')->insertGetId([
            'company_name' => 'MySQL Biz',
            'company_email' => 'm@example.com',
            'company_phone' => '0811111111',
            'default_currency_id' => $currencyId,
            'default_currency_position' => 'prefix',
            'notification_email' => 'm@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Restore true pre-finalization preparation schema:
        // nullable canonical columns, no unique indexes, and legacy setting_id ownership FKs.
        DB::statement('ALTER TABLE `brands` DROP INDEX `brands_normalized_name_unique`');
        DB::statement('ALTER TABLE `categories` DROP INDEX `categories_parent_namespace_normalized_name_unique`');
        DB::statement('ALTER TABLE `brands` MODIFY `normalized_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
        DB::statement('ALTER TABLE `categories` MODIFY `normalized_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
        DB::statement('ALTER TABLE `categories` MODIFY `parent_namespace` BIGINT UNSIGNED NULL');

        Schema::table('brands', function (Blueprint $table) {
            if (!Schema::hasColumn('brands', 'setting_id')) {
                $table->unsignedBigInteger('setting_id')->nullable()->after('id');
                $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            }
        });
        Schema::table('categories', function (Blueprint $table) {
            if (!Schema::hasColumn('categories', 'setting_id')) {
                $table->unsignedBigInteger('setting_id')->nullable()->after('created_by');
                $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            }
        });

        try {
            // Seed legacy populated rows with NULL canonical fields and duplicate identities
            $parentSurvivor = (int) DB::table('categories')->insertGetId([
                'category_code' => 'MY-P1',
                'category_name' => 'ALFA INK',
                'normalized_name' => null,
                'parent_id' => null,
                'parent_namespace' => null,
                'created_by' => $user->id,
                'setting_id' => $settingId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $parentDup = (int) DB::table('categories')->insertGetId([
                'category_code' => 'MY-P2',
                'category_name' => '  alfa ink  ',
                'normalized_name' => null,
                'parent_id' => null,
                'parent_namespace' => null,
                'created_by' => $user->id,
                'setting_id' => $settingId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $childUnderDup = (int) DB::table('categories')->insertGetId([
                'category_code' => 'MY-C1',
                'category_name' => 'TINTA',
                'normalized_name' => null,
                'parent_id' => $parentDup,
                'parent_namespace' => null,
                'created_by' => $user->id,
                'setting_id' => $settingId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $brandSurvivor = (int) DB::table('brands')->insertGetId([
                'setting_id' => $settingId,
                'name' => 'EPSON',
                'normalized_name' => null,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $brandDup = (int) DB::table('brands')->insertGetId([
                'setting_id' => $settingId,
                'name' => 'epson',
                'normalized_name' => null,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $productId = (int) DB::table('products')->insertGetId([
                'setting_id' => $settingId,
                'category_id' => $parentDup,
                'brand_id' => $brandDup,
                'product_name' => 'MySQL Test Product',
                'product_code' => 'MY-PRD-1',
                'product_barcode_symbology' => 'C128',
                'product_quantity' => 1,
                'product_cost' => 1000,
                'product_price' => 2000,
                'product_unit' => 'PCS',
                'product_stock_alert' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 1. Finalizer preflight must reject while canonical fields are NULL and duplicates exist
            $finalizer = app(ProductTaxonomySchemaFinalizer::class);
            $this->assertNotEmpty($finalizer->preflight());

            // 2. Execute reconciliation: remaps products (SET NULL FK) and child categories (CASCADE FK)
            //    and backfills canonical fields before deleting duplicates.
            $reconciler = app(ProductTaxonomyReconciliationService::class);
            $plan = $reconciler->buildPlan();
            $this->assertTrue($plan['valid']);
            $result = $reconciler->execute($plan['plan_fingerprint'], 'mysql-test');
            $this->assertSame('completed', $result['status']);

            $this->assertSame($parentSurvivor, (int) DB::table('categories')->where('id', $childUnderDup)->value('parent_id'));
            $this->assertSame($parentSurvivor, (int) DB::table('products')->where('id', $productId)->value('category_id'));
            $this->assertSame($brandSurvivor, (int) DB::table('products')->where('id', $productId)->value('brand_id'));

            // 3. Simulate partial DDL failure (only brands unique index applied), then verify retry completes cleanly
            DB::statement('ALTER TABLE `brands` ADD UNIQUE INDEX `brands_normalized_name_unique` (`normalized_name`)');
            $this->assertSame([], $finalizer->preflight());
            $finalizer->finalize();

            $this->assertFalse(Schema::hasColumn('brands', 'setting_id'));
            $this->assertFalse(Schema::hasColumn('categories', 'setting_id'));

            // 4. Verify business deletion no longer cascades to surviving brands/categories
            DB::table('products')->where('id', $productId)->delete();
            DB::table('settings')->where('id', $settingId)->delete();
            $this->assertNotNull(DB::table('brands')->where('id', $brandSurvivor)->first());
            $this->assertNotNull(DB::table('categories')->where('id', $parentSurvivor)->first());
        } finally {
            app(ProductTaxonomySchemaFinalizer::class)->finalize();
        }
    }
}

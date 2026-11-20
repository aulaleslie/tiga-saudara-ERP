<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Tests\TestCase;

class ProductTaxonomyUpgradeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_installation_reaches_finalized_schema_automatically(): void
    {
        $this->assertTrue(Schema::hasColumn('brands', 'normalized_name'));
        $this->assertTrue(Schema::hasColumn('categories', 'normalized_name'));
        $this->assertTrue(Schema::hasColumn('categories', 'parent_namespace'));
        $this->assertTrue(Schema::hasTable('product_taxonomy_reconciliation_audits'));
        $this->assertFalse(Schema::hasColumn('brands', 'setting_id'));
        $this->assertFalse(Schema::hasColumn('categories', 'setting_id'));
    }

    public function test_populated_legacy_upgrade_and_recovery_from_partially_migrated_state(): void
    {
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
            'company_name' => 'Legacy Biz',
            'company_email' => 'legacy@example.com',
            'company_phone' => '0811111111',
            'default_currency_id' => $currencyId,
            'default_currency_position' => 'prefix',
            'notification_email' => 'legacy@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Simulate the exact partially migrated state of a populated legacy database:
        // 2026_11_20_100000 and 2026_11_20_100001 are already recorded in migrations,
        // canonical columns are nullable, unique indexes are not yet applied, and setting_id still exists.
        Schema::table('brands', function (Blueprint $table) use ($settingId) {
            $table->dropUnique('brands_normalized_name_unique');
            $table->unsignedBigInteger('setting_id')->default($settingId);
        });
        Schema::table('categories', function (Blueprint $table) use ($settingId) {
            $table->dropUnique('categories_parent_namespace_normalized_name_unique');
            $table->unsignedBigInteger('setting_id')->default($settingId);
        });

        DB::table('brands')->insert([
            [
                'setting_id' => $settingId,
                'name' => 'EPSON',
                'normalized_name' => null,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'setting_id' => $settingId,
                'name' => '  epson  ',
                'normalized_name' => null,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('categories')->insert([
            [
                'category_code' => 'LEG-1',
                'category_name' => 'LAPTOP',
                'normalized_name' => null,
                'parent_id' => null,
                'parent_namespace' => null,
                'created_by' => $user->id,
                'setting_id' => $settingId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category_code' => 'LEG-2',
                'category_name' => 'laptop',
                'normalized_name' => null,
                'parent_id' => null,
                'parent_namespace' => null,
                'created_by' => $user->id,
                'setting_id' => $settingId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 1. Ordinary `php artisan migrate` completes cleanly without deleting legacy rows
        $this->withoutMockingConsoleOutput();
        $this->assertSame(0, Artisan::call('migrate'));
        $this->assertSame(2, DB::table('brands')->count());
        $this->assertSame(2, DB::table('categories')->count());
        $this->assertTrue(Schema::hasColumn('brands', 'setting_id'));
        $this->assertTrue(Schema::hasColumn('categories', 'setting_id'));

        // 2. Finalization command refuses to run before reconciliation (preflight fails)
        $this->assertSame(1, Artisan::call('product:finalize-taxonomy', ['--check' => true]));
        $this->assertSame(1, Artisan::call('product:finalize-taxonomy'));
        $this->assertTrue(Schema::hasColumn('brands', 'setting_id'));

        // 3. Application writes remain compatible during the preparation phase
        session(['setting_id' => $settingId]);
        $prepBrand = Brand::create(['name' => 'Brother', 'created_by' => $user->id]);
        $prepCat = Category::create([
            'category_code' => 'LEG-3',
            'category_name' => 'Printer',
            'created_by' => $user->id,
        ]);
        $this->assertSame($settingId, (int) DB::table('brands')->where('id', $prepBrand->id)->value('setting_id'));
        $this->assertSame($settingId, (int) DB::table('categories')->where('id', $prepCat->id)->value('setting_id'));

        // 4. Preview (read-only) -> Confirmed Reconciliation
        $this->assertSame(0, Artisan::call('product:reconcile-taxonomy', ['--json' => true]));
        $plan = json_decode(Artisan::output(), true);
        $this->assertTrue($plan['valid']);

        $this->assertSame(0, Artisan::call('product:reconcile-taxonomy', [
            '--execute' => true,
            '--confirm' => true,
            '--fingerprint' => $plan['plan_fingerprint'],
            '--operator' => 'upgrade-test',
        ]));

        // 5. Explicit finalization now passes preflight, drops setting_id, and is idempotent
        $this->assertSame(0, Artisan::call('product:finalize-taxonomy', ['--check' => true]));
        $this->assertSame(0, Artisan::call('product:finalize-taxonomy'));
        $this->assertFalse(Schema::hasColumn('brands', 'setting_id'));
        $this->assertFalse(Schema::hasColumn('categories', 'setting_id'));

        // Idempotent retry
        $this->assertSame(0, Artisan::call('product:finalize-taxonomy'));

        // 6. Application writes continue working after finalization
        $postBrand = Brand::create(['name' => 'Canon', 'created_by' => $user->id]);
        $postCat = Category::create([
            'category_code' => 'POST-1',
            'category_name' => 'Scanner',
            'created_by' => $user->id,
        ]);
        $this->assertNotNull($postBrand->id);
        $this->assertNotNull($postCat->id);
    }
}

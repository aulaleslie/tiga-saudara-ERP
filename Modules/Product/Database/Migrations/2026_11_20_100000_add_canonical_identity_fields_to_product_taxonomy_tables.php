<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMysql = in_array(DB::connection($this->getConnection())->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::table('brands', function (Blueprint $table) use ($isMysql) {
            if (!Schema::hasColumn('brands', 'normalized_name')) {
                $column = $table->string('normalized_name', 255)->nullable()->after('name');
                if ($isMysql) {
                    $column->collation('utf8mb4_bin');
                }
                $table->index('normalized_name', 'brands_normalized_name_idx');
            }
        });

        Schema::table('categories', function (Blueprint $table) use ($isMysql) {
            if (!Schema::hasColumn('categories', 'normalized_name')) {
                $column = $table->string('normalized_name', 255)->nullable()->after('category_name');
                if ($isMysql) {
                    $column->collation('utf8mb4_bin');
                }
            }
            if (!Schema::hasColumn('categories', 'parent_namespace')) {
                $table->unsignedBigInteger('parent_namespace')->nullable()->after('parent_id');
            }
            $table->index(['parent_namespace', 'normalized_name'], 'categories_parent_namespace_normalized_name_idx');
        });

        // Fresh installations (zero existing brands and categories) can safely reach
        // the finalized global schema immediately. Populated databases remain in the
        // preparation phase until reviewed reconciliation and explicit finalization run.
        $db = DB::connection($this->getConnection());
        if ($db->table('brands')->count() === 0 && $db->table('categories')->count() === 0) {
            app(\Modules\Product\Services\ProductTaxonomySchemaFinalizer::class)->finalize($this->getConnection());
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'normalized_name') || Schema::hasColumn('categories', 'parent_namespace')) {
                $table->dropIndex('categories_parent_namespace_normalized_name_idx');
            }
            if (Schema::hasColumn('categories', 'parent_namespace')) {
                $table->dropColumn('parent_namespace');
            }
            if (Schema::hasColumn('categories', 'normalized_name')) {
                $table->dropColumn('normalized_name');
            }
        });

        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'normalized_name')) {
                $table->dropIndex('brands_normalized_name_idx');
                $table->dropColumn('normalized_name');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_import_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('product_import_batches', 'import_type')) {
                $table->string('import_type', 50)->default('product')->after('status');
            }
        });

        Schema::table('product_import_rows', function (Blueprint $table) {
            if (!Schema::hasColumn('product_import_rows', 'result_metadata')) {
                $table->json('result_metadata')->nullable()->after('created_stock_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_import_batches', function (Blueprint $table) {
            if (Schema::hasColumn('product_import_batches', 'import_type')) {
                $table->dropColumn('import_type');
            }
        });

        Schema::table('product_import_rows', function (Blueprint $table) {
            if (Schema::hasColumn('product_import_rows', 'result_metadata')) {
                $table->dropColumn('result_metadata');
            }
        });
    }
};

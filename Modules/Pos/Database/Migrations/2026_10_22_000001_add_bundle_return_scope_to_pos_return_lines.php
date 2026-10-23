<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('pos_return_lines')) {
            return;
        }

        Schema::table('pos_return_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_return_lines', 'bundle_return_scope')) {
                $table->string('bundle_return_scope')->nullable()->index()->after('bundle_parent_sale_detail_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('pos_return_lines')) {
            return;
        }

        Schema::table('pos_return_lines', function (Blueprint $table) {
            if (Schema::hasColumn('pos_return_lines', 'bundle_return_scope')) {
                $table->dropColumn('bundle_return_scope');
            }
        });
    }
};

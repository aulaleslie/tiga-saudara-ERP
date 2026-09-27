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
        if (! Schema::hasTable('pos_returns')) {
            return;
        }

        Schema::table('pos_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_returns', 'deleted_at')) {
                $table->softDeletes();
            }

            if (! Schema::hasColumn('pos_returns', 'deleted_by')) {
                $table->unsignedBigInteger('deleted_by')->nullable()->index()->after('updated_at');
            }

            if (! Schema::hasColumn('pos_returns', 'delete_reason')) {
                $table->text('delete_reason')->nullable()->after('deleted_by');
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
        if (! Schema::hasTable('pos_returns')) {
            return;
        }

        Schema::table('pos_returns', function (Blueprint $table) {
            if (Schema::hasColumn('pos_returns', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            $columns = [];

            foreach (['deleted_by', 'delete_reason'] as $column) {
                if (Schema::hasColumn('pos_returns', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

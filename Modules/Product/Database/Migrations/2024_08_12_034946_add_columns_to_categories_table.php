<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('category_name');
            $table->unsignedBigInteger('created_by')->after('parent_id');
            $table->unsignedBigInteger('setting_id')->after('created_by');

            // Add foreign key constraints
            $table->foreign('parent_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Drop the foreign key constraints first
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['setting_id']);

            // Then drop the columns
            $table->dropColumn('parent_id');
            $table->dropColumn('created_by');
            $table->dropColumn('setting_id');
        });
    }
};

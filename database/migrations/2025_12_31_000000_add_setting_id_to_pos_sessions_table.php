<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('setting_id')->nullable()->after('user_id');
            // Assuming the settings table is named 'settings' or similar. 
            // Based on investigation, I will leave it as an indexed column first 
            // because exact table name for Setting model in Modules might be different 
            // (e.g. modules_settings_settings) and foreign key might fail if I guess wrong.
            // But usually indexes are good enough for scoping.
            // Wait, I should double check the table name for Setting model.
            $table->index('setting_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->dropColumn('setting_id');
        });
    }
};

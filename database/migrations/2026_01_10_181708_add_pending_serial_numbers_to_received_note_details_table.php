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
        Schema::table('received_note_details', function (Blueprint $table) {
            $table->json('pending_serial_numbers')->nullable()->after('quantity_received');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('received_note_details', function (Blueprint $table) {
            $table->dropColumn('pending_serial_numbers');
        });
    }
};

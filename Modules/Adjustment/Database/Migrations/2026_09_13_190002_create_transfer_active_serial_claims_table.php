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
        if (Schema::hasTable('transfer_movement_serials')) {
            Schema::table('transfer_movement_serials', function (Blueprint $table) {
                if (!Schema::hasColumn('transfer_movement_serials', 'custody_started_at')) {
                    $table->timestamp('custody_started_at')->nullable()->after('transit_custody_status');
                }
                if (!Schema::hasColumn('transfer_movement_serials', 'custody_closed_at')) {
                    $table->timestamp('custody_closed_at')->nullable()->after('custody_started_at');
                }
                if (!Schema::hasColumn('transfer_movement_serials', 'origin_location_id')) {
                    $table->foreignId('origin_location_id')->nullable()->after('custody_closed_at')->constrained('locations')->nullOnDelete();
                }
                if (!Schema::hasColumn('transfer_movement_serials', 'destination_location_id')) {
                    $table->foreignId('destination_location_id')->nullable()->after('origin_location_id')->constrained('locations')->nullOnDelete();
                }
            });
        }

        if (!Schema::hasTable('transfer_active_serial_claims')) {
            Schema::create('transfer_active_serial_claims', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_serial_number_id');
                $table->unsignedBigInteger('transfer_movement_id');
                $table->unsignedBigInteger('transfer_movement_serial_id');
                $table->timestamps();

                $table->foreign('product_serial_number_id', 'fk_tasc_serial')
                    ->references('id')
                    ->on('product_serial_numbers')
                    ->onDelete('restrict');

                $table->foreign('transfer_movement_id', 'fk_tasc_movement')
                    ->references('id')
                    ->on('transfer_movements')
                    ->onDelete('cascade');

                $table->foreign('transfer_movement_serial_id', 'fk_tasc_serial_row')
                    ->references('id')
                    ->on('transfer_movement_serials')
                    ->onDelete('cascade');

                $table->unique(['product_serial_number_id'], 'uniq_tasc_serial');
                $table->index('transfer_movement_id', 'idx_tasc_movement');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_active_serial_claims');

        if (Schema::hasTable('transfer_movement_serials')) {
            Schema::table('transfer_movement_serials', function (Blueprint $table) {
                $columns = [
                    'custody_started_at',
                    'custody_closed_at',
                    'origin_location_id',
                    'destination_location_id',
                ];

                foreach ($columns as $column) {
                    if (Schema::hasColumn('transfer_movement_serials', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};

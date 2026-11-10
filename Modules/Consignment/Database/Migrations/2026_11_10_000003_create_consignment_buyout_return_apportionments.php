<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Explicit acquired/unacquired apportionment of a customer return detail against a sold source that has a
        // finalized sold-unbilled buyout. DECLARED = operator mapping before receiving; APPLIED = used by receiving.
        Schema::create('consignment_buyout_return_apportionments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('sale_return_detail_id');
            $table->unsignedBigInteger('sale_return_id')->nullable();
            $table->unsignedBigInteger('dispatch_detail_id');
            $table->unsignedBigInteger('consignment_sold_source_id');
            $table->string('status', 20)->default('APPLIED'); // DECLARED, APPLIED
            $table->string('origin', 20); // NONE, ALL, EXPLICIT, SERIAL
            $table->decimal('returned_quantity', 15, 3)->default(0);
            $table->decimal('acquired_quantity', 15, 3)->default(0);   // serial + non-serialized acquired units
            $table->decimal('unacquired_quantity', 15, 3)->default(0);
            $table->json('acquired_serial_ids')->nullable();
            $table->json('allocations')->nullable();                    // [{line_id, quantity, destination_location_id}]
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamps();

            $table->foreign('setting_id', 'fk_cbra2_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('consignment_sold_source_id', 'fk_cbra2_source')->references('id')->on('consignment_sold_sources')->onDelete('restrict');
            $table->foreign('actor_id', 'fk_cbra2_actor')->references('id')->on('users')->nullOnDelete();

            $table->unique(['sale_return_detail_id'], 'uniq_cbra2_return_detail');
            $table->index(['consignment_sold_source_id', 'status'], 'idx_cbra2_source_status');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('consignment_buyout_return_apportionments') && DB::table('consignment_buyout_return_apportionments')->exists()) {
            throw new \RuntimeException('Refusing to roll back: customer-return apportionment evidence exists.');
        }
        Schema::dropIfExists('consignment_buyout_return_apportionments');
    }
};

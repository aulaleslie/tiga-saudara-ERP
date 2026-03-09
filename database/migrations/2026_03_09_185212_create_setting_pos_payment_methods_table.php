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
        Schema::create('setting_pos_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->constrained('settings')->onDelete('cascade');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->onDelete('cascade');
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->unique(['setting_id', 'payment_method_id'], 'setting_payment_method_unique');
            $table->index(['setting_id', 'is_enabled', 'payment_method_id'], 'setting_payment_method_idx');
        });

        // Backfill
        $settings = \Illuminate\Support\Facades\DB::table('settings')->pluck('id');
        $paymentMethods = \Illuminate\Support\Facades\DB::table('payment_methods')->pluck('id');
        
        $now = now();
        $payload = [];
        
        foreach ($settings as $settingId) {
            foreach ($paymentMethods as $paymentMethodId) {
                $payload[] = [
                    'setting_id' => $settingId,
                    'payment_method_id' => $paymentMethodId,
                    'is_enabled' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (count($payload) > 0) {
            $chunks = array_chunk($payload, 500);
            foreach ($chunks as $chunk) {
                \Illuminate\Support\Facades\DB::table('setting_pos_payment_methods')->insertOrIgnore($chunk);
            }
        }

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('is_available_in_pos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('is_available_in_pos')->default(false);
        });

        Schema::dropIfExists('setting_pos_payment_methods');
    }
};

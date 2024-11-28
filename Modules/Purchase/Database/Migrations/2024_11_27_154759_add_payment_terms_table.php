<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('setting_id'); // Foreign key to settings table
            $table->string('name');
            $table->integer('longevity')->default(0);
            $table->timestamps();

            // Define the foreign key constraint
            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
        });

        // Seed the table with the provided data
        $this->seedPaymentTerms();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }

    /**
     * Seed the payment_terms table.
     */
    private function seedPaymentTerms(): void
    {
        // Get the first setting_id from the settings table
        $firstSettingId = DB::table('settings')->value('id');

        if (!$firstSettingId) {
            // If no settings are available, exit the seed process
            return;
        }

        $paymentTerms = [
            ['id' => 858231, 'setting_id' => $firstSettingId, 'name' => 'Net 30', 'longevity' => 30],
            ['id' => 858232, 'setting_id' => $firstSettingId, 'name' => 'Cash on Delivery', 'longevity' => 0],
            ['id' => 858233, 'setting_id' => $firstSettingId, 'name' => 'Net 15', 'longevity' => 15],
            ['id' => 858234, 'setting_id' => $firstSettingId, 'name' => 'Net 60', 'longevity' => 60],
            ['id' => 858235, 'setting_id' => $firstSettingId, 'name' => 'Custom', 'longevity' => 0],
            ['id' => 873940, 'setting_id' => $firstSettingId, 'name' => 'Term 14 hari', 'longevity' => 14],
            ['id' => 898556, 'setting_id' => $firstSettingId, 'name' => 'net 21', 'longevity' => 21],
            ['id' => 1188378, 'setting_id' => $firstSettingId, 'name' => 'NET 7 HARI', 'longevity' => 7],
            ['id' => 1726493, 'setting_id' => $firstSettingId, 'name' => '45 HR', 'longevity' => 45],
            ['id' => 2353345, 'setting_id' => $firstSettingId, 'name' => '10 HARI', 'longevity' => 10],
            ['id' => 2627421, 'setting_id' => $firstSettingId, 'name' => '24 HR', 'longevity' => 24],
            ['id' => 2917154, 'setting_id' => $firstSettingId, 'name' => '28HR', 'longevity' => 28],
        ];

        DB::table('payment_terms')->insert($paymentTerms);
    }
};

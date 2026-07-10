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
        Schema::create('barcode_identities', function (Blueprint $table) {
            $table->id();
            $table->string('canonical_key')->unique();
            $table->string('value');
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_unit_conversion_id')->nullable()->constrained('product_unit_conversions')->cascadeOnDelete();
            $table->timestamps();
        });

        if (in_array(\Illuminate\Support\Facades\DB::getDriverName(), ['mysql', 'pgsql'])) {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE barcode_identities ADD CONSTRAINT chk_single_owner CHECK ((product_id IS NULL AND product_unit_conversion_id IS NOT NULL) OR (product_id IS NOT NULL AND product_unit_conversion_id IS NULL))');
        } elseif (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            \Illuminate\Support\Facades\DB::unprepared("
                CREATE TRIGGER chk_single_owner_insert BEFORE INSERT ON barcode_identities
                FOR EACH ROW
                WHEN (NEW.product_id IS NULL AND NEW.product_unit_conversion_id IS NULL) 
                  OR (NEW.product_id IS NOT NULL AND NEW.product_unit_conversion_id IS NOT NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Must have exactly one owner');
                END;
            ");
            \Illuminate\Support\Facades\DB::unprepared("
                CREATE TRIGGER chk_single_owner_update BEFORE UPDATE ON barcode_identities
                FOR EACH ROW
                WHEN (NEW.product_id IS NULL AND NEW.product_unit_conversion_id IS NULL) 
                  OR (NEW.product_id IS NOT NULL AND NEW.product_unit_conversion_id IS NOT NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Must have exactly one owner');
                END;
            ");
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('barcode_identities');
    }
};

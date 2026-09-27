<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductSerialNumber;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('product_serial_numbers')
            ->whereNotNull('dispatch_detail_id')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereRaw('UPPER(status) = ?', [ProductSerialNumber::STATUS_ACTIVE]);
            })
            ->update([
                'status' => ProductSerialNumber::STATUS_SOLD,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally no-op. Reversing this backfill would be destructive because
        // we cannot safely distinguish original ACTIVE rows from legitimately SOLD rows.
    }
};

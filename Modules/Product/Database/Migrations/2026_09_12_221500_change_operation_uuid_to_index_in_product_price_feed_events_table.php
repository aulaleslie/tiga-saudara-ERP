<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_price_feed_events', function (Blueprint $table) {
            $table->dropUnique('product_price_feed_events_operation_uuid_unique');
            $table->index('operation_uuid', 'product_price_feed_events_operation_uuid_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // To restore uniqueness safely without failing on existing shared operation UUIDs,
        // assign fresh distinct UUIDs to duplicate rows before recreating the unique index.
        $duplicates = DB::table('product_price_feed_events')
            ->select('operation_uuid')
            ->groupBy('operation_uuid')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('operation_uuid');

        foreach ($duplicates as $opUuid) {
            $records = DB::table('product_price_feed_events')
                ->where('operation_uuid', $opUuid)
                ->orderBy('id')
                ->get();

            // Keep first record unchanged, assign fresh UUID to subsequent duplicates
            $first = true;
            foreach ($records as $record) {
                if ($first) {
                    $first = false;
                    continue;
                }
                DB::table('product_price_feed_events')
                    ->where('id', $record->id)
                    ->update(['operation_uuid' => (string) Str::uuid()]);
            }
        }

        Schema::table('product_price_feed_events', function (Blueprint $table) {
            $table->dropIndex('product_price_feed_events_operation_uuid_index');
            $table->unique('operation_uuid', 'product_price_feed_events_operation_uuid_unique');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('transfers', 'document_number')) {
                $table->string('document_number')->nullable()->after('id');
            }
        });

        DB::transaction(function () {
            $counters = [];

            DB::table('transfers')
                ->select(
                    'transfers.id',
                    'transfers.created_at',
                    'transfers.origin_location_id',
                    'locations.setting_id as origin_setting_id'
                )
                ->leftJoin('locations', 'locations.id', '=', 'transfers.origin_location_id')
                ->orderBy('transfers.id')
                ->chunkById(100, function ($rows) use (&$counters) {
                    foreach ($rows as $row) {
                        $timestamp = $row->created_at ? Carbon::parse($row->created_at) : now();
                        $year      = $timestamp->format('Y');
                        $month     = $timestamp->format('m');

                        $settingKey = $row->origin_setting_id ?? ('origin:' . (int) $row->origin_location_id);
                        $counterKey = sprintf('%s-%s-%s', $settingKey, $year, $month);

                        $counters[$counterKey] = ($counters[$counterKey] ?? 0) + 1;

                        $documentNumber = sprintf('TS-%s-%s-%04d', $year, $month, $counters[$counterKey]);

                        DB::table('transfers')
                            ->where('id', $row->id)
                            ->update(['document_number' => $documentNumber]);
                    }
                }, 'transfers.id', 'transfers_id');
        });

        Schema::table('transfers', function (Blueprint $table) {
            if (Schema::hasColumn('transfers', 'document_number')) {
                $table->unique('document_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (Schema::hasColumn('transfers', 'document_number')) {
                $table->dropUnique('transfers_document_number_unique');
                $table->dropColumn('document_number');
            }
        });
    }
};

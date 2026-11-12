<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory-owner business on buyout evidence (track-transferred-consignment-provenance 1.2).
 *
 * consignment_buyouts.setting_id stays the supplier billing business. A NULL
 * inventory_owner_setting_id on legacy rows means "same as the header setting",
 * so existing local-only buyouts remain valid without backfill.
 * Cost events were already setting-scoped; their uniqueness now includes the
 * owner setting so one buyout buying a product in two businesses yields two events.
 */
return new class extends Migration
{
    private const OWNER_TABLES = [
        'consignment_buyout_lines' => 'fk_cbol_owner_setting',
        'consignment_buyout_purchase_lineages' => 'fk_cbpl_owner_setting',
        'consignment_buyout_movements' => 'fk_cbm_owner_setting',
        'consignment_buyout_ownership_events' => 'fk_cboe_owner_setting',
    ];

    public function up(): void
    {
        foreach (self::OWNER_TABLES as $table => $fk) {
            Schema::table($table, function (Blueprint $t) use ($fk) {
                $t->unsignedBigInteger('inventory_owner_setting_id')->nullable();
                $t->foreign('inventory_owner_setting_id', $fk)->references('id')->on('settings')->onDelete('restrict');
            });
        }

        Schema::table('consignment_buyout_cost_events', function (Blueprint $t) {
            $t->unique(['consignment_buyout_id', 'setting_id', 'product_id', 'kind'], 'uniq_cbce_buyout_owner_product_kind');
        });
        Schema::table('consignment_buyout_cost_events', function (Blueprint $t) {
            $t->dropUnique('uniq_cbce_buyout_product_kind');
        });
    }

    public function down(): void
    {
        $remote = DB::table('consignment_buyout_lines')->whereNotNull('inventory_owner_setting_id')
            ->join('consignment_buyouts', 'consignment_buyouts.id', '=', 'consignment_buyout_lines.consignment_buyout_id')
            ->whereColumn('consignment_buyout_lines.inventory_owner_setting_id', '!=', 'consignment_buyouts.setting_id')
            ->exists();
        $multiOwner = DB::table('consignment_buyout_cost_events')
            ->select('consignment_buyout_id', 'product_id', 'kind')
            ->groupBy('consignment_buyout_id', 'product_id', 'kind')
            ->havingRaw('COUNT(*) > 1')->exists();
        if ($remote || $multiOwner) {
            throw new RuntimeException('Refusing to roll back: remote inventory-owner buyout evidence exists. Forward-fix instead.');
        }

        Schema::table('consignment_buyout_cost_events', function (Blueprint $t) {
            $t->unique(['consignment_buyout_id', 'product_id', 'kind'], 'uniq_cbce_buyout_product_kind');
        });
        Schema::table('consignment_buyout_cost_events', function (Blueprint $t) {
            $t->dropUnique('uniq_cbce_buyout_owner_product_kind');
        });

        foreach (self::OWNER_TABLES as $table => $fk) {
            if (DB::getDriverName() === 'sqlite') {
                // Native drop: no FK was attached on SQLite, and a schema-builder rebuild would trip parent FKs.
                DB::statement("ALTER TABLE {$table} DROP COLUMN inventory_owner_setting_id");
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($fk) {
                $t->dropForeign($fk);
                $t->dropColumn('inventory_owner_setting_id');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only ledger of EVERY change to a shared carrying average (product_prices.average_purchase_price), written
     * by database triggers so ordinary Eloquent saves, mass updates, imports and raw statements are all captured.
     * Buyout reversal uses it to detect intervening cost events even when the average later returns to the same value.
     */
    public function up(): void
    {
        // MySQL DDL implicitly commits, so this migration must be re-runnable after a partial failure (for example a
        // refused CREATE TRIGGER): create the table only when missing and drop any half-installed trigger first.
        if (! Schema::hasTable('product_price_cost_events')) {
            Schema::create('product_price_cost_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('setting_id')->nullable();
                $table->decimal('old_average', 15, 2)->nullable();
                $table->decimal('new_average', 15, 2)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['product_id', 'setting_id', 'id'], 'idx_ppce_product_setting');
            });
        }
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ppce_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ppce_update');

        try {
            $this->installTriggers();
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '1419')) {
                throw new \RuntimeException('Cannot create the cost-event ledger triggers: MySQL has binary logging enabled and the migration user lacks '
                    . 'SUPER/SET_USER_ID. Run this migration as a privileged user or set log_bin_trust_function_creators=1 for the migration, '
                    . 'then re-run it (it is safe to re-run). See openspec/changes/buy-out-unsold-consignment-stock/uat-and-deployment.md.', 0, $e);
            }
            throw $e;
        }
    }

    private function installTriggers(): void
    {

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER trg_ppce_insert AFTER INSERT ON product_prices
                WHEN NEW.average_purchase_price IS NOT NULL
                BEGIN INSERT INTO product_price_cost_events (product_id, setting_id, old_average, new_average, created_at)
                      VALUES (NEW.product_id, NEW.setting_id, NULL, NEW.average_purchase_price, CURRENT_TIMESTAMP); END");
            DB::unprepared("CREATE TRIGGER trg_ppce_update AFTER UPDATE ON product_prices
                WHEN OLD.average_purchase_price IS NOT NEW.average_purchase_price
                BEGIN INSERT INTO product_price_cost_events (product_id, setting_id, old_average, new_average, created_at)
                      VALUES (NEW.product_id, NEW.setting_id, OLD.average_purchase_price, NEW.average_purchase_price, CURRENT_TIMESTAMP); END");
        } else {
            DB::unprepared("CREATE TRIGGER trg_ppce_insert AFTER INSERT ON product_prices FOR EACH ROW
                BEGIN IF NEW.average_purchase_price IS NOT NULL THEN
                    INSERT INTO product_price_cost_events (product_id, setting_id, old_average, new_average, created_at)
                    VALUES (NEW.product_id, NEW.setting_id, NULL, NEW.average_purchase_price, NOW());
                END IF; END");
            DB::unprepared("CREATE TRIGGER trg_ppce_update AFTER UPDATE ON product_prices FOR EACH ROW
                BEGIN IF NOT (OLD.average_purchase_price <=> NEW.average_purchase_price) THEN
                    INSERT INTO product_price_cost_events (product_id, setting_id, old_average, new_average, created_at)
                    VALUES (NEW.product_id, NEW.setting_id, OLD.average_purchase_price, NEW.average_purchase_price, NOW());
                END IF; END");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('consignment_buyout_cost_events') && DB::table('consignment_buyout_cost_events')->whereNotNull('price_event_id')->exists()) {
            throw new \RuntimeException('Refusing to drop the cost-event ledger: buyout cost evidence references it.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ppce_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ppce_update');
        Schema::dropIfExists('product_price_cost_events');
    }
};

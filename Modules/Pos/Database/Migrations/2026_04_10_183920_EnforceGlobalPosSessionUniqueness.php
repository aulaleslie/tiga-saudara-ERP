<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
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
        if (! Schema::hasTable('pos_sessions')) {
            return;
        }

        if (! $this->hasColumns('pos_sessions', ['cashier_user_id', 'active_marker'])) {
            return;
        }

        $this->dropUniqueIfExists('pos_sessions', 'pos_sessions_user_active_unique');

        // Ensure no duplicates exist before applying the global unique constraint.
        // If duplicates are found, we keep the latest one active and close the others.
        $this->ensureNoActiveDuplicates();

        $this->addUniqueIfMissing(
            'pos_sessions',
            ['cashier_user_id', 'active_marker'],
            'pos_sessions_global_active_user_unique'
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('pos_sessions')) {
            return;
        }

        if (! $this->hasColumns('pos_sessions', ['cashier_user_id', 'active_marker'])) {
            return;
        }

        $this->dropUniqueIfExists('pos_sessions', 'pos_sessions_global_active_user_unique');

        if (! $this->hasColumns('pos_sessions', ['setting_id', 'cashier_user_id', 'active_marker'])) {
            return;
        }

        $this->addUniqueIfMissing(
            'pos_sessions',
            ['setting_id', 'cashier_user_id', 'active_marker'],
            'pos_sessions_user_active_unique'
        );
    }

    private function ensureNoActiveDuplicates(): void
    {
        if (! $this->hasColumns('pos_sessions', ['id', 'cashier_user_id', 'active_marker', 'opened_at', 'status', 'closed_at', 'close_notes'])) {
            return;
        }

        $duplicates = DB::table('pos_sessions')
            ->whereNotNull('active_marker')
            ->select('cashier_user_id', DB::raw('COUNT(*) as count'))
            ->groupBy('cashier_user_id')
            ->having('count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $sessions = DB::table('pos_sessions')
                ->where('cashier_user_id', $duplicate->cashier_user_id)
                ->whereNotNull('active_marker')
                ->orderBy('opened_at', 'desc')
                ->get();

            // Keep the latest session active
            $sessions->shift();
            
            // Collect IDs of sessions to close
            $idsToClose = [];
            foreach ($sessions as $session) {
                $idsToClose[] = $session->id;
            }

            if (!empty($idsToClose)) {
                DB::table('pos_sessions')
                    ->whereIn('id', $idsToClose)
                    ->update([
                        'active_marker' => null,
                        'status' => 'CLOSED',
                        'closed_at' => now(),
                        'close_notes' => 'Force closed by global uniqueness migration cleanup.'
                    ]);
            }
        }
    }

    private function dropUniqueIfExists(string $tableName, string $indexName): void
    {
        if (! $this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName) {
            $table->dropUnique($indexName);
        });
    }

    private function addUniqueIfMissing(string $tableName, array $columns, string $indexName): void
    {
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->unique($columns, $indexName);
        });
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $tableName)
                ->where('index_name', $indexName)
                ->exists();
        }

        if ($driver === 'pgsql') {
            return DB::table('pg_indexes')
                ->where('schemaname', 'public')
                ->where('tablename', $tableName)
                ->where('indexname', $indexName)
                ->exists();
        }

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$tableName}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function hasColumns(string $tableName, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return false;
            }
        }

        return true;
    }
};

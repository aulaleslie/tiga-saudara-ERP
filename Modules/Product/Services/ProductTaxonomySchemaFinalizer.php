<?php

namespace Modules\Product\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProductTaxonomySchemaFinalizer
{
    /**
     * Preflight that canonical backfill is complete and zero conflicts exist.
     *
     * @return array<int, string> List of blockers (empty when ready)
     */
    public function preflight(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $schema = Schema::connection($connection);
        $blockers = [];

        if (!$schema->hasColumn('brands', 'normalized_name')) {
            $blockers[] = 'brands.normalized_name column is missing; run preparation migrations first.';
        } else {
            $nullBrands = $db->table('brands')
                ->where(fn ($q) => $q->whereNull('normalized_name')->orWhere('normalized_name', ''))
                ->count();
            if ($nullBrands > 0) {
                $blockers[] = sprintf('%d brand(s) have NULL or empty normalized_name. Run product:reconcile-taxonomy first.', $nullBrands);
            }

            $dupBrands = $db->table('brands')
                ->select('normalized_name', DB::raw('COUNT(*) as cnt'))
                ->whereNotNull('normalized_name')
                ->groupBy('normalized_name')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            if ($dupBrands->isNotEmpty()) {
                $names = $dupBrands->pluck('normalized_name')->implode(', ');
                $blockers[] = sprintf('Duplicate brand normalized_name values remain (including deleted): %s.', $names);
            }
        }

        if (!$schema->hasColumn('categories', 'normalized_name') || !$schema->hasColumn('categories', 'parent_namespace')) {
            $blockers[] = 'categories canonical columns are missing; run preparation migrations first.';
        } else {
            $nullCats = $db->table('categories')
                ->where(fn ($q) => $q->whereNull('normalized_name')->orWhere('normalized_name', '')->orWhereNull('parent_namespace'))
                ->count();
            if ($nullCats > 0) {
                $blockers[] = sprintf('%d category(ies) have NULL/empty normalized_name or parent_namespace. Run product:reconcile-taxonomy first.', $nullCats);
            }

            $dupCats = $db->table('categories')
                ->select('parent_namespace', 'normalized_name', DB::raw('COUNT(*) as cnt'))
                ->whereNotNull('normalized_name')
                ->whereNotNull('parent_namespace')
                ->groupBy('parent_namespace', 'normalized_name')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            if ($dupCats->isNotEmpty()) {
                $keys = $dupCats->map(fn ($r) => $r->parent_namespace . ':' . $r->normalized_name)->implode(', ');
                $blockers[] = sprintf('Duplicate category (parent_namespace, normalized_name) values remain: %s.', $keys);
            }
        }

        return $blockers;
    }

    /**
     * Idempotently finalize unique constraints and drop legacy setting_id ownership
     * from brands and categories. Safe to retry after partial DDL failure.
     */
    public function finalize(?string $connection = null): void
    {
        $blockers = $this->preflight($connection);
        if ($blockers !== []) {
            throw new RuntimeException(
                "Cannot finalize global product taxonomy schema:\n- " . implode("\n- ", $blockers)
            );
        }

        $db = DB::connection($connection);
        $schema = Schema::connection($connection);
        $driver = $db->getDriverName();

        // 1. Add unique constraints (idempotent)
        if (!$this->indexExists($connection, 'brands', 'brands_normalized_name_unique')) {
            $schema->table('brands', function (Blueprint $table) {
                $table->unique('normalized_name', 'brands_normalized_name_unique');
            });
        }

        if (!$this->indexExists($connection, 'categories', 'categories_parent_namespace_normalized_name_unique')) {
            $schema->table('categories', function (Blueprint $table) {
                $table->unique(['parent_namespace', 'normalized_name'], 'categories_parent_namespace_normalized_name_unique');
            });
        }

        // 2. Drop legacy preparation non-unique indexes if present
        if ($this->indexExists($connection, 'brands', 'brands_normalized_name_idx')) {
            $schema->table('brands', function (Blueprint $table) {
                $table->dropIndex('brands_normalized_name_idx');
            });
        }

        if ($this->indexExists($connection, 'categories', 'categories_parent_namespace_normalized_name_idx')) {
            $schema->table('categories', function (Blueprint $table) {
                $table->dropIndex('categories_parent_namespace_normalized_name_idx');
            });
        }

        // 3. Enforce NOT NULL on canonical columns on MySQL/MariaDB
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $db->statement('ALTER TABLE `brands` MODIFY `normalized_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
            $db->statement('ALTER TABLE `categories` MODIFY `normalized_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
            $db->statement('ALTER TABLE `categories` MODIFY `parent_namespace` BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }

        // 4. Drop setting_id foreign keys and columns from brands and categories (idempotent)
        if ($schema->hasColumn('brands', 'setting_id')) {
            $this->dropForeignKeysForColumn($connection, 'brands', 'setting_id');
            $schema->table('brands', function (Blueprint $table) {
                $table->dropColumn('setting_id');
            });
        }

        if ($schema->hasColumn('categories', 'setting_id')) {
            $this->dropForeignKeysForColumn($connection, 'categories', 'setting_id');
            $schema->table('categories', function (Blueprint $table) {
                $table->dropColumn('setting_id');
            });
        }
    }

    private function indexExists(?string $connection, string $table, string $indexName): bool
    {
        $db = DB::connection($connection);
        $driver = $db->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $rows = $db->select(
                'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [$db->getDatabaseName(), $table, $indexName]
            );
            return $rows !== [];
        }

        if ($driver === 'sqlite') {
            $rows = $db->select("SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ? LIMIT 1", [$table, $indexName]);
            return $rows !== [];
        }

        return false;
    }

    private function dropForeignKeysForColumn(?string $connection, string $table, string $column): void
    {
        $db = DB::connection($connection);
        $driver = $db->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $rows = $db->select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$db->getDatabaseName(), $table, $column]
            );
            foreach ($rows as $row) {
                $db->statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $row->CONSTRAINT_NAME));
            }
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite drops foreign keys automatically when table is rebuilt by dropColumn,
            // or we can rely on Laravel's Schema::table dropForeign if named.
            return;
        }
    }
}

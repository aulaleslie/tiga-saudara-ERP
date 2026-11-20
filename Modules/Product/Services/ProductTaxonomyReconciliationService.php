<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class ProductTaxonomyReconciliationService
{
    public function __construct(
        private readonly ProductTaxonomyCanonicalizer $canonicalizer,
        private readonly ProductTaxonomyHierarchyResolver $hierarchyResolver
    ) {
    }

    /**
     * Build a read-only reconciliation plan with deterministic fingerprint.
     *
     * @return array<string, mixed>
     */
    public function buildPlan(?string $connection = null, bool $lockForUpdate = false): array
    {
        $db = DB::connection($connection);
        $blockers = [];

        $unsupportedFks = $this->detectUnsupportedIncomingForeignKeys($connection);
        foreach ($unsupportedFks as $fk) {
            $blockers[] = sprintf(
                'Unsupported incoming foreign key: %s.%s references %s.%s.',
                $fk['table'],
                $fk['column'],
                $fk['referenced_table'],
                $fk['referenced_column']
            );
        }

        $categoryQuery = $db->table('categories')->orderBy('id');
        $brandQuery = $db->table('brands')->orderBy('id');
        if ($lockForUpdate) {
            $categoryQuery->lockForUpdate();
            $brandQuery->lockForUpdate();
        }

        $categoryRows = $categoryQuery->get()->map(fn ($r) => (array) $r)->all();
        $brandRows = $brandQuery->get()->map(fn ($r) => (array) $r)->all();

        $hierarchy = $this->hierarchyResolver->resolve($categoryRows);
        if (!$hierarchy['valid']) {
            foreach ($hierarchy['errors'] as $err) {
                $blockers[] = $err;
            }
        }

        $categoryById = [];
        foreach ($categoryRows as $row) {
            $categoryById[(int) $row['id']] = $row;
        }

        // Process categories
        $categoryDuplicateGroups = [];
        $categoryMappings = [];
        $redundantCategoryIds = [];
        $categoryMetadataConflicts = [];
        $categoriesNeedingBackfill = 0;

        if ($hierarchy['valid']) {
            foreach ($hierarchy['groups'] as $group) {
                $survivorId = $group['survivor_id'];
                if ($group['redundant_ids'] !== []) {
                    $conflicts = $this->detectCategoryMetadataConflicts($group['member_ids'], $categoryById);
                    if ($conflicts !== []) {
                        $categoryMetadataConflicts[$group['identity_key']] = $conflicts;
                    }

                    $categoryDuplicateGroups[] = [
                        'identity_key' => $group['identity_key'],
                        'normalized_name' => $group['normalized_name'],
                        'canonical_parent_id' => $group['canonical_parent_id'],
                        'parent_namespace' => $group['parent_namespace'],
                        'survivor_id' => $survivorId,
                        'survivor_code' => $group['survivor_code'],
                        'survivor_name' => $group['survivor_name'],
                        'member_ids' => $group['member_ids'],
                        'redundant_ids' => $group['redundant_ids'],
                        'metadata_conflicts' => $conflicts,
                    ];

                    foreach ($group['redundant_ids'] as $oldId) {
                        $redundantCategoryIds[] = $oldId;
                        $categoryMappings[$oldId] = $survivorId;
                    }
                }
            }

            foreach ($hierarchy['nodes'] as $id => $node) {
                if (isset($categoryMappings[$id])) {
                    continue;
                }
                $raw = $categoryById[$id];
                $currentNormalized = $raw['normalized_name'] ?? null;
                $currentNamespace = isset($raw['parent_namespace']) && $raw['parent_namespace'] !== null
                    ? (int) $raw['parent_namespace']
                    : null;
                if ($currentNormalized !== $node['normalized_name'] || $currentNamespace !== $node['parent_namespace']) {
                    $categoriesNeedingBackfill++;
                }
            }
        }

        sort($redundantCategoryIds, SORT_NUMERIC);
        ksort($categoryMappings, SORT_NUMERIC);

        // Process brands (active vs soft-deleted)
        $activeBrands = [];
        $deletedBrands = [];
        $brandById = [];
        foreach ($brandRows as $row) {
            $id = (int) $row['id'];
            $brandById[$id] = $row;
            if (($row['deleted_at'] ?? null) !== null) {
                $deletedBrands[] = $row;
            } else {
                $activeBrands[] = $row;
            }
        }

        $activeBrandGroups = [];
        $brandDuplicateGroups = [];
        $brandMappings = [];
        $redundantBrandIds = [];
        $brandMetadataConflicts = [];
        $brandsNeedingBackfill = 0;

        foreach ($activeBrands as $row) {
            $id = (int) $row['id'];
            try {
                $normalized = $this->canonicalizer->normalizeName((string) ($row['name'] ?? ''));
            } catch (InvalidArgumentException) {
                $blockers[] = sprintf('Active brand #%d has a blank or un-normalizable name ("%s").', $id, (string) ($row['name'] ?? ''));
                continue;
            }

            if (!isset($activeBrandGroups[$normalized])) {
                $activeBrandGroups[$normalized] = [
                    'normalized_name' => $normalized,
                    'survivor_id' => $id,
                    'survivor_name' => $row['name'],
                    'member_ids' => [],
                    'redundant_ids' => [],
                ];
            }

            $survivorId = $activeBrandGroups[$normalized]['survivor_id'];
            $activeBrandGroups[$normalized]['member_ids'][] = $id;
            if ($id !== $survivorId) {
                $activeBrandGroups[$normalized]['redundant_ids'][] = $id;
                $redundantBrandIds[] = $id;
                $brandMappings[$id] = $survivorId;
            } elseif (($row['normalized_name'] ?? null) !== $normalized) {
                $brandsNeedingBackfill++;
            }
        }

        foreach ($activeBrandGroups as $normalized => $group) {
            if ($group['redundant_ids'] !== []) {
                $conflicts = $this->detectBrandMetadataConflicts($group['member_ids'], $brandById);
                if ($conflicts !== []) {
                    $brandMetadataConflicts[$normalized] = $conflicts;
                }
                $brandDuplicateGroups[] = [
                    'normalized_name' => $normalized,
                    'survivor_id' => $group['survivor_id'],
                    'survivor_name' => $group['survivor_name'],
                    'member_ids' => $group['member_ids'],
                    'redundant_ids' => $group['redundant_ids'],
                    'metadata_conflicts' => $conflicts,
                ];
            }
        }

        // Report deleted brands separately and detect collisions
        $deletedBrandReports = [];
        $deletedByNormalized = [];
        $deletedBrandConflicts = [];

        foreach ($deletedBrands as $row) {
            $id = (int) $row['id'];
            try {
                $normalized = $this->canonicalizer->normalizeName((string) ($row['name'] ?? ''));
            } catch (InvalidArgumentException) {
                $blockers[] = sprintf('Deleted brand #%d has a blank or un-normalizable name ("%s").', $id, (string) ($row['name'] ?? ''));
                continue;
            }

            $deletedBrandReports[] = [
                'id' => $id,
                'name' => $row['name'],
                'normalized_name' => $normalized,
                'deleted_at' => $row['deleted_at'],
            ];

            if (($row['normalized_name'] ?? null) !== $normalized) {
                $brandsNeedingBackfill++;
            }

            if (isset($activeBrandGroups[$normalized])) {
                $msg = sprintf(
                    'Deleted brand #%d ("%s") collides with active brand survivor #%d ("%s"). Resolve explicitly before reconciliation.',
                    $id,
                    $row['name'],
                    $activeBrandGroups[$normalized]['survivor_id'],
                    $activeBrandGroups[$normalized]['survivor_name']
                );
                $deletedBrandConflicts[] = $msg;
                $blockers[] = $msg;
            }

            if (isset($deletedByNormalized[$normalized])) {
                $msg = sprintf(
                    'Deleted brand #%d ("%s") collides with deleted brand #%d on normalized name "%s". Resolve explicitly before reconciliation.',
                    $id,
                    $row['name'],
                    $deletedByNormalized[$normalized],
                    $normalized
                );
                $deletedBrandConflicts[] = $msg;
                $blockers[] = $msg;
            } else {
                $deletedByNormalized[$normalized] = $id;
            }
        }

        sort($redundantBrandIds, SORT_NUMERIC);
        ksort($brandMappings, SORT_NUMERIC);

        // Check orphan product references
        $categoryIdSet = array_keys($categoryById);
        $brandIdSet = array_keys($brandById);

        $orphanProductCategories = $db->table('products')
            ->whereNotNull('category_id')
            ->when($categoryIdSet !== [], fn ($q) => $q->whereNotIn('category_id', $categoryIdSet))
            ->count();
        if ($orphanProductCategories > 0) {
            $blockers[] = sprintf('%d product(s) reference non-existent category_id values.', $orphanProductCategories);
        }

        $orphanProductBrands = $db->table('products')
            ->whereNotNull('brand_id')
            ->when($brandIdSet !== [], fn ($q) => $q->whereNotIn('brand_id', $brandIdSet))
            ->count();
        if ($orphanProductBrands > 0) {
            $blockers[] = sprintf('%d product(s) reference non-existent brand_id values.', $orphanProductBrands);
        }

        // Compute reference counts & affected rows for fingerprinting
        $affectedProductCategoryRows = [];
        if ($redundantCategoryIds !== []) {
            $q = $db->table('products')
                ->select('id', 'category_id')
                ->whereIn('category_id', $redundantCategoryIds)
                ->orderBy('id');
            if ($lockForUpdate) {
                $q->lockForUpdate();
            }
            $affectedProductCategoryRows = $q->get()->map(fn ($r) => [(int) $r->id, (int) $r->category_id])->all();
        }

        $affectedProductBrandRows = [];
        if ($redundantBrandIds !== []) {
            $q = $db->table('products')
                ->select('id', 'brand_id')
                ->whereIn('brand_id', $redundantBrandIds)
                ->orderBy('id');
            if ($lockForUpdate) {
                $q->lockForUpdate();
            }
            $affectedProductBrandRows = $q->get()->map(fn ($r) => [(int) $r->id, (int) $r->brand_id])->all();
        }

        $affectedCategoryParentRows = [];
        if ($hierarchy['valid'] && $redundantCategoryIds !== []) {
            foreach ($hierarchy['nodes'] as $id => $node) {
                if (isset($categoryMappings[$id])) {
                    continue;
                }
                if ($node['original_parent_id'] !== $node['canonical_parent_id']) {
                    $affectedCategoryParentRows[] = [$id, $node['original_parent_id'], $node['canonical_parent_id']];
                }
            }
        }

        $fingerprintPayload = [
            'categories' => array_map(fn ($r) => [
                'id' => (int) $r['id'],
                'category_name' => $r['category_name'],
                'category_code' => $r['category_code'] ?? null,
                'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'setting_id' => array_key_exists('setting_id', $r) && $r['setting_id'] !== null ? (int) $r['setting_id'] : null,
                'created_by' => isset($r['created_by']) && $r['created_by'] !== null ? (int) $r['created_by'] : null,
            ], $categoryRows),
            'brands' => array_map(fn ($r) => [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'description' => $r['description'] ?? null,
                'deleted_at' => $r['deleted_at'] ?? null,
                'setting_id' => array_key_exists('setting_id', $r) && $r['setting_id'] !== null ? (int) $r['setting_id'] : null,
                'created_by' => isset($r['created_by']) && $r['created_by'] !== null ? (int) $r['created_by'] : null,
            ], $brandRows),
            'category_mappings' => $categoryMappings,
            'brand_mappings' => $brandMappings,
            'affected_product_categories' => $affectedProductCategoryRows,
            'affected_product_brands' => $affectedProductBrandRows,
            'affected_category_parents' => $affectedCategoryParentRows,
        ];

        $planFingerprint = hash('sha256', json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $hasWork = $redundantCategoryIds !== []
            || $redundantBrandIds !== []
            || $categoriesNeedingBackfill > 0
            || $brandsNeedingBackfill > 0;

        return [
            'valid' => $blockers === [],
            'blockers' => $blockers,
            'has_work' => $hasWork,
            'plan_fingerprint' => $planFingerprint,
            'summary' => [
                'categories_before' => count($categoryRows),
                'categories_after' => count($categoryRows) - count($redundantCategoryIds),
                'category_groups_merged' => count($categoryDuplicateGroups),
                'categories_removed' => count($redundantCategoryIds),
                'categories_needing_backfill' => $categoriesNeedingBackfill,
                'brands_before_active' => count($activeBrands),
                'brands_after_active' => count($activeBrands) - count($redundantBrandIds),
                'brands_deleted' => count($deletedBrandReports),
                'brand_groups_merged' => count($brandDuplicateGroups),
                'brands_removed' => count($redundantBrandIds),
                'brands_needing_backfill' => $brandsNeedingBackfill,
                'product_category_references_updated' => count($affectedProductCategoryRows),
                'category_parent_references_updated' => count($affectedCategoryParentRows),
                'product_brand_references_updated' => count($affectedProductBrandRows),
            ],
            'categories' => [
                'duplicate_groups' => $categoryDuplicateGroups,
                'mappings' => $categoryMappings,
                'redundant_ids' => $redundantCategoryIds,
                'metadata_conflicts' => $categoryMetadataConflicts,
                'nodes' => $hierarchy['nodes'],
            ],
            'brands' => [
                'duplicate_groups' => $brandDuplicateGroups,
                'mappings' => $brandMappings,
                'redundant_ids' => $redundantBrandIds,
                'metadata_conflicts' => $brandMetadataConflicts,
                'deleted_brands' => $deletedBrandReports,
                'deleted_brand_conflicts' => $deletedBrandConflicts,
            ],
            'unsupported_foreign_keys' => $unsupportedFks,
        ];
    }

    /**
     * Execute a reviewed reconciliation plan atomically with durable audit recording.
     *
     * @param callable|null $beforeCommitHook Optional test hook invoked before commit to verify rollback behavior.
     * @return array<string, mixed>
     */
    public function execute(
        string $expectedFingerprint,
        string $operator,
        ?string $connection = null,
        ?callable $beforeCommitHook = null
    ): array {
        $operator = trim($operator);
        if ($operator === '') {
            throw new InvalidArgumentException('Operator identity is required for reconciliation execution.');
        }

        $expectedFingerprint = trim($expectedFingerprint);
        if ($expectedFingerprint === '') {
            throw new InvalidArgumentException('Plan fingerprint is required for reconciliation execution.');
        }

        $db = DB::connection($connection);

        return $db->transaction(function () use ($db, $connection, $expectedFingerprint, $operator, $beforeCommitHook) {
            $plan = $this->buildPlan($connection, true);

            if (!$plan['valid']) {
                throw new RuntimeException(
                    "Reconciliation blocked:\n- " . implode("\n- ", $plan['blockers'])
                );
            }

            if (!hash_equals($plan['plan_fingerprint'], $expectedFingerprint)) {
                throw new RuntimeException(sprintf(
                    'Stale plan fingerprint: expected %s but current database state produced %s. Generate a fresh preview before executing.',
                    $expectedFingerprint,
                    $plan['plan_fingerprint']
                ));
            }

            if (!$plan['has_work']) {
                return [
                    'status' => 'no_op',
                    'audit_id' => null,
                    'plan_fingerprint' => $plan['plan_fingerprint'],
                    'summary' => $plan['summary'],
                ];
            }

            $categoryMappings = $plan['categories']['mappings'];
            $redundantCategoryIds = $plan['categories']['redundant_ids'];
            $brandMappings = $plan['brands']['mappings'];
            $redundantBrandIds = $plan['brands']['redundant_ids'];

            // Snapshot original rows before mutation
            $removedCategoriesSnapshot = $redundantCategoryIds !== []
                ? $db->table('categories')->whereIn('id', $redundantCategoryIds)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()
                : [];
            $removedBrandsSnapshot = $redundantBrandIds !== []
                ? $db->table('brands')->whereIn('id', $redundantBrandIds)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()
                : [];

            // 1. Remap products.category_id (preserving NULLs)
            $actualProductCategoryUpdated = 0;
            foreach ($categoryMappings as $oldId => $survivorId) {
                $actualProductCategoryUpdated += $db->table('products')
                    ->where('category_id', $oldId)
                    ->update(['category_id' => $survivorId]);
            }

            // 2. Remap categories.parent_id before deleting redundant categories
            $actualCategoryParentUpdated = 0;
            foreach ($plan['categories']['nodes'] as $id => $node) {
                if (isset($categoryMappings[$id])) {
                    continue;
                }
                if ($node['original_parent_id'] !== $node['canonical_parent_id']) {
                    $actualCategoryParentUpdated += $db->table('categories')
                        ->where('id', $id)
                        ->update(['parent_id' => $node['canonical_parent_id']]);
                }
            }

            // 3. Remove redundant categories (break any internal parent_id references among redundant rows first for CASCADE safety)
            if ($redundantCategoryIds !== []) {
                $db->table('categories')
                    ->whereIn('id', $redundantCategoryIds)
                    ->update(['parent_id' => null]);

                $db->table('categories')
                    ->whereIn('id', $redundantCategoryIds)
                    ->delete();
            }

            // 4. Backfill canonical columns on surviving categories
            $hasCatNormalized = Schema::connection($connection)->hasColumn('categories', 'normalized_name');
            $hasCatNamespace = Schema::connection($connection)->hasColumn('categories', 'parent_namespace');
            if ($hasCatNormalized || $hasCatNamespace) {
                foreach ($plan['categories']['nodes'] as $id => $node) {
                    if (isset($categoryMappings[$id])) {
                        continue;
                    }
                    $update = [];
                    if ($hasCatNormalized) {
                        $update['normalized_name'] = $node['normalized_name'];
                    }
                    if ($hasCatNamespace) {
                        $update['parent_namespace'] = $node['parent_namespace'];
                    }
                    $db->table('categories')->where('id', $id)->update($update);
                }
            }

            // 5. Remap products.brand_id (preserving NULLs)
            $actualProductBrandUpdated = 0;
            foreach ($brandMappings as $oldId => $survivorId) {
                $actualProductBrandUpdated += $db->table('products')
                    ->where('brand_id', $oldId)
                    ->update(['brand_id' => $survivorId]);
            }

            // 6. Hard-delete redundant brand rows (bypasses SoftDeletes only inside audited reconciliation)
            if ($redundantBrandIds !== []) {
                $db->table('brands')
                    ->whereIn('id', $redundantBrandIds)
                    ->delete();
            }

            // 7. Backfill normalized_name on surviving brands (including non-conflicting deleted brands)
            if (Schema::connection($connection)->hasColumn('brands', 'normalized_name')) {
                $survivingBrands = $db->table('brands')->orderBy('id')->get();
                foreach ($survivingBrands as $brand) {
                    $normalized = $this->canonicalizer->normalizeName((string) $brand->name);
                    if ($brand->normalized_name !== $normalized) {
                        $db->table('brands')
                            ->where('id', $brand->id)
                            ->update(['normalized_name' => $normalized]);
                    }
                }
            }

            // 8. Verify postconditions inside the transaction
            if ($redundantCategoryIds !== []) {
                $lingeringCatProducts = $db->table('products')->whereIn('category_id', $redundantCategoryIds)->count();
                $lingeringCatParents = $db->table('categories')->whereIn('parent_id', $redundantCategoryIds)->count();
                if ($lingeringCatProducts > 0 || $lingeringCatParents > 0) {
                    throw new RuntimeException('Postcondition failed: lingering references to removed categories detected.');
                }
            }
            if ($redundantBrandIds !== []) {
                $lingeringBrandProducts = $db->table('products')->whereIn('brand_id', $redundantBrandIds)->count();
                if ($lingeringBrandProducts > 0) {
                    throw new RuntimeException('Postcondition failed: lingering references to removed brands detected.');
                }
            }

            // 9. Optional test hook for injected mid-merge failure
            if ($beforeCommitHook !== null) {
                $beforeCommitHook($plan);
            }

            // 10. Record durable before-state audit
            $now = now();
            $auditId = $db->table('product_taxonomy_reconciliation_audits')->insertGetId([
                'plan_fingerprint' => $plan['plan_fingerprint'],
                'operator' => $operator,
                'status' => 'completed',
                'category_groups_merged' => $plan['summary']['category_groups_merged'],
                'categories_removed' => count($redundantCategoryIds),
                'brand_groups_merged' => $plan['summary']['brand_groups_merged'],
                'brands_removed' => count($redundantBrandIds),
                'product_category_references_updated' => $actualProductCategoryUpdated,
                'category_parent_references_updated' => $actualCategoryParentUpdated,
                'product_brand_references_updated' => $actualProductBrandUpdated,
                'mapping_payload' => json_encode([
                    'category_mappings' => $categoryMappings,
                    'brand_mappings' => $brandMappings,
                    'category_duplicate_groups' => $plan['categories']['duplicate_groups'],
                    'brand_duplicate_groups' => $plan['brands']['duplicate_groups'],
                    'removed_categories' => $removedCategoriesSnapshot,
                    'removed_brands' => $removedBrandsSnapshot,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'summary_payload' => json_encode([
                    'summary' => $plan['summary'],
                    'deleted_brands' => $plan['brands']['deleted_brands'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'executed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'status' => 'completed',
                'audit_id' => $auditId,
                'plan_fingerprint' => $plan['plan_fingerprint'],
                'counts' => [
                    'categories_removed' => count($redundantCategoryIds),
                    'brands_removed' => count($redundantBrandIds),
                    'product_category_references_updated' => $actualProductCategoryUpdated,
                    'category_parent_references_updated' => $actualCategoryParentUpdated,
                    'product_brand_references_updated' => $actualProductBrandUpdated,
                ],
                'summary' => $plan['summary'],
            ];
        });
    }

    /**
     * @param array<int, int> $memberIds
     * @param array<int, array<string, mixed>> $byId
     * @return array<string, array<int, mixed>>
     */
    private function detectCategoryMetadataConflicts(array $memberIds, array $byId): array
    {
        $fields = ['category_name', 'category_code', 'setting_id', 'created_by'];
        $conflicts = [];

        foreach ($fields as $field) {
            $values = [];
            foreach ($memberIds as $id) {
                $val = $byId[$id][$field] ?? null;
                $values[$id] = $val;
            }
            if (count(array_unique(array_map(fn ($v) => (string) ($v ?? ''), $values))) > 1) {
                $conflicts[$field] = $values;
            }
        }

        return $conflicts;
    }

    /**
     * @param array<int, int> $memberIds
     * @param array<int, array<string, mixed>> $byId
     * @return array<string, array<int, mixed>>
     */
    private function detectBrandMetadataConflicts(array $memberIds, array $byId): array
    {
        $fields = ['name', 'description', 'setting_id', 'created_by'];
        $conflicts = [];

        foreach ($fields as $field) {
            $values = [];
            foreach ($memberIds as $id) {
                $val = $byId[$id][$field] ?? null;
                $values[$id] = $val;
            }
            if (count(array_unique(array_map(fn ($v) => (string) ($v ?? ''), $values))) > 1) {
                $conflicts[$field] = $values;
            }
        }

        return $conflicts;
    }

    /**
     * Detect any database foreign keys referencing `categories` or `brands`
     * other than the known supported relations.
     *
     * @return array<int, array{table: string, column: string, referenced_table: string, referenced_column: string}>
     */
    private function detectUnsupportedIncomingForeignKeys(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $driver = $db->getDriverName();
        $allowed = [
            'products.category_id->categories.id' => true,
            'categories.parent_id->categories.id' => true,
            'products.brand_id->brands.id' => true,
        ];

        $unsupported = [];

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $dbName = $db->getDatabaseName();
            $rows = $db->select(
                'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ?
                   AND REFERENCED_TABLE_NAME IN (?, ?)',
                [$dbName, 'categories', 'brands']
            );

            foreach ($rows as $row) {
                $sig = sprintf('%s.%s->%s.%s', $row->TABLE_NAME, $row->COLUMN_NAME, $row->REFERENCED_TABLE_NAME, $row->REFERENCED_COLUMN_NAME);
                if (!isset($allowed[$sig])) {
                    $unsupported[] = [
                        'table' => $row->TABLE_NAME,
                        'column' => $row->COLUMN_NAME,
                        'referenced_table' => $row->REFERENCED_TABLE_NAME,
                        'referenced_column' => $row->REFERENCED_COLUMN_NAME,
                    ];
                }
            }
        } elseif ($driver === 'sqlite') {
            $tables = $db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
            foreach ($tables as $t) {
                $tableName = $t->name;
                $fks = $db->select('PRAGMA foreign_key_list("' . str_replace('"', '""', $tableName) . '")');
                foreach ($fks as $fk) {
                    if (!in_array($fk->table, ['categories', 'brands'], true)) {
                        continue;
                    }
                    $sig = sprintf('%s.%s->%s.%s', $tableName, $fk->from, $fk->table, $fk->to);
                    if (!isset($allowed[$sig])) {
                        $unsupported[] = [
                            'table' => $tableName,
                            'column' => $fk->from,
                            'referenced_table' => $fk->table,
                            'referenced_column' => $fk->to,
                        ];
                    }
                }
            }
        }

        return $unsupported;
    }
}

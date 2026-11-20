<?php

namespace Modules\Product\Services;

use InvalidArgumentException;

class ProductTaxonomyHierarchyResolver
{
    public function __construct(
        private readonly ProductTaxonomyCanonicalizer $canonicalizer
    ) {
    }

    /**
     * Validate category hierarchy and compute canonical parent namespaces,
     * topological levels, and duplicate-group survivors (lowest ID per identity).
     *
     * @param iterable<int, array<string, mixed>|object> $categories
     * @return array{
     *     valid: bool,
     *     errors: array<int, string>,
     *     nodes: array<int, array<string, mixed>>,
     *     groups: array<string, array<string, mixed>>,
     *     id_to_survivor: array<int, int>
     * }
     */
    public function resolve(iterable $categories): array
    {
        $byId = [];
        foreach ($categories as $raw) {
            $row = is_array($raw) ? $raw : (array) $raw;
            $id = (int) $row['id'];
            $byId[$id] = [
                'id' => $id,
                'category_name' => (string) ($row['category_name'] ?? ''),
                'category_code' => $row['category_code'] ?? null,
                'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'setting_id' => isset($row['setting_id']) && $row['setting_id'] !== null ? (int) $row['setting_id'] : null,
                'created_by' => isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
                'raw' => $row,
            ];
        }

        ksort($byId, SORT_NUMERIC);

        $errors = [];

        // 1. Validate blank names, self-parents, and missing/orphan parents
        foreach ($byId as $id => $node) {
            try {
                $this->canonicalizer->normalizeName($node['category_name']);
            } catch (InvalidArgumentException) {
                $errors[] = sprintf(
                    'Category #%d has a blank or un-normalizable name ("%s").',
                    $id,
                    $node['category_name']
                );
            }

            $parentId = $node['parent_id'];
            if ($parentId !== null) {
                if ($parentId === $id) {
                    $errors[] = sprintf(
                        'Category #%d ("%s") references itself as parent_id.',
                        $id,
                        $node['category_name']
                    );
                } elseif (!array_key_exists($parentId, $byId)) {
                    $errors[] = sprintf(
                        'Category #%d ("%s") references missing parent_id #%d.',
                        $id,
                        $node['category_name'],
                        $parentId
                    );
                }
            }
        }

        // 2. Detect cycles and compute depth for every reachable node
        $depths = [];
        $cycleReported = [];

        foreach ($byId as $id => $node) {
            if (array_key_exists($id, $depths)) {
                continue;
            }

            $path = [];
            $pathIndex = [];
            $current = $id;

            while ($current !== null && array_key_exists($current, $byId)) {
                if (array_key_exists($current, $depths)) {
                    $baseDepth = $depths[$current];
                    for ($i = count($path) - 1; $i >= 0; $i--) {
                        $baseDepth++;
                        $depths[$path[$i]] = $baseDepth;
                    }
                    break;
                }

                if (array_key_exists($current, $pathIndex)) {
                    $cycleSlice = array_slice($path, $pathIndex[$current]);
                    $cycleSlice[] = $current;
                    $canonicalCycleKey = implode(',', $this->normalizeCycleSignature($cycleSlice));
                    if (!isset($cycleReported[$canonicalCycleKey])) {
                        $cycleReported[$canonicalCycleKey] = true;
                        $errors[] = sprintf(
                            'Category hierarchy cycle detected: %s.',
                            implode(' -> ', array_map(fn ($cid) => '#' . $cid, $cycleSlice))
                        );
                    }
                    break;
                }

                $pathIndex[$current] = count($path);
                $path[] = $current;
                $parentId = $byId[$current]['parent_id'];

                if ($parentId === null) {
                    $baseDepth = 0;
                    for ($i = count($path) - 1; $i >= 0; $i--) {
                        $depths[$path[$i]] = $baseDepth;
                        $baseDepth++;
                    }
                    break;
                }

                if (!array_key_exists($parentId, $byId)) {
                    // Orphan already recorded above
                    break;
                }

                $current = $parentId;
            }
        }

        if ($errors !== []) {
            return [
                'valid' => false,
                'errors' => $errors,
                'nodes' => [],
                'groups' => [],
                'id_to_survivor' => [],
            ];
        }

        // 3. Sort topologically: by depth ASC, then id ASC (ensures lowest ID is seen first in each level)
        $orderedIds = array_keys($byId);
        usort($orderedIds, function (int $a, int $b) use ($depths): int {
            return ($depths[$a] <=> $depths[$b]) ?: ($a <=> $b);
        });

        $nodes = [];
        $groups = [];
        $idToSurvivor = [];

        foreach ($orderedIds as $id) {
            $row = $byId[$id];
            $normalizedName = $this->canonicalizer->normalizeName($row['category_name']);
            $canonicalParentId = $row['parent_id'] !== null ? $idToSurvivor[$row['parent_id']] : null;
            $parentNamespace = $this->canonicalizer->parentNamespace($canonicalParentId);
            $identityKey = $this->canonicalizer->categoryKey($row['category_name'], $canonicalParentId);

            if (!isset($groups[$identityKey])) {
                $groups[$identityKey] = [
                    'identity_key' => $identityKey,
                    'normalized_name' => $normalizedName,
                    'canonical_parent_id' => $canonicalParentId,
                    'parent_namespace' => $parentNamespace,
                    'survivor_id' => $id,
                    'survivor_code' => $row['category_code'],
                    'survivor_name' => $row['category_name'],
                    'member_ids' => [],
                    'redundant_ids' => [],
                ];
            }

            $survivorId = $groups[$identityKey]['survivor_id'];
            $groups[$identityKey]['member_ids'][] = $id;
            if ($id !== $survivorId) {
                $groups[$identityKey]['redundant_ids'][] = $id;
            }

            $idToSurvivor[$id] = $survivorId;
            $nodes[$id] = [
                'id' => $id,
                'category_name' => $row['category_name'],
                'category_code' => $row['category_code'],
                'original_parent_id' => $row['parent_id'],
                'canonical_parent_id' => $canonicalParentId,
                'parent_namespace' => $parentNamespace,
                'normalized_name' => $normalizedName,
                'identity_key' => $identityKey,
                'survivor_id' => $survivorId,
                'depth' => $depths[$id],
            ];
        }

        ksort($nodes, SORT_NUMERIC);
        ksort($idToSurvivor, SORT_NUMERIC);

        return [
            'valid' => true,
            'errors' => [],
            'nodes' => $nodes,
            'groups' => $groups,
            'id_to_survivor' => $idToSurvivor,
        ];
    }

    /**
     * Check whether moving $categoryId under $newParentId would create a cycle.
     *
     * @param callable(int): ?int $parentIdLookup
     */
    public function wouldCreateCycle(int $categoryId, ?int $newParentId, callable $parentIdLookup): bool
    {
        if ($newParentId === null) {
            return false;
        }

        if ($newParentId === $categoryId) {
            return true;
        }

        $visited = [$categoryId => true];
        $current = $newParentId;

        while ($current !== null) {
            if (isset($visited[$current])) {
                return true;
            }
            $visited[$current] = true;
            $current = $parentIdLookup($current);
        }

        return false;
    }

    /**
     * @param array<int, int> $cycleSlice
     * @return array<int, int>
     */
    private function normalizeCycleSignature(array $cycleSlice): array
    {
        $unique = array_values(array_unique($cycleSlice));
        sort($unique, SORT_NUMERIC);

        return $unique;
    }
}

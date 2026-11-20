<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

class SaleByProductCategoryTreeResolver
{
    /**
     * Expand selected category IDs to include all descendants (cycle-safe, deduplicated).
     *
     * @param  array<int>  $selectedCategoryIds
     * @return array<int>
     */
    public function expandWithDescendants(array $selectedCategoryIds): array
    {
        $seeds = array_values(array_unique(array_filter(
            array_map('intval', $selectedCategoryIds),
            fn (int $id) => $id > 0
        )));

        if (empty($seeds)) {
            return [];
        }

        $categories = DB::table('categories')
            ->select('id', 'parent_id')
            ->get();

        $childrenByParent = [];
        foreach ($categories as $cat) {
            $parentId = $cat->parent_id !== null ? (int) $cat->parent_id : 0;
            $childrenByParent[$parentId][] = (int) $cat->id;
        }

        $visited = [];
        $queue = $seeds;

        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            foreach ($childrenByParent[$current] ?? [] as $childId) {
                if (!isset($visited[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        $result = array_keys($visited);
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * Build full parent-path display labels for categories (e.g., "ATK > Kertas > HVS").
     *
     * @param  array<int>|null  $categoryIds  Optional subset of IDs to return paths for.
     * @return array<int, string>
     */
    public function buildPathLabels(?array $categoryIds = null): array
    {
        $categories = DB::table('categories')
            ->select('id', 'parent_id', 'category_name')
            ->get()
            ->keyBy('id');

        $targetIds = $categoryIds !== null
            ? array_values(array_unique(array_map('intval', $categoryIds)))
            : $categories->keys()->map(fn ($id) => (int) $id)->all();

        $labels = [];
        foreach ($targetIds as $id) {
            if (!$categories->has($id)) {
                continue;
            }

            $parts = [];
            $visited = [];
            $cursor = $id;

            while ($cursor !== null && $categories->has($cursor) && !isset($visited[$cursor])) {
                $visited[$cursor] = true;
                $node = $categories->get($cursor);
                array_unshift($parts, (string) $node->category_name);
                $cursor = $node->parent_id !== null ? (int) $node->parent_id : null;
            }

            $labels[$id] = implode(' > ', $parts);
        }

        return $labels;
    }
}

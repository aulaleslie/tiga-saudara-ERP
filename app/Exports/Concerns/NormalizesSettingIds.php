<?php

namespace App\Exports\Concerns;

trait NormalizesSettingIds
{
    /**
     * Normalize one or more candidate setting ID inputs into a deduplicated list of positive integers.
     * Evaluates candidates in order and returns the first non-empty list found.
     *
     * @param  array|int|null  ...$candidates
     * @return int[]
     */
    protected function normalizeSettingIds(array|int|null ...$candidates): array
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = array_values(array_unique(array_filter(
                array_map('intval', (array) $candidate),
                fn (int $id): bool => $id > 0
            )));

            if (!empty($normalized)) {
                return $normalized;
            }
        }

        return [];
    }
}

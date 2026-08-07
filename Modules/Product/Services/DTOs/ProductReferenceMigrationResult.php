<?php

namespace Modules\Product\Services\DTOs;

class ProductReferenceMigrationResult
{
    /**
     * @param bool $isCompleted Whether migration succeeded
     * @param array $migratedCounts Immutable snapshot of counts actually migrated: [table => count, ...]
     *                              Must exactly match the before snapshot minus post-migration count
     */
    public function __construct(
        public readonly bool $isCompleted,
        public readonly array $migratedCounts = []
    ) {
        if (!$isCompleted && !empty($migratedCounts)) {
            throw new \InvalidArgumentException('migratedCounts must be empty when isCompleted is false.');
        }
    }
}

<?php

namespace Modules\Product\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use RuntimeException;

class ProductTaxonomyResolver
{
    public function __construct(
        private readonly ProductTaxonomyCanonicalizer $canonicalizer,
        private readonly ProductTaxonomyHierarchyResolver $hierarchyResolver
    ) {
    }

    public function canonicalizer(): ProductTaxonomyCanonicalizer
    {
        return $this->canonicalizer;
    }

    /**
     * Validate and create a global brand for interactive forms/quick-add.
     */
    public function createBrand(string $rawName, ?string $description, ?int $createdBy): Brand
    {
        $canonical = $this->canonicalizer->canonicalize($rawName);
        $this->assertBrandNameAvailable($canonical['normalized_name']);

        try {
            return Brand::create([
                'name' => $canonical['display_name'],
                'normalized_name' => $canonical['normalized_name'],
                'description' => $description,
                'created_by' => $createdBy,
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKeyException($e, 'brands_normalized_name_unique', ['brands.normalized_name'])) {
                $this->assertBrandNameAvailable($canonical['normalized_name']);
                throw ValidationException::withMessages([
                    'name' => ['Merek dengan nama yang sama sudah ada.'],
                ]);
            }
            throw $e;
        }
    }

    /**
     * Validate and update a global brand.
     */
    public function updateBrand(Brand $brand, string $rawName, ?string $description): Brand
    {
        $canonical = $this->canonicalizer->canonicalize($rawName);
        $this->assertBrandNameAvailable($canonical['normalized_name'], (int) $brand->id);

        try {
            $brand->update([
                'name' => $canonical['display_name'],
                'normalized_name' => $canonical['normalized_name'],
                'description' => $description,
            ]);

            return $brand->refresh();
        } catch (QueryException $e) {
            if ($this->isDuplicateKeyException($e, 'brands_normalized_name_unique', ['brands.normalized_name'])) {
                $this->assertBrandNameAvailable($canonical['normalized_name'], (int) $brand->id);
                throw ValidationException::withMessages([
                    'name' => ['Merek dengan nama yang sama sudah ada.'],
                ]);
            }
            throw $e;
        }
    }

    /**
     * Resolve or create a brand during product import.
     * Reuses active match, blocks deleted-brand conflicts, handles concurrent duplicate-key races.
     */
    public function resolveOrCreateBrandForImport(?string $rawName, ?int $createdBy): ?Brand
    {
        $normalized = $this->canonicalizer->tryNormalizeName($rawName);
        if ($normalized === null) {
            return null;
        }

        $displayName = $this->canonicalizer->displayName($rawName);

        $existing = Brand::withTrashed()
            ->where('normalized_name', $normalized)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                throw new RuntimeException(sprintf(
                    'Brand "%s" is reserved by a deleted brand (#%d) and cannot be recreated or restored automatically.',
                    $displayName,
                    $existing->id
                ));
            }

            return $existing;
        }

        try {
            return Brand::create([
                'name' => $displayName,
                'normalized_name' => $normalized,
                'created_by' => $createdBy,
            ]);
        } catch (QueryException $e) {
            if (!$this->isDuplicateKeyException($e, 'brands_normalized_name_unique', ['brands.normalized_name'])) {
                throw $e;
            }

            // Use lockForUpdate() so InnoDB performs a current read rather than a stale REPEATABLE READ snapshot.
            $winner = Brand::withTrashed()
                ->where('normalized_name', $normalized)
                ->lockForUpdate()
                ->first();

            if ($winner && $winner->trashed()) {
                throw new RuntimeException(sprintf(
                    'Brand "%s" is reserved by a deleted brand (#%d) and cannot be recreated or restored automatically.',
                    $displayName,
                    $winner->id
                ));
            }

            if ($winner) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * Validate and create a global category for interactive forms/quick-add.
     */
    public function createCategory(string $categoryCode, string $rawName, ?int $parentId, ?int $createdBy): Category
    {
        $canonical = $this->canonicalizer->canonicalize($rawName);
        $namespace = $this->canonicalizer->parentNamespace($parentId);

        $this->assertCategoryIdentityAvailable($canonical['normalized_name'], $namespace);

        try {
            return Category::create([
                'category_code' => $categoryCode,
                'category_name' => $canonical['display_name'],
                'normalized_name' => $canonical['normalized_name'],
                'parent_id' => $parentId,
                'parent_namespace' => $namespace,
                'created_by' => $createdBy,
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKeyException($e, 'categories_parent_namespace_normalized_name_unique', ['categories.parent_namespace', 'categories.normalized_name'])) {
                throw ValidationException::withMessages([
                    'category_name' => ['Kategori dengan nama yang sama sudah ada pada induk tersebut.'],
                ]);
            }
            throw $e;
        }
    }

    /**
     * Validate and update a global category (including serialized cycle and sibling uniqueness checks).
     */
    public function updateCategory(Category $category, string $categoryCode, string $rawName, ?int $parentId): Category
    {
        $canonical = $this->canonicalizer->canonicalize($rawName);
        $namespace = $this->canonicalizer->parentNamespace($parentId);

        return DB::transaction(function () use ($category, $categoryCode, $canonical, $parentId, $namespace): Category {
            // Serialize concurrent hierarchy moves in deterministic ID order to prevent A->B / B->A cycles.
            $lockedParents = Category::query()
                ->select('id', 'parent_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('parent_id', 'id')
                ->map(fn ($pid) => $pid !== null ? (int) $pid : null)
                ->all();

            if ($parentId !== null) {
                $wouldCycle = $this->hierarchyResolver->wouldCreateCycle(
                    (int) $category->id,
                    $parentId,
                    fn (int $id): ?int => $lockedParents[$id] ?? null
                );
                if ($wouldCycle) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['Pemindahan kategori tidak valid karena akan membentuk siklus hierarki.'],
                    ]);
                }
            }

            $this->assertCategoryIdentityAvailable($canonical['normalized_name'], $namespace, (int) $category->id);

            try {
                $category->update([
                    'category_code' => $categoryCode,
                    'category_name' => $canonical['display_name'],
                    'normalized_name' => $canonical['normalized_name'],
                    'parent_id' => $parentId,
                    'parent_namespace' => $namespace,
                ]);

                return $category->refresh();
            } catch (QueryException $e) {
                if ($this->isDuplicateKeyException($e, 'categories_parent_namespace_normalized_name_unique', ['categories.parent_namespace', 'categories.normalized_name'])) {
                    throw ValidationException::withMessages([
                        'category_name' => ['Kategori dengan nama yang sama sudah ada pada induk tersebut.'],
                    ]);
                }
                throw $e;
            }
        });
    }

    /**
     * Resolve or create a category during name-only product import.
     * - Reuses a unique active match across all parent paths (including unique child categories).
     * - Reports actionable ambiguity when multiple parent paths match the same name.
     * - Creates a root category only when no active match exists.
     * - Handles concurrent duplicate-key races by reloading the winning root record.
     */
    public function resolveOrCreateCategoryForImport(?string $rawName, ?int $createdBy): ?Category
    {
        $normalized = $this->canonicalizer->tryNormalizeName($rawName);
        if ($normalized === null) {
            return null;
        }

        $displayName = $this->canonicalizer->displayName($rawName);

        $matches = Category::query()
            ->with('parent')
            ->where('normalized_name', $normalized)
            ->orderBy('id')
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            $paths = $matches->map(function (Category $cat): string {
                return $cat->parent
                    ? sprintf('#%d (%s | %s)', $cat->id, $cat->parent->category_name, $cat->category_name)
                    : sprintf('#%d (ROOT | %s)', $cat->id, $cat->category_name);
            })->implode(', ');

            throw new RuntimeException(sprintf(
                'Ambiguous category name "%s": matches multiple parent paths [%s]. Specify a unique category or resolve duplicates.',
                $displayName,
                $paths
            ));
        }

        $attempt = 0;
        while ($attempt < 5) {
            $attempt++;
            $code = $this->generateUniqueCategoryCode($displayName, $attempt > 1 ? $attempt : null);

            try {
                return Category::create([
                    'category_code' => $code,
                    'category_name' => $displayName,
                    'normalized_name' => $normalized,
                    'parent_id' => null,
                    'parent_namespace' => ProductTaxonomyCanonicalizer::ROOT_PARENT_NAMESPACE,
                    'created_by' => $createdBy,
                ]);
            } catch (QueryException $e) {
                $isIdentityDup = $this->isDuplicateKeyException(
                    $e,
                    'categories_parent_namespace_normalized_name_unique',
                    ['categories.parent_namespace', 'categories.normalized_name']
                );
                $isCodeDup = $this->isDuplicateKeyException(
                    $e,
                    'categories_category_code_unique',
                    ['categories.category_code']
                );

                if (!$isIdentityDup && !$isCodeDup) {
                    throw $e;
                }

                // Use lockForUpdate() so InnoDB performs a current read rather than a stale REPEATABLE READ snapshot.
                $winner = Category::query()
                    ->where('parent_namespace', ProductTaxonomyCanonicalizer::ROOT_PARENT_NAMESPACE)
                    ->where('normalized_name', $normalized)
                    ->lockForUpdate()
                    ->first();

                if ($winner) {
                    return $winner;
                }

                // If only category_code collided with a different category name, retry with a fresh suffix.
                if ($isCodeDup && $attempt < 5) {
                    continue;
                }

                throw $e;
            }
        }

        throw new RuntimeException(sprintf('Unable to allocate a unique category_code for "%s".', $displayName));
    }

    public function assertBrandNameAvailable(string $normalizedName, ?int $ignoreId = null): void
    {
        $query = Brand::withTrashed()->where('normalized_name', $normalizedName);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        $existing = $query->first();
        if (!$existing) {
            return;
        }

        if ($existing->trashed()) {
            throw ValidationException::withMessages([
                'name' => ['Nama merek ini sudah digunakan oleh merek yang dihapus dan tidak dapat dibuat ulang secara otomatis.'],
            ]);
        }

        throw ValidationException::withMessages([
            'name' => ['Merek dengan nama yang sama sudah ada.'],
        ]);
    }

    public function assertCategoryIdentityAvailable(string $normalizedName, int $parentNamespace, ?int $ignoreId = null): void
    {
        $query = Category::query()
            ->where('parent_namespace', $parentNamespace)
            ->where('normalized_name', $normalizedName);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'category_name' => ['Kategori dengan nama yang sama sudah ada pada induk tersebut.'],
            ]);
        }
    }

    private function generateUniqueCategoryCode(string $displayName, ?int $salt = null): string
    {
        $base = Str::limit(Str::slug($displayName, '_'), 36, '');
        if ($base === '') {
            $base = 'cat';
        }

        $candidate = $salt !== null ? $base . '_' . $salt : $base;
        $suffix = $salt !== null ? $salt + 1 : 1;
        while (Category::query()->where('category_code', $candidate)->exists()) {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Identify whether a QueryException is specifically a unique-constraint violation
     * for the target identity index (and NOT a foreign-key or NOT NULL violation).
     *
     * @param array<int, string> $sqliteColumns
     */
    private function isDuplicateKeyException(QueryException $e, ?string $mysqlIndexName = null, array $sqliteColumns = []): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = $e->getMessage();

        // MySQL / MariaDB: 1062 is ER_DUP_ENTRY (distinct from 1451/1452 FK and 1048 NOT NULL)
        if ($driverCode === 1062) {
            return $mysqlIndexName === null || str_contains($message, $mysqlIndexName);
        }

        // SQLite: 19 is SQLITE_CONSTRAINT; require explicit UNIQUE constraint failure
        if ($driverCode === 19 && str_contains($message, 'UNIQUE constraint failed')) {
            if ($sqliteColumns === []) {
                return true;
            }
            foreach ($sqliteColumns as $col) {
                if (!str_contains($message, $col)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }
}

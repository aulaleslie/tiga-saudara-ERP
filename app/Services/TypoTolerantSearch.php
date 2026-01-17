<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;

/**
 * Provides typo-tolerant search functionality using MySQL FULLTEXT with ngram parser.
 *
 * This service builds search queries that can match partial and misspelled terms
 * like "katrid" -> "CATRIDGE" or "n150 512" -> "ACER ASPIRE LITE AL14 N150 8GB 512GB SSD".
 *
 * Note: Falls back to LIKE search on non-MySQL databases (e.g., SQLite for testing).
 */
class TypoTolerantSearch
{
    /**
     * Check if typo-tolerant search is enabled.
     * Returns false on non-MySQL databases since FULLTEXT isn't available.
     */
    public static function isEnabled(): bool
    {
        // FULLTEXT with ngram only works on MySQL/MariaDB
        if (!self::isMySql()) {
            return false;
        }

        return config('search.typo_tolerant', true);
    }

    /**
     * Check if we're running on MySQL/MariaDB.
     */
    public static function isMySql(): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        return in_array($driver, ['mysql', 'mariadb']);
    }

    /**
     * Build a FULLTEXT MATCH condition for searching products.
     *
     * @param string $searchTerm The user's search input
     * @param array $columns Columns to search in (must have FULLTEXT index)
     * @return array ['sql' => string, 'bindings' => array]
     */
    public static function buildMatchCondition(string $searchTerm, array $columns = ['product_name', 'product_code']): array
    {
        if (!self::isEnabled()) {
            return self::buildLikeCondition($searchTerm, $columns);
        }

        $term = trim($searchTerm);
        if ($term === '') {
            return ['sql' => '1=1', 'bindings' => []];
        }

        // Prepare the search term for FULLTEXT boolean mode
        // Split by whitespace and prepare each token
        $booleanTerm = self::prepareBooleanTerm($term);

        $columnList = implode(', ', $columns);

        return [
            'sql' => "MATCH({$columnList}) AGAINST(:ft_search_term IN BOOLEAN MODE)",
            'bindings' => ['ft_search_term' => $booleanTerm],
        ];
    }

    /**
     * Build MATCH condition for raw SQL with named parameter prefix.
     *
     * @param string $searchTerm The user's search input
     * @param string $tableAlias Table alias prefix (e.g., 'p' for 'p.product_name')
     * @param string $paramPrefix Unique parameter prefix to avoid conflicts
     * @param array $columns Columns to search (without table alias)
     * @return array ['sql' => string, 'bindings' => array]
     */
    public static function buildRawMatchCondition(
        string $searchTerm,
        string $tableAlias = 'p',
        string $paramPrefix = 'ft',
        array $columns = ['product_name', 'product_code']
    ): array {
        if (!self::isEnabled()) {
            return self::buildRawLikeCondition($searchTerm, $tableAlias, $paramPrefix, $columns);
        }

        $term = trim($searchTerm);
        if ($term === '') {
            return ['sql' => '1=1', 'bindings' => []];
        }

        $booleanTerm = self::prepareBooleanTerm($term);
        $paramName = $paramPrefix . '_term';

        // Build column list with table alias
        $columnList = implode(', ', array_map(
            fn($col) => "{$tableAlias}.{$col}",
            $columns
        ));

        return [
            'sql' => "MATCH({$columnList}) AGAINST(:{$paramName} IN BOOLEAN MODE)",
            'bindings' => [$paramName => $booleanTerm],
        ];
    }

    /**
     * Prepare search term for FULLTEXT boolean mode search.
     *
     * Converts "n150 512" to "+n150* +512*" for partial matching.
     * Each token is prefixed with + (required) and suffixed with * (wildcard).
     *
     * @param string $term Raw search term
     * @return string Boolean mode search term
     */
    public static function prepareBooleanTerm(string $term): string
    {
        // Clean and normalize the term
        $term = mb_strtolower(trim($term));

        // Remove special FULLTEXT boolean operators that could cause issues
        $term = preg_replace('/[+\-><()~*"@]/', ' ', $term);

        // Split into tokens
        $tokens = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($tokens)) {
            return '';
        }

        // Each token is required (+) and wildcarded (*)
        // This allows "n150 512" to match "...N150 8GB 512GB..."
        $preparedTokens = array_map(function ($token) {
            // Skip very short tokens (less than 2 chars) as ngram minimum is usually 2
            if (mb_strlen($token) < 2) {
                return '';
            }
            return '+' . $token . '*';
        }, $tokens);

        // Filter out empty tokens
        $preparedTokens = array_filter($preparedTokens);

        return implode(' ', $preparedTokens);
    }

    /**
     * Fallback LIKE-based search condition for Eloquent query builder.
     */
    public static function buildLikeCondition(string $searchTerm, array $columns): array
    {
        $term = '%' . mb_strtolower(trim($searchTerm)) . '%';

        $conditions = [];
        $bindings = [];

        foreach ($columns as $i => $column) {
            $paramName = "like_term_{$i}";
            $conditions[] = "LOWER({$column}) LIKE :{$paramName}";
            $bindings[$paramName] = $term;
        }

        return [
            'sql' => '(' . implode(' OR ', $conditions) . ')',
            'bindings' => $bindings,
        ];
    }

    /**
     * Fallback LIKE-based search condition for raw SQL.
     */
    public static function buildRawLikeCondition(
        string $searchTerm,
        string $tableAlias,
        string $paramPrefix,
        array $columns
    ): array {
        $term = '%' . mb_strtolower(trim($searchTerm)) . '%';

        $conditions = [];
        $bindings = [];

        foreach ($columns as $i => $column) {
            $paramName = "{$paramPrefix}_like_{$i}";
            $conditions[] = "LOWER({$tableAlias}.{$column}) LIKE :{$paramName}";
            $bindings[$paramName] = $term;
        }

        return [
            'sql' => '(' . implode(' OR ', $conditions) . ')',
            'bindings' => $bindings,
        ];
    }

    /**
     * Apply typo-tolerant search to an Eloquent query builder.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $searchTerm
     * @param string $table Table name for column prefix
     * @param array $fulltextColumns Columns with FULLTEXT index (name, code)
     * @param array $exactColumns Columns for exact LIKE match (barcode)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function applyToQuery(
        $query,
        string $searchTerm,
        string $table = 'products',
        array $fulltextColumns = ['product_name', 'product_code'],
        array $exactColumns = ['barcode']
    ) {
        $term = trim($searchTerm);
        if ($term === '') {
            return $query;
        }

        $like = '%' . $term . '%';

        if (self::isEnabled()) {
            $booleanTerm = self::prepareBooleanTerm($term);
            $columnList = implode(', ', array_map(fn($c) => "{$table}.{$c}", $fulltextColumns));

            return $query->where(function ($q) use ($columnList, $booleanTerm, $table, $exactColumns, $like) {
                // FULLTEXT search for name/code (typo tolerant)
                $q->whereRaw("MATCH({$columnList}) AGAINST(? IN BOOLEAN MODE)", [$booleanTerm]);

                // Exact match fallback for barcode and other exact columns
                foreach ($exactColumns as $col) {
                    $q->orWhere("{$table}.{$col}", 'like', $like);
                }
            });
        }

        // Fallback to LIKE search
        return $query->where(function ($q) use ($table, $fulltextColumns, $exactColumns, $like) {
            foreach (array_merge($fulltextColumns, $exactColumns) as $col) {
                $q->orWhere("{$table}.{$col}", 'like', $like);
            }
        });
    }
}

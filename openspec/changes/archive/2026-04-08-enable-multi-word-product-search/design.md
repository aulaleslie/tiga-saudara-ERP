## Context

The global search in the `ProductDataTable` natively breaks down input into tokens separated by spaces, matching them across specified columns (e.g., `product_code`, `product_name`, `category`, `brand`). In contrast, the product searches on the Sales API and Purchase Livewire endpoints currently apply an exact substring match (`LIKE '%var%'`) against the entire block of text. This causes a mismatch in UX, leaving users frustrated when attempting a mixed search like "SAM GAL FO" on the transaction screens.

## Goals / Non-Goals

**Goals:**
- Port the tokenization behavior of `Yajra\DataTables` global search to the product searches in the Sales and Purchase modules via manual query building.
- Extend the `orWhere` filters in these searches to match the fields accessible in the index table (`category_name` and `brand.name`).

**Non-Goals:**
- Refactoring `ProductDataTable` or changing how the index table functions.
- Adjusting any pricing logic or setting scoping beyond expanding the searchable string parameters.

## Decisions

- **Tokenizing Input**: Inputs will be exploded by spaces. For every generated token, the code will loop through an overarching `AND` condition applied to the query. Inside each token's iteration, an isolated query builder closure will link `LIKE` matching on `product_code` or `product_name` or `category_name` or `brand.name` with `OR` clauses (`orWhere`, `orWhereHas`).
- **DRY Approach (Scope)**: The same logic will be required in `/api/products/search` and `app/Livewire/Purchase/SearchProduct.php`. To ensure we don't repeat this verbose chunk of code, we will implement a local query scope `scopeGlobalSearch($query, $search)` on the `Modules\Product\Entities\Product` model. The API and Livewire components will be instructed to utilize this single scope.

## Risks / Trade-offs

- **Performance**: Heavy usage of `orWhereHas` for relationships like `brand` and `category` within a multi-token iteration sequence will result in nested correlated sub-queries (i.e. `EXISTS (SELECT * FROM categories...)`). For small/medium catalogs, the impact is negligible. For extremely large inventories, explicit indexes on `category_name` and `name` (for brands) might be necessary to avoid slowdowns.

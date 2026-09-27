## Why

Currently, the product search in the index table (`/products`) powered by Yajra DataTables naturally splits search input by spaces. For example, typing "SAM GAL FO" will filter out products that have both "SAM", "GAL", and "FO" across any searchable column, correctly matching "SAMSUNG GALAXY FOLD".

However, the product search in the Sales page (via `/api/products/search`) and the Purchase page (`app/Livewire/Purchase/SearchProduct.php`) treats the search term as a single string. "SAM GAL FO" looks for that exact contiguous sequence, resulting in 0 matches. This breaks user expectations and makes cashiers' tasks harder.

## What Changes

Update the search algorithms used in both the Sales module and the Purchase module to safely tokenize the search string into words based on spaces. Each token must be present in at least one of the searchable fields (acting as a grouped `AND` condition of `OR` clauses). 
Additionally, we will add the `category.category_name` and `brand.name` fields to the `orWhere` fallbacks for these search endpoints so they match the exact column set accessible within the `/products` index table.

## Capabilities

### New Capabilities
- `multi-word-search`: Implement multi-word tokenization and relational searching (category, brand) for product searches in Sales and Purchases.

### Modified Capabilities

## Impact

- `routes/api.php` endpoint `/api/products/search` will be updated to explode `query` parameters and chain where checks.
- `app/Livewire/Purchase/SearchProduct.php` will be similarly updated.

## 1. Product Model Scope Implementation

- [x] 1.1 Implement `scopeGlobalSearch($query, $search)` in `Modules\Product\Entities\Product`.
- [x] 1.2 Within `scopeGlobalSearch`, implement `explode(' ', $search)` to split the input into tokens. Use a loop over these tokens wrapping each in a strict `->where(function($q) use ($word) { ... })`. Inside each token wrapper, chain `orWhere` clauses for `product_name`, `product_code`, and `orWhereHas` queries targeting the `category.category_name` and `brand.name` fields.

## 2. Refactor Sales Search API

- [x] 2.1 Open `routes/api.php` and locate the `GET /products/search` endpoint.
- [x] 2.2 Replace the raw `$q->where('p.product_name', 'like'...)->orWhere(...)` closure block with a single method call to `->globalSearch($search)`. Ensure `->where('p.stock_managed', true)` remains intact.

## 3. Refactor Purchase Livewire Search

- [x] 3.1 Open `app/Livewire/Purchase/SearchProduct.php`.
- [x] 3.2 Update the `getProducts()` method to replace the entire `$query->where(function ($q) { ... })` manual multi-column matching block with `$query->globalSearch($this->query)`. Ensure existing functionality (like `supplier_id` filtering and `stock_managed` check) is left untouched.

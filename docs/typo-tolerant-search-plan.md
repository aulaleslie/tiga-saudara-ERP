# Typo-Tolerant Search Plan (Offline, No Docker)

## Current state (observed)
- Search is mostly SQL LIKE or in-memory substring matching without typo tolerance.
- Key entry points include `app/Services/GlobalPurchaseAndSalesSearchService.php`, `Modules/Sale/Services/SerialNumberSearchService.php`, `app/Livewire/SearchProduct.php`, `app/Livewire/Components/SearchableSelect.php`, `app/Livewire/Purchase/SearchProduct.php`, `app/Livewire/PurchaseReturn/ProductSearchDropdown.php`, and `Modules/People/Livewire/SupplierSearchDropdown.php`.
- Client-side dropdown filtering uses simple `includes` matching in `resources/js/alpine-components/searchable-dropdown.js`.
- Scout config exists at `config/scout.php`, but `laravel/scout` is not listed in `composer.json`; an Algolia reindex command exists at `app/Console/Commands/ReindexProductsForSetting.php`.

## Gap analysis answers (captured)
- Scope: start with POS product search; later add purchase/sales lists and product searches on sales/purchase creation.
- Fuzzy fields: names and references; keep barcode and serial exact.
- Data size: thousands to tens of thousands per tenant.
- Latency target: p95 <= 1s for interactive search.
- DB: MySQL fulltext + ngram is acceptable if supported; verify MySQL 8+ and `ngram_token_size`.
- Dependencies: ok to add composer deps and local indexes, but prefer MySQL fulltext first.
- Ranking policy: hybrid; exact/prefix boosted, fuzzy for length >= 3, exact-only for short/numeric queries.
- Tenant isolation: no separate per-tenant indexes; respect existing query filters.
- Normalization: basic lowercase/whitespace/punctuation only.
- Reindexing: synchronous only (no queued background jobs).

## Recommended approach (offline)
Use MySQL fulltext with the ngram parser for typo tolerance, and keep exact/prefix matching for barcodes and serial numbers.

Option A (selected): MySQL fulltext with ngram parser
- Add FULLTEXT indexes using `WITH PARSER ngram` on normalized text columns for products, suppliers, customers, purchases, sales, and POS receipts.
- Use `MATCH ... AGAINST` for fuzzy retrieval and rank by fulltext score; fall back to prefix/LIKE for short inputs.
- Pros: no extra services, no new runtime dependencies. Cons: requires MySQL 8+ and ngram support/config.

Option B (fallback if ngram is unavailable): Local index via Scout + TNTSearch
- Add `laravel/scout` and `teamtnt/laravel-scout-tntsearch-driver`.
- Store indexes in `storage/` and use TNTSearch fuzziness for typo tolerance.
- Pros: strong fuzzy matching, no external services. Cons: new deps, index sync and storage management.

## Implementation plan (phased)
Phase 0: Validate ngram support
- Verify MySQL 8+ and that `WITH PARSER ngram` is available; if not, switch to Option B.

Phase 1: POS product search (first)
- Add fulltext indexes for products name/code fields needed by `app/Livewire/SearchProduct.php`.
- Keep exact barcode and serial lookups unchanged.

Phase 2: POS query integration
- Update `app/Livewire/SearchProduct.php` suggestions query to use `MATCH ... AGAINST` for name/code with fallback to LIKE when query length is short or results are empty.
- Apply the existing location and stock filters without changing behavior.

Phase 3: Observability and guardrails
- Log fuzzy vs exact usage, response time, and result counts for POS search.
- Add a feature flag to enable/disable fuzzy matching.

Phase 4: Expand to other modules
- Add fulltext indexes for suppliers, customers, purchases, sales, and POS receipts.
- Update global search and dropdown components to use fuzzy matching where appropriate.

Phase 5: Tests and rollout
- Add tests for typo tolerance on product names; keep barcode/serial exact.
- Roll out: enable POS product search first, then extend to other modules.

## Acceptance criteria
- Typo-tolerant queries return relevant results for product and party names with 1-2 character errors.
- Exact barcode/serial lookups remain fast and precise.
- Search remains fully functional offline without any dockerized service.

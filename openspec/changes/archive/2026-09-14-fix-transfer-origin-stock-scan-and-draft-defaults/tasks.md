## 1. Repair Dispatch Counter Defaults

- [x] 1.1 Add an additive Adjustment migration that restores unsigned non-null `DEFAULT 0` definitions for all five `transfer_products` dispatch quantity columns without rewriting existing values.
- [x] 1.2 Make the migration safe for supported database drivers and use explicit, MySQL-compatible schema operations whose rollback restores the immediately preceding definitions.
- [x] 1.3 Add focused schema tests proving omitted dispatch counters persist as zero, existing nonzero values survive the migration, and MySQL metadata reports zero defaults.

## 2. Make Origin Inventory Authoritative for Transfer Entry

- [x] 2.1 Refactor shared resolver product and conversion queries so catalogue `setting_id` does not exclude active stock-managed products with eligible stock at the authorized selected origin.
- [x] 2.2 Apply the same authorized-origin eligibility to deliberate tokenized product search and ambiguity candidate revalidation without exposing protected stock, bucket, location, condition, or tax values.
- [x] 2.3 Apply the same rule to serialized lookup while preserving exact location, condition, availability, reservation, dispatch, return-process, and active-transfer-custody guards.
- [x] 2.4 Confirm every independently callable scan/search/selection boundary still rejects an origin outside the active tenant and a product stocked only at another location.

## 3. Verify Draft Persistence and Reported Regressions

- [x] 3.1 Add focused resolver tests for a cross-catalogue product with eligible taxed or non-taxed stock at the authorized origin, including exact product barcode and conversion barcode behavior.
- [x] 3.2 Add focused search and serialized-product tests covering cross-catalogue origin inventory, opposite-condition rejection, and unrelated-location isolation.
- [x] 3.3 Add a focused MySQL-compatible draft-save regression proving two valid serialized selections persist with destination unset and all dispatch counters initialized to zero.
- [x] 3.4 Reproduce the reported barcode `2029194594988` shape using fixtures, run only the directly affected transfer-entry and draft-persistence tests, and record the focused verification results.

## Context

Terminal Harga is rendered by `App\Livewire\PricePoint\Browser`. Its product query already scopes visible products to the active setting through `product_prices.setting_id`, loads product metadata for display, supports customer-tier price resolution, and keeps the search input scanner-friendly by submitting on Enter and refocusing after search.

The current product search predicate uses the whole entered term for fallback `LIKE` matching. That works for a contiguous product name or scanned code, but it differs from Product List behavior where a query such as `SAM GAL FO` is treated as separate required tokens. Existing `Product::globalSearch()` captures that Product List style for name, code, category, and brand, but Terminal Harga also searches scanner-oriented fields that should not be weakened or reinterpreted: product barcode, unit conversion barcode, and serial number.

## Goals / Non-Goals

**Goals:**
- Make Terminal Harga free-text product search match Product List style multi-word behavior.
- Preserve whole-input scanning behavior for product barcode, conversion barcode, and serial number searches.
- Preserve active-setting price-row filtering, customer-tier display, pagination reset, scanner submit, and search refocus behavior.
- Keep the implementation local and additive to the existing Livewire component unless a small reusable helper is clearly justified.

**Non-Goals:**
- Changing Product List, Sales, Purchase, POS cart, or other product search behavior.
- Changing customer search, customer-tier price resolution, or displayed price rules in Terminal Harga.
- Adding a new search UI mode, scanner classifier, route, database schema, external dependency, or indexing strategy.
- Making barcode/serial scanning typo tolerant or tokenized.

## Decisions

- **Use a combined search predicate:** Terminal Harga should match products when either the whole input matches scanner-code fields or the tokenized free-text predicate matches descriptive fields.
  - Rationale: this preserves scanner behavior while fixing typed multi-word search.
  - Alternative considered: replace the entire Terminal Harga predicate with `Product::globalSearch()`. Rejected because that scope does not cover Terminal Harga's barcode, conversion barcode, or serial number scanner fields.

- **Tokenize only the free-text branch:** Free-text tokens should be whitespace-separated, empty tokens ignored, and each token required. For each token, match against product name, product code, category name, or brand name with `OR` semantics inside the token group.
  - Rationale: this mirrors the Product List expectation described by the existing multi-word search capability without changing scanner-code fields.
  - Alternative considered: use the current whole-term `LIKE` for all fields. Rejected because it is the source of the Product List parity gap.

- **Keep scanner-code matching as whole-input matching:** Product barcode, conversion barcode, and serial number checks should continue using the trimmed full search string.
  - Rationale: scanner values are meant to be interpreted as exact or contiguous identifiers; splitting them into tokens adds no value and can create false positives.
  - Alternative considered: detect barcode-like input and branch the whole query. Rejected for now because a combined predicate is simpler and preserves both use cases when operators paste or type mixed values.

- **Avoid UI changes:** The existing single search box, hidden submit button, Livewire `q` URL binding, pagination reset, and `refocus-search` dispatch should remain unchanged.
  - Rationale: the behavior change is query semantics, not interaction design.

## Risks / Trade-offs

- [Risk] A combined predicate can return a scanner-code match and a free-text match for the same input. -> Mitigation: the result list is paginated product rows, not an auto-add flow, so multiple results are acceptable in Terminal Harga.
- [Risk] Tokenized relational searches can add `whereHas` conditions for category and brand per token. -> Mitigation: Terminal Harga paginates 12 rows and uses the same small/medium-catalog trade-off already accepted for Product List style search.
- [Risk] MySQL fulltext typo-tolerant search in the current component behaves differently from Product List tokenized search. -> Mitigation: prioritize deterministic Product List style token matching for Terminal Harga free text, while preserving scanner fields; no new indexes are introduced.
- [Risk] Tests on SQLite may not exercise MySQL fulltext behavior. -> Mitigation: focused tests should assert the fallback/tokenized behavior that must remain database-portable.

## Migration Plan

No database migration is required. Deploy as a code-only change. Rollback is the previous `Browser` search predicate.

## Open Questions

None. The desired behavior is clarified: keep barcode scanning behavior and apply Product List parity only to free-text search.

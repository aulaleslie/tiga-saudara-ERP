## Context

The "Filter lainnya" drawer on `/reports/sale-by-product` is an Alpine-driven offcanvas built as a flex column:

```
  <div style="display:flex; flex-direction:column; height:100vh">
    <div class="offcanvas-header ...">              flex-shrink not set  → defaults to 1
    <div class="offcanvas-body" overflow-y:auto; flex-grow:1
                                                   min-height not set   → defaults to auto
    <div class="offcanvas-footer p-3 border-top">   flex-shrink not set  → defaults to 1
```

Two CSS defaults combine into the reported bug. A flex item's `min-height` defaults to `auto`, which means it refuses to shrink below its content's intrinsic height — so `overflow-y: auto` on the body never engages. And `flex-shrink` defaults to `1` on the footer, so when the body refuses to shrink, the footer is what gives way. The result is that growing content pushes the Filter/Reset buttons out of the viewport.

The content that grows is the selected-value pill list, rendered in a `d-flex flex-wrap` container with no height cap, one badge per selection.

Survey of the codebase: 21 report views contain `offcanvas-footer`, 20 of them with byte-identical markup, and **none** sets `flex-shrink`. This change fixes only the Sale by Product drawer by explicit decision; the rest are recorded as follow-up.

Separately, the report's option lookups pass the raw search phrase into a single `LIKE %phrase%` bound to one column. The product list DataTable instead uses Yajra smart search, enabled in `config/datatables.php`:

```
  'search' => ['smart' => true, 'multi_term' => true]
```

`DataTableAbstract::smartGlobalSearch()` splits the keyword on spaces and AND-s a `LIKE %token%` per token across every searchable column. Measured against local data:

```
  "alf in"   single LIKE %alf in%        →   0 rows
             tokens LIKE %alf% AND %in%  →  66 rows
```

## Goals / Non-Goals

**Goals:**
- Keep the drawer's footer actions reachable regardless of how many values are selected.
- Let a user act on every product matching a search term, not just the first ten.
- Make the report's product search behave like the product list's search box, including searching the product code.
- Make near-identical product names distinguishable in the option list.

**Non-Goals:**
- Typo-tolerant or fuzzy matching. `app/Services/TypoTolerantSearch.php` exists, `config/search.php` enables it, and the MySQL ngram FULLTEXT indexes (`ft_products_name`, `ft_products_code`, `ft_products_name_code`) are live — but the service has zero callers and the product list does not use it. Tokenized LIKE is what the list does and what this change adopts. Wiring up FULLTEXT is separate work.
- Fixing the other 20 report drawers or the four sibling reports' search lookups.
- Any change to report aggregation, business scoping, snapshot hashing, or export behaviour.

## Decisions

### 1. Fix the flex layout rather than cap the drawer's content

Set `flex-shrink: 0` on the header and footer, and `min-height: 0` on the scrolling body.

Rationale: this addresses the actual cause. The body was always meant to be the scrolling region — it already carries `overflow-y: auto` and `flex-grow: 1` — but `min-height: auto` silently disabled it. Adding `min-height: 0` is the standard fix for this well-known flexbox behaviour.

Alternative considered: giving the drawer a fixed footer via `position: sticky` or absolute positioning. Rejected — it works around the layout rather than correcting it, and would need manual body padding to avoid the footer overlapping content.

### 2. Bound the pill area independently of the fix above

Even with correct scrolling, 62 pills push every other filter far down the drawer. Give the pill container its own `max-height` with `overflow-y: auto`, and past a threshold collapse it to a summary ("62 produk dipilih") with a control to expand or clear.

Rationale: Decision 1 makes the drawer usable; this keeps it *legible*. The two are independent — one is a layout bug, the other is information density — and both are needed for the reported scenario.

### 3. Bulk selection re-runs the search unlimited, then caps

"Pilih semua hasil" re-executes the current search predicate **without** `limit(10)`, selecting only the identifier column, and merges the result into the existing selection. If the match count exceeds a ceiling, select up to the ceiling and surface a message that the selection was truncated.

Rationale: the user's need is "everything matching this term", which cannot be served from the ten rendered options. Re-querying is the only correct source. The cap protects two things: the Livewire component payload, which serialises the full selection on every request, and the `whereIn` clause the report builds from it. A ceiling of 500 is proposed as a starting point — it comfortably covers the 62-product "alfa ink" case while bounding the worst case.

Alternative considered: no cap. Rejected — a two-character search can match thousands of the ~5,586 products, and the resulting payload and query would degrade the page. Alternative considered: blocking above the threshold. Rejected as user-hostile; truncating with a clear message still delivers most of the value.

The selection must merge with, not replace, any existing selection, and must not introduce duplicates.

### 4. Tokenized matching with per-token field freedom

Split the trimmed search input on whitespace, discard empty tokens, and require **every** token to match (AND). Each token may match **any** of the searched fields (OR within the token).

```
  "alf in"  →  (name LIKE %alf% OR code LIKE %alf%)
           AND (name LIKE %in%  OR code LIKE %in%)
```

Rationale: this is exactly Yajra's smart-search semantics, which is what the product list exposes and what the user asked to match. Per-token field freedom is more forgiving and lets a query mix a name fragment with a code fragment.

The existing two-character minimum applies to the whole input, not per token, so "alf in" still qualifies. The `limit(10)` display cap is unchanged for the option list — Decision 3 covers reaching past it.

Note: tokenized LIKE is **not** typo tolerance. "alfa inc" still returns zero, because `inc` is not a substring of anything. Verified against local data.

### 5. Search and display the product code

Add `product_code` to the product lookup's searched fields, to the selected columns, and to the rendered option label and pill text.

Rationale: product codes are opaque SKUs (`SKU-B9E1EB27`), so 62 options all beginning "ALFA INK …" are otherwise indistinguishable in the dropdown. The product list already renders name and code together. Searching the code also makes SKUs findable, which they currently are not from this filter.

Category and customer lookups gain tokenized matching (Decision 4) but no new fields — they have no equivalent code column.

## Risks / Trade-offs

- **Tokenized LIKE prevents index usage** → `%token%` has a leading wildcard and cannot use a B-tree index, and multiple AND-ed tokens mean several such scans over ~5,586 products. Mitigation: the existing 300ms debounce and two-character minimum still apply, and `limit(10)` bounds the returned rows. Measure the response time on the largest bucket; if it regresses, the live ngram FULLTEXT indexes are an available (separate) remedy.

- **Bulk select re-queries without a limit** → The count query and the id-selection query both scan the full match set before the cap applies. Mitigation: select only the id column, and count before materialising so the ceiling is enforced without hydrating models.

- **Large selections bloat the Livewire payload** → Every selected id plus its label round-trips on each request. Mitigation: the Decision 3 ceiling, plus the Decision 2 collapse so the DOM does not render hundreds of badges.

- **Truncated bulk selection could mislead** → A user who selects "all" and silently gets 500 of 3,000 would draw wrong conclusions from the report. Mitigation: the truncation message is mandatory, not optional, and must state both the cap and the true match count.

- **Searching `product_code` changes which products match a given phrase** → A term that previously matched nothing may now return rows via the code column. This is the intended improvement, but it is a behaviour change to an existing filter and is marked BREAKING at spec level.

- **Drawer CSS fix could regress other viewports** → `min-height: 0` and `flex-shrink: 0` are narrow, well-understood properties, but the drawer is fixed at `width: 420px; height: 100vh`. Mitigation: verify at small viewport heights, where the footer-collapse behaviour is most visible.

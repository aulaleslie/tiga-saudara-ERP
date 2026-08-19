## Context

The Sale by Product report currently holds a scalar `public $settingId` initialised from `session('setting_id')`, used in eight places: three filter option lookups (product, category, customer) and four `$filter->scopeSettingId = $this->settingId` assignments feeding the query service, snapshot, validator, and export.

The scope split in the data is:

```
  MASTER DATA          setting_id = provenance (where the row was created)
  ├─ products          50,439 crossing sale lines; real ownership is
  │                    product_stocks → locations.setting_id
  ├─ categories        92% of products have NULL category; per-business
  │                    split effectively abandoned (1-3 products each
  │                    outside ATK/s=6 which holds 436)
  ├─ customers         99.2% have NULL setting_id; 989 customers
  │                    transact into more than one setting
  └─ tags              no setting_id column (Spatie, global)

  TRANSACTIONS         setting_id = ownership
  ├─ sales             ← the report's row scope
  └─ sale_returns      ← the report's row scope
```

Because `customers.setting_id` is NULL for 99.2% of rows, the existing customer option lookup already matches almost nothing (SQL `NULL != 1`), so that filter is effectively broken today in the same way the product filter is.

An established multi-select scope pattern already exists and is used by six reports (profit-loss, trial balance, general ledger, balance sheet, cash flow): the `HasReportSettingScope` trait plus the `business-source-selector` Blade partial (Select2, `theme: 'coreui'`, `wire:ignore`, `@this.set()` on change, `sync-select2-<selectId>` event for reset).

The one thing Sale by Product does that none of those six reports do is **hash its filter set**. `SaleByProductReportFilterData::hash()` is `md5(serialize($this->toArray()))`, and the snapshot service compares that hash to decide whether an export is still valid. That makes array normalization a correctness concern here that it is not elsewhere.

## Goals / Non-Goals

**Goals:**
- Let a user select multiple businesses and see Sale by Product rows across them, using the existing scope trait and Select2 partial so styling and behaviour match `/profit-loss-report`.
- Make filter options and report rows agree by construction, so any product visible in a row is also selectable in the filter.
- Preserve current behaviour when no business is selected (current session setting).
- Keep the export filter-drift guard correct under multi-select, so re-selecting the same businesses in a different order does not block export.

**Non-Goals:**
- Changing how products, categories, customers, sales, or returns are stored. No migrations.
- Removing or repurposing the `setting_id` column on master data tables.
- Fixing the same `products.setting_id` predicate in POS search, sales carts, or purchase forms. Offering every business's products in an operational picker may be undesirable for reasons unrelated to reporting correctness; that is a separate decision.
- Deduplicating or merging the duplicate category rows in the data.

## Decisions

### 1. Scope transactions only; leave master data unscoped

Filter option lookups drop their `setting_id` predicate for product, category, and customer. Row scoping stays on `sales.setting_id` and `sale_returns.setting_id`.

Rationale: the report has never scoped rows by product, category, or customer setting. The option predicates were reading a provenance column as if it were a tenancy column, which is precisely what produced the ALFA INK symptom. Removing them makes options a superset that exactly matches what rows can contain.

Alternative considered: derive filter options from the sale lines currently in scope (join through `sale_details`). This also guarantees agreement, but it is a heavier query, makes option lists depend on the selected date range, and adds latency to every keystroke in a search box. Rejected as over-engineered given master-data `setting_id` carries no ownership meaning — simply dropping the predicate achieves the same agreement.

### 2. Normalize the scope array in the `FilterData` constructor

`scopeSettingIds` is cast to `int`, sorted ascending, and reindexed with `array_values()` inside the constructor, before any `toArray()`/`hash()` call.

Rationale: `serialize()` encodes array order, integer keys, and value types. All three drift in practice here:

```
  [1,6]     vs [6,1]       → DIFFERENT   Select2 appends in click order
  [1,6]     vs ["1","6"]   → DIFFERENT   Select2 hands Livewire strings;
                                         the empty-selection default returns
                                         [(int) session('setting_id')]
  keys 0,1  vs keys 1,2    → DIFFERENT   getEffectiveSettingIds() ends in a
                                         bare array_filter() with no
                                         array_values(), so dropping a falsy
                                         id leaves a key gap
```

Any of these produces a different hash for an identical business selection, which makes `isValidForExport()` return `false` and silently refuses the export as "filters drifted" when nothing changed.

Note the existing trait already has an asymmetry: `render()` passes through `validateSettingIds()` (which does wrap in `array_values`), while the export path calls `getEffectiveSettingIds()` directly and does not. This is harmless in ProfitLossReport because nothing there hashes the result; copying the trait into a hashing report would import a latent bug.

Alternative considered: normalize in `toArray()`. Narrower, but leaves the public property un-normalized for anything reading it directly, and any future second hashing path would have to remember to normalize. Constructor normalization is canonical by construction and cannot be bypassed.

Alternative considered: change `hash()` from `serialize()` to a canonical JSON encoding. Larger blast radius — it invalidates every persisted snapshot on deploy and changes hashing for all other filter fields. Rejected in favour of the targeted fix.

### 3. Reuse `HasReportSettingScope` and the Select2 partial as-is

Neither the trait nor the partial is modified. The report supplies its own `selectId` (e.g. `saleByProductSettingIds`) to the partial, matching how profit-loss passes `profitLossSettingIds`.

Rationale: consistency of styling and multi-select behaviour was an explicit requirement, and the partial already handles Select2 destroy/re-init, the `wire:ignore` boundary, and reset syncing. Normalization lives in `FilterData` rather than in the trait so the six existing consumers are untouched.

### 4. Empty selection means current session setting

`getEffectiveSettingIds()` already returns `[(int) session('setting_id')]` when nothing is selected. This is inherited unchanged, so a user who never touches the new filter sees exactly today's report.

### 5. List duplicate category names as-is

Four distinct `LAPTOP` categories (settings 1, 3, 4, 6) render as four options once the category lookup is unscoped.

Rationale: `categoryIds` filters by id, so deduplicating by name would change filter semantics from "this category" to "every category with this name", and would hide a data-quality problem that is intended to be fixed separately. The dropdown should mirror the table honestly.

## Risks / Trade-offs

- **Duplicate-looking options in category, product, and customer dropdowns** → Accepted deliberately (Decision 5). The duplication is real and visible rather than silently merged. Category duplication is small (18 categories total).

- **Selecting multiple settings merges the same product's lines into one row** → The query groups by product; `products.setting_id` is not a grouping key. Since product `286` is one physical item stocked across settings 1 and 6, merging is the intended reading of "sales by product". Must be covered by an explicit test so the behaviour is pinned rather than incidental.

- **Persisted snapshots from before the change carry a scalar `scopeSettingId`** → `SaleByProductReportSnapshot::fromArray()` and `SaleByProductReportFilterData::fromArray()` must tolerate the old key. Snapshots live in the session (`sale_by_product_report_snapshot`), so the exposure window is short, but a stale-session hydration must not fatal. Mitigation: accept both keys on read, promoting a scalar to a single-element array; write only the plural key.

- **Unscoped product search over ~5,586 products** → The lookup is `LIKE '%term%'` with `limit(10)` and a 2-character minimum. Removing a `setting_id` predicate widens the scan. Mitigation: verify the existing minimum length and limit remain, and confirm response time on the largest bucket; no index change is anticipated but should be checked rather than assumed.

- **Export drift guard regression** → Normalization is the fix, but it is the subtlest part of the change. Mitigation: dedicated tests asserting identical hashes for reversed selection order, string-vs-int ids, and key-gapped arrays.

- **Validator currently has no rule for the scope field** → An unvalidated array reaching the query builder is the kind of gap that invites injection or type errors later. Mitigation: add `nullable|array` plus an integer element rule, and keep `validateSettingIds()` intersecting against actually-available settings.

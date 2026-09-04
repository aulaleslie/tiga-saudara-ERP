## Context

`inventory.view_remaining_stock` (shipped in `2026-09-04-gate-remaining-stock-quantity-visibility`) currently gates whether an exact stock quantity is shown on individual product cards in POS and Price Points. There is no report today that lets a permitted user see remaining stock for every product across every business they have access to in one place.

The underlying stock schema already supports most of what's needed: `product_stocks` tracks `quantity_tax`/`quantity_non_tax` (good) and `broken_quantity_tax`/`broken_quantity_non_tax` (bad) per `product_id` + `location_id`; `locations.setting_id` groups warehouses under a business (`settings`); `product_serial_numbers` tracks per-unit serials with a `status` enum and `is_broken`/`is_in_return_process` flags. A business's PKP status (`settings.is_pkp`) is a business-level flag, not stored per stock record — the tax/non-tax split already living on `product_stocks` is what lets us surface a mismatch (e.g., non-tax quantity present on a PKP=true business) without inferring anything.

User-to-business assignment already exists via the `user_setting` pivot (`User::settings()` relation, `withPivot('role_id')`), and is already correctly enforced in `BusinessSelector.php` (Super Admin sees all businesses via `hasRole('Super Admin')`; everyone else is restricted to their assigned businesses). However, the 6 existing reports using `HasReportSettingScope::getAvailableSettings()` do **not** apply this restriction — that is a known, pre-existing gap in the reports module, out of scope for this change.

## Goals / Non-Goals

**Goals:**
- Show per-product Good/Bad remaining stock across every business the acting user is assigned to (or all businesses for Super Admin), with a drill-down to per-location detail.
- Surface tax/non-tax composition mismatches against a business's `is_pkp` flag as an informational tooltip, at both the collapsed (business-aggregated) and expanded (per-location) level.
- Let a user find sellable serial numbers for a specific business/location/condition cell via a dialog, reusing the existing "sellable" filter semantics.
- Support fast search by product identity (name/code/category/brand, multi-token, order-independent) and by exact barcode or serial number.
- Export the same data to Excel, always fully expanded to per-location detail regardless of on-screen UI state.

**Non-Goals:**
- Fixing `HasReportSettingScope::getAvailableSettings()` for the other 6 reports that currently leak all businesses to any authenticated user — tracked as a separate, future concern.
- Validating or alerting on tax/non-tax mismatches — the tooltip is informational only; no business rule enforcement.
- Substring/partial barcode or serial number search — these use exact match only, by explicit decision (see Decisions).
- Full test-suite coverage or end-to-end browser testing — focused unit/feature verification only; browser testing will be performed manually by the requester.
- Real seeded broken-stock data — `broken_quantity` is universally zero in current production data; this path is verified via unit tests, not manual QA against real records.

## Decisions

**1. Business-visibility scoping is implemented locally in this component, not via a shared trait fix.**
`HasReportSettingScope::getAvailableSettings()` returns all businesses unconditionally today, a gap shared by 6 other reports. This component implements its own scoped query (`hasRole('Super Admin')` ? all : `$user->settings()`), mirroring the pattern already proven correct in `BusinessSelector.php:23-39`. Alternative considered: patch the shared trait once, fixing all 7 reports at once — rejected because it changes behavior of already-shipped reports outside this change's scope, and the user explicitly chose the local-fix path.

**2. Search splits into two independent, non-interacting paths.**
Product identity (name/code/category/brand) reuses `Product::scopeGlobalSearch`'s existing multi-token `LIKE`-chain logic unchanged — it already handles order-independent multi-word matching (e.g., "acer 8 core i3" matching "acer 8GB RAM core i3") correctly. Barcode and serial number use a separate **exact-match** equality lookup, not `LIKE`. Alternative considered: fulltext indexes with boolean-mode prefix matching on `barcode`/`serial_number` — rejected for this change because (a) it introduces short-token and word-boundary edge cases that need separate tuning/verification, (b) the user explicitly accepted full-match-only for barcode/serial in exchange for simpler, faster delivery, and (c) product name search — the one thing that must not regress — is left completely untouched by keeping it on its own path.

**3. One non-breaking index added: plain BTREE on `products.barcode`.**
`product_serial_numbers.serial_number` is already indexed (`idx_psn_serial_number`); `products.barcode` has no index today (only `product_name`/`product_code` have fulltext indexes). Since barcode search is now exact-match, a plain BTREE index makes it an O(log n) equality lookup. Additive migration only — no existing index, column, or query elsewhere is touched.

**4. Business/location aggregation: Good = `quantity_tax + quantity_non_tax`, Bad = `broken_quantity_tax + broken_quantity_non_tax`, summed across all `product_stocks` rows joined through `locations.setting_id`.**
A business can have multiple locations (0–3 in current data); the table shows a collapsed per-business subtotal by default, expandable to per-location breakdown. Both collapsed and expanded views compute the tax/non-tax tooltip from the same source rows — the collapsed tooltip aggregates the mismatch quantity across all of the business's locations, the expanded tooltip shows the mismatch per individual location.

**5. Serial number dialog is scoped to the exact business + location + condition (Good/Bad) cell clicked, defaulting to the "sellable" filter.**
Reuses the exact filter combination proven in `ProductSerialNumbersTable.php`: not broken (`is_broken = false`), not in return (`is_in_return_process = false`), not dispatched (`dispatch_detail_id IS NULL`), not returned (`status != 'RETURNED'`). Clicking the Bad cell's button instead filters to `is_broken = true`. No cross-business or cross-location aggregation inside the dialog — one cell, one location, one condition.

**6. Search-by-serial/barcode does not auto-open the serial dialog or auto-scroll.**
It resolves to the owning product's row via the same paginated table/filter mechanism as any other search term; the user still clicks the relevant cell's button to see the serial list. Rationale: the primary purpose of barcode/serial search is avoiding manual typing (scanner input), not jumping straight to a specific unit's detail — landing on the correct product row is sufficient.

**7. Column collapse/expand is new UI with no existing precedent in this codebase.**
Row-level expand-on-demand exists (`InventoryDetailReport`'s `expandedProducts`/`loadedProductDetails`), but not column-group collapse. This will require new grouped `<thead>` markup (colspan-based business headers, second-tier Good/Bad or per-location sub-headers) combined with a sticky first column and horizontal scroll. Accepted bar: functionally correct and not broken, not a polish exercise — consistent with the current UI quality bar elsewhere in the app.

## Risks / Trade-offs

- **[Risk] Barcode/serial exact-match may surprise users expecting partial/prefix matching (e.g., a scanner returning a truncated read).** → Mitigation: explicitly communicated and accepted trade-off; can be revisited later in isolation (e.g., swap to fulltext) without touching the product-name search path.
- **[Risk] Broken-quantity display/tooltip logic is unverified against real production data (all zero today).** → Mitigation: covered by unit/feature tests with seeded non-zero broken quantities; acceptable given the user's explicit sign-off.
- **[Risk] Column collapse/expand is unprecedented UI in this codebase and may take longer to get right than a row-based pattern would.** → Mitigation: accepted; scoped to "not broken," not polished, per user direction.
- **[Risk] Local business-scoping duplicates logic also present in `BusinessSelector.php`, risking future drift if `user_setting`/Super Admin semantics change.** → Mitigation: explicitly accepted as out of scope; noted here for future awareness only, no action taken in this change.

## Migration Plan

1. Add migration: plain BTREE index on `products.barcode` (additive, reversible via down-migration dropping the index — no data changes).
2. Ship new Livewire component, view, and Excel export as net-new files — no modifications to existing reports, routes, or the `HasReportSettingScope` trait.
3. Add menu entry gated by `inventory.view_remaining_stock` (existing permission — no new permission or permission-seeding migration needed).
4. Rollback: remove the new component/view/export files and the menu entry; drop the added index via the migration's down method. No data migration or backfill involved at any point.

## Open Questions

None outstanding — all prior open questions (aggregation scoping, tooltip granularity, serial dialog scope, search-result behavior, performance approach) were resolved during exploration and are captured as Decisions above.

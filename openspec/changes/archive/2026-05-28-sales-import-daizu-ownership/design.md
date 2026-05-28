## Context

Sales import currently separates transaction ownership from stock ownership. The sale header owner is resolved from Tag first and product marker second. Stock ownership is resolved later per detail line from marker, then latest non-CV Tiga Nusa purchase history, then the sale owner. That allows a Daizu product sale to be posted under PERDANA while the stock decrement and inventory Transaction are posted under Daizu if purchase history happens to point there.

Purchase import now has an explicit Daizu product override for whole-word `KEDELE`, `KEDELAI`, and `RAGI`, and routes both purchase ownership and stock ownership to Daizu Kedelai. Sales import should use the same product classification so the outbound side of the same product family remains aligned.

The relevant code paths are `Modules/Sale/Services/SalesImportService.php`, `Modules/Sale/Jobs/StageSalesImportRows.php`, and `Modules/Sale/Http/Controllers/SalesUploadController.php`. Staging already captures `tag`, `produk`, and `gudang`; no schema change is needed.

## Goals / Non-Goals

**Goals:**
- Use one Daizu product classifier in sales import that matches purchase import semantics.
- Make Daizu products a priority-zero override for sales owner and stock owner.
- Keep sale header `setting_id`, new product `setting_id`, `ProductPrice.setting_id`, dispatch location, `product_stocks` decrement location, and inventory `Transaction.setting_id` aligned to Daizu for Daizu-matched rows.
- Resolve dispatch location from CSV `Gudang` within Daizu when supplied, and fail explicitly if the requested Daizu location is missing.
- Treat existing non-Daizu sales for the same Daizu invoice as duplicate conflicts to avoid double importing during the ownership transition.
- Preserve existing Tag, marker, history fallback, chunking, staging, tax calculation, and standard duplicate behavior for non-Daizu products.

**Non-Goals:**
- No migration or automatic rewrite of historical sales already imported under the wrong owner.
- No new database columns or tables.
- No change to purchase import behavior beyond using it as a behavioral reference.
- No broad rewrite of import batching, CSV parsing, tax calculation, or customer/product creation.

## Decisions

1. **Use the purchase importer word-boundary classifier.**
   - Decision: Normalize product names to uppercase, remove punctuation, collapse whitespace, then match whole words `KEDELE`, `KEDELAI`, or `RAGI`.
   - Rationale: This matches the existing purchase import contract and avoids false positives such as `PREKEDELAI` or `RAGING`.
   - Alternative considered: substring matching. Rejected because it is less precise and would diverge from purchase import tests.

2. **Make Daizu ownership priority zero for sale and stock.**
   - Decision: `resolveTenant()` returns Daizu before Tag or marker for Daizu-matched products, and `resolveStockSetting()` returns Daizu before marker or history fallback.
   - Rationale: The business decision is full ownership by Daizu Kedelai, not just inventory segregation.
   - Alternative considered: only override stock owner. Rejected because it leaves `sales.setting_id` and `ProductPrice.setting_id` under another tenant.

3. **Group Daizu rows by effective owner, not raw Tag or marker.**
   - Decision: Invoice grouping should use Daizu as the grouping key for Daizu-matched rows. Non-Daizu rows continue grouping by Tag when present, otherwise marker.
   - Rationale: A Daizu row with a Tag or marker should not split into a non-Daizu invoice group.
   - Alternative considered: leave grouping unchanged and rely on processing-time owner override. Rejected because duplicate grouping and multi-line invoices can be harder to reason about when grouping keys do not match effective owner.

4. **Resolve Daizu dispatch location from `Gudang` inside Daizu only.**
   - Decision: If CSV `gudang` is present for a Daizu row, match a Daizu-owned `Location` by name. If blank, use the Daizu default location. If the setting or required location is missing, mark rows invalid.
   - Rationale: Warehouse names are meaningful only within the owner that will own the stock movement. Falling back to another tenant creates misleading inventory history.
   - Alternative considered: current fallback to source setting location. Rejected because `Transaction.setting_id` could be Daizu while `location_id` belongs to another owner.

5. **Treat legacy non-Daizu invoice matches as conflicts for Daizu products.**
   - Decision: For Daizu-matched invoice groups, duplicate detection must check existing sales with the same imported reference under Daizu and existing non-Daizu sales containing Daizu-matched products. Existing Daizu matches are skipped as normal duplicates; legacy non-Daizu matches are invalid/conflict rows requiring reconciliation.
   - Rationale: A second Daizu sale for the same invoice would double count sales and stock. Silently skipping against a non-Daizu sale would also hide unresolved historical ownership.
   - Alternative considered: ignore legacy non-Daizu duplicates and import under Daizu. Rejected because it creates duplicate invoices and stock decrements.

## Risks / Trade-offs

- Legacy data can block imports -> Mitigation: mark rows invalid with a clear conflict message identifying the existing sale so operators can decide whether to reconcile or skip the file.
- Daizu setting lookup by company name can be brittle -> Mitigation: follow purchase import's `%DAIZU%` lookup now; keep the helper isolated so a future canonical setting identifier can replace it.
- Warehouse matching may fail because CSV names differ from ERP location names -> Mitigation: fail loudly for supplied `Gudang` and include the warehouse name in the error; blank `Gudang` can still use the Daizu default location.
- Existing tests may assume sales import mirrors purchase marker/history logic only -> Mitigation: add Daizu-specific tests and preserve existing non-Daizu marker/history behavior.
- Multi-line invoices with mixed Daizu and non-Daizu products could split into multiple sales by effective owner -> Mitigation: preserve per-owner grouping; Daizu rows must remain Daizu, while unrelated products keep existing owner rules.

## Migration Plan

1. Add Daizu detection and setting/location helpers to sales import.
2. Update sale owner, stock owner, grouping, duplicate detection, and dispatch location resolution for Daizu-matched rows.
3. Add focused tests for Daizu ownership alignment, missing setup failures, legacy duplicate conflicts, `Gudang` location resolution, and whole-word matching.
4. Run focused sales import tests, then broader PHP tests as appropriate.

Rollback is code-only: revert the service and tests. No data migration is introduced.

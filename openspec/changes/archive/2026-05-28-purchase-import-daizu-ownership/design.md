## Context

Purchase CSV imports currently resolve document ownership through `Tag` first and product-name markers second. Stock ownership is resolved separately: marker-owned rows go to fixed tenants, while unmarked rows can use the latest non-CV Tiga Nusa `BUY` transaction history before falling back to the purchase document owner.

The kedelai purchase files use untagged product names such as `KEDELE IMPORT` and `RAGI`. Without a dedicated rule, those rows can become `PERDANA` purchase documents or use historical stock owners instead of the seeded `Daizu Kedelai` setting. The `Daizu Kedelai` company is seeded with document prefix `DK` and a default `Gudang Barang` location.

## Goals / Non-Goals

**Goals:**
- Resolve purchase import rows whose product names contain `KEDELE`, `KEDELAI`, or `RAGI` to `Daizu Kedelai`.
- Keep Daizu-matched purchase document ownership, stock location ownership, stock transaction ownership, and purchase price ownership aligned.
- Preserve existing generic tag, marker, and history behavior for non-Daizu products.
- Make missing Daizu setting or location failures explicit at row level.
- Correct duplicate progress accounting and per-line raw product-name stock resolution.

**Non-Goals:**
- Do not change sales import behavior in this change.
- Do not rewrite historical purchase, stock, transaction, or product rows.
- Do not introduce tenant-scoped product masters; product lookup remains global by cleaned product name.
- Do not auto-create the Daizu setting or location during import.

## Decisions

1. Add a purchase-import product ownership override before existing tag, marker, and history resolution.

   Daizu matching is based on normalized product name containment for `KEDELE`, `KEDELAI`, or `RAGI`. This is intentionally broader than exact `KEDELE IMPORT` and `RAGI` so known spelling and naming variants route consistently.

   Alternative considered: exact product-name matching only. Rejected because historical source files include variants such as `KEDELE IMPORT @50`, and future imports may use `KEDELAI`.

2. Apply the Daizu override to both transaction owner and stock owner.

   For matching rows, `purchase.setting_id`, `ProductPrice.setting_id`, `ProductStock.location_id`, and inventory `Transaction.setting_id` must all resolve through the Daizu setting/location. This avoids split ownership between accounting documents and stock movement.

   Alternative considered: only stock owner override. Rejected because the purchase document would still be filed under `PERDANA` or a tag-derived tenant, making Daizu purchase reporting incomplete.

3. Fail rows when Daizu setting or location is missing.

   The importer should mark affected rows invalid with an actionable error instead of falling back to another tenant. This preserves data integrity and makes seeder/setup problems visible.

   Alternative considered: auto-create Daizu setting/location. Rejected because company creation is seeded configuration, not an import side effect.

4. Preserve global product identity.

   Product lookup remains based on cleaned product name. The ownership change controls document, price, stock, and transaction rows without introducing duplicate product masters.

   Alternative considered: setting-scoped product lookup. Rejected as broader than this import correction and inconsistent with the current importer.

5. Treat duplicate skipped rows as processed, not successful.

   Duplicate rows already represent no-op import outcomes. Counting them as processed keeps batch progress truthful while keeping `success_count` limited to rows that created or updated import-side records.

6. Store each row's raw product name with its prepared detail.

   The current detail-building loop computes a raw product name per row, but the stock loop can later reuse the final loop value. The import should carry `raw_product_name` on each detail so mixed-product invoices resolve stock ownership per line.

## Risks / Trade-offs

- [Risk] Broad `RAGI` matching could capture an unrelated product that contains the same token. → Mitigation: normalize and match product-name tokens, and cover examples in tests.
- [Risk] Existing products originally created under other settings will receive Daizu stock/price rows. → Mitigation: this follows the chosen global product identity model and only changes ownership rows created by purchase import.
- [Risk] Case and punctuation variants can bypass matching if normalization is too weak. → Mitigation: uppercase matching and collapse whitespace/punctuation around product-name tokens.
- [Risk] Duplicate accounting may confuse users if `success_count` is lower than processed progress. → Mitigation: status views should surface skipped rows as a processed no-op status, separate from success and error counts.

## Migration Plan

No database migration is required. Deploy code and tests, then run focused purchase import tests. Rollback is a code rollback; imported rows created before rollback are not rewritten.

## Open Questions

None for purchase import. Sales import Daizu alignment is intentionally deferred to a separate exploration/change.

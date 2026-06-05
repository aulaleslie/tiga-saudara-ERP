## Context

The ERP already has product import infrastructure in `Modules/Product`: upload routing, product import batches and rows, monitoring views, and a queued `ProcessProductImportBatch` job. Purchase and Sales imports also contain owner-routing marker logic, with Sales import using product-name markers as the fallback owner source.

The stock snapshot file for this change is not a purchase, sale, or full product master import. It contains product identity, stock quantity, and unit columns. Owner routing is encoded in `Product Name` markers, and the import must update inventory by owner setting while preserving a single product identity where the same clean product name appears under multiple owners.

## Goals / Non-Goals

**Goals:**
- Import `warehouse_stock_quantity.csv` style product stock snapshots through a product import flow.
- Route each row to an owner setting from the product-name marker: `*` to CV TIGA NUSA COMPUTER, trailing `TP` to CV TOP IT INTERNUSA, and no marker to PERDANA.
- Remove owner markers before product matching or product creation.
- Create missing products using clean product name, optional product code, and product unit.
- Overwrite the stock quantity at the first location for the resolved owner setting, including `0` and negative values.
- Preserve row-level audit, import progress, errors, and stock mutation records.

**Non-Goals:**
- Do not create purchase or sale documents from the stock file.
- Do not infer owner from purchase/sales tags for this flow.
- Do not split stock across multiple locations for one owner.
- Do not introduce serial-number creation from this file.
- Do not change historical purchase, sale, POS, or product price import behavior.

## Decisions

1. Reuse the Product import area instead of Purchase or Sales upload pages.

   The target data is product stock state, and `Modules/Product` already owns product import batches, product rows, product creation, stock rows, and import monitoring. Purchase and Sales imports are useful references for marker parsing, but adapting them directly would couple stock snapshots to document import behavior they do not need.

   Alternative considered: create a purchase-style upload flow. Rejected because it would imply supplier documents and payment/tax logic that are unrelated to a stock snapshot.

2. Treat products as global identities and stock as owner-location state.

   After marker cleanup, the same product name can appear under multiple markers. The import should match or create one Product by clean name/code and update separate `product_stocks` rows at each resolved owner location.

   Alternative considered: create separate products per owner marker. Rejected because duplicate clean product names would fragment search, pricing, bundle, and transaction history across owner-specific duplicates.

3. Use `Total Quantity` as the source quantity.

   The CSV includes `Unassigned` and `Total Quantity`; the import will use `Total Quantity` for stock overwrite and retain `Unassigned` in row raw payload for visibility.

   Alternative considered: use `Unassigned`. Rejected because the user selected total stock as the snapshot value.

4. Overwrite stock rather than adjusting by delta.

   Each row represents a snapshot. The processor must set the target product/location stock to the CSV quantity and record a stock transaction containing previous and after quantities so the overwrite remains auditable.

   Alternative considered: add the imported quantity to existing stock. Rejected because it would double-count when importing a full warehouse snapshot.

5. Resolve the target location as the first location for the owner setting.

   Owner marker resolution returns a `Setting`; the processor will use the first configured `Location` for that setting. Rows for owners without a matching setting or location will fail with row errors instead of silently falling back to another owner.

   Alternative considered: ask the user to choose one upload location. Rejected because one file contains rows for multiple owner settings.

6. Allow zero and negative stock values.

   The import must overwrite stock to exactly match the CSV, including `0` rows and the negative quantity row present in the source file. Negative values are not corrected or skipped by this flow.

   Alternative considered: reject negative stock. Rejected because the user explicitly allowed it for this import.

7. Complete the feature with an explicit stock snapshot UI, not only header auto-detection on the generic product upload page.

   The backend may still auto-detect stock snapshot files for compatibility, but users need a visible entry point or upload mode labelled for stock snapshot import. The UI should make the import type visible before and after upload, provide a stock snapshot template download, explain marker routing rules, and distinguish stock snapshot batches from product master batches in the import list and detail screens.

   Alternative considered: keep stock snapshot as an invisible variant of generic product upload. Rejected because users cannot confidently choose the right template, understand owner marker rules, or inspect stock effects without explicit UI support.

8. Store row-level stock snapshot result metadata for monitoring.

   Successful stock snapshot rows should persist enough result data for the detail page to show the resolved owner, target location, resolved product, previous quantity, after quantity, bucket effect, and stock transaction reference without requiring operators to infer it from raw payload or manually search stock transactions. The existing `product_import_rows.created_txn_id` and `created_stock_id` columns are appropriate references; structured result details can be added through nullable metadata columns or an existing row payload/result convention if one exists.

   Alternative considered: rely only on the `transactions` table as audit. Rejected because the requirement includes row-level visibility in the import monitoring workflow.

9. Treat PKP bucket routing as part of backend completion.

   Stock snapshot overwrite must keep `product_stocks.quantity`, `quantity_tax`, `quantity_non_tax`, `products.product_quantity`, and transaction quantity bucket fields mutually consistent. PKP owners route the snapshot quantity into the tax bucket; non-PKP owners route it into the non-tax bucket. The signed transaction quantity remains the delta from previous total to after total.

   Alternative considered: always write non-tax quantity for snapshot imports. Rejected because owner setting PKP status is already part of stock bucket semantics.

## Risks / Trade-offs

- Duplicate product matching may be imperfect for historical products with inconsistent punctuation or spacing -> Use the same marker cleanup and whitespace normalization during matching and creation, and surface row errors for ambiguous code/name conflicts.
- Negative inventory can affect reports and POS availability -> Keep the behavior explicit in the spec and transaction reason so downstream users can identify snapshot-driven negative stock.
- First-location routing may be surprising when a setting has multiple warehouses -> Keep it deterministic and visible in row payload/result; future changes can add configurable default stock import locations.
- Product creation from sparse CSV data may create minimal products with default pricing/category/brand -> Keep creation conservative, stock-managed, and unit-backed; do not invent price, brand, category, or serial metadata.
- Overwriting stock can erase manual corrections if the wrong file is uploaded -> Preserve batch visibility and transaction audit; row-level undo can be considered only if existing product import undo semantics support all touched records safely.
- Header auto-detection can make mistakes if unrelated files contain `Unassigned` and `Total Quantity` columns -> Prefer an explicit stock snapshot upload mode while retaining detection as a fallback or validation aid.

## Migration Plan

No destructive schema migration is expected if existing product import batch/row tables can store the needed row payload and references. If row-level stock reference metadata is insufficient, add nullable columns rather than changing existing import records. Existing `product_import_batches.import_type`, `product_import_rows.created_txn_id`, and `product_import_rows.created_stock_id` should be used or completed before adding new schema.

Deploy by adding the stock snapshot import mode/routes or extending the product upload flow, then process imports through the existing queue. Rollback should disable the new route/mode while leaving already-created products, product stock rows, and audit transactions intact.

## Open Questions

No blocking questions remain. Confirmed decisions: create missing products, use `Total Quantity`, overwrite stock including `0` and negative quantities, and route to the first location for the resolved owner setting.

## Context

The current POS return implementation was planned as a wrapper over Sales Return execution records, but the draft create path currently creates linked `sale_returns` immediately and the snapshot/UI collapses returnable rows by `product_id`. This breaks transactions where the same SKU appears in multiple source contexts, such as `TNC-TXN-2026-05-0001`, where two serialized Samsung units were sold through a bundle and one serialized Samsung unit was sold without a bundle.

The real `TNC-RCP-2026-05-00001` rendering also shows a second bundle-specific issue. Checkout stored the bundled Samsung line and its serials on the original POS transaction line metadata, while split posting created separate Sales Detail / Sales Bundle Item allocation rows for the bundle components. The current draft return snapshot uses `sale_detail->bundleItems` to detect bundled rows, so it renders zero-quantity component allocation details as top-level `Bundle / Habis` cards and renders the actual bundled Samsung serials without their bundle context. The return draft must instead follow the receipt/POS-line shape first, then retain Sales Detail and component allocation metadata underneath for traceability.

For draft create/edit/delete, the POS Return document must remain an intake document only. It may snapshot the source POS transaction and persist selected draft resolutions, but it must not create Sales Return documents, mutate stock, reduce dispatch quantities, settle payments, or write inventory transaction history.

## Goals / Non-Goals

**Goals:**

- Persist one POS Return draft header for a completed posted POS transaction.
- Persist draft line selections at the correct source granularity:
  - one row per original sold serial for serial-tracked products;
  - one row per original source sale/dispatch/component group for non-serial products with positive/actionable quantities.
- Support per-line resolution values: `none`, `product_replacement`, and `cash_return`.
- Require at least one actionable draft line before save.
- Require active/available replacement serial input for serial-tracked `product_replacement` lines.
- Preserve source POS line, sale, sale detail, dispatch, owner/source setting, source location, tax, serial, and bundle context.
- Treat original POS transaction line metadata as authoritative for whether a sold serial belongs to a bundle.
- Group serialized draft UI rows by original POS transaction line so the form mirrors the receipt shape.
- Hide zero-quantity split bundle allocation Sales Detail rows from top-level returnable cards.
- For bundled serial `cash_return`, show/store the full original POS unit price as the customer-facing expected cash amount.
- For bundled serial `product_replacement`, show component trace rows and remaining component availability counts, without exposing location names.
- Allow draft edit/delete and rejected edit/delete according to the agreed lifecycle rules.

**Non-Goals:**

- Submit for approval.
- Approval or rejection actions.
- Receive returned goods.
- Replacement dispatch.
- Cash return settlement.
- Sales Return document creation.
- Stock, serial-status, dispatch-quantity, payment, or `transactions` table mutations.
- Breakage handling for non-serial bundled components.
- Origin/location/owner validation for replacement serial selection.
- Reserving replacement parent serials or bundled component stock during draft.
- Selecting replacement component products or component serials during draft.
- Blocking draft save based only on bundled component availability. Component availability is informational in this change.

## Decisions

### Decision 1: Treat Draft POS Return as the Only Persisted Document During Create/Edit

Draft create and edit will write `pos_returns` and `pos_return_lines` only. `sale_returns` and `sale_return_details` remain absent until a later approval or settlement phase.

Rationale: draft intake must be reversible and must not create execution-side documents before approval. This also prevents draft documents from reducing POS availability, dispatch quantities, stock, or payment balances.

Alternative considered: create linked Sales Return documents during draft save. Rejected because it makes draft persistence look like execution, creates cleanup risk, and conflicts with the requirement that store does not incur any transaction yet.

### Decision 2: Use Source Identity Instead of Product Aggregation

Returnable draft rows must be keyed by source identity, not by `product_id`. For serialized POS lines, the primary UI/source key is the original `pos_transaction_line_id` plus returned serial identity. The source key must preserve enough information to distinguish same-SKU rows by original POS transaction line, sale, sale detail, dispatch detail, serial, bundle context, owner/source setting, source location, and tax context.

Rationale: product-level aggregation loses the difference between bundled and non-bundled sales of the same serialized product. It also makes serial-specific resolutions impossible.

Alternative considered: keep the current product-level UI row and map serials during save. Rejected because the UI and draft payload would remain ambiguous for bundles, source sales, and per-serial replacement decisions.

### Decision 2A: Use POS Transaction Line Metadata as Bundle Source of Truth

For serialized parent products, bundled context is derived from the original POS transaction line metadata (`bundle_id`, `bundle_name`, and `bundle_items`) rather than from whether the parent Sales Detail has `bundleItems`. Sales Detail, Sales Bundle Item, checkout sale, dispatch, source setting, source location, and tax rows remain part of the snapshot for accounting/source traceability.

Rationale: split posting can put component allocation rows on separate zero-quantity Sales Details, while the sold parent serials still belong to the original bundled POS transaction line. The return form is customer-facing and must match the receipt shape: the bundled Samsung serials should appear under the bundled POS line, not as unrelated tracked serial rows.

Alternative considered: continue using `sale_detail->bundleItems` as the bundle detector. Rejected because `TNC-RCP-2026-05-00001` demonstrates that this renders empty top-level bundle cards and loses the bundled context for the actual serial rows.

### Decision 3: Store Serial-Tracked Source Units Individually

For serial-tracked products, each original sold serial is represented as its own draft line with a returned serial reference and a resolution. The default resolution is `none`.

Rationale: each serial can have a different outcome in one POS Return document, such as one serial cash return, one serial product replacement, and one serial no action.

Alternative considered: store one row per product with a JSON list of selected serials. Rejected because replacement serials and resolutions are per returned serial, not per product.

### Decision 4: Require Replacement Serial During Draft Product Replacement

When a serial-tracked line has `product_replacement`, the draft must store a replacement serial selected through a scanner/barcode-friendly input. The replacement serial must be an active/available serial for the same product and must not be the returned serial. No source owner/location validation is required for this change.

Rationale: the replacement intent is reviewable from draft onward and can be revalidated before future approval. Keeping draft free of stock mutations means the replacement serial is not reserved or locked.

Alternative considered: defer replacement serial selection until approval or dispatch. Rejected for this phase because the draft document should already describe the requested resolution completely.

### Decision 5: Carry Bundle Components Only for Actionable Bundled Serials

When a serialized bundle parent has `cash_return` or `product_replacement`, the draft stores or derives the required bundle component trace rows for that source bundle instance. When the parent serial is `none`, component rows remain absent from executable draft lines and available only from the source snapshot.

Rationale: bundled returns must preserve component traceability without creating noise for source units that are not being returned. Non-serial component breakage is handled manually outside this return process.

Alternative considered: always store component rows for every source bundled serial. Rejected because `none` rows are not executable and should not inflate draft line count.

### Decision 5A: Keep Bundle Component Availability Informational During Draft

For bundled serial `product_replacement`, the draft UI shows each required component quantity per returned serial and the remaining available component quantity. Availability is computed from the source setting/location allocation context used by POS checkout posting, but the UI does not show location names. Draft save does not reserve component stock, mutate component stock, or require component replacement selection.

Rationale: store users need to know whether replacement bundle components remain available, but draft intake is still reversible and must not create stock effects. Showing location labels adds noise; the source/location context is an internal calculation detail.

Alternative considered: block draft save when component availability is insufficient. Rejected for this change because approval/receiving/replacement execution semantics are out of scope, and draft documents must not reserve stock.

### Decision 5B: Use Full POS Unit Price for Bundled Serial Cash Return Display

When a bundled serialized parent uses `cash_return`, the customer-facing expected cash amount is the original POS unit price for that POS transaction line. Source allocation metadata may still preserve parent residual and component allocation values for later accounting/settlement work.

Rationale: the return draft should match what the customer saw on the receipt. For `TNC-RCP-2026-05-00001`, the bundled Samsung line was sold at `Rp 6,000,000` per unit, even though split posting allocated `Rp 5,920,000` to the parent residual and `Rp 80,000` to components.

Alternative considered: show only the parent residual amount from the parent Sales Detail. Rejected because it under-represents the customer-facing sold unit price for bundled returns.

### Decision 6: Delete Rules Stay Narrow

Draft POS Returns are hard-deletable because they have no execution effects. Rejected POS Returns are editable, and saving the edit resets status to draft. Rejected deletion uses an audited soft-delete style marker. Approved/archive behavior is intentionally left for a later lifecycle change.

Rationale: draft cleanup should be simple, while rejected documents already have approval history and should retain an audit trail.

## Risks / Trade-offs

- Source mapping gaps in existing sale/dispatch rows → Build the snapshot from POS transaction lines, POS line serials, checkout sales, sale details, dispatch details, and product serial numbers together; add focused tests for bundled and non-bundled same-SKU serials.
- Bundle context can be split across POS line metadata and Sales Detail allocation rows → Use POS transaction line metadata for customer-facing grouping and bundle identity, then attach Sales Detail / Sales Bundle Item / checkout sale allocation metadata underneath.
- Replacement serial can be sold after draft save → Do not reserve it during draft; revalidate active/available status on edit and in the later approval workflow.
- Component availability can drift after draft save → Treat component counts as informational during draft and revalidate before future execution workflows.
- Schema drift from existing `return_option` header field → Keep backward compatibility where needed, but new draft behavior must treat line resolution as authoritative.
- Rejected soft-delete may need new audit fields → Add nullable fields or reuse existing archive/delete audit columns without rewriting historical data.
- Bundle component trace rows can become confusing → Keep the UI grouped under each source serial and clearly separate parent resolution from component trace rows.

## Migration Plan

- Add nullable, backward-compatible columns needed for draft line-level resolution and serial replacement metadata.
- Add nullable audit columns for rejected soft-delete if existing fields cannot represent the agreed behavior.
- Keep existing data valid; do not rewrite historical POS, Sales Return, dispatch, or stock records.
- Ensure `down()` migrations drop added indexes and columns in dependency-safe order.
- Rollback is schema-level rollback plus removal of the new draft behavior; since draft create/edit/delete must not mutate execution tables, rollback risk is limited to draft POS Return records created during the change.

## Open Questions

- None for create/edit/delete scope. The agreed draft behavior is: POS transaction line metadata drives bundle grouping; bundled component availability is informational and shown only for `product_replacement`; draft save does not reserve or mutate parent or component stock.
